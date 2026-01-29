# Enterprise PSR-3 Logger

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://php.net)

PSR-3 compliant logging library built on Monolog with **enterprise-grade configuration-based filtering**.

Part of the **Enterprise Lightning Framework** - integrates with admin-panel and bootstrap for UI-driven configuration.

**Author:** Nicola Cucurachi (ADOS Labs)

## 🎯 What This Package Does

- **PSR-3 compliant**: Works with any PSR-3 compatible code
- **Channel-based logging**: Multiple named loggers (security, api, database, etc.)
- **Configuration-driven filtering**: Uses `should_log()` function for dynamic log level control
- **Multiple output formats**: JSON, human-readable, pretty-printed
- **File rotation**: Daily/hourly rotation with compression
- **Context enrichment**: Automatic request ID, memory, timing
- **Telegram notifications**: Separate notification level from channel level with password visibility toggle
- **Admin panel integration**: UI for channel configuration when admin-panel is installed
- **JavaScript error logging**: Client-side error capture with rate limiting
- **Nginx/Apache log parsing**: View and search server access logs
- **PHP error log viewer**: Monitor PHP errors from admin panel

## 🏗️ Enterprise Framework Integration

This package integrates with the Enterprise Lightning Framework for UI-driven configuration:

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        YOUR APPLICATION                                  │
├─────────────────────────────────────────────────────────────────────────┤
│   ┌─────────────────────┐    ┌─────────────────────┐                   │
│   │ enterprise-admin-panel│◄───│ enterprise-psr3-logger (THIS PACKAGE) │
│   │                     │    │                     │                   │
│   │ • LogConfigService  │    │ • PSR-3 Logging     │                   │
│   │ • Channel Config UI │    │ • Calls should_log()│                   │
│   │ • Telegram Config   │    │ • Telegram Handler  │                   │
│   └─────────┬───────────┘    └──────────┬──────────┘                   │
│             │         INTEGRATION       │                               │
│             ▼                           ▼                               │
│   ┌─────────────────────────────────────────────────┐                   │
│   │            enterprise-bootstrap                  │                   │
│   │  • should_log() function (intelligent filter)   │                   │
│   │  • Multi-layer caching (~0.001μs per decision)  │                   │
│   └─────────────────────────────────────────────────┘                   │
└─────────────────────────────────────────────────────────────────────────┘
```

### Installation Order (RECOMMENDED)

```bash
# 1. FIRST: Admin Panel (creates log_channels table, provides UI)
composer require ados-labs/enterprise-admin-panel
php vendor/ados-labs/enterprise-admin-panel/setup/install.php \
    --driver=pgsql --host=localhost --database=myapp \
    --username=admin --password=secret

# 2. SECOND: Bootstrap (provides should_log() with multi-layer caching)
composer require ados-labs/enterprise-bootstrap

# 3. THIRD: PSR-3 Logger (THIS PACKAGE)
composer require ados-labs/enterprise-psr3-logger
php vendor/ados-labs/enterprise-psr3-logger/setup/install.php \
    --driver=pgsql --host=localhost --database=myapp \
    --username=admin --password=secret
```

### Integration Benefits

| Feature | Standalone | With Bootstrap | With Admin Panel |
|---------|------------|----------------|------------------|
| Logging | ✅ Works | ✅ Works | ✅ Works |
| `should_log()` | Stub (always true) | ✅ Intelligent filtering | ✅ Database-driven |
| Performance | Basic | ~0.001μs/decision | ~0.001μs/decision |
| Channel config | ENV vars only | ENV vars | ✅ UI + Database |
| Telegram | Manual config | Manual config | ✅ UI configuration |

## ⚠️ ENTERPRISE REQUIREMENT: `should_log()` Function

**CRITICAL**: This package requires a global `should_log()` function for production use.

### Why `should_log()` is Required

Enterprise applications need **dynamic log level configuration** without code changes:
- ✅ Change log levels from admin panel (no deploy needed)
- ✅ Enable debug logs temporarily for troubleshooting
- ✅ Reduce log volume in production (disable info/debug by default)
- ✅ Multi-level caching for ultra-fast filtering (~0.01μs overhead)

**Random sampling is NOT recommended** for enterprise applications because:
- ❌ You lose critical logs randomly (non-deterministic)
- ❌ No control over WHAT you log (just probability)
- ❌ Cannot enable/disable specific channels/levels dynamically

## 📦 Installation

```bash
composer require ados-labs/enterprise-psr3-logger

# Run installer to create logs table
php vendor/ados-labs/enterprise-psr3-logger/setup/install.php \
    --driver=pgsql --host=localhost --database=myapp \
    --username=admin --password=secret
