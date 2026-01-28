#!/usr/bin/env php
<?php

/**
 * Log Worker - Redis to File/DB Writer
 *
 * Reads log entries from Redis queue and writes them to files or database.
 * Designed to run as a separate Docker container or background process.
 *
 * USAGE:
 * ```bash
 * # Run as daemon
 * php bin/log-worker.php
 *
 * # With custom config
 * REDIS_HOST=redis REDIS_PORT=6379 LOG_PATH=/var/log/app php bin/log-worker.php
 *
 * # Docker
 * docker run -e REDIS_HOST=redis log-worker
 * ```
 *
 * CONFIGURATION (Environment Variables):
 * - REDIS_HOST: Redis server host (default: 127.0.0.1)
 * - REDIS_PORT: Redis server port (default: 6379)
 * - REDIS_PASSWORD: Redis password (optional)
 * - REDIS_DATABASE: Redis database number (default: 0)
 * - LOG_APP_NAME: Application name for queue key (default: app)
 * - LOG_PATH: Directory for log files (default: /var/log/app)
 * - LOG_BATCH_SIZE: Entries per batch (default: 100)
 * - LOG_FLUSH_INTERVAL: Max seconds between flushes (default: 5)
 * - LOG_WORKER_VERBOSE: Show verbose output (default: false)
 * - LOG_IDLE_SLEEP_MS: Sleep time in ms when idle (default: 100)
 * - LOG_MAX_RECONNECT_INTERVAL: Max reconnect interval in seconds (default: 60)
 *
 * FEATURES:
 * - Graceful shutdown via SIGTERM/SIGINT
 * - Exponential backoff for Redis reconnection
 * - Configurable idle sleep for CPU tuning
 * - Batch processing with configurable intervals
 * - File rotation by channel and date
 *
 * @version 2.0.0
 */

declare(strict_types=1);

// ============================================================================
// Configuration
// ============================================================================

$config = [
    'redis_host' => getenv('REDIS_HOST') ?: '127.0.0.1',
    'redis_port' => (int) (getenv('REDIS_PORT') ?: 6379),
    'redis_password' => getenv('REDIS_PASSWORD') ?: null,
    'redis_database' => (int) (getenv('REDIS_DATABASE') ?: 0),
    'app_name' => getenv('LOG_APP_NAME') ?: 'app',
    'log_path' => getenv('LOG_PATH') ?: '/var/log/app',
    'batch_size' => (int) (getenv('LOG_BATCH_SIZE') ?: 100),
    'flush_interval' => (int) (getenv('LOG_FLUSH_INTERVAL') ?: 5),
    'verbose' => filter_var(getenv('LOG_WORKER_VERBOSE') ?: false, FILTER_VALIDATE_BOOLEAN),
    'idle_sleep_ms' => (int) (getenv('LOG_IDLE_SLEEP_MS') ?: 100),
    'max_reconnect_interval' => (int) (getenv('LOG_MAX_RECONNECT_INTERVAL') ?: 60),
];

$queueKey = 'eap:logs:' . $config['app_name'];

// ============================================================================
// Statistics
// ============================================================================

$stats = [
    'entries_processed' => 0,
    'entries_written' => 0,
    'flushes' => 0,
    'redis_reconnects' => 0,
    'errors' => 0,
    'start_time' => time(),
];

// ============================================================================
// Functions
// ============================================================================

function logMsg(string $msg, bool $verbose, bool $isVerbose = false): void
{
    if ($isVerbose && !$verbose) {
        return;
    }
    $time = date('Y-m-d H:i:s');
    echo "[{$time}] {$msg}\n";
}

function connectRedis(array $config, bool $verbose): ?\Redis
{
    try {
        $redis = new \Redis();
        $connected = $redis->connect(
            $config['redis_host'],
            $config['redis_port'],
            2.0,  // timeout
            null, // reserved
            0,    // retry_interval
            2.0,  // read_timeout
        );

        if (!$connected) {
            return null;
        }

        if ($config['redis_password']) {
            $redis->auth($config['redis_password']);
        }

        if ($config['redis_database'] > 0) {
            $redis->select($config['redis_database']);
        }

        // Test connection
        $redis->ping();

        return $redis;
    } catch (\Throwable $e) {
        logMsg("Redis connection error: {$e->getMessage()}", $verbose);

        return null;
    }
}

