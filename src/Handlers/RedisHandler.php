<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Handlers;

use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Redis Handler
 *
 * Writes logs to Redis for high-performance logging and log aggregation.
 * Supports multiple storage strategies.
 *
 * STRATEGIES:
 * - list: RPUSH to a list (FIFO, good for processing)
 * - pubsub: PUBLISH to a channel (real-time streaming)
 * - stream: XADD to a stream (Redis 5.0+, best for log aggregation)
 *
 * USAGE:
 * ```php
 * $redis = new \Redis();
 * $redis->connect('127.0.0.1', 6379);
 *
 * // List-based (for processing queues)
 * $handler = new RedisHandler($redis, 'logs:app');
 *
 * // Pub/Sub (for real-time monitoring)
 * $handler = new RedisHandler($redis, 'logs:app', strategy: 'pubsub');
 *
 * // Stream (for log aggregation with consumer groups)
 * $handler = new RedisHandler($redis, 'logs:app', strategy: 'stream');
 * ```
 */
class RedisHandler extends AbstractProcessingHandler implements HandlerInterface
{
    public const STRATEGY_LIST = 'list';
    public const STRATEGY_PUBSUB = 'pubsub';
    public const STRATEGY_STREAM = 'stream';

    /** @var \Redis|\Predis\Client */
    private $redis;

    private string $key;
    private string $strategy;
    private int $maxLength;
    private int $ttl;

    /**
     * @param \Redis|\Predis\Client $redis Redis client
     * @param string $key Redis key/channel name
     * @param Level $level Minimum log level
     * @param bool $bubble Whether to bubble
     * @param string $strategy Storage strategy (list, pubsub, stream)
     * @param int $maxLength Max entries to keep (0 = unlimited, for list/stream)
     * @param int $ttl TTL for list entries in seconds (0 = no expiry)
     */
    public function __construct(
        $redis,
        string $key = 'logs',
        Level $level = Level::Debug,
        bool $bubble = true,
        string $strategy = self::STRATEGY_LIST,
        int $maxLength = 10000,
        int $ttl = 0,
    ) {
        parent::__construct($level, $bubble);

        $this->redis = $redis;
        $this->key = $key;
        $this->strategy = $strategy;
        $this->maxLength = max(0, $maxLength);
        $this->ttl = max(0, $ttl);
    }

    /**
     * {@inheritdoc}
     */
    protected function write(LogRecord $record): void
    {
        $data = $this->formatRecord($record);

        try {
            match ($this->strategy) {
                self::STRATEGY_PUBSUB => $this->publishRecord($data),
                self::STRATEGY_STREAM => $this->streamRecord($record, $data),
                default => $this->listRecord($data),
            };
        } catch (\Throwable $e) {
            error_log('RedisHandler: Write failed - ' . $e->getMessage());
        }
    }

