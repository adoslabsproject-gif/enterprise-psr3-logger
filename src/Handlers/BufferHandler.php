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

    /** @var bool Flag to prevent double close */
    private bool $closed = false;

    /** @var int Count of consecutive flush failures */
    private int $consecutiveFailures = 0;

    /** @var int Max consecutive failures before force-clearing buffer */
    private const MAX_CONSECUTIVE_FAILURES = 3;

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
        bool $flushOnShutdown = true,
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
        if (empty($this->buffer) || $this->closed) {
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
        // Keep a copy in case of failure for potential retry/recovery
        $recordsToFlush = $this->buffer;

        try {
            if (count($recordsToFlush) === 1) {
                $this->handler->handle($recordsToFlush[0]);
            } else {
                $this->handler->handleBatch($recordsToFlush);
            }
            // Only clear on success
            $this->clear();
            $this->consecutiveFailures = 0;
        } catch (\Throwable $e) {
            $this->consecutiveFailures++;

            // Log failure
            error_log("BufferHandler: Flush failed (attempt {$this->consecutiveFailures}) - " . $e->getMessage());

            // If too many consecutive failures, force clear to prevent infinite loop
            if ($this->consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                error_log('BufferHandler: Too many consecutive failures, discarding ' . count($this->buffer) . ' records');
                $this->clear();
                $this->consecutiveFailures = 0;
            } elseif (count($this->buffer) > 10000) {
                // Also clear if buffer is huge to prevent memory exhaustion
                error_log('BufferHandler: Buffer overflow, discarding ' . count($this->buffer) . ' records');
                $this->clear();
            }
        }
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
     *
     * Protected against double-close which can happen when:
     * 1. User calls $handler->close() manually
     * 2. Shutdown handler also calls close()
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->flush();
        $this->handler->close();
    }

    /**
     * Check if handler is closed
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Register shutdown handler
     *
     * Uses WeakReference to avoid preventing garbage collection
     * if the handler is destroyed before shutdown.
     */
    private function registerShutdownHandler(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;

        // Use WeakReference to avoid preventing GC
        $weakSelf = \WeakReference::create($this);

        register_shutdown_function(static function () use ($weakSelf): void {
            $self = $weakSelf->get();
            if ($self !== null && !$self->isClosed()) {
                $self->close();
            }
        });
    }
}