/**
 * Calculate exponential backoff interval
 */
function calculateBackoff(int $attempt, int $maxInterval): int
{
    // Exponential backoff: 1, 2, 4, 8, 16, 32, ... up to max
    $interval = min((int) pow(2, $attempt), $maxInterval);

    // Add jitter (±25%) to prevent thundering herd
    $jitter = (int) ($interval * 0.25);
    $interval += random_int(-$jitter, $jitter);

    return max(1, $interval);
}

function writeToFile(string $logPath, array $entries, bool $verbose): int
{
    // Group entries by channel and date
    $byFile = [];

    foreach ($entries as $entry) {
        $channel = $entry['channel'] ?? 'default';
        $timestamp = $entry['timestamp'] ?? date('Y-m-d\TH:i:s.uP');
        $date = substr($timestamp, 0, 10);

        $fileKey = "{$channel}-{$date}";
        $byFile[$fileKey][] = $entry;
    }

    $written = 0;

    foreach ($byFile as $fileKey => $fileEntries) {
        $filePath = $logPath . '/' . $fileKey . '.log';

        $lines = array_map(function ($entry) {
            return formatLogLine($entry);
        }, $fileEntries);

        $content = implode("\n", $lines) . "\n";

        if (@file_put_contents($filePath, $content, FILE_APPEND | LOCK_EX) !== false) {
            $written += count($fileEntries);
        } else {
            logMsg("Failed to write to {$filePath}", $verbose);
        }
    }

    return $written;
}

function formatLogLine(array $entry): string
{
    $timestamp = $entry['timestamp'] ?? date('Y-m-d H:i:s');
    // Convert ISO format to simple format
    if (str_contains($timestamp, 'T')) {
        try {
            $dt = new \DateTimeImmutable($timestamp);
            $timestamp = $dt->format('Y-m-d H:i:s.u');
        } catch (\Throwable $e) {
            // Keep original if parsing fails
        }
    }

    $channel = $entry['channel'] ?? 'app';
    $level = strtoupper($entry['level'] ?? 'INFO');
    $message = $entry['message'] ?? '';

    // Format context as key=value
    $contextStr = '';
    $context = $entry['context'] ?? [];
    if (!empty($context) && is_array($context)) {
        $pairs = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value)) {
                $pairs[] = "{$key}=" . json_encode($value, JSON_UNESCAPED_SLASHES);
            }
        }
        if (!empty($pairs)) {
            $contextStr = ' | ' . implode(' ', $pairs);
        }
    }

    // Add metadata if present
    $metaStr = '';
    $meta = $entry['_meta'] ?? [];
    if (!empty($meta['pid'])) {
        $metaStr .= " [pid:{$meta['pid']}]";
    }

    return "[{$timestamp}] [{$level}] {$channel}{$metaStr} | {$message}{$contextStr}";
}

function printStats(array $stats, bool $verbose): void
{
    if (!$verbose) {
        return;
    }

    $uptime = time() - $stats['start_time'];
    $rate = $uptime > 0 ? round($stats['entries_written'] / $uptime, 2) : 0;

    logMsg(sprintf(
        'Stats: processed=%d written=%d flushes=%d reconnects=%d errors=%d rate=%.2f/s uptime=%ds',
        $stats['entries_processed'],
        $stats['entries_written'],
        $stats['flushes'],
        $stats['redis_reconnects'],
        $stats['errors'],
        $rate,
        $uptime,
    ), $verbose);
}

// ============================================================================
// Main Loop
// ============================================================================

logMsg('Log Worker v2.0.0 starting...', true);
logMsg("Redis: {$config['redis_host']}:{$config['redis_port']}", $config['verbose']);
logMsg("Queue: {$queueKey}", $config['verbose']);
logMsg("Log Path: {$config['log_path']}", $config['verbose']);
logMsg("Batch Size: {$config['batch_size']}", $config['verbose']);
logMsg("Flush Interval: {$config['flush_interval']}s", $config['verbose']);
logMsg("Idle Sleep: {$config['idle_sleep_ms']}ms", $config['verbose']);

