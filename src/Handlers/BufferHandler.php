<?php

declare(strict_types=1);

namespace Senza1dio\EnterprisePSR3Logger\Handlers;

use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Buffer Handler
 *
 * Buffers log records and flushes them all at once to the wrapped handler.
 * Useful for batch processing, reducing I/O operations, and conditional logging.
 *
 * FLUSH STRATEGIES:
 * - On shutdown (default)
 * - When buffer reaches limit
 * - On error level or above
 * - Manual flush
 *
 * USAGE:
 * ```php
 * // Buffer and flush on shutdown
 * $handler = new BufferHandler(
 *     new StreamHandler('/var/log/app.log'),
 *     bufferLimit: 100
 * );
 *
 * // Flush immediately on errors
 * $handler = new BufferHandler(
 *     new StreamHandler('/var/log/app.log'),
 *     flushOnError: true
 * );
 *
 * // Manual control
 * $handler = new BufferHandler($wrapped, flushOnShutdown: false);
 * // ... log stuff ...
 * $handler->flush();
 * ```
 *
 * CONDITIONAL LOGGING:
 * Set flushOnlyOnError to discard debug/info logs unless an error occurs.
 * Useful for reducing log volume while retaining context on errors.
 *
 * @package Senza1dio\EnterprisePSR3Logger\Handlers
 */
class BufferHandler implements HandlerInterface
{
    private HandlerInterface $handler;
    private int $bufferLimit;
    private bool $flushOnOverflow;
    private bool $flushOnError;
    private bool $flushOnlyOnError;
    private Level $flushLevel;

    /** @var array<LogRecord> */
    private array $buffer = [];

    private bool $initialized = false;

    /**
     * @param HandlerInterface $handler Handler to forward buffered records to
     * @param int $bufferLimit Maximum records to buffer (0 = unlimited)
     * @param bool $flushOnOverflow Flush when buffer is full (true) or drop oldest (false)
     * @param bool $flushOnError Immediately flush when error level reached
     * @param bool $flushOnlyOnError Only flush if buffer contains error+ level
     * @param Level $flushLevel Level that triggers immediate flush (default: Error)
     * @param bool $flushOnShutdown Register shutdown handler to flush
     */
    public function __construct(
        HandlerInterface $handler,
        int $bufferLimit = 0,
        bool $flushOnOverflow = true,
        bool $flushOnError = false,
        bool $flushOnlyOnError = false,
        Level $flushLevel = Level::Error,
        bool $flushOnShutdown = true
    ) {
        $this->handler = $handler;
        $this->bufferLimit = max(0, $bufferLimit);
        $this->flushOnOverflow = $flushOnOverflow;
        $this->flushOnError = $flushOnError;
        $this->flushOnlyOnError = $flushOnlyOnError;
        $this->flushLevel = $flushLevel;

        if ($flushOnShutdown) {
            $this->registerShutdownHandler();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isHandling(LogRecord $record): bool
    {
        return true; // We buffer everything and let wrapped handler decide
    }

    /**
     * {@inheritdoc}
     */
    public function handle(LogRecord $record): bool
    {
        $this->buffer[] = $record;

        // Check buffer limit
        if ($this->bufferLimit > 0 && count($this->buffer) > $this->bufferLimit) {
            if ($this->flushOnOverflow) {
                $this->flush();
            } else {
                // Drop oldest record
                array_shift($this->buffer);
            }
        }

        // Check immediate flush on error
        if ($this->flushOnError && $record->level->value >= $this->flushLevel->value) {
            $this->flush();
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function handleBatch(array $records): void
    {
        foreach ($records as $record) {
            $this->handle($record);
        }
    }

    /**
     * Flush buffered records to the wrapped handler
     */
    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        // Check if we should only flush on error
        if ($this->flushOnlyOnError) {
            $hasError = false;
            foreach ($this->buffer as $record) {
                if ($record->level->value >= $this->flushLevel->value) {
                    $hasError = true;
                    break;
                }
            }

            if (!$hasError) {
                $this->clear();
                return;
            }
        }

        // Flush to wrapped handler
        try {
            if (count($this->buffer) === 1) {
                $this->handler->handle($this->buffer[0]);
            } else {
                $this->handler->handleBatch($this->buffer);
            }
        } catch (\Throwable $e) {
            error_log("BufferHandler: Flush failed - " . $e->getMessage());
        }

        $this->clear();
    }

    /**
     * Clear the buffer without flushing
     */
    public function clear(): void
    {
        $this->buffer = [];
    }

    /**
     * Get current buffer size
     */
    public function getBufferSize(): int
    {
        return count($this->buffer);
    }

    /**
     * Get buffered records (without flushing)
     *
     * @return array<LogRecord>
     */
    public function getBuffer(): array
    {
        return $this->buffer;
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        $this->flush();
        $this->handler->close();
    }

    /**
     * Register shutdown handler
     */
    private function registerShutdownHandler(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;

        register_shutdown_function(function (): void {
            $this->close();
        });
    }
}
