<?php

declare(strict_types=1);

namespace Senza1dio\EnterprisePSR3Logger\Handlers;

use Monolog\Handler\HandlerInterface;
use Monolog\LogRecord;

/**
 * Group Handler
 *
 * Forwards log records to multiple handlers.
 * Useful for writing to multiple destinations simultaneously.
 *
 * USAGE:
 * ```php
 * // Write to both file and stdout
 * $handler = new GroupHandler([
 *     new StreamHandler('/var/log/app.log'),
 *     new StreamHandler('php://stdout'),
 * ]);
 *
 * // With bubble control
 * $handler = new GroupHandler(
 *     handlers: [...],
 *     bubble: false  // Stop processing after this handler
 * );
 * ```
 *
 * BEHAVIOR:
 * - All handlers receive the record
 * - Errors in one handler don't affect others
 * - isHandling() returns true if ANY handler handles the level
 *
 * @package Senza1dio\EnterprisePSR3Logger\Handlers
 */
class GroupHandler implements HandlerInterface
{
    /** @var array<HandlerInterface> */
    private array $handlers;

    private bool $bubble;

    /**
     * @param array<HandlerInterface> $handlers Handlers to forward to
     * @param bool $bubble Whether to bubble to next handler
     */
    public function __construct(
        array $handlers,
        bool $bubble = true
    ) {
        $this->handlers = $handlers;
        $this->bubble = $bubble;
    }

    /**
     * Add a handler to the group
     */
    public function addHandler(HandlerInterface $handler): self
    {
        $this->handlers[] = $handler;
        return $this;
    }

    /**
     * Get all handlers
     *
     * @return array<HandlerInterface>
     */
    public function getHandlers(): array
    {
        return $this->handlers;
    }

    /**
     * {@inheritdoc}
     */
    public function isHandling(LogRecord $record): bool
    {
        foreach ($this->handlers as $handler) {
            if ($handler->isHandling($record)) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function handle(LogRecord $record): bool
    {
        $handled = false;

        foreach ($this->handlers as $handler) {
            if ($handler->isHandling($record)) {
                try {
                    $handler->handle($record);
                    $handled = true;
                } catch (\Throwable $e) {
                    // Log error but continue to other handlers
                    error_log("GroupHandler: Handler failed - " . $e->getMessage());
                }
            }
        }

        return $handled === false || $this->bubble;
    }

    /**
     * {@inheritdoc}
     */
    public function handleBatch(array $records): void
    {
        foreach ($this->handlers as $handler) {
            try {
                $handler->handleBatch($records);
            } catch (\Throwable $e) {
                error_log("GroupHandler: Handler batch failed - " . $e->getMessage());
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        foreach ($this->handlers as $handler) {
            try {
                $handler->close();
            } catch (\Throwable $e) {
                error_log("GroupHandler: Handler close failed - " . $e->getMessage());
            }
        }
    }
}
