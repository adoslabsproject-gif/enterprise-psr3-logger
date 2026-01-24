<?php

declare(strict_types=1);

namespace Senza1dio\EnterprisePSR3Logger\Formatters;

use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;

/**
 * Detailed Line Formatter
 *
 * Single-line format with maximum information density.
 * Designed for log files that need to be human-readable while remaining grep-friendly.
 *
 * OUTPUT FORMAT:
 * ```
 * [2024-01-15 10:30:00.123456] [ERROR] [app.security] [req:abc-123] [pid:12345] [mem:45.2MB]
 *   ▶ Database connection failed after 3 retries
 *   │ host=db.example.com port=5432 database=production attempts=3
 *   │ error="Connection refused" duration_ms=1523
 *   └ PDOException in Connection.php:45 - SQLSTATE[HY000] [2002] Connection refused
 * ```
 *
 * FEATURES:
 * - Microsecond precision timestamps
 * - Request/correlation ID tracking
 * - Process ID for multi-process debugging
 * - Memory usage monitoring
 * - Duration tracking (if provided in context)
 * - Structured context with key=value pairs
 * - Exception summary with file:line
 *
 * @package Senza1dio\EnterprisePSR3Logger\Formatters
 */
class DetailedLineFormatter extends NormalizerFormatter implements FormatterInterface
{
    private const LEVEL_ICONS = [
        'DEBUG' => 'DBG',
        'INFO' => 'INF',
        'NOTICE' => 'NTC',
        'WARNING' => 'WRN',
        'ERROR' => 'ERR',
        'CRITICAL' => 'CRT',
        'ALERT' => 'ALT',
        'EMERGENCY' => 'EMG',
    ];

    private bool $includeProcessId;
    private bool $includeMemoryUsage;
    private bool $includeRequestId;
    private bool $multiLine;
    private string $requestIdKey;
    private int $maxContextLength;
    private int $maxExceptionMessageLength;

    /**
     * @param string|null $dateFormat Date format (null = Y-m-d H:i:s.u)
     * @param bool $includeProcessId Include PHP process ID
     * @param bool $includeMemoryUsage Include current memory usage
     * @param bool $includeRequestId Include request/correlation ID from context
     * @param bool $multiLine Use multi-line format for better readability
     * @param string $requestIdKey Context key for request ID
     */
    public function __construct(
        ?string $dateFormat = null,
        bool $includeProcessId = true,
        bool $includeMemoryUsage = true,
        bool $includeRequestId = true,
        bool $multiLine = true,
        string $requestIdKey = 'request_id'
    ) {
        parent::__construct($dateFormat ?? 'Y-m-d H:i:s.u');

        $this->includeProcessId = $includeProcessId;
        $this->includeMemoryUsage = $includeMemoryUsage;
        $this->includeRequestId = $includeRequestId;
        $this->multiLine = $multiLine;
        $this->requestIdKey = $requestIdKey;
        $this->maxContextLength = 1000;
        $this->maxExceptionMessageLength = 200;
    }

    /**
     * Set maximum context string length
     */
    public function setMaxContextLength(int $length): self
    {
        $this->maxContextLength = max(100, $length);
        return $this;
    }

