<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Formatters;

use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;

/**
 * Line Formatter
 *
 * Formats log records as single-line text entries.
 * Human-readable format for development and simple log files.
 *
 * DEFAULT FORMAT (Enhanced):
 * [2024-01-15 10:30:00.123456] [ERROR] app.security | Database connection failed | host=db.example.com port=5432
 *
 * SIMPLE FORMAT:
 * [2024-01-15 10:30:00] app.ERROR: Database connection failed {"host":"db.example.com"} []
 *
 * CUSTOM FORMAT:
 * %datetime% [%level_name%] %channel%: %message% %context% %extra%
 *
 * AVAILABLE PLACEHOLDERS:
 * - %datetime%    - Formatted timestamp
 * - %channel%     - Logger channel name
 * - %level_name%  - Log level (ERROR, INFO, etc.)
 * - %level%       - Log level lowercase (error, info, etc.)
 * - %message%     - Log message
 * - %context%     - Context as JSON
 * - %context_kv%  - Context as key=value pairs
 * - %extra%       - Extra data as JSON
 * - %extra_kv%    - Extra data as key=value pairs
 * - %pid%         - Process ID
 * - %memory%      - Memory usage
 */
class LineFormatter extends NormalizerFormatter implements FormatterInterface
{
    public const SIMPLE_FORMAT = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";
    public const ENHANCED_FORMAT = "[%datetime%] [%level_name%] %channel% | %message% | %context_kv%\n";
    public const COMPACT_FORMAT = "[%datetime%] %level_name% %message%\n";

    private string $format;
    private bool $allowInlineLineBreaks;
    private bool $ignoreEmptyContextAndExtra;
    private bool $includeStacktraces;

    /** @var int Maximum length of context JSON (0 = unlimited) */
    private int $maxContextLength = 0;

    /** @var bool Include process ID in output */
    private bool $includeProcessId = false;

    /** @var bool Include memory usage in output */
    private bool $includeMemoryUsage = false;

    /**
     * @param string|null $format Log line format (null = default)
     * @param string|null $dateFormat Date format (null = Y-m-d H:i:s)
     * @param bool $allowInlineLineBreaks Allow line breaks in message
     * @param bool $ignoreEmptyContextAndExtra Omit empty context/extra
     * @param bool $includeStacktraces Include stack traces
     */
    public function __construct(
        ?string $format = null,
        ?string $dateFormat = null,
        bool $allowInlineLineBreaks = false,
        bool $ignoreEmptyContextAndExtra = false,
        bool $includeStacktraces = true,
    ) {
        parent::__construct($dateFormat ?? 'Y-m-d H:i:s');

        $this->format = $format ?? self::SIMPLE_FORMAT;
        $this->allowInlineLineBreaks = $allowInlineLineBreaks;
        $this->ignoreEmptyContextAndExtra = $ignoreEmptyContextAndExtra;
        $this->includeStacktraces = $includeStacktraces;
    }

    /**
     * Set maximum context JSON length
     *
     * @param int $length Maximum length (0 = unlimited)
     * @return self
     */
    public function setMaxContextLength(int $length): self
    {
        $this->maxContextLength = max(0, $length);

        return $this;
    }

    /**
     * Enable process ID in output
     *
     * @param bool $include
     * @return self
     */
    public function setIncludeProcessId(bool $include): self
    {
        $this->includeProcessId = $include;

        return $this;
    }

    /**
     * Enable memory usage in output
     *
     * @param bool $include
     * @return self
     */
    public function setIncludeMemoryUsage(bool $include): self
    {
        $this->includeMemoryUsage = $include;

        return $this;
    }

    /**
     * Use enhanced format with better readability
     *
     * @return self
     */
    public function useEnhancedFormat(): self
    {
        $this->format = self::ENHANCED_FORMAT;
        $this->ignoreEmptyContextAndExtra = true;

        return $this;
    }

