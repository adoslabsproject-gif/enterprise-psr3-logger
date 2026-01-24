<?php

declare(strict_types=1);

namespace Senza1dio\EnterprisePSR3Logger\Contracts;

use Monolog\LogRecord;

/**
 * Formatter Interface
 *
 * Defines the contract for log record formatters.
 */
interface FormatterInterface
{
    /**
     * Format a log record
     *
     * @param LogRecord $record The log record to format
     * @return string The formatted log line
     */
    public function format(LogRecord $record): string;

    /**
     * Format a batch of log records
     *
     * @param array<LogRecord> $records
     * @return string
     */
    public function formatBatch(array $records): string;
}
