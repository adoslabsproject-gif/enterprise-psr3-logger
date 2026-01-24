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

### Processors

| Processor | Added Fields |
|-----------|--------------|
| `RequestProcessor` | request_id, http_method, url, ip, user_agent |
| `MemoryProcessor` | memory_usage, memory_peak |
| `ExecutionTimeProcessor` | execution_time_ms |
| `HostnameProcessor` | hostname, server_ip, environment |
| `ContextProcessor` | Custom static fields |

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
   - Size check is approximate (checked before write)
   - No coordination between processes beyond file locking

2. **Performance**
   - Synchronous I/O (blocking writes)
   - File locking may impact high-concurrency scenarios
   - No async/non-blocking option

3. **Memory**
   - Context data is not size-limited by default
   - Large contexts can cause memory issues
   - BufferHandler can consume memory if not flushed

4. **Not Included**
   - Email/Slack/external service handlers
   - Log forwarding to remote servers
   - Log search/query capabilities
   - Log encryption

5. **Sampling**
   - Random-based, not deterministic
   - Same request may have some logs sampled out
   - No request-level sampling (all or nothing per request)

## Requirements

- PHP 8.0+
- ext-json
- monolog/monolog ^3.0
- psr/log ^3.0

## License

MIT