    /**
     * Format a single log record
     */
    public function format(LogRecord $record): string
    {
        // Sanitize message against log injection
        $message = $this->sanitizeString($record->message);

        $vars = [
            '%datetime%' => $record->datetime->format($this->dateFormat),
            '%channel%' => $this->sanitizeString($record->channel),
            '%level_name%' => str_pad($record->level->name, 9), // Pad for alignment
            '%level%' => strtolower($record->level->name),
            '%message%' => $message,
            '%pid%' => $this->includeProcessId ? '[pid:' . getmypid() . ']' : '',
            '%memory%' => $this->includeMemoryUsage ? '[mem:' . $this->formatBytes(memory_get_usage(true)) . ']' : '',
        ];

        // Context as JSON
        $context = $this->normalize($record->context);
        if ($this->ignoreEmptyContextAndExtra && empty($context)) {
            $vars['%context%'] = '';
            $vars['%context_kv%'] = '';
        } else {
            $contextJson = $this->toJson($context);
            if ($this->maxContextLength > 0 && strlen($contextJson) > $this->maxContextLength) {
                $contextJson = substr($contextJson, 0, $this->maxContextLength) . '...';
            }
            $vars['%context%'] = $contextJson;
            $vars['%context_kv%'] = is_array($context) ? $this->toKeyValue($context) : '';
        }

        // Extra as JSON
        $extra = $this->normalize($record->extra);
        if ($this->ignoreEmptyContextAndExtra && empty($extra)) {
            $vars['%extra%'] = '';
            $vars['%extra_kv%'] = '';
        } else {
            $vars['%extra%'] = $this->toJson($extra);
            $vars['%extra_kv%'] = is_array($extra) ? $this->toKeyValue($extra) : '';
        }

        $output = strtr($this->format, $vars);

        // Clean up empty placeholders and trailing separators
        $result = preg_replace('/\s*\|\s*\n/', "\n", $output);
        if ($result !== null) {
            $output = $result;
        }

        $result = preg_replace('/\s+\n/', "\n", $output);
        if ($result !== null) {
            $output = $result;
        }

        // Handle line breaks in message
        if (!$this->allowInlineLineBreaks) {
            // Preserve final newline
            $hasNewline = str_ends_with($output, "\n");
            $output = str_replace(["\r\n", "\r", "\n"], ' ', $output);
            if ($hasNewline) {
                $output = rtrim($output) . "\n";
            }
        }

        return $output;
    }

    /**
     * Sanitize string to prevent log injection
     *
     * Removes newlines and ANSI escape sequences that could
     * confuse log parsers or hide content in terminals.
     */
    private function sanitizeString(string $value): string
    {
        // Remove ANSI escape sequences
        $value = preg_replace('/\x1b\[[0-9;]*m/', '', $value) ?? $value;

        // Replace newlines with visible marker
        $value = str_replace(["\r\n", "\r", "\n"], ' ⏎ ', $value);

        // Remove other control characters except tab
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? $value;

        return $value;
    }

    /**
     * Format a batch of records
     *
     * @param array<LogRecord> $records
     */
    public function formatBatch(array $records): string
    {
        $output = '';

        foreach ($records as $record) {
            $output .= $this->format($record);
        }

        return $output;
    }

    /**
     * Convert data to JSON string
     */
    protected function toJson($data, bool $ignoreErrors = false): string
    {
        if (empty($data)) {
            return '[]';
        }

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            if ($ignoreErrors) {
                return '[]';
            }

            return parent::toJson($data, true);
        }

        return $json;
    }

    /**
     * Convert data to key=value format
     *
     * @param array<string, mixed> $data
     */
    private function toKeyValue(array $data): string
    {
        if (empty($data)) {
            return '';
        }

        $pairs = [];
        foreach ($data as $key => $value) {
            // Skip exceptions (too verbose for key=value)
            if ($key === 'exception' || $value instanceof \Throwable) {
                continue;
            }

            $pairs[] = $key . '=' . $this->formatScalarValue($value);
        }

        $result = implode(' ', $pairs);

        // Apply max length
        if ($this->maxContextLength > 0 && strlen($result) > $this->maxContextLength) {
            $result = substr($result, 0, $this->maxContextLength) . '...';
        }

        return $result;
    }

    /**
     * Format a value for key=value output
     */
    private function formatScalarValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            // Escape quotes and handle strings with spaces
            if (str_contains($value, ' ') || str_contains($value, '=') || str_contains($value, '"')) {
                $value = str_replace('"', '\\"', $value);

                return '"' . $value . '"';
            }

            return $value;
        }

        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return $json !== false ? $json : '[array]';
        }

        if (is_object($value)) {
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d\TH:i:s');
            }

            return '[' . get_class($value) . ']';
        }

        return '[' . gettype($value) . ']';
    }

    /**
     * Format bytes to human-readable string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $factor < count($units) - 1) {
            $value /= 1024;
            $factor++;
        }

        return round($value, 1) . $units[$factor];
    }
}
