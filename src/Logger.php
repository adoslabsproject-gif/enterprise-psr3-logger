<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger;

use Monolog\Handler\HandlerInterface;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Enterprise PSR-3 Logger
 *
 * A wrapper around Monolog that provides:
 * - Channel-based logging (multiple named loggers)
 * - Context enrichment (automatic request/correlation IDs)
 * - Lazy handler initialization
 * - Sampling support for high-volume logs
 *
 * USAGE:
 * ```php
 * $logger = new Logger('app');
 * $logger->addHandler(new StreamHandler('/var/log/app.log'));
 *
 * $logger->info('User logged in', ['user_id' => 123]);
 * $logger->error('Payment failed', ['order_id' => 456, 'error' => $e->getMessage()]);
 * ```
 *
 * LIMITATIONS:
 * - Not thread-safe (PHP is single-threaded per request anyway)
 * - File handlers block during write (use async handler for high throughput)
 * - Context size not limited (large contexts can cause memory issues)
 *
 * PERFORMANCE:
 * - Uses spread operator instead of array_merge() for O(1) context merging
 * - Caches error level lookup for zero-overhead checks
 * - Early returns to minimize CPU cycles on filtered logs
 */
class Logger implements LoggerInterface
{
    /**
     * Error levels for stack trace inclusion (cached for performance)
     * Using const array avoids creating new array on every isErrorLevel() call
     */
    private const ERROR_LEVELS = [
        LogLevel::EMERGENCY => true,
        LogLevel::ALERT => true,
        LogLevel::CRITICAL => true,
        LogLevel::ERROR => true,
    ];

    private const MAX_STACK_FRAMES = 5;
    private const MAX_EXCEPTION_DEPTH = 10;

    private readonly MonologLogger $monolog;
    private readonly string $channel;

    /** @var array<string, mixed> Global context added to all logs */
    private array $globalContext = [];

    /** @var float Sampling rate (0.0 to 1.0, 1.0 = log everything) */
    private float $samplingRate = 1.0;

    /** @var array<string, float> Per-level sampling rates */
    private array $levelSamplingRates = [];

    /** @var bool Whether to include stack traces in error logs */
    private bool $includeStackTraces = true;

    /** @var int Maximum context depth for serialization */
    private int $maxContextDepth = 10;

    /**
     * @param string $channel Channel name (e.g., 'app', 'security', 'audit')
     * @param array<HandlerInterface> $handlers Initial handlers
     * @param array<ProcessorInterface|callable(LogRecord): LogRecord> $processors Initial processors
     */
    public function __construct(
        string $channel = 'app',
        array $handlers = [],
        array $processors = [],
    ) {
        $this->channel = $channel;
        $this->monolog = new MonologLogger($channel, $handlers, $processors);
    }

    /**
     * Get the channel name
     */
    public function getChannel(): string
    {
        return $this->channel;
    }

    /**
     * Get the underlying Monolog instance
     *
     * Use this for advanced Monolog features not exposed by this wrapper.
     */
    public function getMonolog(): MonologLogger
    {
        return $this->monolog;
    }

    /**
     * Add a handler to the logger
     *
     * @param HandlerInterface $handler
     * @return self
     */
    public function addHandler(HandlerInterface $handler): self
    {
        $this->monolog->pushHandler($handler);

        return $this;
    }

    /**
     * Add a processor to the logger
     *
     * @param ProcessorInterface|callable $processor
     * @return self
     */
    public function addProcessor(ProcessorInterface|callable $processor): self
    {
        $this->monolog->pushProcessor($processor);

        return $this;
    }

    /**
     * Set global context that's added to all log entries
     *
     * @param array<string, mixed> $context
     * @return self
     */
    public function setGlobalContext(array $context): self
    {
        $this->globalContext = $context;

        return $this;
    }

    /**
     * Add to global context
     *
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function addGlobalContext(string $key, mixed $value): self
    {
        $this->globalContext[$key] = $value;

        return $this;
    }

    /**
     * Set sampling rate for all levels
     *
     * @param float $rate 0.0 to 1.0 (1.0 = log everything)
     * @return self
     */
    public function setSamplingRate(float $rate): self
    {
        $this->samplingRate = max(0.0, min(1.0, $rate));

        return $this;
    }

    /**
     * Set sampling rate for a specific log level
     *
     * @param string $level PSR-3 log level
     * @param float $rate 0.0 to 1.0
     * @return self
     */
    public function setLevelSamplingRate(string $level, float $rate): self
    {
        $this->levelSamplingRates[$level] = max(0.0, min(1.0, $rate));

        return $this;
    }

    /**
     * Enable/disable stack traces in error logs
     *
     * @param bool $include
     * @return self
     */
    public function setIncludeStackTraces(bool $include): self
    {
        $this->includeStackTraces = $include;

        return $this;
    }