```

## 🚀 Bootstrap Setup (REQUIRED for Production)

### Step 1: Define `should_log()` Function in GLOBAL Namespace

**CRITICAL**: The `should_log()` function MUST be defined in the **global namespace** (no `namespace` declaration).

Create this function in your bootstrap file that checks if a channel/level should be logged.

**Example: Simple Implementation**
```php
// bootstrap.php

// ⚠️ IMPORTANT: NO namespace declaration here!
// This function MUST be in the global namespace

/**
 * Check if logging is enabled for channel and level
 *
 * @param string $channel Channel name (e.g., 'default', 'security', 'api')
 * @param string $level PSR-3 log level (debug, info, notice, warning, error, critical, alert, emergency)
 * @return bool True if should log, false to skip
 */
function should_log(string $channel, string $level): bool
{
    // Simple example: Log only warnings and errors in production
    if (getenv('APP_ENV') === 'production') {
        return in_array($level, ['warning', 'error', 'critical', 'alert', 'emergency']);
    }

    // Development: Log everything
    return true;
}
```

**Example: Enterprise Implementation (Database-driven)**
```php
// bootstrap.php

function should_log(string $channel, string $level): bool
{
    static $cache = [];
    static $service = null;

    // Ultra-fast static cache (same process)
    $cacheKey = "{$channel}:{$level}";
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey]; // ~0.01μs - FASTEST
    }

    // Fetch from database configuration (with Redis cache)
    if ($service === null) {
        $service = new LoggingConfigService($pdo, $redis);
    }

    $result = $service->shouldLog($channel, $level);
    $cache[$cacheKey] = $result;

    return $result;
}
```

**Example: Full Enterprise Implementation**

See `examples/should_log.php` for a complete production-ready implementation with:
- Multi-layer cache (static → APCu → Redis → Database)
- Database configuration service
- Performance: ~0.01μs for cache hits (99% of calls)

### Step 2: Load `should_log()` BEFORE Composer Autoload

**CRITICAL ORDER**: Define `should_log()` BEFORE loading Composer autoload.

#### Option A: Define in Bootstrap (RECOMMENDED)

```php
// index.php

// 1. Define should_log() FIRST (before Composer autoload)
function should_log(string $channel, string $level): bool {
    // Your custom logic here
    if (getenv('APP_ENV') === 'production') {
        return in_array($level, ['warning', 'error', 'critical', 'alert', 'emergency']);
    }
    return true;
}

// 2. Load Composer autoload (includes PSR-3 logger)
require __DIR__ . '/vendor/autoload.php';

// 3. NOW create loggers (they will use your should_log() implementation)
use AdosLabs\EnterprisePSR3Logger\LoggerFactory;

$logger = LoggerFactory::production('app', '/var/log/app');
```

#### Option B: Separate Bootstrap File

```php
// bootstrap.php

// Define should_log() in global namespace
function should_log(string $channel, string $level): bool {
    // Your custom logic
    return true;
}
```

```php
// index.php

// 1. Load bootstrap FIRST
require __DIR__ . '/bootstrap.php';

// 2. Load Composer autoload
require __DIR__ . '/vendor/autoload.php';

// 3. Use loggers
use AdosLabs\EnterprisePSR3Logger\LoggerFactory;

$logger = LoggerFactory::production('app', '/var/log/app');
```

#### Option C: Use Composer Autoload Files (ADVANCED)

If you want Composer to load your `should_log()` automatically:

```json
// composer.json (your project, NOT the package)

{
    "autoload": {
        "files": [
            "app/Helpers/should_log.php"
        ]
    }
}
```

```php
// app/Helpers/should_log.php

function should_log(string $channel, string $level): bool {
    // Your custom logic
    return true;
}
```

Then run `composer dump-autoload` and your function will be loaded automatically.

### ⚠️ What if I Don't Define `should_log()`?

The package includes a **stub implementation** that always returns `true` (logs everything).

This is OK for development/testing, but **NOT recommended for production** because:
- ❌ No configuration-based filtering
- ❌ No dynamic log level control
- ❌ All logs written (high disk usage)

**You'll see a warning** in your error log:
```
[ENTERPRISE PSR-3 LOGGER] WARNING: should_log() function not found.
All logs will be written without configuration-based filtering.
Define should_log() in the GLOBAL namespace in your bootstrap for production use.
```

### Step 3: Use Channel-Based Logging

```php
use AdosLabs\EnterprisePSR3Logger\LoggerRegistry;

