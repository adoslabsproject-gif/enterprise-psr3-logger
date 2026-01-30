<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Handlers;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Redis Buffer Handler - Async Logging via Redis Queue
 *
 * Pushes log entries to a Redis list for async processing by a worker.
 * The worker (separate Docker container) reads from Redis and writes to files/DB.
 *
 * PERFORMANCE: ~0.1ms per log entry (Redis LPUSH)
 *
 * ARCHITECTURE:
 * ```
 * PHP Request → LPUSH to Redis → Worker Container → File/DB
 *     │                              │
 *     └─ ~0.1ms                      └─ Background (no request impact)
 * ```
 *
 * FEATURES:
 * - Non-blocking for PHP requests
 * - Automatic batching (worker processes in batches)
 * - Persistent queue (survives restarts)
 * - Graceful degradation (falls back to file if Redis unavailable)
 * - Backpressure handling with configurable thresholds
 * - Monitoring statistics for observability
 *
 * USAGE:
 * ```php
 * $redis = new \Redis();
 * $redis->connect('redis', 6379);
 * $handler = new RedisBufferHandler($redis, 'myapp');
 * $logger->addHandler($handler);
 * ```
 *
 * @version 2.0.0
 */
class RedisBufferHandler extends AbstractProcessingHandler
{
    /**
     * Redis queue key prefix
     */
    private const QUEUE_KEY_PREFIX = 'eap:logs:';

    /**
     * Default maximum queue size before applying backpressure
     */
    private const DEFAULT_MAX_QUEUE_SIZE = 100000;

    /**
     * Trim check interval (every N writes)
     */
    private const TRIM_CHECK_INTERVAL = 50;

    private \Redis $redis;
    private string $queueKey;
    private string $appName;
    private bool $redisAvailable = true;

    /** @var int Maximum queue size */
    private int $maxQueueSize = self::DEFAULT_MAX_QUEUE_SIZE;

    /** @var string|null Fallback file path */
    private ?string $fallbackPath = null;

    /** @var bool Include extra metadata (pid, memory, request_id) */
    private bool $includeMetadata = true;

    /** @var int Write counter for trim check */
    private int $writeCounter = 0;

    /** @var int Last known queue size (cached) */
    private int $cachedQueueSize = 0;

    /** @var int Last queue size check timestamp */
    private int $lastSizeCheck = 0;

    /** @var int Total messages pushed to Redis */
    private int $messagesPushed = 0;

    /** @var int Total messages written to fallback */
    private int $messagesFallback = 0;

    /** @var int Total messages dropped due to backpressure */
    private int $messagesDropped = 0;

    /** @var int Redis connection failures */
    private int $connectionFailures = 0;

    /** @var int Last failure timestamp for retry logic */
    private int $lastFailureTime = 0;

    /** @var int Retry interval in seconds */
    private int $retryInterval = 5;

    /** @var bool Backpressure warning logged */
    private bool $backpressureWarningLogged = false;

    /**
     * @param \Redis $redis Redis instance (must be connected)
     * @param string $appName Application name (used in queue key)
     * @param Level $level Minimum log level to handle
     * @param bool $bubble Whether to bubble to next handler
     */
    public function __construct(
        \Redis $redis,
        string $appName = 'app',
        Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);

