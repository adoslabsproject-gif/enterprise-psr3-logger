# Advanced Features Documentation

## Overview

This document covers advanced features and utilities not covered in other documentation files.

## LoggerFactory Configurations

The `LoggerFactory` provides 7 pre-configured setups:

### 1. development()

Colorful terminal output for local development.

```php
$logger = LoggerFactory::development('my-app', useColors: true);
```

**Features:**
- PrettyFormatter with ANSI colors
- Outputs to stdout
- All log levels enabled
- Includes RequestProcessor, MemoryProcessor, ExecutionTimeProcessor

### 2. production()

JSON output with file rotation for production.

```php
$logger = LoggerFactory::production(
    channel: 'my-app',
    logDir: '/var/log/app',
    minLevel: Level::Info,
    maxFiles: 14,
    compress: true
);
```

**Features:**
- JsonFormatter output
- Daily rotating files
- Separate error log (errors kept 2x longer)
- RequestProcessor, HostnameProcessor, ExecutionTimeProcessor

### 3. container()

JSON to stdout for Docker/Kubernetes.

```php
$logger = LoggerFactory::container('my-app', environment: 'production');
```

**Features:**
- JSON output to stdout (for log aggregation)
- No file management needed
- Compact format (ignores empty context/extra)

### 4. minimal()

Simple single-file logging without rotation.

```php
$logger = LoggerFactory::minimal('my-app', '/var/log/app.log');
```

**Features:**
- DetailedLineFormatter (single line mode)
- File locking enabled
- No processors

### 5. full()

Everything enabled for comprehensive logging.

```php
$logger = LoggerFactory::full('my-app', '/var/log/app', alsoPrintToStdout: true);
```

**Features:**
- Multiple outputs: JSON log + human-readable log + error log
- Optional stdout printing
- All processors enabled
- 30-day JSON retention, 14-day text retention, 60-day error retention

### 6. udpSyslog()

Zero-overhead UDP syslog (~0.01ms per log).

```php
$logger = LoggerFactory::udpSyslog(
    channel: 'my-app',
    syslogHost: '127.0.0.1',
    syslogPort: 514,
    facility: UdpSyslogHandler::FACILITY_LOCAL0
);
```

**Features:**
- Fire-and-forget UDP (non-blocking)
- RFC 5424 compliant
- Perfect for high-throughput applications

### 7. redisBuffered()

Async Redis queue for background processing.

```php
$logger = LoggerFactory::redisBuffered(
    redis: $redis,
    channel: 'my-app',
    fallbackPath: '/var/log/app-fallback.log'
);
```

**Features:**
- Non-blocking Redis LPUSH (~0.1ms per log)
- Background worker writes to files
- Automatic fallback if Redis fails

### 8. hybrid()

Redis buffer + UDP syslog for best of both worlds.

```php
$logger = LoggerFactory::hybrid(
    redis: $redis,
    channel: 'my-app',
    syslogHost: '127.0.0.1',
    syslogPort: 514,
    fallbackPath: '/var/log/app-fallback.log'
);
```

**Features:**
- Primary: Redis buffer for persistent logs (all levels)
- Secondary: UDP syslog for real-time monitoring (warnings+)

## Security Utilities

### SecureErrorHandler

Sanitizes error messages for client responses while logging full details server-side.

```php
use AdosLabs\EnterprisePSR3Logger\Security\SecureErrorHandler;

$handler = new SecureErrorHandler(debug: false);

try {
    // ... code that might throw
} catch (\Throwable $e) {
    $response = $handler->handle($e, 'api_endpoint');
    // Returns: ['message' => 'Generic safe message', 'code' => 'ERROR_CODE']

    // Full error logged server-side automatically
}
```

**Sensitive Patterns Detected:**
| Pattern | Description |
|---------|-------------|
| File paths | `/path/to/file.php` |
| Database strings | `mysql:`, `pgsql:` |
| Stack traces | `#0 /path/to/file.php` |
| SQL errors | `SQLSTATE[xxxxx]` |
| Server info | `nginx`, `apache` |
| Credentials | `api_key`, `password`, `token` |
| IP addresses | `192.168.1.1` |

**Generic Error Messages:**

