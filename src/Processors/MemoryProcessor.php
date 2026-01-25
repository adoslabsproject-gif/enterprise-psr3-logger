<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Processors;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Memory Processor
 *
 * Adds memory usage information to log records.
 * Useful for tracking memory consumption and detecting memory leaks.
 *
 * ADDED FIELDS (in extra):
 * - memory_usage: Current memory usage (formatted or bytes)
 * - memory_peak: Peak memory usage (formatted or bytes)
 * - memory_limit: PHP memory limit (formatted)
 * - memory_percent: Percentage of limit used
 *
 * USAGE:
 * ```php
 * $logger->addProcessor(new MemoryProcessor());
 * // or with raw bytes:
 * $logger->addProcessor(new MemoryProcessor(formatBytes: false));
 * ```
 */
class MemoryProcessor implements ProcessorInterface
{
    private bool $realUsage;
    private bool $formatBytes;
    private bool $includePeak;
    private bool $includeLimit;
    private bool $includePercent;

    /**
     * @param bool $realUsage Use real_usage flag (includes allocated but unused memory)
     * @param bool $formatBytes Format bytes as human-readable (e.g., "45.2 MB")
     * @param bool $includePeak Include peak memory usage
     * @param bool $includeLimit Include memory limit
     * @param bool $includePercent Include percentage of limit used
     */
    public function __construct(
        bool $realUsage = true,
        bool $formatBytes = true,
        bool $includePeak = true,
        bool $includeLimit = false,
        bool $includePercent = false,
    ) {
        $this->realUsage = $realUsage;
        $this->formatBytes = $formatBytes;
        $this->includePeak = $includePeak;
        $this->includeLimit = $includeLimit;
        $this->includePercent = $includePercent;
    }

    /**
     * Process log record
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        $currentUsage = memory_get_usage($this->realUsage);
        $extra['memory_usage'] = $this->formatBytes
            ? $this->formatBytesValue($currentUsage)
            : $currentUsage;

        if ($this->includePeak) {
            $peakUsage = memory_get_peak_usage($this->realUsage);
            $extra['memory_peak'] = $this->formatBytes
                ? $this->formatBytesValue($peakUsage)
                : $peakUsage;
        }

        if ($this->includeLimit || $this->includePercent) {
            $limit = $this->getMemoryLimit();

            if ($this->includeLimit) {
                $extra['memory_limit'] = $this->formatBytes
                    ? $this->formatBytesValue($limit)
                    : $limit;
            }

            if ($this->includePercent && $limit > 0) {
                $extra['memory_percent'] = round(($currentUsage / $limit) * 100, 1);
            }
        }

        return $record->with(extra: $extra);
    }

    /**
     * Get PHP memory limit in bytes
     */
    private function getMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');

        if ($limit === '-1') {
            return PHP_INT_MAX; // Unlimited
        }

        return $this->parseBytes($limit);
    }

    /**
     * Parse PHP memory string to bytes
     *
     * Uses float to avoid integer overflow on 32-bit systems.
     */
    private function parseBytes(string $value): int
    {
        $value = trim($value);
        $last = strtolower(substr($value, -1));
        $numValue = (float) $value;

        switch ($last) {
            case 'g':
                $numValue *= 1024.0 * 1024.0 * 1024.0;
                break;
            case 'm':
                $numValue *= 1024.0 * 1024.0;
                break;
            case 'k':
                $numValue *= 1024.0;
                break;
        }

        // Return as int, capped at PHP_INT_MAX
        if ($numValue > PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        return (int) $numValue;
    }

    /**
     * Format bytes to human-readable string
     */
    private function formatBytesValue(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = 0;

        while ($bytes >= 1024 && $factor < count($units) - 1) {
            $bytes /= 1024;
            $factor++;
        }

        return round($bytes, 2) . ' ' . $units[$factor];
    }
}
