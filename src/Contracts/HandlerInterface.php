<?php

declare(strict_types=1);

namespace Senza1dio\EnterprisePSR3Logger\Contracts;

use Monolog\LogRecord;

/**
 * Handler Interface
 *
 * Defines the contract for log handlers.
 */
interface HandlerInterface
{
    /**
     * Check if the handler handles the given log level
     *
     * @param LogRecord $record
     * @return bool
     */
    public function isHandling(LogRecord $record): bool;

    /**
     * Handle a log record
     *
     * @param LogRecord $record
     * @return bool Whether the record was handled
     */
    public function handle(LogRecord $record): bool;

    /**
     * Handle a batch of log records
     *
     * @param array<LogRecord> $records
     * @return void
     */
    public function handleBatch(array $records): void;

    /**
     * Close the handler and free resources
     *
     * @return void
     */
    public function close(): void;
}