| Error Type | Safe Message |
|------------|--------------|
| Database | "A database error occurred. Please try again later." |
| Connection | "Unable to connect to the service. Please try again later." |
| Validation | "The provided data is invalid." |
| Permission | "You do not have permission to perform this action." |
| Not Found | "The requested resource was not found." |
| Timeout | "The operation timed out. Please try again." |
| Rate Limit | "Too many requests. Please wait before trying again." |
| Config | "A configuration error occurred. Please contact support." |
| Default | "An unexpected error occurred. Please try again later." |

**Methods:**
```php
// Get safe message for client
$safeMessage = $handler->getSafeMessage($exception);

// Get error code for client
$errorCode = $handler->getErrorCode($exception);

// Sanitize a string (replace sensitive patterns)
$sanitized = $handler->sanitize($errorMessage);
```

### RateLimiter

Enterprise-grade rate limiting with sliding window algorithm.

```php
use AdosLabs\EnterprisePSR3Logger\Security\RateLimiter;

$limiter = new RateLimiter();

// Check if request is allowed
$result = $limiter->attempt('user:123', 'default');
// Returns: [
//     'allowed' => true,
//     'remaining' => 59,
//     'reset_at' => 1706540460,
//     'retry_after' => null
// ]

if (!$result['allowed']) {
    http_response_code(429);
    header("Retry-After: {$result['retry_after']}");
    exit('Rate limit exceeded');
}
```

**Built-in Categories:**

| Category | Limit | Window | Use Case |
|----------|-------|--------|----------|
| `default` | 60 req | 1 min | General API endpoints |
| `sensitive` | 10 req | 1 min | Data modification |
| `test` | 5 req | 5 min | Test endpoints |
| `auth` | 5 req | 15 min | Login attempts |

**Storage Backends:**
- **Redis**: Primary (if available) - distributed rate limiting
- **Local Memory**: Fallback - per-process rate limiting

**Methods:**
```php
// Check only (doesn't increment counter)
$result = $limiter->check('key', 'category');

// Record a hit (increment counter)
$limiter->hit('key', 'category');

// Check and hit in one call (ATOMIC - recommended)
$result = $limiter->attempt('key', 'category');

// Check and hit with CUSTOM rate limit (not using predefined categories)
$result = $limiter->attemptWithLimit('key', maxRequests: 30, windowSeconds: 60);

// Clear rate limit for a key
$limiter->clear('key');
```

**Custom Rate Limits:**
```php
// Use attemptWithLimit() for rates that don't match predefined categories
$result = $limiter->attemptWithLimit(
    'telegram:' . $chatId,
    maxRequests: 30,    // Custom limit
    windowSeconds: 60   // 1 minute window
);
```

## Helper Functions

### XSS Prevention Helpers

```php
// General HTML escaping
echo esc($userInput);
// Uses: htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')

// Attribute escaping (alias for esc)
echo '<input value="' . esc_attr($value) . '">';

// URL validation and escaping
echo '<a href="' . esc_url($url) . '">Link</a>';
// Only allows: http, https, mailto
// Returns empty string for invalid URLs
```

## LoggerRegistry

Global logger access without dependency injection.

```php
use AdosLabs\EnterprisePSR3Logger\LoggerRegistry;

// Register loggers
LoggerRegistry::register($appLogger, 'app', setAsDefault: true);
LoggerRegistry::register($securityLogger, 'security');

// Get logger
$logger = LoggerRegistry::get('security');
$logger->warning('Failed login attempt');

// Get default logger
$defaultLogger = LoggerRegistry::get();

// Check if logger exists
if (LoggerRegistry::has('audit')) {
    // ...
}

// Get all registered channels
$channels = LoggerRegistry::getChannels();
// Returns: ['app', 'security']

// Change default channel
LoggerRegistry::setDefaultChannel('security');

// Clear all loggers (for testing)
LoggerRegistry::clear();
```

## LoggerManager

Manages multiple loggers with channel inheritance.

```php
use AdosLabs\EnterprisePSR3Logger\LoggerManager;

$manager = new LoggerManager();

// Configure default handler
$manager->setDefaultHandler(new StreamHandler('/var/log/app.log'));
$manager->addDefaultProcessor(new RequestProcessor());

// Configure channel-specific handlers
$manager->setChannelHandlers('security', [
    new StreamHandler('/var/log/security.log'),
    WebhookHandler::slack($slackUrl, '#security'),
]);

// Get loggers (created on demand)
$appLog = $manager->channel('app');
$securityLog = $manager->channel('security');

// Channel inheritance (additive)
// 'app.http' inherits from 'app'
$manager->setChannelHandlers('app', [$fileHandler]);
$manager->setChannelHandlers('app.http', [$httpHandler]);

$httpLog = $manager->channel('app.http.requests');
// Gets BOTH handlers: $fileHandler + $httpHandler

// Add context to all loggers
$manager->setGlobalContext(['app_version' => '1.2.3']);

// Close all loggers
$manager->closeAll();
```

