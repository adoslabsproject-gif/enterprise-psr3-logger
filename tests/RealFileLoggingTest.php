<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Tests;

use Monolog\Level;
use PHPUnit\Framework\TestCase;
use AdosLabs\EnterprisePSR3Logger\Formatters\JsonFormatter;
use AdosLabs\EnterprisePSR3Logger\Formatters\LineFormatter;
use AdosLabs\EnterprisePSR3Logger\Handlers\FilterHandler;
use AdosLabs\EnterprisePSR3Logger\Handlers\GroupHandler;
use AdosLabs\EnterprisePSR3Logger\Handlers\RotatingFileHandler;
use AdosLabs\EnterprisePSR3Logger\Handlers\StreamHandler;
use AdosLabs\EnterprisePSR3Logger\Logger;
use AdosLabs\EnterprisePSR3Logger\LoggerManager;
use AdosLabs\EnterprisePSR3Logger\Processors\ExecutionTimeProcessor;
use AdosLabs\EnterprisePSR3Logger\Processors\MemoryProcessor;
use AdosLabs\EnterprisePSR3Logger\Processors\RequestProcessor;

/**
 * Real file logging tests
 *
 * These tests write REAL log files and verify:
 * - File content is correct
 * - Channels work properly
 * - Error levels are filtered correctly
 * - Exceptions are logged with full details
 * - Multi-channel scenarios work
 */
class RealFileLoggingTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/psr3-real-logs-' . uniqid();
        mkdir($this->logDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->logDir);
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

    private function readLogFile(string $path): string
    {
        return file_exists($path) ? file_get_contents($path) : '';
    }

    private function countLogLines(string $path): int
    {
        if (!file_exists($path)) {
            return 0;
        }
        $content = file_get_contents($path);

        return count(array_filter(explode("\n", $content)));
    }

    // =========================================================================
    // BASIC FILE LOGGING
    // =========================================================================

    public function testBasicFileLogging(): void
    {
        $logFile = $this->logDir . '/basic.log';

        $handler = new StreamHandler($logFile);
        $handler->setFormatter(new LineFormatter(
            LineFormatter::COMPACT_FORMAT,
            ignoreEmptyContextAndExtra: true,
        ));

        $logger = new Logger('app', [$handler]);

        $logger->debug('Debug message');
        $logger->info('Info message');
        $logger->warning('Warning message');
        $logger->error('Error message');
        $logger->critical('Critical message');

        $logger->close();

        // Verify file exists
        $this->assertFileExists($logFile);

        // Verify content
        $content = $this->readLogFile($logFile);

        $this->assertStringContainsString('Debug message', $content);
        $this->assertStringContainsString('Info message', $content);
        $this->assertStringContainsString('Warning message', $content);
        $this->assertStringContainsString('Error message', $content);
        $this->assertStringContainsString('Critical message', $content);

        // Verify level names are present
        $this->assertStringContainsString('Debug', $content);
        $this->assertStringContainsString('Info', $content);
        $this->assertStringContainsString('Warning', $content);
        $this->assertStringContainsString('Error', $content);
        $this->assertStringContainsString('Critical', $content);
    }

    public function testLogWithContext(): void
    {
        $logFile = $this->logDir . '/context.log';

        $handler = new StreamHandler($logFile);
        $handler->setFormatter(new JsonFormatter());

        $logger = new Logger('app', [$handler]);

        $logger->info('User action', [
            'user_id' => 12345,
            'action' => 'login',
            'ip' => '192.168.1.100',
            'timestamp' => time(),
        ]);

        $logger->close();

        $content = $this->readLogFile($logFile);

        // Parse JSON and verify
        $lines = array_filter(explode("\n", $content));
        $this->assertCount(1, $lines);

        $log = json_decode($lines[0], true);
        $this->assertNotNull($log);
        $this->assertEquals('User action', $log['message']);
        $this->assertEquals(12345, $log['context']['user_id']);
        $this->assertEquals('login', $log['context']['action']);
        $this->assertEquals('192.168.1.100', $log['context']['ip']);
    }

    // =========================================================================
    // EXCEPTION LOGGING
    // =========================================================================

    public function testExceptionLogging(): void
    {
        $logFile = $this->logDir . '/exceptions.log';

        $handler = new StreamHandler($logFile);
        $handler->setFormatter(new JsonFormatter(includeStacktraces: true));

        $logger = new Logger('app', [$handler]);

        try {
            throw new \RuntimeException('Something went wrong', 500);
        } catch (\Throwable $e) {
            $logger->error('Operation failed', ['exception' => $e]);
        }

        $logger->close();

        $content = $this->readLogFile($logFile);
        $log = json_decode(trim($content), true);

        $this->assertNotNull($log);
        $this->assertEquals('Operation failed', $log['message']);
        $this->assertEqualsIgnoringCase('Error', $log['level']);

        // Exception should be in context
        $this->assertArrayHasKey('exception', $log['context']);
        $this->assertStringContainsString('RuntimeException', $log['context']['exception']['class']);
        $this->assertStringContainsString('Something went wrong', $log['context']['exception']['message']);
    }

    public function testChainedExceptionLogging(): void
    {
        $logFile = $this->logDir . '/chained.log';

        $handler = new StreamHandler($logFile);
        $handler->setFormatter(new JsonFormatter(includeStacktraces: true));

        $logger = new Logger('app', [$handler]);

        try {
            try {
                throw new \InvalidArgumentException('Invalid input', 400);
            } catch (\Throwable $e) {
                throw new \RuntimeException('Processing failed', 500, $e);
            }
        } catch (\Throwable $e) {
            $logger->error('Request failed', ['exception' => $e]);
        }

        $logger->close();

        $content = $this->readLogFile($logFile);
        $log = json_decode(trim($content), true);

        $this->assertNotNull($log);
        $this->assertArrayHasKey('exception', $log['context']);

        // Check main exception
        $this->assertStringContainsString('RuntimeException', $log['context']['exception']['class']);
        $this->assertStringContainsString('Processing failed', $log['context']['exception']['message']);

        // Note: The JsonFormatter normalizer includes 'previous' in the exception data
        // Check the raw content for the previous exception mention
        $this->assertStringContainsString('Invalid input', $content);
    }

    public function testRealWorldExceptionScenario(): void
    {
        $logFile = $this->logDir . '/realworld-exception.log';

        $handler = new StreamHandler($logFile);
        $handler->setFormatter(new JsonFormatter());

        $logger = new Logger('api', [$handler]);

        // Simulate a real API error scenario
        $requestData = [
            'endpoint' => '/api/users/123',
            'method' => 'PUT',
            'payload' => ['name' => 'John', 'email' => 'invalid-email'],
        ];

        try {
            // Simulate validation error
            if (!filter_var($requestData['payload']['email'], FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Invalid email format');
            }
        } catch (\Throwable $e) {
            $logger->warning('API validation failed', [
                'exception' => $e,
                'request' => $requestData,
                'validation_errors' => ['email' => 'Invalid format'],
            ]);
        }

        $logger->close();

        $content = $this->readLogFile($logFile);
        $log = json_decode(trim($content), true);

        $this->assertEquals('API validation failed', $log['message']);
        $this->assertEquals('/api/users/123', $log['context']['request']['endpoint']);
        $this->assertArrayHasKey('validation_errors', $log['context']);
    }

    // =========================================================================
    // CHANNEL SEPARATION
    // =========================================================================

    public function testMultipleChannelsWriteToSeparateFiles(): void
    {
        $appLog = $this->logDir . '/app.log';
        $securityLog = $this->logDir . '/security.log';
        $auditLog = $this->logDir . '/audit.log';

        $formatter = new LineFormatter(LineFormatter::COMPACT_FORMAT, ignoreEmptyContextAndExtra: true);

        $appHandler = new StreamHandler($appLog);
        $appHandler->setFormatter($formatter);

        $securityHandler = new StreamHandler($securityLog);
        $securityHandler->setFormatter($formatter);

        $auditHandler = new StreamHandler($auditLog);
        $auditHandler->setFormatter($formatter);

        $appLogger = new Logger('app', [$appHandler]);
        $securityLogger = new Logger('security', [$securityHandler]);
        $auditLogger = new Logger('audit', [$auditHandler]);

        // Log to different channels
        $appLogger->info('Application started');
        $appLogger->debug('Loading configuration');

        $securityLogger->warning('Failed login attempt');
        $securityLogger->error('Brute force detected');

        $auditLogger->info('User created');
        $auditLogger->info('Permission changed');

        $appLogger->close();
        $securityLogger->close();
        $auditLogger->close();

        // Verify each file has correct content
        $appContent = $this->readLogFile($appLog);
        $this->assertStringContainsString('Application started', $appContent);
        $this->assertStringContainsString('Loading configuration', $appContent);
        $this->assertStringNotContainsString('Failed login', $appContent);
        $this->assertStringNotContainsString('User created', $appContent);

        $securityContent = $this->readLogFile($securityLog);
        $this->assertStringContainsString('Failed login attempt', $securityContent);
        $this->assertStringContainsString('Brute force detected', $securityContent);
        $this->assertStringNotContainsString('Application started', $securityContent);

        $auditContent = $this->readLogFile($auditLog);
        $this->assertStringContainsString('User created', $auditContent);
        $this->assertStringContainsString('Permission changed', $auditContent);
        $this->assertStringNotContainsString('Failed login', $auditContent);
    }

    public function testLoggerManagerChannelSeparation(): void
    {
        $mainLog = $this->logDir . '/main.log';
        $errorLog = $this->logDir . '/errors.log';

        $formatter = new LineFormatter(LineFormatter::COMPACT_FORMAT, ignoreEmptyContextAndExtra: true);

        $mainHandler = new StreamHandler($mainLog);
        $mainHandler->setFormatter($formatter);

        $errorHandler = new StreamHandler($errorLog);
        $errorHandler->setFormatter($formatter);

        $manager = new LoggerManager();
        $manager->setDefaultHandler($mainHandler);
        $manager->setChannelHandlers('errors', [$errorHandler]);

        $appLogger = $manager->channel('app');
        $errorLogger = $manager->channel('errors');

        $appLogger->info('Normal operation');
        $appLogger->warning('Minor issue');

        $errorLogger->error('Critical failure');
        $errorLogger->critical('System down');

        $manager->closeAll();

        // Main log should have app channel logs
        $mainContent = $this->readLogFile($mainLog);
        $this->assertStringContainsString('Normal operation', $mainContent);
        $this->assertStringContainsString('Minor issue', $mainContent);

        // Error log should have error channel logs
        $errorContent = $this->readLogFile($errorLog);
        $this->assertStringContainsString('Critical failure', $errorContent);
        $this->assertStringContainsString('System down', $errorContent);
    }

    // =========================================================================
    // LEVEL FILTERING
    // =========================================================================

    public function testErrorOnlyFileFilter(): void
    {
        $allLog = $this->logDir . '/all.log';
        $errorLog = $this->logDir . '/error-only.log';

        $formatter = new LineFormatter(LineFormatter::COMPACT_FORMAT, ignoreEmptyContextAndExtra: true);

        $allHandler = new StreamHandler($allLog);
        $allHandler->setFormatter($formatter);

        $errorOnlyHandler = new FilterHandler(
            new StreamHandler($errorLog),
            minLevel: Level::Error,
        );
        $errorOnlyHandler->getHandler()->setFormatter($formatter);

        $logger = new Logger('app', [$allHandler, $errorOnlyHandler]);

        $logger->debug('Debug message');
        $logger->info('Info message');
        $logger->warning('Warning message');
        $logger->error('Error message');
        $logger->critical('Critical message');

        $logger->close();

        // All log should have everything
        $allContent = $this->readLogFile($allLog);
        $this->assertStringContainsString('Debug message', $allContent);
        $this->assertStringContainsString('Info message', $allContent);
        $this->assertStringContainsString('Warning message', $allContent);
        $this->assertStringContainsString('Error message', $allContent);
        $this->assertStringContainsString('Critical message', $allContent);

        // Error log should only have error and above
        $errorContent = $this->readLogFile($errorLog);
        $this->assertStringNotContainsString('Debug message', $errorContent);
        $this->assertStringNotContainsString('Info message', $errorContent);
        $this->assertStringNotContainsString('Warning message', $errorContent);
        $this->assertStringContainsString('Error message', $errorContent);
        $this->assertStringContainsString('Critical message', $errorContent);
    }

    public function testLevelRangeFilter(): void
    {
        $warningLog = $this->logDir . '/warning-only.log';

        $formatter = new LineFormatter(LineFormatter::COMPACT_FORMAT, ignoreEmptyContextAndExtra: true);

        // Only Warning and Notice (not Error, not Info)
        $handler = new FilterHandler(
            new StreamHandler($warningLog),
            minLevel: Level::Notice,
            maxLevel: Level::Warning,
        );
        $handler->getHandler()->setFormatter($formatter);

        $logger = new Logger('app', [$handler]);

        $logger->debug('Debug');
        $logger->info('Info');
        $logger->notice('Notice');
        $logger->warning('Warning');
        $logger->error('Error');
        $logger->critical('Critical');

        $logger->close();

        $content = $this->readLogFile($warningLog);

        $this->assertStringNotContainsString('Debug', $content);
        $this->assertStringNotContainsString('Info', $content);
        $this->assertStringContainsString('Notice', $content);
        $this->assertStringContainsString('Warning', $content);
        $this->assertStringNotContainsString('Error', $content);
        $this->assertStringNotContainsString('Critical', $content);
    }

    // =========================================================================
    // ROTATING FILES
    // =========================================================================

    public function testRotatingFileByDate(): void
    {
        $baseFile = $this->logDir . '/rotating.log';

        $handler = new RotatingFileHandler(
            $baseFile,
            rotationType: RotatingFileHandler::ROTATION_DAILY,
        );

        $logger = new Logger('app', [$handler]);

        for ($i = 0; $i < 50; $i++) {
            $logger->info("Message $i");
        }

        $logger->close();

        // Should create file with today's date
        $expectedFile = $this->logDir . '/rotating-' . date('Y-m-d') . '.log';
        $this->assertFileExists($expectedFile);

        $content = $this->readLogFile($expectedFile);
        $this->assertStringContainsString('Message 0', $content);
        $this->assertStringContainsString('Message 49', $content);
    }

    // =========================================================================
    // PROCESSORS
    // =========================================================================

    public function testProcessorsAddMetadata(): void
    {
        $logFile = $this->logDir . '/processors.log';

        $handler = new StreamHandler($logFile);
        $handler->setFormatter(new JsonFormatter());

        $logger = new Logger('app', [$handler]);

        $requestProcessor = new RequestProcessor();
        $requestProcessor->setRequestId('test-req-123');

        $logger->addProcessor($requestProcessor);
        $logger->addProcessor(new MemoryProcessor());
        $logger->addProcessor(new ExecutionTimeProcessor());

        $logger->info('Test message');

        $logger->close();

        $content = $this->readLogFile($logFile);
        $log = json_decode(trim($content), true);

        $this->assertNotNull($log);

        // Check request_id
        $this->assertArrayHasKey('request_id', $log['extra']);
        $this->assertEquals('test-req-123', $log['extra']['request_id']);

        // Check memory
        $this->assertArrayHasKey('memory_usage', $log['extra']);

        // Check execution time
        $this->assertArrayHasKey('execution_time_ms', $log['extra']);
    }

    // =========================================================================
    // GROUP HANDLER
    // =========================================================================

    public function testGroupHandlerWritesToMultipleFiles(): void
    {
        $file1 = $this->logDir . '/group1.log';
        $file2 = $this->logDir . '/group2.log';
        $file3 = $this->logDir . '/group3.log';

        $formatter = new LineFormatter(LineFormatter::COMPACT_FORMAT, ignoreEmptyContextAndExtra: true);

        $h1 = new StreamHandler($file1);
        $h1->setFormatter($formatter);

        $h2 = new StreamHandler($file2);
        $h2->setFormatter($formatter);

        $h3 = new StreamHandler($file3);
        $h3->setFormatter($formatter);

        $groupHandler = new GroupHandler([$h1, $h2, $h3]);

        $logger = new Logger('app', [$groupHandler]);

        $logger->info('Broadcast message');
        $logger->error('Error broadcast');

        $logger->close();

        // All three files should have the same content
        $content1 = $this->readLogFile($file1);
        $content2 = $this->readLogFile($file2);
        $content3 = $this->readLogFile($file3);

        $this->assertStringContainsString('Broadcast message', $content1);
        $this->assertStringContainsString('Broadcast message', $content2);
        $this->assertStringContainsString('Broadcast message', $content3);

        $this->assertStringContainsString('Error broadcast', $content1);
        $this->assertStringContainsString('Error broadcast', $content2);
        $this->assertStringContainsString('Error broadcast', $content3);
    }

    // =========================================================================
    // GLOBAL CONTEXT
    // =========================================================================

    public function testGlobalContextAppliedToAllLogs(): void
    {
        $logFile = $this->logDir . '/global-context.log';

        $handler = new StreamHandler($logFile);
        $handler->setFormatter(new JsonFormatter());

        $logger = new Logger('app', [$handler]);
        $logger->setGlobalContext([
            'app_version' => '1.2.3',
            'environment' => 'testing',
            'server' => 'test-server-01',
        ]);

        $logger->info('First message');
        $logger->warning('Second message');
        $logger->error('Third message');

        $logger->close();

        $content = $this->readLogFile($logFile);
        $lines = array_filter(explode("\n", $content));

        foreach ($lines as $line) {
            $log = json_decode($line, true);
            $this->assertNotNull($log);

            // Global context should be in every log
            $this->assertEquals('1.2.3', $log['context']['app_version']);
            $this->assertEquals('testing', $log['context']['environment']);
            $this->assertEquals('test-server-01', $log['context']['server']);
        }
    }

    // =========================================================================
    // REAL-WORLD SCENARIOS
    // =========================================================================

    public function testCompleteApplicationLoggingScenario(): void
    {
        // Setup: separate logs for app, errors, and audit
        $appLog = $this->logDir . '/scenario-app.log';
        $errorLog = $this->logDir . '/scenario-errors.log';
        $auditLog = $this->logDir . '/scenario-audit.log';

        $jsonFormatter = new JsonFormatter();

        // App handler - all levels
        $appHandler = new StreamHandler($appLog);
        $appHandler->setFormatter($jsonFormatter);

        // Error handler - errors only
        $errorFilter = new FilterHandler(
            new StreamHandler($errorLog),
            minLevel: Level::Error,
        );
        $errorFilter->getHandler()->setFormatter($jsonFormatter);

        // Audit handler
        $auditHandler = new StreamHandler($auditLog);
        $auditHandler->setFormatter($jsonFormatter);

        // Create loggers
        $appLogger = new Logger('app', [$appHandler, $errorFilter]);
        $auditLogger = new Logger('audit', [$auditHandler]);

        // Add processors
        $requestProcessor = new RequestProcessor();
        $requestProcessor->setRequestId('req-abc-123');

        $appLogger->addProcessor($requestProcessor);
        $auditLogger->addProcessor($requestProcessor);

        // Simulate application flow
        $appLogger->info('Request received', ['path' => '/api/users', 'method' => 'POST']);

        // Simulate validation
        $userData = ['username' => 'john', 'email' => 'john@example.com'];
        $appLogger->debug('Validating user data', ['data' => $userData]);

        // Simulate database operation
        $userId = 12345;
        $appLogger->info('User created in database', ['user_id' => $userId]);

        // Audit log
        $auditLogger->info('USER_CREATED', [
            'user_id' => $userId,
            'username' => $userData['username'],
            'created_by' => 'system',
        ]);

        // Simulate an error
        try {
            throw new \RuntimeException('Email service unavailable');
        } catch (\Throwable $e) {
            $appLogger->error('Failed to send welcome email', [
                'exception' => $e,
                'user_id' => $userId,
            ]);
        }

        $appLogger->info('Request completed', ['status' => 201, 'duration_ms' => 150]);

        $appLogger->close();
        $auditLogger->close();

        // Verify app log
        $appContent = $this->readLogFile($appLog);
        $this->assertStringContainsString('Request received', $appContent);
        $this->assertStringContainsString('User created', $appContent);
        $this->assertStringContainsString('Failed to send welcome email', $appContent);
        $this->assertStringContainsString('req-abc-123', $appContent);

        // Verify error log only has errors
        $errorContent = $this->readLogFile($errorLog);
        $this->assertStringContainsString('Failed to send welcome email', $errorContent);
        $this->assertStringNotContainsString('Request received', $errorContent);
        $this->assertStringNotContainsString('User created', $errorContent);

        // Verify audit log
        $auditContent = $this->readLogFile($auditLog);
        $auditLog = json_decode(trim($auditContent), true);
        $this->assertEquals('USER_CREATED', $auditLog['message']);
        $this->assertEquals(12345, $auditLog['context']['user_id']);
        $this->assertEquals('req-abc-123', $auditLog['extra']['request_id']);
    }

    public function testHighVolumeLogging(): void
    {
        $logFile = $this->logDir . '/high-volume.log';

        $handler = new StreamHandler($logFile);
        $handler->setFormatter(new LineFormatter(
            LineFormatter::COMPACT_FORMAT,
            ignoreEmptyContextAndExtra: true,
        ));

        $logger = new Logger('benchmark', [$handler]);

        $count = 5000;
        $start = microtime(true);

        for ($i = 0; $i < $count; $i++) {
            $logger->info("Message $i", ['index' => $i, 'batch' => (int) ($i / 100)]);
        }

        $logger->close();

        $duration = microtime(true) - $start;
        $rate = $count / $duration;

        // Should write at least 1000 logs/second
        $this->assertGreaterThan(1000, $rate, "Write rate too slow: $rate logs/sec");

        // Verify all logs were written
        $lineCount = $this->countLogLines($logFile);
        $this->assertEquals($count, $lineCount);

        // Verify first and last
        $content = $this->readLogFile($logFile);
        $this->assertStringContainsString('Message 0', $content);
        $this->assertStringContainsString('Message 4999', $content);
    }

    public function testLoggingWithSpecialCharacters(): void
    {
        $logFile = $this->logDir . '/special-chars.log';

        $handler = new StreamHandler($logFile);
        $handler->setFormatter(new JsonFormatter());

        $logger = new Logger('app', [$handler]);

        $logger->info('Unicode test: 日本語 中文 한국어 العربية');
        $logger->info('Emoji test: 🎉 🚀 ✅ ❌ 🔥');
        $logger->info('Special chars: <script>alert("xss")</script>');
        $logger->info("Newlines and tabs:\nLine2\tTabbed");

        $logger->close();

        $content = $this->readLogFile($logFile);
        $lines = array_filter(explode("\n", $content));

        $this->assertCount(4, $lines);

        // Verify JSON is valid for each line
        foreach ($lines as $line) {
            $log = json_decode($line, true);
            $this->assertNotNull($log, "Invalid JSON: $line");
        }

        // Check content
        $this->assertStringContainsString('日本語', $content);
        $this->assertStringContainsString('🎉', $content);
    }
}