// Register channels
LoggerRegistry::register(LoggerFactory::production('default', '/var/log/app'), 'default');
LoggerRegistry::register(LoggerFactory::production('security', '/var/log/security'), 'security');
LoggerRegistry::register(LoggerFactory::production('api', '/var/log/api'), 'api');
LoggerRegistry::register(LoggerFactory::production('database', '/var/log/db'), 'database');

// Use it
$logger = LoggerRegistry::get('security');
$logger->warning('Failed login attempt', ['ip' => '1.2.3.4', 'username' => 'admin']);
// Calls: should_log('security', 'warning') internally
```

### ⚠️ What Happens Without `should_log()`

If you don't define `should_log()`, the logger will:
1. ✅ **Still work** (logs everything - no filtering)
2. ⚠️ **Emit a warning** on first use (visible in error_log)
3. ❌ **NOT filter logs** based on configuration (all logs written)

**This is OK for development/testing, but NOT recommended for production.**

---

## Quick Start

### Development (Colored Terminal Output)

```php
use AdosLabs\EnterprisePSR3Logger\LoggerFactory;

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
use AdosLabs\EnterprisePSR3Logger\Logger;
use AdosLabs\EnterprisePSR3Logger\Handlers\StreamHandler;
use AdosLabs\EnterprisePSR3Logger\Handlers\RotatingFileHandler;
use AdosLabs\EnterprisePSR3Logger\Formatters\DetailedLineFormatter;
use AdosLabs\EnterprisePSR3Logger\Processors\RequestProcessor;
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

## 🔄 Channel-Based Static API (LoggerFacade)

**NEW**: Use the `LoggerFacade` for **clean channel-based logging** with static methods.

### Why Use LoggerFacade?

- ✅ **Clean syntax**: `Logger::channel($level, $message, $context)`
- ✅ **Static methods**: No need to pass logger instances around
- ✅ **Channel-based**: `Logger::security()`, `Logger::api()`, `Logger::database()`
- ✅ **Simple**: One line to log to any channel

### Setup

```php
// bootstrap.php

// Step 1: Define should_log() (see above)
function should_log(string $channel, string $level): bool {
    // Your custom logic
    return true;
}

// Step 2: Load Composer autoload
require 'vendor/autoload.php';

// Step 3: Setup channels
use AdosLabs\EnterprisePSR3Logger\LoggerFactory;
use AdosLabs\EnterprisePSR3Logger\LoggerRegistry;

LoggerRegistry::register(LoggerFactory::production('default', '/var/log/app'), 'default');
LoggerRegistry::register(LoggerFactory::production('security', '/var/log/security'), 'security');
LoggerRegistry::register(LoggerFactory::production('api', '/var/log/api'), 'api');
LoggerRegistry::register(LoggerFactory::production('database', '/var/log/db'), 'database');
LoggerRegistry::register(LoggerFactory::production('email', '/var/log/email'), 'email');

// Step 4: Alias LoggerFacade as Logger
use AdosLabs\EnterprisePSR3Logger\LoggerFacade as Logger;
```

### Usage (Channel-Based Syntax)

```php
// Channel-based logging (clean syntax)
Logger::security('warning', 'Failed login attempt', [
    'ip' => '1.2.3.4',
    'username' => 'admin',
]);

Logger::api('info', 'HTTP Request', [
    'method' => 'GET',
    'uri' => '/api/users',
]);

Logger::database('error', 'Query failed', [
    'query' => 'SELECT * FROM users',
    'error' => 'Connection timeout',
]);

Logger::email('debug', 'Email sent', [
    'to' => 'user@example.com',
]);

// Convenience methods (channel = 'default')
Logger::error('Database connection failed');
Logger::warning('Cache miss detected');
Logger::info('User logged in');
Logger::debug('Session synced');
```

### Available Channels

| Method | Channel | Description |
|--------|---------|-------------|
| `Logger::default($level, $msg, $ctx)` | `default` | General application logs |
| `Logger::security($level, $msg, $ctx)` | `security` | Security events, auth, etc. |
| `Logger::api($level, $msg, $ctx)` | `api` | HTTP requests, API calls |
| `Logger::database($level, $msg, $ctx)` | `database` | Database queries, slow queries |
| `Logger::email($level, $msg, $ctx)` | `email` | Email sending, SMTP errors |
| `Logger::debug_general($level, $msg, $ctx)` | `debug_general` | Debug logs, workers |
| `Logger::performance($level, $msg, $ctx)` | `performance` | Performance metrics |
| `Logger::js_errors($level, $msg, $ctx)` | `js_errors` | Frontend JavaScript errors |
| `Logger::error_channel($level, $msg, $ctx)` | `error` | Application errors |
| `Logger::channel($ch, $lvl, $msg, $ctx)` | custom | Custom channel |