// Ensure log directory exists
if (!is_dir($config['log_path'])) {
    if (!@mkdir($config['log_path'], 0755, true)) {
        logMsg("ERROR: Cannot create log directory: {$config['log_path']}", true);
        exit(1);
    }
}

// Connect to Redis with exponential backoff
$redis = null;
$reconnectAttempt = 0;
$lastConnectAttempt = 0;

$redis = connectRedis($config, $config['verbose']);
if ($redis !== null) {
    logMsg('Connected to Redis', $config['verbose']);
} else {
    logMsg('WARNING: Redis not available, will retry with exponential backoff...', true);
}

// Signal handling for graceful shutdown
$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$running) {
        $running = false;
    });
    pcntl_signal(SIGINT, function () use (&$running) {
        $running = false;
    });
}

$lastFlush = time();
$lastStatsLog = time();
$buffer = [];

logMsg('Worker ready, entering main loop...', $config['verbose']);

while ($running) {
    // Handle signals
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    // Reconnect to Redis if needed with exponential backoff
    if ($redis === null) {
        $now = time();
        $backoffInterval = calculateBackoff($reconnectAttempt, $config['max_reconnect_interval']);

        if (($now - $lastConnectAttempt) >= $backoffInterval) {
            $lastConnectAttempt = $now;
            $reconnectAttempt++;

            logMsg("Attempting Redis reconnection (attempt #{$reconnectAttempt}, backoff {$backoffInterval}s)...", $config['verbose']);

            $redis = connectRedis($config, $config['verbose']);
            if ($redis !== null) {
                logMsg('Reconnected to Redis', $config['verbose']);
                $reconnectAttempt = 0; // Reset on success
                $stats['redis_reconnects']++;
            }
        }
    }

    // Read from Redis
    if ($redis !== null) {
        try {
            // RPOP to get entries in FIFO order
            $entry = $redis->rPop($queueKey);

            if ($entry !== false && $entry !== null) {
                $decoded = json_decode($entry, true);
                if ($decoded !== null) {
                    $buffer[] = $decoded;
                    $stats['entries_processed']++;
                    logMsg("Received entry: {$decoded['channel']} - {$decoded['message']}", $config['verbose'], true);
                }
            }
        } catch (\Throwable $e) {
            logMsg("Redis error: {$e->getMessage()}", $config['verbose']);
            $redis = null;
            $stats['errors']++;
            // Don't reset reconnectAttempt - continue backoff
        }
    }

    // Flush conditions
    $shouldFlush = false;
    $now = time();

    // Batch size reached
    if (count($buffer) >= $config['batch_size']) {
        $shouldFlush = true;
        logMsg("Flushing: batch size reached ({$config['batch_size']})", $config['verbose'], true);
    }

    // Flush interval reached (only if buffer not empty)
    if (!$shouldFlush && !empty($buffer) && ($now - $lastFlush) >= $config['flush_interval']) {
        $shouldFlush = true;
        logMsg("Flushing: interval reached ({$config['flush_interval']}s)", $config['verbose'], true);
    }

    // Flush
    if ($shouldFlush && !empty($buffer)) {
        $written = writeToFile($config['log_path'], $buffer, $config['verbose']);
        $stats['entries_written'] += $written;
        $stats['flushes']++;
        logMsg("Flushed {$written} entries to files", $config['verbose']);
        $buffer = [];
        $lastFlush = $now;
    }

    // Log stats periodically (every 60 seconds)
    if (($now - $lastStatsLog) >= 60) {
        printStats($stats, $config['verbose']);
        $lastStatsLog = $now;
    }

    // Sleep if no entries (to avoid CPU spin)
    $queueLength = 0;
    if ($redis !== null) {
        try {
            $queueLength = $redis->lLen($queueKey);
        } catch (\Throwable $e) {
            // Ignore
        }
    }

    if ($redis === null || $queueLength === 0) {
        usleep($config['idle_sleep_ms'] * 1000);
    }
}

// Final flush on shutdown
if (!empty($buffer)) {
    logMsg('Shutdown: flushing remaining ' . count($buffer) . ' entries', true);
    $written = writeToFile($config['log_path'], $buffer, $config['verbose']);
    $stats['entries_written'] += $written;
}

// Final stats
printStats($stats, true);
logMsg('Log Worker stopped.', true);
