# Testing Documentation

## Overview

Enterprise PSR-3 Logger has comprehensive test coverage with **198 tests** and **976 assertions**.

## Test Structure

```
tests/
├── LoggerTest.php           # Core Logger class tests
├── LoggerManagerTest.php    # Multi-channel management tests
├── HandlersTest.php         # All handler tests
├── WebhookHandlerTest.php   # SSRF, Slack, Discord, Teams tests
├── FormatterTest.php        # All formatter tests
├── ProcessorsTest.php       # All processor tests
├── SecurityTest.php         # Security-specific tests
├── LogParsingTest.php       # Log format parsing tests
├── RealFileLoggingTest.php  # Real file I/O tests
├── RealWorldTest.php        # Integration scenarios
└── IntegrationTest.php      # End-to-end tests
```

## Running Tests

```bash
# Run all tests
vendor/bin/phpunit

# Run specific test file
vendor/bin/phpunit tests/SecurityTest.php

# Run specific test method
vendor/bin/phpunit --filter=testSsrfProtection

# Run with coverage (requires xdebug or pcov)
vendor/bin/phpunit --coverage-html coverage/

# Run with verbose output
vendor/bin/phpunit --testdox
```

## Test Categories

### Security Tests (SecurityTest.php)

| Test | Description |
|------|-------------|
| `testPathTraversalBlocked` | Blocks `../` in file paths |
| `testNullByteBlocked` | Blocks null bytes in paths |
| `testLogInjectionSanitized` | Newlines stripped from messages |
| `testCircularReferenceHandled` | No infinite loops in context |
| `testExceptionNormalization` | Safe exception serialization |
| `testRateLimiting` | Rate limiter works correctly |

### SSRF Tests (WebhookHandlerTest.php)

| Test | Description |
|------|-------------|
| `testRejectsInternalIpAddresses` | Blocks 10.x.x.x |
| `testRejectsPrivateNetworkRanges` | Blocks 192.168.x.x, 172.16.x.x |
| `testRejectsCloudMetadataEndpoint` | Blocks 169.254.169.254 |
| `testRejectsHttpWithoutLocalhost` | Requires HTTPS |
| `testRejectsLocalhostInProduction` | Blocks localhost when APP_ENV=production |
| `testAllowsLocalhostInDevelopment` | Allows localhost when APP_ENV=local |

### Log Parsing Tests (LogParsingTest.php)

| Test | Description |
|------|-------------|
| `testParsesNginxAccessLog200` | Parses Nginx 200 responses |
| `testParsesNginxAccessLog404` | Parses Nginx 404 as warning |
| `testParsesNginxAccessLog500` | Parses Nginx 500 as error |
| `testParsesNginxErrorLog` | Parses Nginx error log format |
| `testParsesPhpWarning` | Parses PHP Warning |
| `testParsesPhpFatalError` | Parses PHP Fatal error |
| `testParsesMixedLogFormats` | Handles multiple formats |

### Handler Tests (HandlersTest.php)

| Test | Description |
|------|-------------|
| `testStreamHandlerCreatesFile` | Basic file writing |
| `testRotatingFileHandlerDailyRotation` | Daily rotation |
| `testRotatingFileHandlerHourlyRotation` | Hourly rotation |
| `testFilterHandlerMinLevel` | Level filtering |
| `testGroupHandlerMultiple` | Multiple handlers |

### Formatter Tests (FormatterTest.php)

| Test | Description |
|------|-------------|
| `testJsonFormatterOutput` | Valid JSON output |
| `testLineFormatterOutput` | Single line format |
| `testPrettyFormatterColors` | Color codes present |
| `testNewlineSanitization` | Newlines escaped |
| `testUnicodeHandling` | UTF-8 preserved |

### Integration Tests (IntegrationTest.php)

| Test | Description |
|------|-------------|
| `testFullLoggingPipeline` | End-to-end logging |
| `testMultiChannelSetup` | Multiple channels |
| `testDatabaseHandlerBatching` | Database batching |
| `testDatabaseLogging` | PDO handler |

## Writing New Tests

### Test Template

```php
<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Tests;

use PHPUnit\Framework\TestCase;

class MyFeatureTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/psr3-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Cleanup
        array_map('unlink', glob($this->tempDir . '/*') ?: []);
        @rmdir($this->tempDir);
    }

    public function testMyFeature(): void
    {
        // Arrange
        $handler = new MyHandler($this->tempDir . '/test.log');

        // Act
        $handler->handle($this->createRecord());

        // Assert
        $this->assertFileExists($this->tempDir . '/test.log');
    }

    private function createRecord(
        string $message = 'Test',
        Level $level = Level::Info
    ): LogRecord {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: $level,
            message: $message,
            context: [],
            extra: []
        );
    }
}
```

### Environment Variables in Tests

```php
public function testWithEnvironment(): void
{
    // Set environment
    $_ENV['APP_ENV'] = 'production';

    try {
        // Your test
        $this->expectException(\InvalidArgumentException::class);
        new WebhookHandler('http://localhost/webhook');
    } finally {
        // Always cleanup
        unset($_ENV['APP_ENV']);
    }
}
```

### Testing Private Methods

```php
use ReflectionMethod;

public function testPrivateMethod(): void
{
    $object = new MyClass();

    $method = new ReflectionMethod(MyClass::class, 'privateMethod');
    $method->setAccessible(true);

    $result = $method->invoke($object, 'arg1', 'arg2');

    $this->assertEquals('expected', $result);
}
```

## CI/CD Integration

### GitHub Actions

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['8.1', '8.2', '8.3']

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: pdo_sqlite, redis

      - name: Install dependencies
        run: composer install --prefer-dist --no-progress

      - name: Run tests
        run: vendor/bin/phpunit --testdox

      - name: Run static analysis
        run: vendor/bin/phpstan analyse
```

## Coverage Requirements

Minimum coverage requirements:
- **Statements**: 80%
- **Branches**: 75%
- **Functions**: 85%
- **Lines**: 80%

Current coverage:
- **Tests**: 202
- **Assertions**: 982
- **Skipped**: 4 (require specific extensions)

## Performance Testing

```php
public function testPerformance(): void
{
    $logger = new Logger('perf');
    $handler = new StreamHandler('/dev/null');
    $logger->addHandler($handler);

    $start = microtime(true);

    for ($i = 0; $i < 10000; $i++) {
        $logger->info('Test message', ['i' => $i]);
    }

    $elapsed = microtime(true) - $start;

    // Should complete in under 1 second
    $this->assertLessThan(1.0, $elapsed);
}
```

## Debugging Failed Tests

```bash
# Verbose output
vendor/bin/phpunit --verbose

# Stop on first failure
vendor/bin/phpunit --stop-on-failure

# Debug output
vendor/bin/phpunit --debug

# Generate test log
vendor/bin/phpunit --testdox-text results.txt
```
