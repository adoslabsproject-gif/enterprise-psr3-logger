# Enterprise PSR-3 Logger Documentation

## Quick Links

| Document | Description |
|----------|-------------|
| [SECURITY.md](SECURITY.md) | Security features, SSRF protection, XSS prevention |
| [PERFORMANCE.md](PERFORMANCE.md) | Performance optimizations, benchmarks |
| [HANDLERS.md](HANDLERS.md) | All 12 handlers with examples |
| [FORMATTERS.md](FORMATTERS.md) | All 4 formatters with examples |
| [PROCESSORS.md](PROCESSORS.md) | All 5 processors with examples |
| [TESTING.md](TESTING.md) | Test structure, running tests, CI/CD |
| [ADVANCED.md](ADVANCED.md) | LoggerFactory configs, SecureErrorHandler, RateLimiter, LoggerRegistry |

## Package Overview

Enterprise PSR-3 Logger is a **standalone** PSR-3 compliant logging library that works independently or integrates with the Enterprise Lightning Framework.

### Standalone Usage

```php
use AdosLabs\EnterprisePSR3Logger\LoggerFactory;

$logger = LoggerFactory::production('app', '/var/log/app');
$logger->info('Application started');
```

### With Enterprise Admin Panel

When `enterprise-admin-panel` is installed, additional features are available:
- UI for channel configuration
- Telegram bot configuration
- Log file viewer
- Nginx/Apache log parsing

### With Enterprise Bootstrap

When `enterprise-bootstrap` is installed:
- Intelligent `should_log()` function
- Multi-layer caching (~0.001μs per decision)
- Database-driven configuration

## Installation

```bash
# Standalone
composer require ados-labs/enterprise-psr3-logger

# With Admin Panel (optional)
composer require ados-labs/enterprise-admin-panel

# With Bootstrap (optional)
composer require ados-labs/enterprise-bootstrap
```

## Feature Matrix

| Feature | Standalone | +Admin Panel | +Bootstrap |
|---------|------------|--------------|------------|
| PSR-3 logging | Yes | Yes | Yes |
| File rotation | Yes | Yes | Yes |
| JSON/Pretty output | Yes | Yes | Yes |
| Telegram notifications | Yes | UI Config | UI Config |
| Channel filtering | ENV vars | Database UI | Cached DB |
| should_log() | Stub | Stub | Intelligent |
| Log viewer | No | Yes | Yes |
| Nginx/Apache parsing | No | Yes | Yes |

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    YOUR APPLICATION                          │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  ┌──────────────────┐     ┌──────────────────────────────┐  │
│  │ Logger           │────▶│ Handlers                     │  │
│  │ - log()          │     │ - StreamHandler              │  │
│  │ - error()        │     │ - RotatingFileHandler        │  │
│  │ - info()         │     │ - DatabaseHandler            │  │
│  └────────┬─────────┘     │ - WebhookHandler             │  │
│           │               │ - TelegramHandler            │  │
│           ▼               └──────────────────────────────┘  │
│  ┌──────────────────┐                                        │
│  │ Processors       │     ┌──────────────────────────────┐  │
│  │ - RequestProc.   │────▶│ Formatters                   │  │
│  │ - MemoryProc.    │     │ - JsonFormatter              │  │
│  │ - HostnameProc.  │     │ - PrettyFormatter            │  │
│  └──────────────────┘     │ - LineFormatter              │  │
│                           └──────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

## Security at a Glance

- SSRF protection (14 IP ranges blocked)
- XSS prevention (esc() helper)
- SQL injection protection (prepared statements)
- Path traversal protection
- Log injection prevention
- Request ID validation
- CORS origin validation
- Token redaction in configs

## Performance at a Glance

- O(1) context merging (spread operator)
- O(1) error level lookup (hash map)
- Static function caching (should_log)
- Early exits in hot paths
- No O(n) loops in withContext/withChannel
- Multi-row INSERT for batch database logging

## Test Coverage

- **202 tests**
- **982 assertions**
- Security tests
- SSRF tests
- Log parsing tests
- Integration tests

## Support

- GitHub Issues: https://github.com/adoslabsproject-gif/enterprise-psr3-logger/issues
- Security: security@adoslabs.com

## License

MIT License - See [LICENSE](../LICENSE)

---

**Part of the Enterprise Lightning Framework**

Author: Nicola Cucurachi (ADOS Labs)
