# Handlers Documentation

## Overview

Enterprise PSR-3 Logger provides 12 specialized handlers for different logging needs.

## Core Handlers

### StreamHandler

Writes logs to any PHP stream (file, stdout, stderr, php://memory).

```php
use AdosLabs\EnterprisePSR3Logger\Handlers\StreamHandler;

// File
$handler = new StreamHandler('/var/log/app.log', Level::Info);

// Stdout
$handler = new StreamHandler('php://stdout', Level::Debug);

// Stderr
$handler = new StreamHandler('php://stderr', Level::Error);
```

**Security:**
- Path traversal protection (`../` blocked)
- Null byte injection protection
- Creates directories if needed (mode 0755)

### RotatingFileHandler

File rotation by date or size with optional compression.

```php
use AdosLabs\EnterprisePSR3Logger\Handlers\RotatingFileHandler;

// Daily rotation
$handler = new RotatingFileHandler(
    filename: '/var/log/app.log',
    rotationType: RotatingFileHandler::ROTATION_DAILY,
    maxFiles: 14,
    compress: true
);

// Hourly rotation
$handler = new RotatingFileHandler(
    filename: '/var/log/app.log',
    rotationType: RotatingFileHandler::ROTATION_HOURLY,
    maxFiles: 24
);

// Size-based rotation
$handler = new RotatingFileHandler(
    filename: '/var/log/app.log',
    rotationType: RotatingFileHandler::ROTATION_NONE,
    maxFileSize: 10 * 1024 * 1024 // 10MB
);
```

**Features:**
- Multi-process safe (uses file locking)
- Automatic old file cleanup
- Gzip compression for rotated files

### DatabaseHandler

Write logs to database (MySQL, PostgreSQL, SQLite).

```php
use AdosLabs\EnterprisePSR3Logger\Handlers\DatabaseHandler;

$pdo = new PDO('mysql:host=localhost;dbname=app', 'user', 'pass');

// Create table (one-time setup)
DatabaseHandler::createTable($pdo, 'logs', 'mysql');

// Create handler
$handler = new DatabaseHandler(
    pdo: $pdo,
    table: 'logs',
    batchSize: 100 // Multi-row INSERT for performance
);

// Query logs
$logs = DatabaseHandler::query($pdo, [
    'channel' => 'security',
    'min_level' => 400, // Error+
    'from' => '2026-01-01',
    'to' => '2026-01-31',
    'search' => 'login failed',
    'limit' => 100,
    'order' => 'desc'
]);
```

**Security:**
- Table name validation (blocks system tables)
- Prepared statements for all queries
- Multi-row INSERT for better performance

### RedisBufferHandler

Write logs to Redis with multiple strategies.

```php
use AdosLabs\EnterprisePSR3Logger\Handlers\RedisBufferHandler;

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

// List-based (FIFO queue for workers)
$handler = new RedisBufferHandler(
    redis: $redis,
    key: 'logs:app',
    strategy: 'list'
);

// Stream-based (with consumer groups)
$handler = new RedisBufferHandler(
    redis: $redis,
    key: 'logs:app',
    strategy: 'stream',
    maxLength: 10000
);

// Pub/Sub (real-time monitoring)
$handler = new RedisBufferHandler(
    redis: $redis,
    key: 'logs:app',
    strategy: 'pubsub'
);
```

### WebhookHandler

Send logs to webhooks with SSRF protection.

```php
use AdosLabs\EnterprisePSR3Logger\Handlers\WebhookHandler;

// Slack
$handler = WebhookHandler::slack(
    webhookUrl: 'https://hooks.slack.com/services/xxx/yyy/zzz',
    channel: '#alerts',
    username: 'Logger Bot',
    iconEmoji: ':warning:',
    level: Level::Error
);

// Discord
$handler = WebhookHandler::discord(
    webhookUrl: 'https://discord.com/api/webhooks/xxx/yyy',
    username: 'Logger Bot',
    avatarUrl: 'https://example.com/avatar.png'
);

// Microsoft Teams
$handler = WebhookHandler::teams(
    webhookUrl: 'https://outlook.office.com/webhook/xxx',
    title: 'Production Alerts'
);

// Custom webhook
$handler = new WebhookHandler(
    url: 'https://api.example.com/logs',
    headers: ['Authorization' => 'Bearer token'],
    timeout: 5,
    verifySSL: true
);
```

**Security:**
- SSRF protection (blocks internal IPs)
- DNS fail-closed (blocks unresolved hostnames)
- Localhost blocked in production
- HTTPS required (HTTP only for localhost in dev)

### TelegramHandler

Send logs to Telegram with rate limiting.

```php
use AdosLabs\EnterprisePSR3Logger\Handlers\TelegramHandler;

$handler = new TelegramHandler(
    botToken: '123456:ABC-xxx',
    chatId: '@mychannel',
    level: Level::Error,
    enabled: true,
    silent: false, // Set true for no notification sound
    rateLimitPerMinute: 30
);

// Send test message
$handler->sendTestMessage();

// Get config (tokens redacted)
$config = $handler->getConfig();

// Reset rate limit state (for long-running processes)
TelegramHandler::resetRateLimitState();
```

**Features:**
- Rate limiting (default 30/minute)
- HTML formatting with emojis
- Context truncation for large payloads
- Password visibility toggle in admin UI

## Routing Handlers

### FilterHandler

Route logs by level range.

```php
use AdosLabs\EnterprisePSR3Logger\Handlers\FilterHandler;

// Errors only
$errorHandler = new FilterHandler(
    new StreamHandler('/var/log/error.log'),
    minLevel: Level::Error
);

// Info to Warning (excludes errors)
$infoHandler = new FilterHandler(
    new StreamHandler('/var/log/info.log'),
    minLevel: Level::Info,
    maxLevel: Level::Warning
);

// Debug only
$debugHandler = new FilterHandler(
    new StreamHandler('/var/log/debug.log'),
    minLevel: Level::Debug,
    maxLevel: Level::Debug
);
```

### GroupHandler

Send to multiple handlers.

```php
use AdosLabs\EnterprisePSR3Logger\Handlers\GroupHandler;

$handler = new GroupHandler([
    new StreamHandler('/var/log/app.log'),
    new StreamHandler('php://stdout'),
    WebhookHandler::slack($url, '#logs')
]);
```

### BufferHandler

Buffer logs and flush in batches.

```php
use AdosLabs\EnterprisePSR3Logger\Handlers\BufferHandler;

$handler = new BufferHandler(
    new DatabaseHandler($pdo),
    bufferSize: 100,
    flushOnError: true,
    flushOnShutdown: true
);

// Manual flush
$handler->flush();

// Clear buffer without flushing
$handler->clear();
```

## Async Handlers

### AsyncHandler

Non-blocking logging with multiple strategies.

```php
use AdosLabs\EnterprisePSR3Logger\Handlers\AsyncHandler;

// Shutdown strategy (writes after response)
$handler = new AsyncHandler(
    new DatabaseHandler($pdo),
    strategy: 'shutdown'
);

// FastCGI strategy (uses fastcgi_finish_request)
$handler = new AsyncHandler(
    new WebhookHandler($url),
    strategy: 'fastcgi'
);

// Fork strategy (separate process, requires pcntl)
$handler = new AsyncHandler(
    new DatabaseHandler($pdo),
    strategy: 'fork'
);
```

**Strategies:**
| Strategy | When | Pros | Cons |
|----------|------|------|------|
| shutdown | Register shutdown handler | Safe, works everywhere | Blocks after response |
| fastcgi | Use fastcgi_finish_request() | Fast, non-blocking | PHP-FPM only |
| fork | Fork child process | True async | Requires pcntl, resource inheritance |

## System Handlers

### SyslogHandler

Write to system syslog.

```php
use AdosLabs\EnterprisePSR3Logger\Handlers\SyslogHandler;

$handler = new SyslogHandler(
    ident: 'myapp',
    facility: LOG_LOCAL0
);
```

### UdpSyslogHandler

Zero-overhead UDP syslog (fire and forget).

```php
use AdosLabs\EnterprisePSR3Logger\Handlers\UdpSyslogHandler;

$handler = new UdpSyslogHandler(
    host: 'syslog.example.com',
    port: 514,
    facility: 16, // local0
    hostname: gethostname(),
    appName: 'myapp'
);
```

**Features:**
- RFC 5424 compliant
- Non-blocking UDP (no wait for response)
- Automatic fallback to file on socket failure

### ErrorLogHandler

Write to PHP error_log.

```php
use AdosLabs\EnterprisePSR3Logger\Handlers\ErrorLogHandler;

$handler = new ErrorLogHandler(Level::Error);
```

## Handler Comparison

| Handler | Blocking | Reliable | Use Case |
|---------|----------|----------|----------|
| StreamHandler | Yes | Yes | Development, simple apps |
| RotatingFileHandler | Yes | Yes | Production file logging |
| DatabaseHandler | Yes | Yes | Queryable log storage |
| RedisBufferHandler | No | Yes | Log aggregation queues |
| WebhookHandler | Yes | No | Alert notifications |
| TelegramHandler | Yes | No | Instant notifications |
| SyslogHandler | Yes | Yes | System log integration |
| UdpSyslogHandler | No | No | High-volume logging |
| AsyncHandler | No | Varies | Wrap slow handlers |

## Best Practices

1. **Use RotatingFileHandler for production** - Prevents disk full
2. **Wrap slow handlers with AsyncHandler** - Non-blocking writes
3. **Use FilterHandler for level separation** - Separate error.log from info.log
4. **Use GroupHandler for multiple destinations** - File + Slack + Database
5. **Set appropriate batch sizes** - DatabaseHandler with batchSize: 100
6. **Enable compression for rotated files** - Saves disk space
