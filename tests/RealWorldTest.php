<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Tests;

use AdosLabs\EnterprisePSR3Logger\Handlers\DatabaseHandler;
use AdosLabs\EnterprisePSR3Logger\Handlers\RotatingFileHandler;
use AdosLabs\EnterprisePSR3Logger\Handlers\StreamHandler;
use AdosLabs\EnterprisePSR3Logger\Logger;
use AdosLabs\EnterprisePSR3Logger\LoggerFactory;
use AdosLabs\EnterprisePSR3Logger\Processors\ExecutionTimeProcessor;
use AdosLabs\EnterprisePSR3Logger\Processors\MemoryProcessor;
use AdosLabs\EnterprisePSR3Logger\Processors\RequestProcessor;
use Monolog\Level;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Real-world integration tests with actual I/O operations
 *
 * These tests verify the logger works correctly with real:
 * - File system operations
 * - SQLite database
 * - Concurrent access patterns
 * - Large data volumes
 * - Error conditions
 */
class RealWorldTest extends TestCase
{
    private string $tempDir;
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/psr3-realworld-' . uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        // Close database connection
        $this->pdo = null;

        // Clean up temp files
        $this->recursiveDelete($this->tempDir);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursiveDelete($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function createSqliteDb(): PDO
    {
        if ($this->pdo === null) {
            $dbFile = $this->tempDir . '/test.db';
            $this->pdo = new PDO("sqlite:{$dbFile}");
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            DatabaseHandler::createTable($this->pdo, 'logs', 'sqlite');
        }

        return $this->pdo;
    }

    // =========================================================================
    // FILE SYSTEM TESTS
    // =========================================================================

    public function testRealFileWriting(): void
    {
        $logFile = $this->tempDir . '/app.log';
        $handler = new StreamHandler($logFile);
        $logger = new Logger('app', [$handler]);

        // Write multiple log entries
        $logger->debug('Debug message');
        $logger->info('Info message');
        $logger->warning('Warning message');
        $logger->error('Error message');

        $logger->close();

        // Verify file exists and contains content
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $this->assertStringContainsString('Debug message', $content);
        $this->assertStringContainsString('Info message', $content);
        $this->assertStringContainsString('Warning message', $content);
        $this->assertStringContainsString('Error message', $content);

        // Verify line count
        $lines = array_filter(explode("\n", $content));
        $this->assertGreaterThanOrEqual(4, count($lines));
    }

    public function testRotatingFileCreatesDateSuffixedFiles(): void
    {
        $baseFile = $this->tempDir . '/rotating.log';
        $handler = new RotatingFileHandler(
            filename: $baseFile,
            rotationType: RotatingFileHandler::ROTATION_DAILY,
        );

        $logger = new Logger('rotating', [$handler]);

        for ($i = 0; $i < 10; $i++) {
            $logger->info("Message $i");
        }

        $logger->close();

        // Should create file with date suffix
        $expectedFile = $this->tempDir . '/rotating-' . date('Y-m-d') . '.log';
        $this->assertFileExists($expectedFile);

        $content = file_get_contents($expectedFile);
        for ($i = 0; $i < 10; $i++) {
            $this->assertStringContainsString("Message $i", $content);
        }
    }

    public function testLargeVolumeFileLogging(): void
    {
        $logFile = $this->tempDir . '/large.log';
        $handler = new StreamHandler($logFile);
        $logger = new Logger('large', [$handler]);

        $messageCount = 10000;
        $start = microtime(true);

        for ($i = 0; $i < $messageCount; $i++) {
            $logger->info("Message number $i", [
                'index' => $i,
                'data' => str_repeat('x', 100),
            ]);
        }

        $logger->close();
        $duration = microtime(true) - $start;

        // Should complete in reasonable time
        $this->assertLessThan(10.0, $duration, "Writing $messageCount logs took too long: {$duration}s");

        // Verify file size is reasonable
        $fileSize = filesize($logFile);
        $this->assertGreaterThan(0, $fileSize);

        // Verify line count
        $lineCount = 0;
        $handle = fopen($logFile, 'r');
        while (fgets($handle) !== false) {
            $lineCount++;
        }
        fclose($handle);

        $this->assertGreaterThanOrEqual($messageCount, $lineCount);
    }

    public function testConcurrentFileAccess(): void
    {
        $logFile = $this->tempDir . '/concurrent.log';

        // Use a true single-line formatter
        $singleLineFormatter = new \AdosLabs\EnterprisePSR3Logger\Formatters\LineFormatter(
            format: \AdosLabs\EnterprisePSR3Logger\Formatters\LineFormatter::COMPACT_FORMAT,
            ignoreEmptyContextAndExtra: true,
        );

        // Simulate concurrent access by creating multiple handlers
        $handlers = [];
        for ($i = 0; $i < 5; $i++) {
            $handler = new StreamHandler($logFile, useLocking: true);
            $handler->setFormatter($singleLineFormatter);
            $handlers[] = $handler;
        }

        // Write from all handlers
        foreach ($handlers as $index => $handler) {
            $logger = new Logger("process-$index", [$handler]);

            for ($j = 0; $j < 100; $j++) {
                $logger->info("Message from process $index, iteration $j");
            }

            $logger->close();
        }

        // Verify file integrity
        $content = file_get_contents($logFile);
        $lines = array_filter(explode("\n", $content));

        // Should have 500 lines (5 handlers * 100 messages)
        $this->assertCount(500, $lines);

        // Verify no corrupted lines (each line should contain a message)
        $messageCount = 0;
        foreach ($lines as $line) {
            $this->assertNotEmpty($line);
            if (preg_match('/Message from process \d+, iteration \d+/', $line)) {
                $messageCount++;
            }
        }

        $this->assertEquals(500, $messageCount);
    }

    // =========================================================================
    // DATABASE TESTS
    // =========================================================================

    public function testDatabaseLogging(): void
    {
        $pdo = $this->createSqliteDb();
        $handler = new DatabaseHandler($pdo, 'logs');
        $logger = new Logger('db-test', [$handler]);

        // Write logs
        $logger->info('User logged in', ['user_id' => 123]);
        $logger->warning('Slow query detected', ['duration_ms' => 1500]);
        $logger->error('Database connection failed', ['host' => 'db.example.com']);

        $logger->close();

        // Query logs back
        $logs = DatabaseHandler::query($pdo, ['table' => 'logs']);

        $this->assertCount(3, $logs);

        // Verify order (most recent first by default)
        $this->assertEquals('Database connection failed', $logs[0]['message']);
        $this->assertEquals('Slow query detected', $logs[1]['message']);
        $this->assertEquals('User logged in', $logs[2]['message']);
    }

    public function testDatabaseQueryFilters(): void
    {
        $pdo = $this->createSqliteDb();
        $handler = new DatabaseHandler($pdo, 'logs');
        $logger = new Logger('filter-test', [$handler]);

        $logger->debug('Debug 1');
        $logger->info('Info 1');
        $logger->warning('Warning 1');
        $logger->error('Error 1');
        $logger->critical('Critical 1');

        $logger->close();

        // Query by level
        $errors = DatabaseHandler::query($pdo, [
            'table' => 'logs',
            'min_level' => Level::Error->value,
        ]);

        $this->assertCount(2, $errors);

        // Verify all returned logs are Error or Critical level
        $levels = array_column($errors, 'level');
        $this->assertContains('Error', $levels);
        $this->assertContains('Critical', $levels);

        // Query by search
        $warningLogs = DatabaseHandler::query($pdo, [
            'table' => 'logs',
            'search' => 'Warning',
        ]);

        $this->assertCount(1, $warningLogs);
        $this->assertStringContainsString('Warning', $warningLogs[0]['message']);
    }

    public function testDatabaseBatchInsert(): void
    {
        $pdo = $this->createSqliteDb();
        $handler = new DatabaseHandler($pdo, 'logs', batchSize: 50);
        $logger = new Logger('batch-test', [$handler]);

        // Write more than batch size
        for ($i = 0; $i < 120; $i++) {
            $logger->info("Batch message $i");
        }

        $logger->close();

        // Verify all records were inserted
        $logs = DatabaseHandler::query($pdo, ['table' => 'logs', 'limit' => 200]);
        $this->assertCount(120, $logs);
    }

    public function testDatabaseWithProcessors(): void
    {
        $pdo = $this->createSqliteDb();
        $handler = new DatabaseHandler($pdo, 'logs');
        $logger = new Logger('processor-test', [$handler]);

        $logger->addProcessor(new RequestProcessor());
        $logger->addProcessor(new MemoryProcessor());
        $logger->addProcessor(new ExecutionTimeProcessor());

        $logger->info('Message with processors');
        $logger->close();

        $logs = DatabaseHandler::query($pdo, ['table' => 'logs', 'limit' => 1]);

        $this->assertCount(1, $logs);

        // Verify extra contains processor data
        $extra = json_decode($logs[0]['extra'], true);
        $this->assertArrayHasKey('request_id', $extra);
        $this->assertArrayHasKey('memory_usage', $extra);
        $this->assertArrayHasKey('execution_time_ms', $extra);
    }

    public function testDatabaseRequestIdIndex(): void
    {
        $pdo = $this->createSqliteDb();
        $handler = new DatabaseHandler($pdo, 'logs');

        $processor = new RequestProcessor();
        $processor->setRequestId('test-request-123');

        $logger = new Logger('request-test', [$handler]);
        $logger->addProcessor($processor);

        $logger->info('First message');
        $logger->info('Second message');
        $logger->info('Third message');

        $logger->close();

        // Query by request ID
        $logs = DatabaseHandler::query($pdo, [
            'table' => 'logs',
            'request_id' => 'test-request-123',
        ]);

        $this->assertCount(3, $logs);
    }

    // =========================================================================
    // DATABASE BATCHING TESTS
    // =========================================================================

    public function testDatabaseHandlerBatching(): void
    {
        $pdo = $this->createSqliteDb();

        // Use DatabaseHandler with batchSize (built-in batching)
        $dbHandler = new DatabaseHandler($pdo, 'logs', batchSize: 10);

        $logger = new Logger('batch-test', [$dbHandler]);

        // Write less than batch size
        for ($i = 0; $i < 5; $i++) {
            $logger->info("Batched message $i");
        }

        // Database should be empty (not flushed yet - batchSize not reached)
        $logs = DatabaseHandler::query($pdo, ['table' => 'logs']);
        $this->assertCount(0, $logs);

        // Manually flush
        $dbHandler->flush();

        // Now database should have logs
        $logs = DatabaseHandler::query($pdo, ['table' => 'logs']);
        $this->assertCount(5, $logs);

        $logger->close();
    }

    public function testDatabaseHandlerAutoFlushOnBatchLimit(): void
    {
        $pdo = $this->createSqliteDb();

        // Use DatabaseHandler with small batchSize
        $dbHandler = new DatabaseHandler($pdo, 'logs', batchSize: 3);

        $logger = new Logger('auto-flush-test', [$dbHandler]);

        // Write exactly batch size - should auto-flush
        $logger->info('Message 1');
        $logger->info('Message 2');
        $logger->info('Message 3');

        // Database should have logs now (auto-flushed at batch limit)
        $logs = DatabaseHandler::query($pdo, ['table' => 'logs']);
        $this->assertCount(3, $logs);

        // Write more
        $logger->info('Message 4');
        $logger->info('Message 5');

        // Only 3 in DB (4 and 5 still buffered)
        $logs = DatabaseHandler::query($pdo, ['table' => 'logs']);
        $this->assertCount(3, $logs);

        // Flush remaining
        $dbHandler->flush();

        $logs = DatabaseHandler::query($pdo, ['table' => 'logs']);
        $this->assertCount(5, $logs);

        $logger->close();
    }

    // =========================================================================
    // ERROR CONDITION TESTS
    // =========================================================================

    public function testHandlesFilePermissionError(): void
    {
        // Try to write to a non-existent, non-creatable path
        $handler = new StreamHandler('/nonexistent/path/that/should/fail.log');
        $logger = new Logger('permission-test', [$handler]);

        // Should not throw, just fail silently
        $logger->info('This will fail');
        $logger->close();

        // Test passed if we got here without exception
        $this->assertTrue(true);
    }

    public function testHandlesDatabaseError(): void
    {
        $pdo = $this->createSqliteDb();

        // Drop the table to cause errors
        $pdo->exec('DROP TABLE IF EXISTS logs');

        $handler = new DatabaseHandler($pdo, 'logs');
        $logger = new Logger('db-error-test', [$handler]);

        // Should not throw, just fail silently
        $logger->info('This will fail');
        $logger->close();

        // Test passed if we got here without exception
        $this->assertTrue(true);
    }

    public function testRecoverFromTransientErrors(): void
    {
        $pdo = $this->createSqliteDb();

        // Create table after a few failed inserts
        $pdo->exec('DROP TABLE IF EXISTS logs');

        $handler = new DatabaseHandler($pdo, 'logs');
        $logger = new Logger('recovery-test', [$handler]);

        // These will fail
        $logger->info('Fail 1');
        $logger->info('Fail 2');

        // Now create table
        DatabaseHandler::createTable($pdo, 'logs', 'sqlite');

        // This should succeed
        $logger->info('Success 1');
        $logger->close();

        $logs = DatabaseHandler::query($pdo, ['table' => 'logs']);
        $this->assertCount(1, $logs);
        $this->assertEquals('Success 1', $logs[0]['message']);
    }

    // =========================================================================
    // PERFORMANCE BENCHMARKS
    // =========================================================================

    public function testDatabaseInsertPerformance(): void
    {
        $pdo = $this->createSqliteDb();
        $handler = new DatabaseHandler($pdo, 'logs', batchSize: 100);
        $logger = new Logger('perf-test', [$handler]);

        $count = 1000;
        $start = microtime(true);

        for ($i = 0; $i < $count; $i++) {
            $logger->info("Performance test message $i", [
                'index' => $i,
                'timestamp' => microtime(true),
            ]);
        }

        $logger->close();
        $duration = microtime(true) - $start;

        // Should complete in reasonable time
        $this->assertLessThan(5.0, $duration, "Inserting $count logs took too long: {$duration}s");

        // Calculate rate
        $rate = $count / $duration;
        $this->assertGreaterThan(100, $rate, "Insert rate too slow: $rate logs/sec");
    }

    public function testMemoryUsageUnderLoad(): void
    {
        $logFile = $this->tempDir . '/memory-test.log';
        $handler = new StreamHandler($logFile);
        $logger = new Logger('memory-test', [$handler]);

        $initialMemory = memory_get_usage(true);

        // Write a lot of logs with context
        for ($i = 0; $i < 10000; $i++) {
            $logger->info("Memory test message $i", [
                'data' => str_repeat('x', 1000),
                'array' => range(1, 100),
            ]);
        }

        $logger->close();

        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;

        // Memory increase should be reasonable (< 50MB)
        $this->assertLessThan(
            50 * 1024 * 1024,
            $memoryIncrease,
            'Memory usage increased too much: ' . round($memoryIncrease / 1024 / 1024, 2) . ' MB',
        );
    }

    // =========================================================================
    // FACTORY INTEGRATION TESTS
    // =========================================================================

    public function testProductionFactoryWithRealFiles(): void
    {
        $logger = LoggerFactory::production(
            channel: 'production-test',
            logDir: $this->tempDir,
            compress: false,
        );

        $logger->info('Production info');
        $logger->error('Production error');

        $logger->close();

        // Check main log file
        $mainFiles = glob($this->tempDir . '/production-test-*.log');
        $this->assertNotEmpty($mainFiles);

        // Check error log file
        $errorFiles = glob($this->tempDir . '/production-test-error-*.log');
        $this->assertNotEmpty($errorFiles);

        // Verify content
        $mainContent = file_get_contents($mainFiles[0]);
        $errorContent = file_get_contents($errorFiles[0]);

        $this->assertStringContainsString('Production info', $mainContent);
        $this->assertStringContainsString('Production error', $mainContent);
        $this->assertStringContainsString('Production error', $errorContent);
        $this->assertStringNotContainsString('Production info', $errorContent);
    }

    public function testMinimalFactoryWithRealFiles(): void
    {
        $logFile = $this->tempDir . '/minimal.log';
        $logger = LoggerFactory::minimal('minimal', $logFile, Level::Debug);

        $logger->debug('Minimal debug');
        $logger->info('Minimal info');

        $logger->close();

        $this->assertFileExists($logFile);
        $content = file_get_contents($logFile);
        $this->assertStringContainsString('Minimal debug', $content);
        $this->assertStringContainsString('Minimal info', $content);
    }
}
