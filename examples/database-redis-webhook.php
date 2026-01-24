<?php

/**
 * Database, Redis, and Webhook Examples
 *
 * Shows how to use advanced handlers.
 *
 * Note: These examples use mocked/simulated connections.
 * In production, use real database/Redis connections.
 *
 * Run: php examples/database-redis-webhook.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Senza1dio\EnterprisePSR3Logger\Logger;
use Senza1dio\EnterprisePSR3Logger\Handlers\DatabaseHandler;
use Senza1dio\EnterprisePSR3Logger\Handlers\RedisHandler;
use Senza1dio\EnterprisePSR3Logger\Handlers\WebhookHandler;
use Senza1dio\EnterprisePSR3Logger\Handlers\AsyncHandler;
use Senza1dio\EnterprisePSR3Logger\Handlers\StreamHandler;
use Senza1dio\EnterprisePSR3Logger\Formatters\JsonFormatter;
use Monolog\Level;

echo "=== Database, Redis & Webhook Examples ===\n\n";

// -----------------------------------------------------------------------------
// Example 1: Database Logging (SQLite in-memory)
// -----------------------------------------------------------------------------
echo "--- Example 1: Database Logging ---\n\n";

// Create in-memory SQLite database for demo
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create logs table
DatabaseHandler::createTable($pdo, 'logs', 'sqlite');
echo "Created logs table in SQLite\n";

// Create handler with batch insert
$dbHandler = new DatabaseHandler($pdo, 'logs', Level::Debug, true, 10);
$dbLogger = new Logger('database-example', [$dbHandler]);

// Log some messages
$dbLogger->info('User logged in', ['user_id' => 123, 'ip' => '192.168.1.1']);
$dbLogger->warning('Rate limit approaching', ['requests' => 95, 'limit' => 100]);
$dbLogger->error('Payment failed', ['order_id' => 456, 'error' => 'Card declined']);

// Flush to database
$dbHandler->close();

// Query logs back
echo "\nQuerying logs from database:\n";
$logs = DatabaseHandler::query($pdo, ['limit' => 10]);
foreach ($logs as $log) {
    echo sprintf(
        "  [%s] %s: %s\n",
        $log['level'],
        $log['channel'],
        $log['message']
    );
}

// Query with filters
echo "\nQuerying ERROR level only:\n";
$errorLogs = DatabaseHandler::query($pdo, ['level' => 'ERROR']);
foreach ($errorLogs as $log) {
    echo sprintf("  %s: %s\n", $log['level'], $log['message']);
}

echo "\n";

// -----------------------------------------------------------------------------
// Example 2: Redis Logging (simulated)
// -----------------------------------------------------------------------------
echo "--- Example 2: Redis Logging ---\n\n";

echo "Redis handler supports three strategies:\n";
echo "  - 'list': RPUSH to a Redis list (FIFO, good for processing queues)\n";
echo "  - 'pubsub': PUBLISH to a channel (real-time streaming)\n";
echo "  - 'stream': XADD to a stream (Redis 5.0+, best for log aggregation)\n\n";

echo "Example code:\n";
echo <<<'CODE'
$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);

// List-based logging
$listHandler = new RedisHandler($redis, 'logs:app', Level::Debug, true, 'list');

// Stream-based with automatic trimming
$streamHandler = new RedisHandler(
    $redis,
    'logs:app',
    Level::Debug,
    true,
    'stream',
    maxLength: 10000
);

// Create consumer group for stream processing
$streamHandler->createConsumerGroup('log-processors', '0');

// Read as consumer
$entries = $streamHandler->readAsConsumer('log-processors', 'worker-1', 100);
CODE;

echo "\n\n";

// -----------------------------------------------------------------------------
// Example 3: Webhook Alerting
// -----------------------------------------------------------------------------
echo "--- Example 3: Webhook Alerting ---\n\n";

echo "Slack webhook example:\n";
echo <<<'CODE'
$slackHandler = WebhookHandler::slack(
    webhookUrl: 'https://hooks.slack.com/services/T00/B00/xxx',
    channel: '#alerts',
    username: 'Production Logger',
    iconEmoji: ':warning:',
    level: Level::Error
);
CODE;

echo "\n\nDiscord webhook example:\n";
echo <<<'CODE'
$discordHandler = WebhookHandler::discord(
    webhookUrl: 'https://discord.com/api/webhooks/xxx/yyy',
    username: 'Alert Bot',
    avatarUrl: 'https://example.com/bot-avatar.png',
    level: Level::Error
);
CODE;

echo "\n\nMicrosoft Teams example:\n";
echo <<<'CODE'
$teamsHandler = WebhookHandler::teams(
    webhookUrl: 'https://outlook.office.com/webhook/xxx',
    title: 'Production Alerts',
    level: Level::Error
);
CODE;

echo "\n\nCustom webhook with auth:\n";
echo <<<'CODE'
$customHandler = new WebhookHandler(
    url: 'https://api.example.com/logs',
    headers: [
        'Authorization' => 'Bearer your-token',
        'X-Custom-Header' => 'value'
    ],
    level: Level::Warning,
    timeout: 10,
    verifySSL: true
);
CODE;

echo "\n\n";

// -----------------------------------------------------------------------------
// Example 4: Async Logging
// -----------------------------------------------------------------------------
echo "--- Example 4: Async Logging ---\n\n";

echo "AsyncHandler wraps slow handlers for non-blocking logging:\n\n";

// Demo with a stdout handler (simulating slow handler)
$slowHandler = new StreamHandler('php://stdout', Level::Debug);
$slowHandler->setFormatter(new JsonFormatter());

$asyncHandler = new AsyncHandler($slowHandler, AsyncHandler::STRATEGY_SHUTDOWN);
$asyncLogger = new Logger('async-example', [$asyncHandler]);

echo "Logging with AsyncHandler (shutdown strategy):\n";
$asyncLogger->info('This will be written after script ends', [
    'buffered' => true,
    'strategy' => 'shutdown'
]);

echo "Buffer size: " . $asyncHandler->getBufferSize() . " (will flush on shutdown)\n\n";

echo "Available strategies:\n";
echo "  - 'shutdown': Write in register_shutdown_function (default, safest)\n";
echo "  - 'fastcgi': Use fastcgi_finish_request() then write (PHP-FPM only)\n";
echo "  - 'fork': Fork child process (requires pcntl, inherits resources)\n\n";

echo "Example combining async with webhook:\n";
echo <<<'CODE'
// Wrap webhook in async to avoid blocking on HTTP
$webhookHandler = WebhookHandler::slack($url, '#alerts');
$asyncWebhook = new AsyncHandler($webhookHandler, 'shutdown');
$logger->addHandler($asyncWebhook);
CODE;

echo "\n\n";

// -----------------------------------------------------------------------------
// Example 5: Complete production setup
// -----------------------------------------------------------------------------
echo "--- Example 5: Complete Production Setup ---\n\n";

echo "Combining all handlers:\n";
echo <<<'CODE'
use Senza1dio\EnterprisePSR3Logger\Logger;
use Senza1dio\EnterprisePSR3Logger\Handlers\{
    RotatingFileHandler,
    DatabaseHandler,
    RedisHandler,
    WebhookHandler,
    AsyncHandler,
    FilterHandler
};

// File logging (all levels)
$fileHandler = new RotatingFileHandler(
    '/var/log/app/app.log',
    Level::Debug,
    'daily',
    maxFiles: 14,
    compress: true
);

// Database logging (info and above)
$dbHandler = new DatabaseHandler($pdo, 'logs', Level::Info, batchSize: 50);
$asyncDbHandler = new AsyncHandler($dbHandler, 'shutdown');

// Redis stream for real-time processing
$redisHandler = new RedisHandler($redis, 'logs:app', Level::Debug, true, 'stream');

// Slack alerts (errors only)
$slackHandler = WebhookHandler::slack($slackUrl, '#alerts', level: Level::Error);
$asyncSlackHandler = new AsyncHandler($slackHandler, 'shutdown');

// Combine all handlers
$logger = new Logger('production', [
    $fileHandler,           // Sync file logging
    $asyncDbHandler,        // Async database logging
    $redisHandler,          // Sync Redis (fast)
    $asyncSlackHandler,     // Async Slack alerts
]);

// Add processors
$logger->addProcessor(new RequestProcessor());
$logger->addProcessor(new HostnameProcessor(environment: 'production'));
$logger->addProcessor(new ExecutionTimeProcessor());
CODE;

echo "\n\n=== Examples Complete ===\n";

// The async handler will flush on shutdown
