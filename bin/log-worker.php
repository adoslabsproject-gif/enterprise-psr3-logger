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
 *
 * @version 1.0.0
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
];

$queueKey = 'eap:logs:' . $config['app_name'];

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

function connectRedis(array $config): ?\Redis
{
    try {
        $redis = new \Redis();
        $redis->connect($config['redis_host'], $config['redis_port'], 2.0);

        if ($config['redis_password']) {
            $redis->auth($config['redis_password']);
        }

        if ($config['redis_database'] > 0) {
            $redis->select($config['redis_database']);
        }

        return $redis;
    } catch (\Throwable $e) {
        return null;
    }
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
        $dt = new \DateTimeImmutable($timestamp);
        $timestamp = $dt->format('Y-m-d H:i:s.u');
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

// ============================================================================
// Main Loop
// ============================================================================

logMsg("Log Worker starting...", $config['verbose']);
logMsg("Redis: {$config['redis_host']}:{$config['redis_port']}", $config['verbose']);
logMsg("Queue: {$queueKey}", $config['verbose']);
logMsg("Log Path: {$config['log_path']}", $config['verbose']);
logMsg("Batch Size: {$config['batch_size']}", $config['verbose']);
logMsg("Flush Interval: {$config['flush_interval']}s", $config['verbose']);

// Ensure log directory exists
if (!is_dir($config['log_path'])) {
    if (!@mkdir($config['log_path'], 0755, true)) {
        logMsg("ERROR: Cannot create log directory: {$config['log_path']}", $config['verbose']);
        exit(1);
    }
}

// Connect to Redis
$redis = connectRedis($config);
$lastConnectAttempt = time();
$reconnectInterval = 5;

if ($redis === null) {
    logMsg("WARNING: Redis not available, waiting...", $config['verbose']);
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
$buffer = [];

logMsg("Worker ready, entering main loop...", $config['verbose']);

while ($running) {
    // Handle signals
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    // Reconnect to Redis if needed
    if ($redis === null && (time() - $lastConnectAttempt) >= $reconnectInterval) {
        $lastConnectAttempt = time();
        $redis = connectRedis($config);
        if ($redis !== null) {
            logMsg("Reconnected to Redis", $config['verbose']);
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
                    logMsg("Received entry: {$decoded['channel']} - {$decoded['message']}", $config['verbose'], true);
                }
            }
        } catch (\Throwable $e) {
            logMsg("Redis error: {$e->getMessage()}", $config['verbose']);
            $redis = null;
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
        logMsg("Flushed {$written} entries to files", $config['verbose']);
        $buffer = [];
        $lastFlush = $now;
    }

    // Sleep if no entries (to avoid CPU spin)
    if ($redis === null || ($redis !== null && $redis->lLen($queueKey) === 0)) {
        usleep(100000); // 100ms
    }
}

// Final flush on shutdown
if (!empty($buffer)) {
    logMsg("Shutdown: flushing remaining " . count($buffer) . " entries", $config['verbose']);
    writeToFile($config['log_path'], $buffer, $config['verbose']);
}

logMsg("Log Worker stopped.", $config['verbose']);
