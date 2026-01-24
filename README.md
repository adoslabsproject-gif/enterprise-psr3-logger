# Enterprise PSR-3 Logger

PSR-3 compliant logging library built on Monolog with enterprise features.

## What This Package Does

- **PSR-3 compliant**: Works with any PSR-3 compatible code
- **Channel-based logging**: Multiple named loggers with inheritance
- **Multiple output formats**: JSON, human-readable, pretty-printed
- **File rotation**: Daily/hourly rotation with compression
- **Context enrichment**: Automatic request ID, memory, timing
- **Sampling**: Reduce log volume for high-traffic applications

## Installation

```bash
composer require senza1dio/enterprise-psr3-logger
```

## Quick Start

### Development (Colored Terminal Output)

```php
use Senza1dio\EnterprisePSR3Logger\LoggerFactory;

$logger = LoggerFactory::development('my-app');

$logger->info('Application started');
$logger->error('Database connection failed', [
    'host' => 'db.example.com',
    'error' => 'Connection refused',
]);
```

Output:
```
┌──────────────────────────────────────────────────────────────────────────────
│ 2024-01-15 10:30:00.123456 │ ERROR │ my-app
├──────────────────────────────────────────────────────────────────────────────
│ MESSAGE: Database connection failed
├──────────────────────────────────────────────────────────────────────────────
│ CONTEXT:
│   host ........... db.example.com
│   error .......... Connection refused
└──────────────────────────────────────────────────────────────────────────────
```

### Production (JSON to Rotating Files)

```php
$logger = LoggerFactory::production(
    channel: 'my-app',
    logDir: '/var/log/app',
    maxFiles: 14,
    compress: true
);

$logger->info('User logged in', ['user_id' => 123]);
```

Output (`/var/log/app/my-app-2024-01-15.log`):
```json
{"timestamp":"2024-01-15T10:30:00.123456+00:00","level":"info","channel":"my-app","message":"User logged in","context":{"user_id":123},"extra":{"request_id":"abc-123-def","hostname":"web-01"}}
```

### Container (JSON to stdout)

```php
$logger = LoggerFactory::container('my-app', environment: 'production');
```

## Manual Configuration

```php
use Senza1dio\EnterprisePSR3Logger\Logger;
use Senza1dio\EnterprisePSR3Logger\Handlers\StreamHandler;
use Senza1dio\EnterprisePSR3Logger\Handlers\RotatingFileHandler;
use Senza1dio\EnterprisePSR3Logger\Formatters\DetailedLineFormatter;
use Senza1dio\EnterprisePSR3Logger\Processors\RequestProcessor;
use Monolog\Level;

// Create handlers
$fileHandler = new RotatingFileHandler(
    filename: '/var/log/app.log',
    level: Level::Info,
    rotationType: RotatingFileHandler::ROTATION_DAILY,
    maxFiles: 14,
    compress: true
);
$fileHandler->setFormatter(new DetailedLineFormatter());

$stdoutHandler = new StreamHandler('php://stdout', Level::Debug);

// Create logger
$logger = new Logger('app', [$fileHandler, $stdoutHandler]);

// Add processors
$logger->addProcessor(new RequestProcessor());

// Use it
$logger->info('Hello world');
```

## Components

### Formatters

| Formatter | Description | Use Case |
|-----------|-------------|----------|
| `JsonFormatter` | JSON output, one object per line | Log aggregators (ELK, Loki) |
| `LineFormatter` | Single line with key=value | Simple log files |
| `DetailedLineFormatter` | Multi-line with metadata | Human-readable files |
| `PrettyFormatter` | Box-drawing with colors | Terminal/development |

### Handlers

