<?php

declare(strict_types=1);

namespace Senza1dio\EnterprisePSR3Logger\Handlers;

use Monolog\Handler\HandlerInterface;
use Monolog\LogRecord;

/**
 * Async Handler
 *
 * Writes logs asynchronously using register_shutdown_function
 * or pcntl_fork (if available).
 *
 * This handler buffers all logs during the request and writes them
 * after the response is sent, reducing request latency.
 *
 * STRATEGIES:
 * - shutdown: Write logs in shutdown function (after response sent)
 * - fork: Fork process for writing (requires pcntl extension)
 * - fastcgi: Use fastcgi_finish_request() then write
 *
 * USAGE:
 * ```php
 * $innerHandler = new DatabaseHandler($pdo);
 * $asyncHandler = new AsyncHandler($innerHandler);
 * $logger->addHandler($asyncHandler);
 * ```
 *
 * LIMITATIONS:
 * - Logs may be lost if PHP crashes before shutdown
 * - fork strategy requires pcntl extension
 * - shutdown strategy still blocks during writing (but after response)
 */
class AsyncHandler implements HandlerInterface
{
    public const STRATEGY_SHUTDOWN = 'shutdown';
    public const STRATEGY_FORK = 'fork';
    public const STRATEGY_FASTCGI = 'fastcgi';

    private HandlerInterface $handler;
    private string $strategy;

    /** @var array<LogRecord> */
    private array $buffer = [];

    private bool $registered = false;

    /**
     * @param HandlerInterface $handler Handler to wrap
     * @param string $strategy Async strategy (shutdown, fork, fastcgi)
     */
    public function __construct(
        HandlerInterface $handler,
        string $strategy = self::STRATEGY_SHUTDOWN,
    ) {
        $this->handler = $handler;
        $this->strategy = $this->validateStrategy($strategy);
    }

    /**
     * Validate and select best available strategy
     */
    private function validateStrategy(string $strategy): string
    {
        // Check if requested strategy is available
        switch ($strategy) {
            case self::STRATEGY_FORK:
                if (!function_exists('pcntl_fork')) {
                    error_log('AsyncHandler: pcntl_fork not available, falling back to shutdown');

                    return self::STRATEGY_SHUTDOWN;
                }

                return self::STRATEGY_FORK;

            case self::STRATEGY_FASTCGI:
                if (!function_exists('fastcgi_finish_request')) {
                    error_log('AsyncHandler: fastcgi_finish_request not available, falling back to shutdown');

                    return self::STRATEGY_SHUTDOWN;
                }

                return self::STRATEGY_FASTCGI;

            case self::STRATEGY_SHUTDOWN:
            default:
                return self::STRATEGY_SHUTDOWN;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isHandling(LogRecord $record): bool
    {
        return $this->handler->isHandling($record);
    }

    /**
     * {@inheritdoc}
     */
    public function handle(LogRecord $record): bool
    {
        if (!$this->isHandling($record)) {
            return true;
        }

        $this->buffer[] = $record;
        $this->registerFlush();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function handleBatch(array $records): void
    {
        foreach ($records as $record) {
            if ($this->isHandling($record)) {
                $this->buffer[] = $record;
            }
        }

        $this->registerFlush();
    }

    /**
     * Register the flush callback based on strategy
     *
     * Uses WeakReference to avoid preventing garbage collection
     * if the handler is destroyed before shutdown.
     */
    private function registerFlush(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        // Use WeakReference to allow GC if handler is destroyed before shutdown
        $weakSelf = \WeakReference::create($this);

        switch ($this->strategy) {
            case self::STRATEGY_FASTCGI:
                register_shutdown_function(static function () use ($weakSelf): void {
                    $self = $weakSelf->get();
                    if ($self === null) {
                        return;
                    }
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    $self->flush();
                });
                break;

            case self::STRATEGY_FORK:
                register_shutdown_function(static function () use ($weakSelf): void {
                    $self = $weakSelf->get();
                    if ($self === null) {
                        return;
                    }
                    $self->forkAndFlush();
                });
                break;

            case self::STRATEGY_SHUTDOWN:
            default:
                register_shutdown_function(static function () use ($weakSelf): void {
                    $self = $weakSelf->get();
                    if ($self === null) {
                        return;
                    }
                    $self->flush();
                });
                break;
        }
    }

    /**
     * Fork a child process to write logs
     *
     * WARNING: The child process inherits all resources from the parent.
     * Database connections, file handles, and socket connections will be
     * duplicated. The child uses posix_kill(getmypid(), SIGKILL) to avoid
     * triggering destructors that might corrupt shared resources.
     *
     * @internal Used by shutdown handler
     */
    public function forkAndFlush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            // Fork failed, write synchronously
            error_log('AsyncHandler: Fork failed, writing synchronously');
            $this->flush();
        } elseif ($pid === 0) {
            // Child process - write logs and terminate immediately
            //
            // CRITICAL: We must not call exit() as it triggers destructors
            // which would close resources shared with the parent process
            // (DB connections, file handles, sockets, etc.)
            //
            // Instead, we use SIGKILL to terminate immediately without cleanup.
            // This is safe because:
            // 1. The child only writes to the log handler
            // 2. All other resources belong to the parent
            // 3. The OS will clean up the child's memory
            try {
                $this->flush();
            } catch (\Throwable $e) {
                error_log('AsyncHandler: Child flush failed - ' . $e->getMessage());
            }

            // Terminate without running destructors
            // We MUST use _exit() style termination to avoid corrupting parent resources
            if (function_exists('posix_kill')) {
                $sigkill = defined('SIGKILL') ? SIGKILL : 9;
                $pid = getmypid();
                // This should kill us immediately - no code after this runs
                if ($pid !== false) {
                    posix_kill($pid, $sigkill);
                }
                // If we're still here, SIGKILL failed (shouldn't happen)
                // Use _exit via FFI if available, otherwise we have no safe option
            }

            // DANGER: If we reach here, we have no safe way to exit without destructors
            // Log a warning and use exit() as last resort - may corrupt parent resources
            error_log('AsyncHandler: WARNING - posix_kill unavailable, using unsafe exit()');
            exit(0);
        }
        // Parent process - continue (child handles writing)
    }

    /**
     * Flush buffered records to wrapped handler
     */
    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        try {
            if (count($this->buffer) === 1) {
                $this->handler->handle($this->buffer[0]);
            } else {
                $this->handler->handleBatch($this->buffer);
            }
        } catch (\Throwable $e) {
            error_log('AsyncHandler: Flush failed - ' . $e->getMessage());
        }

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
     * {@inheritdoc}
     */
    public function close(): void
    {
        $this->flush();
        $this->handler->close();
    }
}
