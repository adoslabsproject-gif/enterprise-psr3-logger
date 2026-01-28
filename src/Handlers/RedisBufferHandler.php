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
 *
 * USAGE:
 * ```php
 * $redis = new \Redis();
 * $redis->connect('redis', 6379);
 * $handler = new RedisBufferHandler($redis, 'myapp');
 * $logger->addHandler($handler);
 * ```
 *
 * @version 1.0.0
 */
class RedisBufferHandler extends AbstractProcessingHandler
{
    /**
     * Redis queue key prefix
     */
    private const QUEUE_KEY_PREFIX = 'eap:logs:';

    /**
     * Maximum queue size before dropping old entries
     */
    private const MAX_QUEUE_SIZE = 100000;

    private \Redis $redis;
    private string $queueKey;
    private string $appName;
    private bool $redisAvailable = true;

    /** @var string|null Fallback file path */
    private ?string $fallbackPath = null;

    /** @var bool Include extra metadata (pid, memory, request_id) */
    private bool $includeMetadata = true;

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
     * Get the Redis queue key (for worker configuration)
     */
    public function getQueueKey(): string
    {
        return $this->queueKey;
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

        if ($this->redisAvailable) {
            try {
                $this->pushToRedis($json);

                return;
            } catch (\Throwable $e) {
                $this->redisAvailable = false;
            }
        }

        // Fallback to file
        $this->writeToFallback($json);
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

            // Include request ID if available
            if (isset($record->context['request_id'])) {
                $entry['_meta']['request_id'] = $record->context['request_id'];
            }
        }

        return $entry;
    }

    /**
     * Push entry to Redis queue
     */
    private function pushToRedis(string $json): void
    {
        // LPUSH for FIFO processing (worker uses RPOP)
        $this->redis->lPush($this->queueKey, $json);

        // Trim queue if too large (keep last MAX_QUEUE_SIZE entries)
        // Only check occasionally to reduce overhead
        if (random_int(1, 100) === 1) {
            $size = $this->redis->lLen($this->queueKey);
            if ($size > self::MAX_QUEUE_SIZE) {
                $this->redis->lTrim($this->queueKey, 0, self::MAX_QUEUE_SIZE - 1);
            }
        }
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

            return true;
        } catch (\Throwable $e) {
            $this->redisAvailable = false;

            return false;
        }
    }
}