| Handler | Description |
|---------|-------------|
| `StreamHandler` | Write to any PHP stream (file, stdout, etc.) |
| `RotatingFileHandler` | File rotation by date or size |
| `SyslogHandler` | System syslog |
| `ErrorLogHandler` | PHP error_log() |
| `FilterHandler` | Route logs by level range |
| `GroupHandler` | Send to multiple handlers |
| `BufferHandler` | Buffer logs and flush in batches |
| `AsyncHandler` | Async logging (shutdown, fork, fastcgi strategies) |
| `DatabaseHandler` | Write logs to database (MySQL, PostgreSQL, SQLite) |
| `RedisHandler` | Write logs to Redis (list, pubsub, stream strategies) |
| `WebhookHandler` | Send logs to webhooks (Slack, Discord, Teams, custom) |

### Processors

| Processor | Added Fields |
|-----------|--------------|
| `RequestProcessor` | request_id, http_method, url, ip, user_agent, referrer |
| `MemoryProcessor` | memory_usage, memory_peak, memory_percent |
| `ExecutionTimeProcessor` | execution_time_us (microseconds) |
| `HostnameProcessor` | hostname, environment, php_version |
| `ContextProcessor` | Custom static fields |

**RequestProcessor Security:**
```php
// Default: Only REMOTE_ADDR (safe for direct connections)
$processor = new RequestProcessor();

// Behind trusted proxy: enable X-Forwarded-For
$processor = new RequestProcessor(
    trustProxyHeaders: true,
    trustedProxyHeaders: ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR']
);

// Anonymize IP addresses (GDPR compliance)
$processor = new RequestProcessor(anonymizeIp: true);
```

## Multi-Channel Logging

```php
use Senza1dio\EnterprisePSR3Logger\LoggerManager;

$manager = new LoggerManager();

// Set default handler
$manager->setDefaultHandler(new StreamHandler('/var/log/app.log'));

// Set channel-specific handlers
$manager->setChannelHandlers('security', [
    new StreamHandler('/var/log/security.log'),
]);

// Get loggers
$appLog = $manager->channel('app');
$securityLog = $manager->channel('security');

// Channel inheritance: 'app.http' inherits from 'app'
$httpLog = $manager->channel('app.http');
```

## Sampling

Reduce log volume for high-traffic applications:

```php
$logger = new Logger('app');

// Log only 10% of debug messages
$logger->setLevelSamplingRate('debug', 0.1);

// Log only 50% of info messages
$logger->setLevelSamplingRate('info', 0.5);

// Always log warnings and above (default)
```

## Exception Logging

```php
try {
    // ...
} catch (\Exception $e) {
    $logger->error('Operation failed', [
        'exception' => $e,
        'operation' => 'user_creation',
    ]);
}
```

The `PrettyFormatter` and `DetailedLineFormatter` will display:
- Exception class name
- Message
- File and line
- Stack trace

## Log Separation by Level

```php
use Senza1dio\EnterprisePSR3Logger\Handlers\FilterHandler;

// Error log (ERROR and above)
$errorHandler = new FilterHandler(
    new StreamHandler('/var/log/error.log'),
    minLevel: Level::Error
);

// Info log (INFO to WARNING only)
$infoHandler = new FilterHandler(
    new StreamHandler('/var/log/info.log'),
    minLevel: Level::Info,
    maxLevel: Level::Warning
);

// Debug log (DEBUG only)
$debugHandler = new FilterHandler(
    new StreamHandler('/var/log/debug.log'),
    minLevel: Level::Debug,
    maxLevel: Level::Debug
);

$logger = new Logger('app', [$errorHandler, $infoHandler, $debugHandler]);
```

## Database Logging

```php
use Senza1dio\EnterprisePSR3Logger\Handlers\DatabaseHandler;

$pdo = new PDO('mysql:host=localhost;dbname=app', 'user', 'pass');

// Create table (run once)
DatabaseHandler::createTable($pdo, 'logs', 'mysql');

// Use handler
$handler = new DatabaseHandler($pdo, 'logs', batchSize: 50);
$logger->addHandler($handler);

// Query logs later
$logs = DatabaseHandler::query($pdo, [
    'channel' => 'app',
    'min_level' => 400, // Error and above
    'from' => '2024-01-01',
    'limit' => 100,
]);
```