    /**
     * Format record to JSON
     */
    private function formatRecord(LogRecord $record): string
    {
        return json_encode([
            'timestamp' => $record->datetime->format(\DateTimeInterface::RFC3339_EXTENDED),
            'channel' => $record->channel,
            'level' => $record->level->name,
            'level_value' => $record->level->value,
            'message' => $record->message,
            'context' => $record->context,
            'extra' => $record->extra,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * Push to Redis list
     */
    private function listRecord(string $data): void
    {
        $this->redis->rPush($this->key, $data);

        // Trim list if maxLength is set
        if ($this->maxLength > 0) {
            $this->redis->lTrim($this->key, -$this->maxLength, -1);
        }

        // Set TTL if configured (refreshes on each write)
        if ($this->ttl > 0) {
            $this->redis->expire($this->key, $this->ttl);
        }
    }

    /**
     * Publish to Redis channel
     */
    private function publishRecord(string $data): void
    {
        $this->redis->publish($this->key, $data);
    }

    /**
     * Add to Redis stream
     *
     * Note: xAdd() signature varies between phpredis versions and Predis.
     * This implementation handles both by detecting the client type.
     */
    private function streamRecord(LogRecord $record, string $data): void
    {
        $fields = [
            'timestamp' => $record->datetime->format(\DateTimeInterface::RFC3339_EXTENDED),
            'channel' => $record->channel,
            'level' => $record->level->name,
            'level_value' => (string) $record->level->value,
            'message' => $record->message,
            'data' => $data,
        ];

        // XADD with auto-generated ID
        // Handle different client signatures (phpredis vs Predis)
        if ($this->redis instanceof \Predis\Client) {
            // Predis signature: xadd(key, id, fields)
            $this->redis->xadd($this->key, '*', $fields);

            // Trim separately if maxLength is set
            // Using XTRIM with ~ for approximate trimming (better performance)
            if ($this->maxLength > 0) {
                $this->redis->xtrim($this->key, 'MAXLEN', '~', $this->maxLength);
            }
        } else {
            // phpredis 5.x+: xAdd(key, id, fields, maxlen, approximate)
            if ($this->maxLength > 0) {
                try {
                    // Try phpredis 5.x/6.x style with MAXLEN
                    $this->redis->xAdd($this->key, '*', $fields, $this->maxLength, true);
                } catch (\TypeError $e) {
                    // Fallback: add without trimming, then trim separately
                    $this->redis->xAdd($this->key, '*', $fields);
                    $this->redis->xTrim($this->key, (string) $this->maxLength, true);
                }
            } else {
                $this->redis->xAdd($this->key, '*', $fields);
            }
        }
    }

    /**
     * Read logs from Redis list
     *
     * @param int $count Number of entries to read
     * @param int $start Start offset
     * @return array<array<string, mixed>>
     */
    public function readList(int $count = 100, int $start = 0): array
    {
        $entries = $this->redis->lRange($this->key, $start, $start + $count - 1);

        $logs = [];
        foreach ($entries as $entry) {
            $decoded = json_decode($entry, true);
            if ($decoded !== null) {
                $logs[] = $decoded;
            }
        }

        return $logs;
    }

    /**
     * Read logs from Redis stream
     *
     * @param string $startId Start ID ('0' for beginning, '$' for new only)
     * @param int $count Number of entries to read
     * @return array<string, array<string, mixed>>
     */
    public function readStream(string $startId = '0', int $count = 100): array
    {
        $entries = $this->redis->xRange($this->key, $startId, '+', $count);

        $logs = [];
        foreach ($entries as $id => $fields) {
            $logs[$id] = $fields;
            if (isset($fields['data'])) {
                $decoded = json_decode($fields['data'], true);
                if ($decoded !== null) {
                    $logs[$id] = array_merge($logs[$id], $decoded);
                }
            }
        }

        return $logs;
    }

    /**
     * Create a consumer group for stream processing
     *
     * @param string $groupName Consumer group name
     * @param string $startId Starting ID ('0' for all, '$' for new only)
     */
    public function createConsumerGroup(string $groupName, string $startId = '0'): bool
    {
        try {
            $this->redis->xGroup('CREATE', $this->key, $groupName, $startId, true);

            return true;
        } catch (\Throwable $e) {
            // Group may already exist
            if (!str_contains($e->getMessage(), 'BUSYGROUP')) {
                error_log('RedisHandler: Failed to create consumer group - ' . $e->getMessage());
            }

            return false;
        }
    }

    /**
     * Read from stream as a consumer
     *
     * @param string $groupName Consumer group name
     * @param string $consumerName Consumer name
     * @param int $count Number of entries to read
     * @param int $blockMs Block timeout in milliseconds (0 = no block)
     * @return array<string, array<string, mixed>>
     */
    public function readAsConsumer(
        string $groupName,
        string $consumerName,
        int $count = 100,
        int $blockMs = 0,
    ): array {
        $result = $this->redis->xReadGroup(
            $groupName,
            $consumerName,
            [$this->key => '>'],
            $count,
            $blockMs,
        );

        if ($result === false || !isset($result[$this->key])) {
            return [];
        }

        return $result[$this->key];
    }

    /**
     * Acknowledge processed messages
     *
     * @param string $groupName Consumer group name
     * @param array<string> $ids Message IDs to acknowledge
     */
    public function acknowledge(string $groupName, array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $result = $this->redis->xAck($this->key, $groupName, $ids);

        return $result !== false ? $result : 0;
    }

    /**
     * Get stream info
     *
     * @return array<string, mixed>
     */
    public function getStreamInfo(): array
    {
        try {
            return $this->redis->xInfo('STREAM', $this->key);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get list length
     */
    public function getListLength(): int
    {
        return (int) $this->redis->lLen($this->key);
    }

    /**
     * Clear all logs
     */
    public function clear(): bool
    {
        return (bool) $this->redis->del($this->key);
    }

    /**
     * Get the default formatter
     */
    protected function getDefaultFormatter(): FormatterInterface
    {
        return new \AdosLabs\EnterprisePSR3Logger\Formatters\JsonFormatter(appendNewline: false);
    }
}
