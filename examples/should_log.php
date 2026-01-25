<?php

declare(strict_types=1);

/**
 * ENTERPRISE GALAXY: Example implementations of should_log() function
 *
 * The should_log() function is the CORE of enterprise logging.
 * It determines whether a specific channel/level should be logged.
 *
 * IMPORTANT: Define should_log() in your bootstrap BEFORE creating loggers.
 */

// ============================================================================
// EXAMPLE 1: Simple Environment-Based Filtering
// ============================================================================

/**
 * Simple implementation: Log everything in development, only errors in production
 */
function should_log_simple(string $channel, string $level): bool
{
    $env = getenv('APP_ENV') ?: 'production';

    if ($env === 'development') {
        return true; // Log everything in development
    }

    // Production: Only log warnings and above
    return in_array($level, ['warning', 'error', 'critical', 'alert', 'emergency'], true);
}

// ============================================================================
// EXAMPLE 2: Channel-Specific Filtering
// ============================================================================

/**
 * Channel-specific filtering: Different log levels per channel
 */
function should_log_channels(string $channel, string $level): bool
{
    // Map PSR-3 levels to numeric priorities
    $levelPriority = [
        'debug' => 100,
        'info' => 200,
        'notice' => 250,
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
        'alert' => 550,
        'emergency' => 600,
    ];

    // Define minimum log level per channel
    $channelConfig = [
        'default' => 'warning',      // Only warnings and above
        'security' => 'info',        // Security: log everything info and above
        'api' => 'warning',          // API: only warnings and above
        'database' => 'warning',     // Database: only slow queries (warnings)
        'email' => 'error',          // Email: only errors
        'debug_general' => 'warning', // Debug channel: warnings only
    ];

    // Get minimum level for this channel (default: 'warning')
    $minLevel = $channelConfig[$channel] ?? 'warning';

    // Check if current level meets minimum priority
    $currentPriority = $levelPriority[$level] ?? 0;
    $minPriority = $levelPriority[$minLevel] ?? 300;

    return $currentPriority >= $minPriority;
}

// ============================================================================
// EXAMPLE 3: Database-Driven Configuration (ENTERPRISE)
// ============================================================================

/**
 * Enterprise implementation: Fetch configuration from database
 * with multi-level caching for ultra-fast performance
 */
function should_log_enterprise(string $channel, string $level): bool
{
    // LAYER 1: Static cache (per-process) - FASTEST (~0.01μs)
    static $cache = [];
    static $service = null;

    $cacheKey = "{$channel}:{$level}";
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    // LAYER 2: Fetch from database service (with Redis/APCu cache)
    try {
        if ($service === null) {
            // Initialize service (singleton)
            global $pdo, $redis;
            $service = new \YourApp\Services\LoggingConfigService($pdo, $redis);
        }

        $result = $service->shouldLog($channel, $level);
        $cache[$cacheKey] = $result;

        return $result;

    } catch (\Throwable $e) {
        // FAIL-SAFE: On error, allow logging (safe default)
        error_log('[should_log] Error: ' . $e->getMessage());
        return true; // Allow log on error
    }
}

// ============================================================================
// EXAMPLE 4: Full Enterprise Implementation (PRODUCTION-READY)
// ============================================================================

/**
 * Full enterprise implementation with production-ready features
 *
 * Features:
 * - 3-layer cache (static → APCu → Redis → Database)
 * - Redis invalidation (admin panel changes propagate immediately)
 * - Circuit breaker (Redis failures handled gracefully)
 * - Auto-recovery (PostgreSQL connection retries)
 * - Worker-safe (long-running processes detect config changes)
 * - Performance: ~0.01μs for cache hits (99% of calls)
 */