        $this->redis = $redis;
        $this->appName = $appName;
        $this->queueKey = self::QUEUE_KEY_PREFIX . $appName;
    }

    /**
     * Set maximum queue size (backpressure threshold)
     */
    public function setMaxQueueSize(int $size): self
    {
        $this->maxQueueSize = max(1000, $size);

        return $this;
    }

    /**
     * Set fallback file path for when Redis is unavailable
     */
    public function setFallbackPath(string $path): self
    {
        $this->fallbackPath = $path;

        return $this;
    }

    /**
     * Enable/disable extra metadata
     */
    public function setIncludeMetadata(bool $include): self
    {
        $this->includeMetadata = $include;

        return $this;
    }

    /**
     * Set retry interval for Redis reconnection
     */
    public function setRetryInterval(int $seconds): self
    {
        $this->retryInterval = max(1, $seconds);

        return $this;
    }

    /**
     * Get the Redis queue key (for worker configuration)
     */
    public function getQueueKey(): string
    {
        return $this->queueKey;
    }

    /**
     * Get handler statistics for monitoring
     *
     * @return array{
     *     messages_pushed: int,
     *     messages_fallback: int,
     *     messages_dropped: int,
     *     connection_failures: int,
     *     redis_available: bool,
     *     queue_size: int,
     *     queue_key: string,
     *     backpressure_active: bool
     * }
     */
    public function getStats(): array
    {
        return [
            'messages_pushed' => $this->messagesPushed,
            'messages_fallback' => $this->messagesFallback,
            'messages_dropped' => $this->messagesDropped,
            'connection_failures' => $this->connectionFailures,
            'redis_available' => $this->redisAvailable,
            'queue_size' => $this->getQueueLength(),
            'queue_key' => $this->queueKey,
            'backpressure_active' => $this->isBackpressureActive(),
        ];
    }

    /**
     * Check if handler is healthy
     */
    public function isHealthy(): bool
    {
        return $this->redisAvailable && !$this->isBackpressureActive();
    }

    /**
     * Check if backpressure is active (queue near full)
     */
    public function isBackpressureActive(): bool
    {
        $size = $this->getCachedQueueSize();

        return $size >= $this->maxQueueSize;
    }

    /**
     * Write a log record
     */
    protected function write(LogRecord $record): void
    {
        $entry = $this->formatEntry($record);
        $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return; // JSON encoding failed, skip
        }

        // Check if we should retry Redis after failure
        if (!$this->redisAvailable) {
            $now = time();
            if (($now - $this->lastFailureTime) >= $this->retryInterval) {
                $this->retryRedis();
            }
        }

        if ($this->redisAvailable) {
            // Check backpressure before pushing
            if ($this->shouldApplyBackpressure()) {
                $this->messagesDropped++;
                $this->logBackpressureWarning();

                return;
            }

            try {
                $this->pushToRedis($json);
                $this->messagesPushed++;

                return;
            } catch (\Throwable $e) {
                $this->handleRedisFailure($e);
            }
        }

        // Fallback to file
        $this->writeToFallback($json);
        $this->messagesFallback++;
    }

    /**
     * Format log record as array for JSON encoding
     */
    private function formatEntry(LogRecord $record): array
    {
        $entry = [
            'timestamp' => $record->datetime->format('Y-m-d\TH:i:s.uP'),
            'channel' => $record->channel,
            'level' => $record->level->name,
            'level_value' => $record->level->value,
            'message' => $record->message,
            'context' => $record->context,
            'extra' => $record->extra,
        ];

        if ($this->includeMetadata) {
            $entry['_meta'] = [
                'pid' => getmypid(),
                'memory' => memory_get_usage(true),
                'app' => $this->appName,
            ];

            // Include request ID if available in context
            if (isset($record->context['request_id'])) {
                $entry['_meta']['request_id'] = $record->context['request_id'];
            }
        }

        return $entry;
    }

    /**
     * Push entry to Redis queue with atomic trimming
     */
    private function pushToRedis(string $json): void
    {
        // LPUSH for FIFO processing (worker uses RPOP)
        $this->redis->lPush($this->queueKey, $json);
        $this->writeCounter++;

        // Periodic trim check (deterministic, not random)
        if ($this->writeCounter >= self::TRIM_CHECK_INTERVAL) {
            $this->writeCounter = 0;
            $this->trimQueueIfNeeded();
        }
    }

    /**
     * Trim queue if exceeds maximum size (with atomic check)
     */
    private function trimQueueIfNeeded(): void
    {
        try {
            $sizeResult = $this->redis->lLen($this->queueKey);
            $size = is_int($sizeResult) ? $sizeResult : 0;
            $this->cachedQueueSize = $size;
            $this->lastSizeCheck = time();

            // Trim with margin to avoid constant trimming
            $trimThreshold = (int) ($this->maxQueueSize * 1.1);
            if ($size > $trimThreshold) {
                // Keep only maxQueueSize entries (FIFO: keep oldest, trim newest)
                $this->redis->lTrim($this->queueKey, -$this->maxQueueSize, -1);

                error_log(sprintf(
                    '[%s] RedisBufferHandler: Queue trimmed from %d to %d entries',
                    date('Y-m-d H:i:s'),
                    $size,
                    $this->maxQueueSize,
                ));
            }
        } catch (\Throwable $e) {
            // Non-critical, continue - but log for debugging
            error_log('RedisBufferHandler: Queue trim failed - ' . $e->getMessage());
        }
    }

    /**
     * Check if backpressure should be applied
     */
    private function shouldApplyBackpressure(): bool
    {
        $size = $this->getCachedQueueSize();

        return $size >= $this->maxQueueSize;
    }

    /**
     * Get cached queue size (refresh every 5 seconds max)
     */
    private function getCachedQueueSize(): int
    {
        $now = time();

        // Refresh cache every 5 seconds
        if (($now - $this->lastSizeCheck) >= 5) {
            try {
                $this->cachedQueueSize = (int) $this->redis->lLen($this->queueKey);
                $this->lastSizeCheck = $now;
            } catch (\Throwable $e) {
                // Use cached value on failure
            }
        }

        return $this->cachedQueueSize;
    }

    /**
     * Log backpressure warning (once per session)
     */
    private function logBackpressureWarning(): void
    {
        if ($this->backpressureWarningLogged) {
            return;
        }

        $this->backpressureWarningLogged = true;

        error_log(sprintf(
            '[%s] RedisBufferHandler: Backpressure active - queue size %d exceeds max %d. Messages being dropped.',
            date('Y-m-d H:i:s'),
            $this->cachedQueueSize,
            $this->maxQueueSize,
        ));
    }

    /**
     * Handle Redis connection failure
     */
    private function handleRedisFailure(\Throwable $e): void
    {
        $this->redisAvailable = false;
        $this->connectionFailures++;
        $this->lastFailureTime = time();

        error_log(sprintf(
            '[%s] RedisBufferHandler: Redis connection failed (failure #%d): %s',
            date('Y-m-d H:i:s'),
            $this->connectionFailures,
            $e->getMessage(),
        ));
    }

    /**
     * Write to fallback file
     */
    private function writeToFallback(string $json): void
    {
        if ($this->fallbackPath === null) {
            return;
        }

        $date = date('Y-m-d');
        $filePath = $this->fallbackPath . "/buffer-fallback-{$date}.log";

        @file_put_contents($filePath, $json . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Get queue length (for monitoring)
     */
    public function getQueueLength(): int
    {
        if (!$this->redisAvailable) {
            return -1;
        }

        try {
            return (int) $this->redis->lLen($this->queueKey);
        } catch (\Throwable $e) {
            return -1;
        }
    }

    /**
     * Check if Redis is available
     */
    public function isRedisAvailable(): bool
    {
        return $this->redisAvailable;
    }

    /**
     * Retry Redis connection
     */
    public function retryRedis(): bool
    {
        try {
            $this->redis->ping();
            $this->redisAvailable = true;
            $this->backpressureWarningLogged = false; // Reset warning flag

            return true;
        } catch (\Throwable $e) {
            $this->redisAvailable = false;
            $this->lastFailureTime = time();

            return false;
        }
    }
}