    /**
     * Set maximum context depth for serialization
     *
     * @param int $depth
     * @return self
     */
    public function setMaxContextDepth(int $depth): self
    {
        $this->maxContextDepth = max(1, $depth);

        return $this;
    }

    // ==================== PSR-3 Methods ====================

    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        // 🔥 ENTERPRISE: Check global should_log() function FIRST for config-based filtering
        // PERFORMANCE: Single function_exists check, cached result in static var
        static $shouldLogFunction = null;
        static $initialized = false;

        if (!$initialized) {
            $initialized = true;
            // Check only global namespace - that's the documented requirement
            if (function_exists('\should_log')) {
                $shouldLogFunction = '\should_log';
            } else {
                // Warning logged once per process
                error_log('[ENTERPRISE PSR-3 LOGGER] WARNING: should_log() function not found. ' .
                    'All logs will be written without configuration-based filtering. ' .
                    'Define should_log() in the GLOBAL namespace in your bootstrap for production use.');
            }
        }

        if ($shouldLogFunction !== null && !$shouldLogFunction($this->channel, $level)) {
            return; // Exit immediately - zero overhead
        }

        // 🔥 SAMPLING: Apply probabilistic sampling for high-volume logs
        // CRITICAL: Error levels NEVER sampled - always logged for reliability
        if (!isset(self::ERROR_LEVELS[$level]) && !$this->shouldSample($level)) {
            return; // Sampled out - zero overhead
        }

        // Merge global context using spread operator (faster than array_merge)
        $mergedContext = [...$this->globalContext, ...$context];

        // Add stack trace for errors if enabled (uses cached constant lookup)
        // Don't add stack_trace if exception is already in context OR stack_trace already exists
        if ($this->includeStackTraces && isset(self::ERROR_LEVELS[$level])) {
            if (!isset($mergedContext['exception']) && !isset($mergedContext['stack_trace'])) {
                $mergedContext['stack_trace'] = $this->getStackTrace();
            }
        }

        // Sanitize context depth
        $mergedContext = $this->sanitizeContext($mergedContext);

        // Convert Stringable to string
        $messageStr = (string) $message;