function should_log_enterprise_full(string $channel, string $level): bool
{
    // PERFORMANCE: Static cache key (computed once per request)
    static $cache = [];
    static $lastInvalidationCheck = 0;
    static $lastRedisCheckTime = 0.0;
    static $serviceAvailable = true;
    static $serviceFailedAt = 0.0;
    static $service = null;
    static $redis_circuit_breaker_fails = 0;
    static $redis_circuit_breaker_last_fail = 0.0;

    // CIRCUIT BREAKER: Skip Redis if it's failing (>5 failures in last 60 seconds)
    $now = microtime(true);
    $redis_check_needed = ($now - $lastRedisCheckTime) >= 300.0; // 5 minutes
    $redis_circuit_open = ($redis_circuit_breaker_fails >= 5) && (($now - $redis_circuit_breaker_last_fail) < 60.0);

    // 🔥 ENTERPRISE GALAXY FIX: Redis invalidation check MUST happen BEFORE static cache return
    // This ensures long-running workers (audio, email, newsletter) detect config changes
    // Without this check FIRST, static cache returns immediately and never sees Redis invalidation
    // Performance: Only checks Redis every 5 minutes (~1-2ms), then ultra-fast cache hits (~0.01μs)
    if ($redis_check_needed && !$redis_circuit_open) {
        try {
            // Lazy Redis connection (only when needed)
            $redisManager = \Need2Talk\Core\EnterpriseRedisManager::getInstance();
            $redis = $redisManager->getConnection('L1_cache');

            if ($redis) {
                $invalidationTimestamp = $redis->get('logging:config:invalidation_timestamp');

                // If invalidation timestamp changed, clear ALL caches immediately
                if ($invalidationTimestamp && $invalidationTimestamp > $lastInvalidationCheck) {
                    $cache = []; // 🔥 CRITICAL: Clear static cache so workers reload config
                    $lastInvalidationCheck = $invalidationTimestamp;

                    // Clear APCu cache too (if available)
                    if (extension_loaded('apcu') && apcu_enabled()) {
                        apcu_clear_cache();
                    }
                }

                // Reset circuit breaker on success
                $redis_circuit_breaker_fails = 0;
            }

            $lastRedisCheckTime = $now;

        } catch (\Throwable $e) {
            // Circuit breaker: Track Redis failures
            $redis_circuit_breaker_fails++;
            $redis_circuit_breaker_last_fail = $now;
            $lastRedisCheckTime = $now; // Don't retry immediately

            // Log only first failure (avoid spam)
            if ($redis_circuit_breaker_fails === 1) {
                error_log('[should_log] Redis check failed, using cached values: ' . $e->getMessage());
            }
        }
    }

    // ULTRA-FAST PATH: Static cache (same process) - ZERO overhead
    // NOW this check happens AFTER Redis invalidation check (every 5 minutes)
    // So long-running workers will detect config changes and reload!
    $cacheKey = "{$channel}:{$level}";
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey]; // ~0.01 microseconds - FASTEST RETURN
    }

    // 🔥 ENTERPRISE GALAXY FIX: Auto-recovery after PostgreSQL becomes ready
    // If service failed during bootstrap (PostgreSQL not ready), retry after 60 seconds
    // This ensures workers populate cache with correct config once PostgreSQL is available
    if (!$serviceAvailable) {
        $timeSinceFailure = $now - $serviceFailedAt;

        // After 60s, PostgreSQL should be ready - reset and retry service
        if ($timeSinceFailure >= 60.0) {
            $serviceAvailable = true; // Reset flag - retry service on next block
            // Fall through to service call below (will populate cache correctly)
        } else {
            // Still within 60s grace period - allow logging without cache
            return true;
        }
    }

    // SLOW PATH: Fetch from service (only on cache miss)
    try {
        if ($service === null) {
            $service = \Need2Talk\Services\LoggingConfigService::getInstance();
        }

        $result = $service->shouldLog($channel, $level);

        // Write to static cache
        $cache[$cacheKey] = $result;

        return $result;

    } catch (\Throwable $e) {
        // 🔥 ENTERPRISE GALAXY FIX: Fail-safe - allow logging BUT don't cache TRUE
        // Problem: During worker bootstrap, PostgreSQL may not be ready yet (Connection refused)
        // If we cache TRUE here, it persists for hours and bypasses real config forever
        // Solution: Return TRUE (allow log) but DON'T write to cache, so next call retries service

        $serviceAvailable = false;
        $serviceFailedAt = $now; // 🔥 Track when it failed - retry after 60s

        // Log error ONCE per channel:level (avoid spam)
        static $logged_errors = [];
        if (!isset($logged_errors[$cacheKey])) {
            $logged_errors[$cacheKey] = true;
            error_log("[should_log] Service unavailable for {$cacheKey}, allowing all logging (temporary): " . $e->getMessage());
        }

        // 🔥 CRITICAL FIX: Return TRUE (allow log) but DON'T cache it
        // This ensures next call retries service (when PostgreSQL becomes ready)
        return true;
    }
}

// ============================================================================
// USAGE EXAMPLES
// ============================================================================

// Choose ONE implementation and rename it to should_log()
// Then use it in your bootstrap:

/*
// bootstrap.php

// Load should_log() function FIRST
function should_log(string $channel, string $level): bool {
    // Use one of the examples above
    return should_log_channels($channel, $level);
}

// NOW create loggers (they will auto-detect should_log())
use Senza1dio\EnterprisePSR3Logger\LoggerFactory;

$logger = LoggerFactory::production('app', '/var/log/app');
$logger->debug('This may be filtered by should_log()');
*/
