<?php

declare(strict_types=1);

namespace Senza1dio\EnterprisePSR3Logger;

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
 * @package Senza1dio\EnterprisePSR3Logger
 */
class Logger implements LoggerInterface
{
    private MonologLogger $monolog;
    private string $channel;

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
     * @param array<ProcessorInterface> $processors Initial processors
     */
    public function __construct(
        string $channel = 'app',
        array $handlers = [],
        array $processors = []
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
        // Check sampling
        if (!$this->shouldLog($level)) {
            return;
        }

        // Merge global context
        $mergedContext = array_merge($this->globalContext, $context);

        // Add stack trace for errors if enabled
        if ($this->includeStackTraces && $this->isErrorLevel($level)) {
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
     * @param array<string, mixed> $context Additional context for child
     * @return self
     */
    public function withContext(array $context): self
    {
        $child = clone $this;
        $child->globalContext = array_merge($this->globalContext, $context);
        return $child;
    }

    /**
     * Create a child logger for a sub-channel
     *
     * @param string $subChannel Sub-channel name (appended to current channel)
     * @return self
     */
    public function withChannel(string $subChannel): self
    {
        $newChannel = $this->channel . '.' . $subChannel;
        $child = new self($newChannel);
        $child->globalContext = $this->globalContext;
        $child->samplingRate = $this->samplingRate;
        $child->levelSamplingRates = $this->levelSamplingRates;
        $child->includeStackTraces = $this->includeStackTraces;
        $child->maxContextDepth = $this->maxContextDepth;

        // Copy handlers and processors
        foreach ($this->monolog->getHandlers() as $handler) {
            $child->monolog->pushHandler($handler);
        }
        foreach ($this->monolog->getProcessors() as $processor) {
            $child->monolog->pushProcessor($processor);
        }

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
     * Check if we should log based on sampling
     */
    private function shouldLog(string $level): bool
    {
        // Check level-specific sampling first
        $rate = $this->levelSamplingRates[$level] ?? $this->samplingRate;

        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        // Random sampling
        return (mt_rand() / mt_getrandmax()) < $rate;
    }

    /**
     * Check if level is error or above
     */
    private function isErrorLevel(string $level): bool
    {
        return in_array($level, [
            LogLevel::EMERGENCY,
            LogLevel::ALERT,
            LogLevel::CRITICAL,
            LogLevel::ERROR,
        ], true);
    }

    /**
     * Get stack trace for error logs
     *
     * @return array<int, array<string, mixed>>
     */
    private function getStackTrace(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);

        // Remove internal frames
        $filtered = [];
        $skipClasses = [self::class, MonologLogger::class];

        foreach ($trace as $frame) {
            $class = $frame['class'] ?? '';
            if (!in_array($class, $skipClasses, true)) {
                $filtered[] = [
                    'file' => $frame['file'] ?? 'unknown',
                    'line' => $frame['line'] ?? 0,
                    'function' => ($class ? $class . '::' : '') . ($frame['function'] ?? 'unknown'),
                ];
            }
        }

        return array_slice($filtered, 0, 5); // Limit to 5 frames
    }

    /**
     * Sanitize context to prevent deep nesting and circular references
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitizeContext(array $context, int $depth = 0): array
    {
        if ($depth >= $this->maxContextDepth) {
            return ['_truncated' => true];
        }

        $sanitized = [];

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeContext($value, $depth + 1);
            } elseif (is_object($value)) {
                if ($value instanceof \Throwable) {
                    $sanitized[$key] = [
                        'class' => get_class($value),
                        'message' => $value->getMessage(),
                        'code' => $value->getCode(),
                        'file' => $value->getFile(),
                        'line' => $value->getLine(),
                    ];
                } elseif ($value instanceof \DateTimeInterface) {
                    $sanitized[$key] = $value->format(\DateTimeInterface::RFC3339);
                } elseif ($value instanceof \JsonSerializable) {
                    $sanitized[$key] = $value->jsonSerialize();
                } elseif (method_exists($value, '__toString')) {
                    $sanitized[$key] = (string) $value;
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