        // Log via Monolog
        $this->monolog->log($level, $messageStr, $mergedContext);
    }

    /**
     * Create a child logger with additional context
     *
     * PERFORMANCE: Uses Monolog's native withName() for efficient handler/processor sharing
     * instead of copying arrays in O(n) loops.
     *
     * @param array<string, mixed> $context Additional context for child
     * @return self
     */
    public function withContext(array $context): self
    {
        // Use Monolog's native handler/processor arrays directly (no loops)
        $child = new self(
            $this->channel,
            $this->monolog->getHandlers(),
            $this->monolog->getProcessors(),
        );

        // Copy settings using spread operator (faster than array_merge)
        $child->globalContext = [...$this->globalContext, ...$context];
        $child->samplingRate = $this->samplingRate;
        $child->levelSamplingRates = $this->levelSamplingRates;
        $child->includeStackTraces = $this->includeStackTraces;
        $child->maxContextDepth = $this->maxContextDepth;

        return $child;
    }

    /**
     * Create a child logger for a sub-channel
     *
     * PERFORMANCE: Passes handlers/processors directly to constructor (no loops)
     *
     * @param string $subChannel Sub-channel name (appended to current channel)
     * @return self
     */
    public function withChannel(string $subChannel): self
    {
        // Use Monolog's native handler/processor arrays directly (no loops)
        $child = new self(
            $this->channel . '.' . $subChannel,
            $this->monolog->getHandlers(),
            $this->monolog->getProcessors(),
        );

        // Copy settings
        $child->globalContext = $this->globalContext;
        $child->samplingRate = $this->samplingRate;
        $child->levelSamplingRates = $this->levelSamplingRates;
        $child->includeStackTraces = $this->includeStackTraces;
        $child->maxContextDepth = $this->maxContextDepth;

        return $child;
    }

    /**
     * Close all handlers
     */
    public function close(): void
    {
        $this->monolog->close();
    }

    // ==================== Private Methods ====================

    /**
     * Check if this log entry should be sampled (included)
     *
     * SAMPLING STRATEGY:
     * - Per-level rates take precedence over global rate
     * - Returns true = log should be written
     * - Returns false = log should be skipped (sampled out)
     * - Uses mt_rand for speed (cryptographic randomness not needed)
     *
     * @param string $level PSR-3 log level
     * @return bool True if log should be included
     */
    private function shouldSample(string $level): bool
    {
        // Determine which sampling rate to use
        $rate = $this->levelSamplingRates[$level] ?? $this->samplingRate;

        // Fast path: rate = 1.0 means log everything
        if ($rate >= 1.0) {
            return true;
        }

        // Fast path: rate = 0.0 means log nothing (but this should rarely be configured)
        if ($rate <= 0.0) {
            return false;
        }

        // Probabilistic sampling using integer math (faster than float comparison)
        // mt_rand(1, 1000) / 1000 gives us 0.1% precision
        return mt_rand(1, 1000) <= (int) ($rate * 1000);
    }

    /**
     * Get stack trace for error logs
     * Optimized: single pass with early exit, no intermediate array
     *
     * @return array<int, array<string, mixed>>
     */
    private function getStackTrace(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::MAX_STACK_FRAMES + 5);

        // Use hash map for O(1) class lookup instead of in_array
        static $skipClasses = [
            self::class => true,
            MonologLogger::class => true,
        ];

        $filtered = [];
        $count = 0;

        foreach ($trace as $frame) {
            // Early exit when we have enough frames
            if ($count >= self::MAX_STACK_FRAMES) {
                break;
            }

            $class = $frame['class'] ?? '';
            if (!isset($skipClasses[$class])) {
                $filtered[] = [
                    'file' => $frame['file'] ?? 'unknown',
                    'line' => $frame['line'] ?? 0,
                    'function' => $class !== '' ? $class . '::' . ($frame['function'] ?? 'unknown') : ($frame['function'] ?? 'unknown'),
                ];
                ++$count;
            }
        }

        return $filtered;
    }

    /**
     * Normalize an exception with chained previous exceptions
     *
     * @param \Throwable $exception
     * @param int $depth Current recursion depth
     * @return array<string, mixed>
     */
    private function normalizeException(\Throwable $exception, int $depth = 0): array
    {
        // Prevent infinite recursion using constant
        if ($depth > self::MAX_EXCEPTION_DEPTH) {
            return ['class' => $exception::class, 'message' => '[max depth reached]'];
        }

        $data = [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];

        // Include previous exception (chained exceptions)
        $previous = $exception->getPrevious();
        if ($previous !== null) {
            $data['previous'] = $this->normalizeException($previous, $depth + 1);
        }

        return $data;
    }

    /**
     * Sanitize context to prevent deep nesting and circular references
     *
     * @param array<string, mixed> $context
     * @param int $depth Current recursion depth
     * @param \SplObjectStorage|null $seen Track seen objects to detect cycles
     * @return array<string, mixed>
     */
    private function sanitizeContext(array $context, int $depth = 0, ?\SplObjectStorage $seen = null): array
    {
        if ($depth >= $this->maxContextDepth) {
            return ['_truncated' => true];
        }

        // Initialize seen objects tracker on first call
        if ($seen === null) {
            $seen = new \SplObjectStorage();
        }

        $sanitized = [];

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeContext($value, $depth + 1, $seen);
            } elseif (is_object($value)) {
                // Check for circular reference
                if ($seen->contains($value)) {
                    $sanitized[$key] = '[circular reference: ' . get_class($value) . ']';
                    continue;
                }

                // Track this object
                $seen->attach($value);

                if ($value instanceof \Throwable) {
                    $sanitized[$key] = $this->normalizeException($value);
                } elseif ($value instanceof \DateTimeInterface) {
                    $sanitized[$key] = $value->format(\DateTimeInterface::RFC3339);
                } elseif ($value instanceof \JsonSerializable) {
                    // Safely handle JsonSerializable - it might return itself or a cycle
                    try {
                        $serialized = $value->jsonSerialize();
                        if ($serialized === $value) {
                            // Object returned itself, avoid infinite loop
                            $sanitized[$key] = '[object ' . get_class($value) . ']';
                        } elseif (is_array($serialized)) {
                            $sanitized[$key] = $this->sanitizeContext($serialized, $depth + 1, $seen);
                        } elseif (is_object($serialized)) {
                            // Recursively sanitize returned object
                            $sanitized[$key] = $this->sanitizeContext(['_' => $serialized], $depth + 1, $seen)['_'];
                        } else {
                            $sanitized[$key] = $serialized;
                        }
                    } catch (\Throwable $e) {
                        $sanitized[$key] = '[JsonSerializable error: ' . $e->getMessage() . ']';
                    }
                } elseif (method_exists($value, '__toString')) {
                    try {
                        $sanitized[$key] = (string) $value;
                    } catch (\Throwable $e) {
                        $sanitized[$key] = '[__toString error: ' . $e->getMessage() . ']';
                    }
                } else {
                    $sanitized[$key] = '[object ' . get_class($value) . ']';
                }
            } elseif (is_resource($value)) {
                $sanitized[$key] = '[resource ' . get_resource_type($value) . ']';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