**PERFORMANCE: Inheritance Cache**

LoggerManager caches inherited handlers/processors to avoid repeated string parsing.
Cache is automatically invalidated when configuration changes.

## Admin Panel Integration

### LoggerAdminModule

When `enterprise-admin-panel` is installed, the logger integrates automatically.

**Features:**
- Channel configuration UI
- Log file management (view, download, clear)
- Telegram notification configuration
- JavaScript error logging endpoint
- Database log viewer

**Routes Added:**

| Route | Method | Description |
|-------|--------|-------------|
| `/logger` | GET | Dashboard with channels and log files |
| `/logger/view` | GET | View specific log file |
| `/logger/channel/update` | POST | Update channel configuration |
| `/logger/file/clear` | POST | Clear a log file |
| `/logger/file/download` | GET | Download a log file |
| `/logger/telegram` | GET | Telegram configuration page |
| `/logger/telegram/update` | POST | Update Telegram settings |
| `/logger/telegram/test` | POST | Send test message |
| `/api/log/js-error` | POST | Log JavaScript errors (public) |

**Permissions:**
- `logger.view` - View logs and configuration
- `logger.configure` - Change channel levels
- `logger.clear` - Clear log files
- `logger.download` - Download log files

**Config Schema:**
```php
[
    'log_files_retention_days' => 30,    // Days to keep log files
    'log_database_retention_days' => 7,  // Days to keep database logs
]
```

## fromConfig() Factory Method

Create loggers from configuration arrays.

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
            'compress' => true,
            'formatter' => 'json',
        ],
        [
            'type' => 'stream',
            'path' => 'php://stderr',
            'level' => 'error',
        ],
        [
            'type' => 'udp_syslog',
            'host' => '127.0.0.1',
            'port' => 514,
            'app_name' => 'my-app',
            'max_message_size' => 65000,
            'structured_data' => true,
        ],
        [
            'type' => 'redis_buffer',
            'redis' => $redisInstance,
            'app_name' => 'my-app',
            'fallback_path' => '/var/log/fallback.log',
            'include_metadata' => true,
        ],
    ],
    'processors' => ['request', 'memory', 'execution_time', 'hostname'],
    'context' => [
        'app_version' => '1.2.3',
        'environment' => 'production',
    ],
]);
```

**Supported Handler Types:**
| Type | Description |
|------|-------------|
| `stream` | File or PHP stream |
| `rotating` | File rotation by date |
| `syslog` | System syslog |
| `errorlog` | PHP error_log() |
| `udp_syslog` | UDP syslog (fire-and-forget) |
| `redis_buffer` | Redis buffer for async processing |

**Supported Formatters:**
| Name | Formatter Class |
|------|-----------------|
| `json` | JsonFormatter |
| `line` | LineFormatter |
| `detailed` | DetailedLineFormatter |
| `pretty` | PrettyFormatter |

**Supported Processors:**
| Name | Processor Class |
|------|-----------------|
| `request` | RequestProcessor |
| `memory` | MemoryProcessor |
| `execution_time` | ExecutionTimeProcessor |
| `hostname` | HostnameProcessor |

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_ENV` | `production` | Environment (local, dev, testing, staging, production) |
| `APP_DEBUG` | `false` | Enable debug mode |
| `APP_KEY` | - | Encryption key for token storage |
| `WEBHOOK_ALLOW_LOCALHOST` | `false` | Allow localhost webhooks in production |
| `JS_ERROR_CORS_ORIGINS` | - | Comma-separated allowed CORS origins |
| `LOG_ALLOW_SYSTEM_LOGS` | `false` | Allow viewing system logs |
| `REDIS_HOST` | `localhost` | Redis host for rate limiter |
| `REDIS_PORT` | `6379` | Redis port |
| `REDIS_PASSWORD` | - | Redis password |
| `REDIS_TLS` | `false` | Enable TLS for Redis |
| `REDIS_DATABASE` | - | Redis database number |
