<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Formatters;

use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;

/**
 * JSON Formatter
 *
 * Formats log records as JSON for structured logging.
 * Compatible with ELK stack, Loki, Datadog, and other log aggregators.
 *
 * OUTPUT FORMAT:
 * ```json
 * {
 *   "timestamp": "2024-01-15T10:30:00.123456+00:00",
 *   "level": "error",
 *   "level_name": "ERROR",
 *   "channel": "app",
 *   "message": "Database connection failed",
 *   "context": {
 *     "host": "db.example.com",
 *     "error": "Connection refused"
 *   },
 *   "extra": {
 *     "request_id": "abc-123"
 *   }
 * }
 * ```
 */
class JsonFormatter extends NormalizerFormatter implements FormatterInterface
{
    public const BATCH_MODE_JSON = 1;      // Each record on new line (JSONL)
    public const BATCH_MODE_NEWLINES = 2;  // JSON array of records

    private int $batchMode;
    private bool $appendNewline;
    private bool $ignoreEmptyContextAndExtra;
    private bool $includeStacktraces;

    /** @var array<string> Fields to include (empty = all) */
    private array $includeFields = [];

    /** @var array<string> Fields to exclude */
    private array $excludeFields = [];

    /** @var int JSON encoding flags */
    private int $jsonFlags;

    /**
     * @param int $batchMode Batch mode (BATCH_MODE_JSON or BATCH_MODE_NEWLINES)
     * @param bool $appendNewline Append newline after each record
     * @param bool $ignoreEmptyContextAndExtra Omit empty context/extra
     * @param bool $includeStacktraces Include stack traces in output
     */
    public function __construct(
        int $batchMode = self::BATCH_MODE_NEWLINES,
        bool $appendNewline = true,
        bool $ignoreEmptyContextAndExtra = false,
        bool $includeStacktraces = true,
    ) {
        parent::__construct(\DateTimeInterface::RFC3339_EXTENDED);

        $this->batchMode = $batchMode;
        $this->appendNewline = $appendNewline;
        $this->ignoreEmptyContextAndExtra = $ignoreEmptyContextAndExtra;
        $this->includeStacktraces = $includeStacktraces;

        // Default JSON flags: don't escape slashes, don't escape unicode
        $this->jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    }

    /**
     * Set JSON encoding flags
     *
     * @param int $flags JSON_* constants
     * @return self
     */
    public function setJsonFlags(int $flags): self
    {
        $this->jsonFlags = $flags;

        return $this;
    }

    /**
     * Set fields to include (whitelist)
     *
     * @param array<string> $fields Field names to include
     * @return self
     */
    public function setIncludeFields(array $fields): self
    {
        $this->includeFields = $fields;

        return $this;
    }

    /**
     * Set fields to exclude (blacklist)
     *
     * @param array<string> $fields Field names to exclude
     * @return self
     */
    public function setExcludeFields(array $fields): self
    {
        $this->excludeFields = $fields;

        return $this;
    }

    /**
     * Format a single log record
     */
    public function format(LogRecord $record): string
    {
        $normalized = $this->normalizeLogRecord($record);

        return $this->toJson($normalized) . ($this->appendNewline ? "\n" : '');
    }

    /**
     * Format a batch of records
     *
     * @param array<LogRecord> $records
     */
    public function formatBatch(array $records): string
    {
        $normalized = [];

        foreach ($records as $record) {
            $normalized[] = $this->normalizeLogRecord($record);
        }

        if ($this->batchMode === self::BATCH_MODE_JSON) {
            return $this->toJson($normalized) . ($this->appendNewline ? "\n" : '');
        }

        // BATCH_MODE_NEWLINES: One JSON object per line (JSONL format)
        $output = '';
        foreach ($normalized as $data) {
            $output .= $this->toJson($data) . "\n";
        }

        return $output;
    }

    /**
     * Normalize a log record to array
     *
     * @return array<string, mixed>
     */
    protected function normalizeLogRecord(LogRecord $record): array
    {
        $data = [
            'timestamp' => $record->datetime->format(\DateTimeInterface::RFC3339_EXTENDED),
            'level' => strtolower($record->level->name),
            'level_name' => $record->level->name,
            'channel' => $record->channel,
            'message' => $record->message,
        ];

        // Context - use custom normalization for exceptions
        if (!empty($record->context) || !$this->ignoreEmptyContextAndExtra) {
            $data['context'] = $this->normalizeContext($record->context);
        }

        // Extra
        if (!empty($record->extra) || !$this->ignoreEmptyContextAndExtra) {
            $data['extra'] = $this->normalize($record->extra);
        }

        // Apply field filters
        $data = $this->filterFields($data);

        return $data;
    }

    /**
     * Normalize context with special handling for exceptions
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    protected function normalizeContext(array $context): array
    {
        $normalized = [];

        foreach ($context as $key => $value) {
            if ($value instanceof \Throwable) {
                $normalized[$key] = $this->normalizeException($value);
            } else {
                $normalized[$key] = $this->normalize($value);
            }
        }

        return $normalized;
    }

    /**
     * Normalize an exception with full details including previous exceptions
     *
     * @return array<string, mixed>
     */
    protected function normalizeException(\Throwable $exception, int $depth = 0): array
    {
        // Prevent infinite recursion
        if ($depth > 10) {
            return ['class' => get_class($exception), 'message' => '[max depth reached]'];
        }

        $data = [
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];

        // Include stack trace if enabled
        if ($this->includeStacktraces) {
            $data['trace'] = $this->formatTrace($exception->getTrace());
        }

        // Include previous exception (chained exceptions)
        $previous = $exception->getPrevious();
        if ($previous !== null) {
            $data['previous'] = $this->normalizeException($previous, $depth + 1);
        }

        return $data;
    }

    /**
     * Format stack trace for JSON output
     *
     * @param array<int, array<string, mixed>> $trace
     * @return array<int, string>
     */
    protected function formatTrace(array $trace): array
    {
        $formatted = [];

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '[internal]';
            $line = $frame['line'] ?? 0;
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            $function = $frame['function'] ?? '';

            $call = $class . $type . $function . '()';
            $formatted[] = "{$file}:{$line} {$call}";

            // Limit to 20 frames to avoid huge logs
            if (count($formatted) >= 20) {
                $formatted[] = '... (truncated)';
                break;
            }
        }

        return $formatted;
    }

    /**
     * Filter fields based on include/exclude lists
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function filterFields(array $data): array
    {
        // Apply whitelist if set
        if (!empty($this->includeFields)) {
            $data = array_intersect_key($data, array_flip($this->includeFields));
        }

        // Apply blacklist
        if (!empty($this->excludeFields)) {
            $data = array_diff_key($data, array_flip($this->excludeFields));
        }

        return $data;
    }

    /**
     * Encode data to JSON
     *
     * @param mixed $data
     * @param bool $ignoreErrors
     * @return string
     */
    protected function toJson($data, bool $ignoreErrors = false): string
    {
        $json = json_encode($data, $this->jsonFlags);

        if ($json === false) {
            if ($ignoreErrors) {
                return '{}';
            }
            // Fallback for encoding errors
            $error = json_last_error_msg();

            return json_encode([
                'json_encode_error' => $error,
                'data_type' => gettype($data),
            ], $this->jsonFlags) ?: '{"json_encode_error":"unknown"}';
        }

        return $json;
    }
}
