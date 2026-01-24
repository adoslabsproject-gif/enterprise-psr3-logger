<?php

declare(strict_types=1);

namespace Senza1dio\EnterprisePSR3Logger\Contracts;

use Monolog\LogRecord;

/**
 * Processor Interface
 *
 * Defines the contract for log record processors.
 * Processors add extra data to log records before handling.
 */
interface ProcessorInterface
{
    /**
     * Process a log record
     *
     * @param LogRecord $record
     * @return LogRecord The modified record
     */
    public function __invoke(LogRecord $record): LogRecord;
}
