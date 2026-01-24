# Database Schemas

This directory contains SQL schemas for the `DatabaseHandler`.

## Files

- `schema-mysql.sql` - MySQL/MariaDB schema
- `schema-postgresql.sql` - PostgreSQL schema with JSONB support
- `schema-sqlite.sql` - SQLite schema for development/testing

## Quick Start

### MySQL

```bash
mysql -u user -p database < database/schema-mysql.sql
```

### PostgreSQL

```bash
psql -U user -d database -f database/schema-postgresql.sql
```

### SQLite

```bash
sqlite3 logs.db < database/schema-sqlite.sql
```

## Programmatic Creation

You can also create the table programmatically:

```php
use Senza1dio\EnterprisePSR3Logger\Handlers\DatabaseHandler;

$pdo = new PDO('pgsql:host=localhost;dbname=app', 'user', 'pass');
DatabaseHandler::createTable($pdo, 'logs', 'pgsql');
```

## Level Values

| Level | Value | Description |
|-------|-------|-------------|
| DEBUG | 100 | Detailed debug information |
| INFO | 200 | Interesting events |
| NOTICE | 250 | Normal but significant events |
| WARNING | 300 | Exceptional occurrences that are not errors |
| ERROR | 400 | Runtime errors |
| CRITICAL | 500 | Critical conditions |
| ALERT | 550 | Action must be taken immediately |
| EMERGENCY | 600 | System is unusable |

## Querying Logs

```php
// Get recent errors
$logs = DatabaseHandler::query($pdo, [
    'min_level' => 400,  // ERROR and above
    'limit' => 100,
    'order' => 'desc'
]);

// Get logs for a specific request
$logs = DatabaseHandler::query($pdo, [
    'request_id' => 'abc-123-def'
]);

// Search in messages
$logs = DatabaseHandler::query($pdo, [
    'search' => 'connection failed',
    'from' => '2024-01-01',
    'to' => '2024-01-31'
]);
```
