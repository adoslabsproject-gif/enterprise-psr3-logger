<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Security;

/**
 * Rate Limiter
 *
 * Enterprise-grade rate limiting with multiple storage backends.
 * Implements sliding window algorithm for accurate rate limiting.
 *
 * @version 1.0.0
 */
final class RateLimiter
{
    /**
     * Default limits per endpoint category
     */
    private const DEFAULT_LIMITS = [
        'default' => ['requests' => 60, 'window' => 60],      // 60 req/min
        'sensitive' => ['requests' => 10, 'window' => 60],    // 10 req/min
        'test' => ['requests' => 5, 'window' => 300],         // 5 req/5min
        'auth' => ['requests' => 5, 'window' => 900],         // 5 req/15min
    ];

    private ?\Redis $redis = null;

    /** @var array<string, array<int>> */
    private array $localCache = [];

    private string $prefix = 'eap_rate:';

    public function __construct()
    {
        $this->initRedis();
    }

    /**
     * Check if request is allowed under rate limit
     *
     * @param string $key Unique identifier (e.g., "telegram_test:{user_id}")
     * @param string $category Rate limit category (default, sensitive, test, auth)
     * @return array{allowed: bool, remaining: int, reset_at: int, retry_after: int|null}
     */
    public function check(string $key, string $category = 'default'): array
    {
        $limits = self::DEFAULT_LIMITS[$category] ?? self::DEFAULT_LIMITS['default'];
        $maxRequests = $limits['requests'];
        $windowSeconds = $limits['window'];

        $fullKey = $this->prefix . $key;
        $now = time();
        $windowStart = $now - $windowSeconds;

        if ($this->redis !== null) {
            return $this->checkRedis($fullKey, $maxRequests, $windowSeconds, $now, $windowStart);
        }

        return $this->checkLocal($fullKey, $maxRequests, $windowSeconds, $now, $windowStart);
    }

    /**
     * Record a request (increment counter)
     *
     * @param string $key Unique identifier
     * @param string $category Rate limit category
     */
    public function hit(string $key, string $category = 'default'): void
    {
        $limits = self::DEFAULT_LIMITS[$category] ?? self::DEFAULT_LIMITS['default'];
        $windowSeconds = $limits['window'];

        $fullKey = $this->prefix . $key;
        $now = time();

        if ($this->redis !== null) {
            $this->hitRedis($fullKey, $windowSeconds, $now);
        } else {
            $this->hitLocal($fullKey, $now);
        }
    }

    /**
     * Combined check and hit - most common use case
     *
     * @param string $key Unique identifier
     * @param string $category Rate limit category
     * @return array{allowed: bool, remaining: int, reset_at: int, retry_after: int|null}
     */
    public function attempt(string $key, string $category = 'default'): array
    {
        $result = $this->check($key, $category);

        if ($result['allowed']) {
            $this->hit($key, $category);
            $result['remaining']--;
        }

        return $result;
    }

    /**
     * Clear rate limit for a key
     */
    public function clear(string $key): void
    {
        $fullKey = $this->prefix . $key;

        if ($this->redis !== null) {
            $this->redis->del($fullKey);
        }

        unset($this->localCache[$fullKey]);
    }

    /**
     * Initialize Redis connection if available
     */
    private function initRedis(): void
    {
        if (!class_exists('Redis')) {
            return;
        }

        try {
            $host = $_ENV['REDIS_HOST'] ?? getenv('REDIS_HOST') ?: 'localhost';
            $port = (int) ($_ENV['REDIS_PORT'] ?? getenv('REDIS_PORT') ?: 6379);
            $password = $_ENV['REDIS_PASSWORD'] ?? getenv('REDIS_PASSWORD') ?: null;
            $useTls = filter_var($_ENV['REDIS_TLS'] ?? getenv('REDIS_TLS') ?: false, FILTER_VALIDATE_BOOLEAN);

            $redis = new \Redis();

            // Build connection options
            $options = ['timeout' => 1.0];

            if ($useTls) {
                // TLS connection
                $host = 'tls://' . $host;
                $options['stream'] = ['verify_peer' => true, 'verify_peer_name' => true];
            }

            if (!$redis->connect($host, $port, 1.0)) {
                return;
            }

            // Authenticate if password is set
            if ($password !== null && $password !== '') {
                if (!$redis->auth($password)) {
                    $redis->close();

                    return;
                }
            }

            // Select database if specified
            $database = $_ENV['REDIS_DATABASE'] ?? getenv('REDIS_DATABASE') ?: null;
            if ($database !== null && is_numeric($database)) {
                $redis->select((int) $database);
            }

            $this->redis = $redis;
        } catch (\Throwable $e) {
            // Redis not available, use local fallback
            $this->redis = null;
        }
    }

    /**
     * Check rate limit using Redis (sliding window)
     *
     * @return array{allowed: bool, remaining: int, reset_at: int, retry_after: int|null}
     */
    private function checkRedis(string $key, int $maxRequests, int $windowSeconds, int $now, int $windowStart): array
    {
        $redis = $this->redis;
        if ($redis === null) {
            return $this->checkLocal($key, $maxRequests, $windowSeconds, $now, $windowStart);
        }

        // Remove old entries and count current window
        $redis->zRemRangeByScore($key, '-inf', (string) $windowStart);
        $count = (int) $redis->zCard($key);

        $allowed = $count < $maxRequests;
        $remaining = max(0, $maxRequests - $count);
        $resetAt = $now + $windowSeconds;

        // Get oldest entry to calculate retry_after
        $retryAfter = null;
        if (!$allowed) {
            $oldest = $redis->zRange($key, 0, 0, true);
            if (is_array($oldest) && !empty($oldest)) {
                $oldestTime = (int) reset($oldest);
                $retryAfter = max(1, ($oldestTime + $windowSeconds) - $now);
            }
        }

        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'reset_at' => $resetAt,
            'retry_after' => $retryAfter,
        ];
    }

    /**
     * Record hit in Redis
     */
    private function hitRedis(string $key, int $windowSeconds, int $now): void
    {
        $redis = $this->redis;
        if ($redis === null) {
            $this->hitLocal($key, $now);

            return;
        }

        // Add current timestamp to sorted set with cryptographically secure suffix
        $redis->zAdd($key, $now, (string) $now . '.' . bin2hex(random_bytes(8)));
        $redis->expire($key, $windowSeconds + 1);
    }

    /**
     * Check rate limit using local memory (fallback)
     *
     * @return array{allowed: bool, remaining: int, reset_at: int, retry_after: int|null}
     */
    private function checkLocal(string $key, int $maxRequests, int $windowSeconds, int $now, int $windowStart): array
    {
        // Clean expired entries
        if (isset($this->localCache[$key])) {
            $this->localCache[$key] = array_filter(
                $this->localCache[$key],
                fn ($timestamp) => $timestamp > $windowStart,
            );
        }

        $count = count($this->localCache[$key] ?? []);
        $allowed = $count < $maxRequests;
        $remaining = max(0, $maxRequests - $count);
        $resetAt = $now + $windowSeconds;

        $retryAfter = null;
        if (!$allowed && !empty($this->localCache[$key])) {
            $oldest = min($this->localCache[$key]);
            $retryAfter = max(1, ($oldest + $windowSeconds) - $now);
        }

        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'reset_at' => $resetAt,
            'retry_after' => $retryAfter,
        ];
    }

    /**
     * Record hit in local memory
     */
    private function hitLocal(string $key, int $now): void
    {
        if (!isset($this->localCache[$key])) {
            $this->localCache[$key] = [];
        }

        $this->localCache[$key][] = $now;
    }
}