### Complete Example

See `examples/channel-syntax.php` for a complete working example with all features.

---

## Multi-Channel Logging (Standard PSR-3 API)

Alternatively, use the standard PSR-3 API for instance-based logging:

```php
use AdosLabs\EnterprisePSR3Logger\LoggerManager;

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

## ~~Sampling~~ (DEPRECATED - Use `should_log()` Instead)

**⚠️ DEPRECATED**: Random sampling is NOT recommended for enterprise applications.

**Why sampling is a bad idea:**
- ❌ Non-deterministic (you lose critical logs randomly)
- ❌ No control over WHAT you log (just probability)
- ❌ Cannot enable/disable specific channels/levels dynamically
- ❌ Makes debugging impossible ("this log appeared 10% of the time...")

**Use `should_log()` instead:**
```php
// ❌ OLD WAY (random sampling - CAZZATA)
$logger->setLevelSamplingRate('debug', 0.1); // Log 10% of debug randomly

// ✅ NEW WAY (configuration-based filtering)
function should_log(string $channel, string $level): bool {
    // Fetch from database configuration (admin panel can change it)
    return $config->isEnabled($channel, $level);
}

// Now you can:
// - Enable debug logs for 'security' channel only
// - Disable info logs in production
// - Enable all logs temporarily for troubleshooting
// - Change configuration without code deploy
```

**Migration:**
```php
// If you were using sampling, replace it with should_log()
// OLD:
$logger = new Logger('app');
$logger->setLevelSamplingRate('debug', 0.1);

// NEW:
// Define should_log() in bootstrap (see above)
$logger = new Logger('app'); // Automatically uses should_log()
```

## ❓ Frequently Asked Questions

### Q: Do all frameworks have a bootstrap?

**A: No**, not all frameworks have an explicit bootstrap file. Here's how to handle different scenarios:

**Laravel:**
```php
// bootstrap/app.php or config/app.php

function should_log(string $channel, string $level): bool {
    return config('logging.channels.' . $channel . '.level', 'debug') <= $level;
}
```

**Symfony:**
```php
// config/bootstrap.php

function should_log(string $channel, string $level): bool {
    return $_ENV['LOG_LEVEL'] === 'debug' || in_array($level, ['error', 'critical']);
}
```

**Custom Framework:**
```php
// public/index.php (BEFORE autoload)

function should_log(string $channel, string $level): bool {
    // Your logic
    return true;
}

require __DIR__ . '/../vendor/autoload.php';
```

**No Framework (Plain PHP):**
```php
// index.php

function should_log(string $channel, string $level): bool {
    return true; // Log everything in development
}

require 'vendor/autoload.php';

// Use logger
$logger = AdosLabs\EnterprisePSR3Logger\LoggerFactory::production('app', '/var/log');
```

### Q: Can I use a namespace for `should_log()`?

**A: Yes**, but it MUST be in the **global namespace** (`\should_log`).

**❌ WRONG:**
```php
namespace MyApp\Helpers;

function should_log(string $channel, string $level): bool {
    return true;
}
```

**✅ CORRECT:**
```php
// Option 1: No namespace declaration
function should_log(string $channel, string $level): bool {
    return true;
}

// Option 2: Explicitly declare global namespace
namespace {
    function should_log(string $channel, string $level): bool {
        return true;
    }
}
```

The logger calls `\should_log()` (fully qualified name) to ensure it finds your function.

### Q: What if I want different `should_log()` logic per channel?

**A: Implement it inside the function:**

```php
function should_log(string $channel, string $level): bool {
    // Channel-specific logic
    if ($channel === 'security') {
        return true; // Always log security events
    }

    if ($channel === 'api') {
        return in_array($level, ['warning', 'error', 'critical']);
    }

    // Default: Only errors
    return in_array($level, ['error', 'critical', 'alert', 'emergency']);
}
```

### Q: Can I change `should_log()` logic at runtime?

**A: Yes**, use a service that reads from database/cache:

```php
function should_log(string $channel, string $level): bool {
    static $service = null;

    if ($service === null) {
        $service = \App\Services\LoggingConfigService::getInstance();
    }

    return $service->shouldLog($channel, $level);
}
```

Your service can fetch configuration from database and cache it in Redis/APCu.

### Q: Is `should_log()` called for every log?

**A: Yes**, but with **ultra-fast caching**:

- **WITHOUT caching**: ~1-2μs per call (database query)
- **WITH static cache**: ~0.01μs per call (99% of calls)
- **WITH Redis cache**: ~0.1μs per call (cache miss)

See `examples/should_log.php` for full enterprise implementation with 3-layer cache.

### Q: How do I test my `should_log()` implementation?

**A: Run the included bootstrap test:**

```bash
# From package directory
php examples/bootstrap-test.php

