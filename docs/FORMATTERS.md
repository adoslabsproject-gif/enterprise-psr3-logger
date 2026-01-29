# Formatters Documentation

## Overview

Enterprise PSR-3 Logger provides 4 formatters for different output formats.

## Available Formatters

### JsonFormatter

Structured JSON output, one object per line. Ideal for log aggregators.

```php
use AdosLabs\EnterprisePSR3Logger\Formatters\JsonFormatter;

$formatter = new JsonFormatter(
    appendNewline: true,
    prettyPrint: false
);

$handler->setFormatter($formatter);
```

**Output:**
```json
{"timestamp":"2026-01-29T10:30:00.123456+01:00","level":"error","channel":"app","message":"Database error","context":{"query":"SELECT *","error":"Connection refused"},"extra":{"request_id":"abc-123"}}
```

**Features:**
- RFC 3339 timestamps
- Context and extra preserved as JSON
- Exception serialization with stack traces
- Unicode safe (JSON_UNESCAPED_UNICODE)

### LineFormatter

Single line with key=value pairs. Simple and grep-friendly.

```php
use AdosLabs\EnterprisePSR3Logger\Formatters\LineFormatter;

$formatter = new LineFormatter(
    format: "[%datetime%] %channel%.%level%: %message% %context%\n",
    dateFormat: 'Y-m-d H:i:s.u'
);
```

**Output:**
```
[2026-01-29 10:30:00.123456] app.ERROR: Database error {"query":"SELECT *"}
```

**Features:**
- Customizable format string
- Newline sanitization
- ANSI escape code stripping
- Control character removal

### DetailedLineFormatter

Multi-line format with metadata. Human-readable for files.

```php
use AdosLabs\EnterprisePSR3Logger\Formatters\DetailedLineFormatter;

$formatter = new DetailedLineFormatter(
    includeStackTraces: true,
    maxTraceDepth: 5
);
```

**Output:**
```
[2026-01-29 10:30:00.123456] [ERR] [app] [pid:12345] [mem:2MB]
  ▶ Database connection failed
  │ query = SELECT * FROM users
  │ error = Connection refused
  └ request_id = abc-123-def
```

**Features:**
- Level abbreviations (DBG, INF, WRN, ERR, CRT, ALT, EMG)
- Process ID and memory usage
- Unicode box-drawing characters
- Exception formatting with traces

### PrettyFormatter

Box-drawing with colors. Perfect for terminal/development.

```php
use AdosLabs\EnterprisePSR3Logger\Formatters\PrettyFormatter;

$formatter = new PrettyFormatter(
    useColors: true,
    includeStackTraces: true,
    maxTraceDepth: 5,
    keyPadding: 15
);
```

**Output (with colors):**
```
┌──────────────────────────────────────────────────────────────────────────────
│ 2026-01-29 10:30:00.123456 │ ERROR │ app
├──────────────────────────────────────────────────────────────────────────────
│ MESSAGE: Database connection failed
├──────────────────────────────────────────────────────────────────────────────
│ CONTEXT:
│   query ........... SELECT * FROM users
│   error ........... Connection refused
├──────────────────────────────────────────────────────────────────────────────
│ EXTRA:
│   request_id ..... abc-123-def
│   memory ......... 2.5 MB
└──────────────────────────────────────────────────────────────────────────────
```

**Color Mapping:**
| Level | Color |
|-------|-------|
| DEBUG | Gray |
| INFO | Cyan |
| NOTICE | Blue |
| WARNING | Yellow |
| ERROR | Red |
| CRITICAL | Magenta |
| ALERT | Red (bold) |
| EMERGENCY | White on Red |

## Formatter Comparison

| Formatter | Lines | Colors | Machine Readable | Human Readable |
|-----------|-------|--------|------------------|----------------|
| JsonFormatter | 1 | No | Yes | No |
| LineFormatter | 1 | No | Partial | Yes |
| DetailedLineFormatter | Multi | No | No | Yes |
| PrettyFormatter | Multi | Yes | No | Yes |

## Use Case Recommendations

| Scenario | Recommended Formatter |
|----------|----------------------|
| Production file logs | JsonFormatter |
| Log aggregators (ELK, Loki) | JsonFormatter |
| Development terminal | PrettyFormatter |
| Simple file logs | LineFormatter |
| Human-readable files | DetailedLineFormatter |
| Debugging | PrettyFormatter |

## Security Features

All formatters implement:

1. **Newline sanitization** - Prevents log injection
2. **ANSI stripping** - Removes escape sequences
3. **Control character removal** - Strips non-printable chars
4. **Exception normalization** - Safe serialization
5. **Circular reference detection** - Prevents infinite loops
6. **Context depth limiting** - Prevents memory exhaustion

## Custom Formatters

Create custom formatters by implementing `FormatterInterface`:

```php
use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

class MyFormatter implements FormatterInterface
{
    public function format(LogRecord $record): string
    {
        return sprintf(
            "[%s] %s: %s\n",
            $record->datetime->format('H:i:s'),
            strtoupper($record->level->name),
            $record->message
        );
    }

    public function formatBatch(array $records): string
    {
        $output = '';
        foreach ($records as $record) {
            $output .= $this->format($record);
        }
        return $output;
    }
}
```

## Examples

### Production Setup
```php
$handler = new RotatingFileHandler('/var/log/app.log');
$handler->setFormatter(new JsonFormatter());
```

### Development Setup
```php
$handler = new StreamHandler('php://stdout');
$handler->setFormatter(new PrettyFormatter(useColors: true));
```

### Dual Output
```php
$fileHandler = new StreamHandler('/var/log/app.log');
$fileHandler->setFormatter(new JsonFormatter());

$consoleHandler = new StreamHandler('php://stdout');
$consoleHandler->setFormatter(new PrettyFormatter());

$logger = new Logger('app', [$fileHandler, $consoleHandler]);
```
