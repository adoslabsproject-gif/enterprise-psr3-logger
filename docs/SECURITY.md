# Security Documentation

## Overview

Enterprise PSR-3 Logger implements multiple layers of security hardening to achieve **A+ security rating**.

## Security Features

### 1. XSS Prevention

All HTML output is escaped using the `esc()` helper function:

```php
// Secure output escaping
echo esc($userInput); // Uses ENT_QUOTES | ENT_HTML5 | UTF-8
```

**Implementation:**
- `esc()` - General HTML escaping
- `esc_attr()` - Attribute escaping
- `esc_url()` - URL validation and escaping (only http, https, mailto allowed)

### 2. SSRF Protection (WebhookHandler)

The WebhookHandler blocks requests to internal networks to prevent Server-Side Request Forgery attacks.

**Blocked IP Ranges:**

| Range | Description |
|-------|-------------|
| `10.0.0.0/8` | Private Class A |
| `172.16.0.0/12` | Private Class B |
| `192.168.0.0/16` | Private Class C |
| `127.0.0.0/8` | Loopback |
| `169.254.0.0/16` | Link-local (AWS/GCP/Azure metadata) |
| `0.0.0.0/8` | Current network |
| `100.64.0.0/10` | Carrier-grade NAT |
| `224.0.0.0/4` | Multicast |
| `240.0.0.0/4` | Reserved |

**Example attack blocked:**
```php
// Attacker tries to access AWS credentials
$handler = new WebhookHandler('http://169.254.169.254/latest/meta-data/iam/');
// Throws: InvalidArgumentException - internal/private IP address
```

**Localhost Policy:**
- **Production**: Localhost blocked by default
- **Development**: Localhost allowed when `APP_ENV=local|dev|development|testing`
- **Override**: Set `WEBHOOK_ALLOW_LOCALHOST=true` to allow in production

### 3. DNS Fail-Closed

If DNS resolution fails, the WebhookHandler **blocks** the request instead of allowing it:

```php
// DNS failure = blocked (prevents DNS poisoning attacks)
$handler = new WebhookHandler('https://malicious-dns.example/webhook');
// Throws: InvalidArgumentException - hostname could not be resolved
```

### 4. SQL Injection Protection

**Table Name Validation:**
```php
// Blocked patterns
'pg_*'              // PostgreSQL system tables
'mysql.*'           // MySQL system tables
'information_schema' // Standard system schema
'sys.*'             // System tables
'sqlite_*'          // SQLite system tables

// Validation rules
- Must start with letter or underscore
- Only alphanumeric and underscore allowed
- Maximum 63 characters
```

**Prepared Statements:**
All queries use PDO prepared statements with named parameters.

### 5. Request ID Validation

Incoming request IDs from headers are validated to prevent log injection:

```php
// Pattern: /^[a-zA-Z0-9\-]{1,64}$/
// Only alphanumeric and hyphens, max 64 chars

// Invalid request IDs are replaced with generated UUIDs
$processor = new RequestProcessor('X-Request-ID');
```

### 6. CORS Origin Validation

The JS error endpoint validates origins instead of allowing wildcards:

```php
// Configure allowed origins
// .env
JS_ERROR_CORS_ORIGINS=https://example.com,https://app.example.com

// Wildcard (*) generates a security warning in logs
```

### 7. Token Protection

Sensitive tokens are never exposed in config outputs:

```php
$handler->getConfig();
// Returns:
// [
//     'bot_token' => '[REDACTED]',
//     'chat_id' => '****5678',  // Last 4 chars only
// ]
```

### 8. Rate Limiting

All API endpoints are protected with rate limiting:

```php
// Default: 30 requests per minute
$handler = new TelegramHandler(
    rateLimitPerMinute: 30
);
```

**Long-running process support:**
```php
// Reset rate limit state between requests (Swoole/RoadRunner)
TelegramHandler::resetRateLimitState();
```

### 9. CSPRNG Usage

All random values use cryptographically secure random bytes:

```php
// Request ID generation uses random_bytes(16)
// Formatted as UUID v4
```

### 10. Path Traversal Protection

All file handlers validate paths:

```php
// Blocked patterns
'../'           // Directory traversal
'\0'            // Null byte injection
'..\\' (Windows) // Windows traversal
```

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_ENV` | `production` | Environment (local, dev, testing, staging, production) |
| `APP_DEBUG` | `false` | Debug mode |
| `APP_KEY` | - | Encryption key for token storage |
| `WEBHOOK_ALLOW_LOCALHOST` | `false` | Allow localhost webhooks in production |
| `JS_ERROR_CORS_ORIGINS` | - | Comma-separated allowed CORS origins |
| `LOG_ALLOW_SYSTEM_LOGS` | `false` | Allow viewing system logs |

## Security Headers

When using the admin panel integration, these headers are recommended:

```php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Content-Security-Policy: default-src \'self\'');
header('Referrer-Policy: strict-origin-when-cross-origin');
```

## Reporting Vulnerabilities

Please report security vulnerabilities to: security@adoslabs.com

Do NOT open public issues for security vulnerabilities.

## Changelog

### v1.0.0 (2026-01-29)
- Initial security hardening
- SSRF protection with IP range blocking
- DNS fail-closed policy
- XSS prevention with esc() helper
- Request ID validation
- CORS origin validation
- Token redaction in config output
- Long-running process state reset