    /**
     * Format a single log record
     */
    public function format(LogRecord $record): string
    {
        $output = [];

        // Header line: [timestamp] [LEVEL] [channel] [metadata...]
        $header = $this->formatHeader($record);
        $output[] = $header;

        if ($this->multiLine) {
            // Message line (sanitized)
            $output[] = '  ▶ ' . $this->sanitizeString($record->message);

            // Context line (if not empty)
            $context = $this->normalize($record->context);
            $contextStr = $this->formatContextKeyValue($context);
            if ($contextStr !== '') {
                $output[] = '  │ ' . $contextStr;
            }

            // Extra line (if not empty)
            $extra = $this->normalize($record->extra);
            $extraStr = $this->formatContextKeyValue($extra);
            if ($extraStr !== '') {
                $output[] = '  │ ' . $extraStr;
            }

            // Exception line (if present)
            if (isset($record->context['exception']) && $record->context['exception'] instanceof \Throwable) {
                $output[] = '  └ ' . $this->formatExceptionSummary($record->context['exception']);
            } else {
                // Replace last │ with └ if it exists
                $lastIndex = count($output) - 1;
                if ($lastIndex > 0 && str_starts_with($output[$lastIndex], '  │')) {
                    $output[$lastIndex] = '  └' . substr($output[$lastIndex], 3);
                }
            }
        } else {
            // Single line format
            $parts = [$record->message];

            $context = $this->normalize($record->context);
            $contextStr = $this->formatContextKeyValue($context);
            if ($contextStr !== '') {
                $parts[] = $contextStr;
            }

            if (isset($record->context['exception']) && $record->context['exception'] instanceof \Throwable) {
                $parts[] = $this->formatExceptionSummary($record->context['exception']);
            }

            $output[] = '  ' . implode(' | ', $parts);
        }

        return implode("\n", $output) . "\n";
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
     * Format the header line with metadata
     */
    private function formatHeader(LogRecord $record): string
    {
        $parts = [];

        // Timestamp
        $parts[] = '[' . $record->datetime->format($this->dateFormat) . ']';

        // Level
        $levelIcon = self::LEVEL_ICONS[$record->level->name] ?? $record->level->name;
        $parts[] = '[' . $levelIcon . ']';

        // Channel
        $parts[] = '[' . $record->channel . ']';

        // Request ID (from context or extra)
        if ($this->includeRequestId) {
            $requestId = $record->context[$this->requestIdKey]
                ?? $record->extra[$this->requestIdKey]
                ?? null;

            if ($requestId !== null) {
                // Shorten UUID if too long
                $requestIdStr = (string) $requestId;
                if (strlen($requestIdStr) > 12) {
                    $requestIdStr = substr($requestIdStr, 0, 8) . '..';
                }
                $parts[] = '[req:' . $requestIdStr . ']';
            }
        }

        // Process ID
        if ($this->includeProcessId) {
            $parts[] = '[pid:' . getmypid() . ']';
        }

        // Memory usage
        if ($this->includeMemoryUsage) {
            $memory = memory_get_usage(true);
            $parts[] = '[mem:' . $this->formatBytes($memory) . ']';
        }

        // Duration (if in context)
        if (isset($record->context['duration_ms'])) {
            $parts[] = '[' . round((float) $record->context['duration_ms'], 2) . 'ms]';
        } elseif (isset($record->context['duration'])) {
            $parts[] = '[' . round((float) $record->context['duration'] * 1000, 2) . 'ms]';
        }

        return implode(' ', $parts);
    }

    /**
     * Format context/extra as key=value pairs
     *
     * @param array<string, mixed> $data
     */
    private function formatContextKeyValue(array $data): string
    {
        $pairs = [];

        // Keys to skip (handled elsewhere)
        $skipKeys = ['exception', $this->requestIdKey, 'duration_ms', 'duration', 'stack_trace'];

        foreach ($data as $key => $value) {
            if (in_array($key, $skipKeys, true)) {
                continue;
            }

            $formattedValue = $this->formatScalarValue($value);
            $pairs[] = $key . '=' . $formattedValue;
        }

        if (empty($pairs)) {
            return '';
        }

        $result = implode(' ', $pairs);

        // Truncate if too long
        if (strlen($result) > $this->maxContextLength) {
            $result = substr($result, 0, $this->maxContextLength) . '...';
        }

        return $result;
    }

    /**
     * Format a scalar value for key=value output
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
            // Quote strings with spaces
            if (str_contains($value, ' ') || str_contains($value, '=')) {
                // Escape quotes and truncate
                $value = str_replace('"', '\\"', $value);
                if (strlen($value) > 50) {
                    $value = substr($value, 0, 50) . '...';
                }
                return '"' . $value . '"';
            }

            if (strlen($value) > 50) {
                return substr($value, 0, 50) . '...';
            }

            return $value;
        }

        if (is_array($value)) {
            if (empty($value)) {
                return '[]';
            }

            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json !== false) {
                if (strlen($json) > 100) {
                    return '[' . count($value) . ' items]';
                }
                return $json;
            }

            return '[array]';
        }

        if (is_object($value)) {
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }

            return '[' . get_class($value) . ']';
        }

        return '[' . gettype($value) . ']';
    }

    /**
     * Format exception as a summary line
     */
    private function formatExceptionSummary(\Throwable $exception): string
    {
        $class = $this->getShortClassName($exception);
        $file = basename($exception->getFile());
        $line = $exception->getLine();
        $message = $exception->getMessage();

        // Truncate message if too long
        if (strlen($message) > $this->maxExceptionMessageLength) {
            $message = substr($message, 0, $this->maxExceptionMessageLength) . '...';
        }

        // Remove newlines from message
        $message = str_replace(["\r\n", "\r", "\n"], ' ', $message);

        return "{$class} in {$file}:{$line} - {$message}";
    }

    /**
     * Get short class name without namespace
     */
    private function getShortClassName(object $object): string
    {
        $class = get_class($object);
        $pos = strrpos($class, '\\');

        return $pos !== false ? substr($class, $pos + 1) : $class;
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

    /**
     * Sanitize string to prevent log injection
     */
    private function sanitizeString(string $value): string
    {
        // Remove ANSI escape sequences
        $value = preg_replace('/\x1b\[[0-9;]*m/', '', $value) ?? $value;

        // Remove other control characters except tab and newline
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? $value;

        return $value;
    }
}