## Redis Logging

```php
use Senza1dio\EnterprisePSR3Logger\Handlers\RedisHandler;

$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);

// List-based (for processing queues)
$handler = new RedisHandler($redis, 'logs:app');

// Stream-based (for log aggregation with consumer groups)
$handler = new RedisHandler($redis, 'logs:app', strategy: 'stream', maxLength: 10000);

// Pub/Sub (for real-time monitoring)
$handler = new RedisHandler($redis, 'logs:app', strategy: 'pubsub');
```

## Webhook Alerting

```php
use Senza1dio\EnterprisePSR3Logger\Handlers\WebhookHandler;
use Monolog\Level;

// Slack
$slackHandler = WebhookHandler::slack(
    webhookUrl: 'https://hooks.slack.com/services/xxx/yyy/zzz',
    channel: '#alerts',
    username: 'Logger Bot',
    level: Level::Error
);

// Discord
$discordHandler = WebhookHandler::discord(
    webhookUrl: 'https://discord.com/api/webhooks/xxx/yyy',
    username: 'Logger Bot'
);

// Microsoft Teams
$teamsHandler = WebhookHandler::teams(
    webhookUrl: 'https://outlook.office.com/webhook/xxx',
    title: 'Production Alerts'
);

// Custom webhook
$customHandler = new WebhookHandler(
    url: 'https://api.example.com/logs',
    headers: ['Authorization' => 'Bearer token']
);
```

## Async Logging

```php
use Senza1dio\EnterprisePSR3Logger\Handlers\AsyncHandler;

// Wrap any slow handler with AsyncHandler
$dbHandler = new DatabaseHandler($pdo);
$asyncHandler = new AsyncHandler($dbHandler, strategy: 'shutdown');

// Strategies:
// - 'shutdown': Write after response sent (default, safest)
// - 'fastcgi': Use fastcgi_finish_request() then write (PHP-FPM)
// - 'fork': Fork child process (requires pcntl, resource inheritance warning)

$logger->addHandler($asyncHandler);
```

## Configuration from Array

```php
$logger = LoggerFactory::fromConfig([
    'channel' => 'my-app',
    'handlers' => [
        [
            'type' => 'rotating',
            'path' => '/var/log/app.log',
            'level' => 'info',
            'rotation' => 'daily',
            'max_files' => 14,
            'formatter' => 'json',
        ],
        [
            'type' => 'stream',
            'path' => 'php://stderr',
            'level' => 'error',
        ],
    ],
    'processors' => ['request', 'memory', 'execution_time'],
    'context' => [
        'app_version' => '1.2.3',
    ],
]);
```

## Helper Functions

```php
use Senza1dio\EnterprisePSR3Logger\LoggerRegistry;
use function Senza1dio\EnterprisePSR3Logger\log_info;
use function Senza1dio\EnterprisePSR3Logger\log_error;
use function Senza1dio\EnterprisePSR3Logger\log_exception;

// Register logger globally
LoggerRegistry::register($logger, 'app', setAsDefault: true);

// Use helper functions
log_info('Application started');
log_error('Something went wrong', ['code' => 500]);
log_exception($exception, 'Failed to process request');
```

## Limitations

This package has the following limitations:

1. **File Rotation**
   - Rotation happens on write, not on schedule
   - Size check uses actual file size (multi-process safe) but has I/O overhead
   - Compression runs synchronously (blocks during gzip)
   - Multi-process rotation uses lock file (brief blocking possible)

2. **Performance**
   - Default handlers use synchronous I/O (blocking writes)
   - File locking may impact high-concurrency scenarios
   - AsyncHandler available for non-blocking writes (fork/shutdown/fastcgi strategies)

