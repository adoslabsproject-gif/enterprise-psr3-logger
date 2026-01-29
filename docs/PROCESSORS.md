# Processors Documentation

## Overview

Processors enrich log records with additional context automatically.

## Available Processors

### RequestProcessor

Adds HTTP request information to logs.

```php
use AdosLabs\EnterprisePSR3Logger\Processors\RequestProcessor;

$processor = new RequestProcessor(
    requestIdHeader: 'X-Request-ID',
    anonymizeIp: false,
    userAgentMaxLength: 200,
    trustProxyHeaders: false,
    trustedProxyHeaders: null
);

$logger->addProcessor($processor);
```

**Added Fields:**
| Field | Description |
|-------|-------------|
| `request_id` | Unique ID (from header or generated UUID v4) |
| `http_method` | GET, POST, PUT, DELETE, etc. |
| `url` | Request URI (truncated to 500 chars) |
| `ip` | Client IP address |
| `user_agent` | User agent (truncated to 200 chars) |
| `referrer` | HTTP referrer (if present) |

**Security Options:**

```php
// Default: Only trust REMOTE_ADDR (safe)
$processor = new RequestProcessor();

// Behind trusted proxy: enable X-Forwarded-For
$processor = new RequestProcessor(
    trustProxyHeaders: true,
    trustedProxyHeaders: [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR'
    ]
);

// GDPR compliance: anonymize IPs
$processor = new RequestProcessor(anonymizeIp: true);
// 192.168.1.100 becomes 192.168.1.0
// 2001:db8::1 becomes 2001:db8::0
```

**Request ID Validation:**
- Pattern: `/^[a-zA-Z0-9\-]{1,64}$/`
- Invalid IDs are replaced with generated UUIDs
- Prevents log injection attacks

### MemoryProcessor

Adds memory usage information.

```php
use AdosLabs\EnterprisePSR3Logger\Processors\MemoryProcessor;

$processor = new MemoryProcessor(
    realUsage: true,
    formatBytes: true
);
```

**Added Fields:**
| Field | Description |
|-------|-------------|
| `memory_usage` | Current memory usage |
| `memory_peak` | Peak memory usage |
| `memory_percent` | Percentage of memory limit |

**Output Examples:**
```php
// formatBytes: true
"memory_usage": "2.5 MB"
"memory_peak": "4.2 MB"
"memory_percent": "1.5%"

// formatBytes: false
"memory_usage": 2621440
"memory_peak": 4404019
"memory_percent": 1.5
```

### ExecutionTimeProcessor

Adds execution timing.

```php
use AdosLabs\EnterprisePSR3Logger\Processors\ExecutionTimeProcessor;

$processor = new ExecutionTimeProcessor();
```

**Added Fields:**
| Field | Description |
|-------|-------------|
| `execution_time_us` | Microseconds since request start |

**Note:** Uses `$_SERVER['REQUEST_TIME_FLOAT']` for accurate timing.

### HostnameProcessor

Adds server/environment information.

```php
use AdosLabs\EnterprisePSR3Logger\Processors\HostnameProcessor;

$processor = new HostnameProcessor(
    includeIp: true
);
```

**Added Fields:**
| Field | Description |
|-------|-------------|
| `hostname` | Server hostname |
| `server_ip` | Server IP (if includeIp: true) |
| `environment` | APP_ENV value |
| `php_version` | PHP version |

### ContextProcessor

Adds static context to all logs.

```php
use AdosLabs\EnterprisePSR3Logger\Processors\ContextProcessor;

$processor = new ContextProcessor([
    'app_name' => 'MyApp',
    'app_version' => '1.2.3',
    'deployment_id' => 'abc123'
]);
```

**Added Fields:** Whatever you pass in the constructor.

## Processor Stacking

Processors are applied in order (last added = first applied):

```php
$logger->addProcessor(new ContextProcessor(['app' => 'MyApp']));
$logger->addProcessor(new HostnameProcessor());
$logger->addProcessor(new MemoryProcessor());
$logger->addProcessor(new RequestProcessor());

// Log record will have fields from all processors
```

## CLI vs Web Context

Processors handle CLI context automatically:

```php
// In CLI
$processor = new RequestProcessor();
// Returns: ['request_id' => 'xxx', 'sapi' => 'cli']

// In Web
$processor = new RequestProcessor();
// Returns: ['request_id' => 'xxx', 'http_method' => 'GET', 'url' => '/', ...]
```

## Custom Processors

Create custom processors:

```php
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class UserProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $user = get_current_user(); // Your auth logic

        return $record->with(extra: [
            ...$record->extra,
            'user_id' => $user?->id,
            'user_email' => $user?->email,
        ]);
    }
}
```

## Best Practices

1. **Order matters** - Add general processors first, specific ones last
2. **Use RequestProcessor in web apps** - Essential for debugging
3. **Anonymize IPs for GDPR** - Use `anonymizeIp: true`
4. **Don't trust proxy headers by default** - Only enable with trusted proxies
5. **Keep context small** - Large contexts impact performance

## Performance Notes

- **RequestProcessor**: Uses SHARED static cache across ALL instances
  - Eliminates redundant $_SERVER parsing when multiple loggers exist
  - 20-50% CPU reduction for multi-logger apps
  - 1-second cache TTL (configurable)
  - Instances with custom settings use instance-level cache
  - Call `RequestProcessor::resetSharedCache()` for long-running processes
- **HostnameProcessor**: Caches hostname/IP (static values)
- **MemoryProcessor**: Calls memory_get_usage() each time (minimal overhead)
- **ExecutionTimeProcessor**: Simple microtime calculation

**IP Anonymization - Fail-Safe Design:**
- If IP parsing fails, returns safe placeholder (`0.0.0.0` or `::0`) instead of leaking original IP
- Unknown formats return `[ANONYMIZED]` marker

## Processor Comparison

| Processor | Fields | Performance | Use Case |
|-----------|--------|-------------|----------|
| RequestProcessor | 6 | Cached | Web apps |
| MemoryProcessor | 3 | Per-call | Memory debugging |
| ExecutionTimeProcessor | 1 | Per-call | Performance monitoring |
| HostnameProcessor | 4 | Cached | Multi-server deployments |
| ContextProcessor | Custom | Cached | Static metadata |