# Expected output:
# ✅ should_log() defined in global namespace
# ✅ Composer autoload loaded
# ✅ should_log() exists in global namespace
# ✅ Logger created with channel: test-channel
# ✅ ALL TESTS PASSED
```

This verifies:
- Function is in global namespace
- Function is callable from any namespace
- Logger calls your function correctly
- Works with multiple channels

### Q: What if `should_log()` throws an exception?

**A: The logger catches it** and logs a warning, then allows the log through (fail-safe).

**Example:**
```php
function should_log(string $channel, string $level): bool {
    // Oops, database connection failed
    throw new \PDOException('Connection refused');
}

// Logger behavior:
// 1. Catches exception
// 2. Logs warning: "should_log() failed: Connection refused"
// 3. Returns TRUE (allows log to go through)
// 4. Continues normally (doesn't crash your app)
```

---

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
use AdosLabs\EnterprisePSR3Logger\Handlers\FilterHandler;

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
use AdosLabs\EnterprisePSR3Logger\Handlers\DatabaseHandler;

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
use AdosLabs\EnterprisePSR3Logger\Handlers\RedisHandler;

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
use AdosLabs\EnterprisePSR3Logger\Handlers\WebhookHandler;
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
use AdosLabs\EnterprisePSR3Logger\Handlers\AsyncHandler;

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
use AdosLabs\EnterprisePSR3Logger\LoggerRegistry;
use function AdosLabs\EnterprisePSR3Logger\log_info;
use function AdosLabs\EnterprisePSR3Logger\log_error;
use function AdosLabs\EnterprisePSR3Logger\log_exception;

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
- **CSRF protection**: All admin forms use stateless CSRF tokens (60-minute validity)
- **Rate limiting**: API endpoints protected against abuse (configurable limits)
- **AES-256-GCM encryption**: Telegram bot tokens encrypted at rest when APP_KEY is set
- **CSP compliance**: No inline scripts or styles in admin views
- **XSS protection**: All output escaped with `esc()` helper (ENT_QUOTES | ENT_HTML5 | UTF-8)
- **CORS origin validation**: JS error endpoint validates origins (configurable via `JS_ERROR_CORS_ORIGINS`)
- **System table protection**: DatabaseHandler blocks pg_, mysql., information_schema, sys., sqlite_ prefixes
- **CSPRNG**: Rate limiter uses `random_bytes()` for cryptographically secure unique IDs
- **Multi-row INSERT**: Batch database logging uses single INSERT for better performance

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
10. **Comprehensive test suite** - Including security, integration, and real file I/O tests
11. **Admin UI** - Web interface for channel configuration, Telegram setup, log viewing
12. **Server log parsing** - Nginx/Apache access log viewer with filtering

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
- PHP 8.1+
- ext-json
- ext-pdo (for DatabaseHandler)
- monolog/monolog ^3.0
- psr/log ^3.0

**Optional:**
- ext-redis (for RedisHandler with phpredis, recommended for production)
- ext-pcntl (for AsyncHandler fork strategy)
- predis/predis (alternative Redis client)

**Recommended Packages:**
- `ados-labs/enterprise-bootstrap` - Provides intelligent `should_log()` with multi-layer caching
- `ados-labs/enterprise-admin-panel` - Provides UI for channel configuration

## 🌟 Related Packages

| Package | Description | Integration |
|---------|-------------|-------------|
| **[ados-labs/enterprise-admin-panel](https://github.com/adoslabsproject-gif/enterprise-admin-panel)** | Admin interface with channel config UI | Provides LogConfigService + UI |
| **[ados-labs/enterprise-bootstrap](https://github.com/adoslabsproject-gif/enterprise-bootstrap)** | Application foundation | Provides `should_log()` with caching |
| **[ados-labs/enterprise-security-shield](https://github.com/adoslabsproject-gif/enterprise-security-shield)** | WAF, Honeypot, security | Logs security events |
| **[ados-labs/database-pool](https://github.com/adoslabsproject-gif/database-pool)** | Connection pooling | Logs connection events |

---

**Part of the Enterprise Lightning Framework**

## Author

**Nicola Cucurachi** - [ADOS Labs](https://github.com/adoslabsproject-gif)

## License

MIT - See [LICENSE](LICENSE) for details.