3. **Memory**
   - Context data depth is limited by `setMaxContextDepth()` (default: 10 levels)
   - Large contexts can cause memory issues
   - BufferHandler limits consecutive failures to prevent memory exhaustion

4. **AsyncHandler Caveats**
   - fork strategy requires pcntl extension
   - fork strategy inherits parent resources (DB connections, sockets)
   - shutdown strategy blocks after response (but after output sent)
   - Logs may be lost if PHP crashes before shutdown

5. **WebhookHandler**
   - Synchronous HTTP requests (blocking)
   - Recommend wrapping with AsyncHandler for production
   - Uses file_get_contents (no curl dependency)

6. **Sampling**
   - Random-based, not deterministic
   - Same request may have some logs sampled out
   - No request-level sampling (all or nothing per request)

7. **Security Considerations**
   - RequestProcessor defaults to REMOTE_ADDR only (safe)
   - Proxy headers (X-Forwarded-For) must be explicitly enabled
   - File handlers validate paths against directory traversal

## Security Features

- **Path traversal protection**: All file handlers validate paths to prevent `../` attacks
- **Null byte injection protection**: Paths are validated for null bytes
- **Log injection protection**: Newlines, ANSI sequences, and control characters are sanitized
- **Exception serialization**: Exceptions are serialized to arrays, not stored as objects
- **IP spoofing protection**: RequestProcessor defaults to REMOTE_ADDR only; proxy headers require explicit opt-in
- **Circular reference detection**: Context sanitization detects and handles object cycles
- **SQL injection protection**: DatabaseHandler validates table names and uses prepared statements
- **Chained exception support**: Previous exceptions are normalized recursively (with depth limit)

## Framework Compatibility

Tested compatible with:
- **WordPress**: Works as PSR-3 drop-in replacement for WP logging
- **Laravel**: Multi-channel support, context merging, Monolog backend
- **Symfony**: Direct Monolog compatibility via `getMonolog()`
- **Slim/Lumen**: Middleware-friendly with request context processors
- **Any PSR-3 compatible framework**

## Is This Enterprise-Grade?

### What Makes It Enterprise-Ready

1. **PSR-3 compliance** - Standard interface, works everywhere
2. **Security hardened** - Path traversal, log injection, IP spoofing protection
3. **Multi-channel architecture** - Separation of concerns with inheritance
4. **Context enrichment** - Request ID, memory, timing, hostname automatically added
5. **Log rotation** - Multi-process safe with file locking
6. **Error resilience** - Handlers continue if one fails, consecutive failure protection
7. **Multiple backends** - File, database (MySQL/PostgreSQL/SQLite), Redis, webhooks
8. **Async support** - AsyncHandler with fork/shutdown/fastcgi strategies
9. **Alerting** - WebhookHandler with Slack/Discord/Teams integration
10. **169 passing tests** - Including security, integration, and real file I/O tests

### What It Lacks for Full Enterprise

1. **No distributed tracing** - No OpenTelemetry/Jaeger integration
2. **No log aggregation** - No built-in ELK/Loki/Datadog clients (but JSON format is compatible)
3. **No encryption at rest** - Logs are plaintext
4. **No email handler** - Use external service or SMTP library
5. **Limited field testing** - Not battle-tested in high-traffic production yet

### Verdict

This package is **suitable for production use** in most PHP applications. It provides a solid foundation for logging with good security practices. For truly large-scale enterprise deployments, you would need to add:

- External log aggregation (ship logs to ELK/Loki)
- Async handlers for high-throughput scenarios
- Distributed tracing integration

## Requirements

**Required:**
- PHP 8.0+
- ext-json
- ext-pdo (for DatabaseHandler)
- monolog/monolog ^3.0
- psr/log ^3.0

**Optional:**
- ext-redis (for RedisHandler with phpredis)
- ext-pcntl (for AsyncHandler fork strategy)
- predis/predis (alternative Redis client)

## License

MIT
