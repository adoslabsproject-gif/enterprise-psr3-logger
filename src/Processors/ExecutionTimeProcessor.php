<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Processors;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Execution Time Processor
 *
 * Adds execution time information to log records.
 * Tracks time since request start or custom start point.
 *
 * ADDED FIELDS (in extra):
 * - execution_time: Time since start in milliseconds
 * - execution_time_formatted: Human-readable format (e.g., "1.23s" or "45.67ms")
 *
 * USAGE:
 * ```php
 * // Track from request start
 * $logger->addProcessor(new ExecutionTimeProcessor());
 *
 * // Track from custom start point
 * $processor = new ExecutionTimeProcessor();
 * $processor->start();
 * // ... code ...
 * $logger->info('Operation completed'); // Will include time since start()
 * ```
 */
class ExecutionTimeProcessor implements ProcessorInterface
{
    private float $startTime;
    private bool $includeFormatted;

    /**
     * @param float|null $startTime Custom start time (null = REQUEST_TIME_FLOAT or now)
     * @param bool $includeFormatted Include human-readable formatted time
     */
    public function __construct(
        ?float $startTime = null,
        bool $includeFormatted = true,
    ) {
        $this->startTime = $startTime
            ?? $_SERVER['REQUEST_TIME_FLOAT']
            ?? microtime(true);
        $this->includeFormatted = $includeFormatted;
    }

    /**
     * Reset start time to now
     */
    public function start(): self
    {
        $this->startTime = microtime(true);

        return $this;
    }

    /**
     * Get elapsed time in milliseconds
     */
    public function getElapsedMs(): float
    {
        return (microtime(true) - $this->startTime) * 1000;
    }

    /**
     * Process log record
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $elapsedMs = $this->getElapsedMs();

        $extra = $record->extra;
        $extra['execution_time_ms'] = round($elapsedMs, 2);

        if ($this->includeFormatted) {
            $extra['execution_time'] = $this->formatTime($elapsedMs);
        }

        return $record->with(extra: $extra);
    }

    /**
     * Format time in human-readable format
     */
    private function formatTime(float $ms): string
    {
        // Note: Using 'us' instead of the Greek letter mu (μ) for ASCII compatibility
        if ($ms < 1) {
            return round($ms * 1000, 2) . 'us';
        }

        if ($ms < 1000) {
            return round($ms, 2) . 'ms';
        }

        $seconds = $ms / 1000;

        if ($seconds < 60) {
            return round($seconds, 2) . 's';
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = fmod($seconds, 60);

        return $minutes . 'm ' . round($remainingSeconds, 1) . 's';
    }
}
