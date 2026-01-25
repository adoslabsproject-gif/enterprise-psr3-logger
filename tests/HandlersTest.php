<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Tests;

use AdosLabs\EnterprisePSR3Logger\Handlers\BufferHandler;
use AdosLabs\EnterprisePSR3Logger\Handlers\FilterHandler;
use AdosLabs\EnterprisePSR3Logger\Handlers\GroupHandler;
use AdosLabs\EnterprisePSR3Logger\Handlers\RotatingFileHandler;
use AdosLabs\EnterprisePSR3Logger\Handlers\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class HandlersTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/psr3-logger-test-' . uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $files = glob($this->tempDir . '/*');
        if ($files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        @rmdir($this->tempDir);
    }

    private function createRecord(
        string $message = 'Test message',
        Level $level = Level::Info,
    ): LogRecord {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: $level,
            message: $message,
            context: [],
            extra: [],
        );
    }

    // === StreamHandler Tests ===

    public function testStreamHandlerWritesToFile(): void
    {
        $file = $this->tempDir . '/stream.log';
        $handler = new StreamHandler($file);

        $record = $this->createRecord('Hello World');
        $handler->handle($record);
        $handler->close();

        $this->assertFileExists($file);
        $content = file_get_contents($file);
        $this->assertStringContainsString('Hello World', $content);
    }

    public function testStreamHandlerRejectsPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('traversal');

        new StreamHandler('/var/log/../../../etc/passwd');
    }

    public function testStreamHandlerRejectsNullBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('null bytes');

        new StreamHandler("/var/log/app.log\0.txt");
    }

    public function testStreamHandlerAllowsPhpStreams(): void
    {
        $handler = new StreamHandler('php://memory');
        $this->assertInstanceOf(StreamHandler::class, $handler);
    }

    public function testStreamHandlerCreatesDirectory(): void
    {
        $file = $this->tempDir . '/subdir/nested/stream.log';
        $handler = new StreamHandler($file);

        $record = $this->createRecord();
        $handler->handle($record);
        $handler->close();

        $this->assertFileExists($file);
    }

    // === RotatingFileHandler Tests ===

    public function testRotatingFileHandlerCreatesFile(): void
    {
        $file = $this->tempDir . '/rotating.log';
        $handler = new RotatingFileHandler(
            filename: $file,
            rotationType: RotatingFileHandler::ROTATION_NONE,
        );

        $handler->handle($this->createRecord());
        $handler->close();

        $this->assertFileExists($file);
    }

    public function testRotatingFileHandlerDailyRotation(): void
    {
        $file = $this->tempDir . '/daily.log';
        $handler = new RotatingFileHandler(
            filename: $file,
            rotationType: RotatingFileHandler::ROTATION_DAILY,
        );

        $handler->handle($this->createRecord());
        $handler->close();

        // Should create file with date suffix
        $datePattern = $this->tempDir . '/daily-' . date('Y-m-d') . '.log';
        $this->assertFileExists($datePattern);
    }

    public function testRotatingFileHandlerRejectsPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RotatingFileHandler('../../../etc/passwd');
    }

    public function testRotatingFileHandlerRejectsNullBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RotatingFileHandler("/var/log/app.log\0");
    }

    // === FilterHandler Tests ===

    public function testFilterHandlerFiltersbyLevel(): void
    {
        $file = $this->tempDir . '/filtered.log';
        $innerHandler = new StreamHandler($file);
        $handler = new FilterHandler($innerHandler, minLevel: Level::Error);

        // Info should be filtered out
        $handler->handle($this->createRecord('Info message', Level::Info));
        // Error should pass through
        $handler->handle($this->createRecord('Error message', Level::Error));

        $handler->close();

        $content = file_get_contents($file);
        $this->assertStringNotContainsString('Info message', $content);
        $this->assertStringContainsString('Error message', $content);
    }

    public function testFilterHandlerMaxLevel(): void
    {
        $file = $this->tempDir . '/filtered-max.log';
        $innerHandler = new StreamHandler($file);
        $handler = new FilterHandler(
            $innerHandler,
            minLevel: Level::Info,
            maxLevel: Level::Warning,
        );

        $handler->handle($this->createRecord('Debug', Level::Debug));
        $handler->handle($this->createRecord('Info', Level::Info));
        $handler->handle($this->createRecord('Warning', Level::Warning));
        $handler->handle($this->createRecord('Error', Level::Error));

        $handler->close();

        $content = file_get_contents($file);
        $this->assertStringNotContainsString('Debug', $content);
        $this->assertStringContainsString('Info', $content);
        $this->assertStringContainsString('Warning', $content);
        $this->assertStringNotContainsString('Error', $content);
    }

    public function testFilterHandlerCustomFilter(): void
    {
        $file = $this->tempDir . '/custom-filter.log';
        $innerHandler = new StreamHandler($file);
        $handler = new FilterHandler(
            $innerHandler,
            filter: fn (LogRecord $r) => str_contains($r->message, 'important'),
        );

        $handler->handle($this->createRecord('Normal message'));
        $handler->handle($this->createRecord('This is important'));

        $handler->close();

        $content = file_get_contents($file);
        $this->assertStringNotContainsString('Normal message', $content);
        $this->assertStringContainsString('important', $content);
    }

    // === GroupHandler Tests ===

    public function testGroupHandlerForwardsToAll(): void
    {
        $file1 = $this->tempDir . '/group1.log';
        $file2 = $this->tempDir . '/group2.log';

        $handler = new GroupHandler([
            new StreamHandler($file1),
            new StreamHandler($file2),
        ]);

        $handler->handle($this->createRecord('Group message'));
        $handler->close();

        $this->assertStringContainsString('Group message', file_get_contents($file1));
        $this->assertStringContainsString('Group message', file_get_contents($file2));
    }

    public function testGroupHandlerBubbling(): void
    {
        $file = $this->tempDir . '/bubble.log';

        // With bubble = false, handle should return false
        $handler = new GroupHandler([new StreamHandler($file)], bubble: false);

        $result = $handler->handle($this->createRecord());
        $handler->close();

        $this->assertFalse($result);
    }

    public function testGroupHandlerContinuesBubbling(): void
    {
        $file = $this->tempDir . '/continue.log';

        // With bubble = true (default), handle should return true
        $handler = new GroupHandler([new StreamHandler($file)]);

        $result = $handler->handle($this->createRecord());
        $handler->close();

        $this->assertTrue($result);
    }

    // === BufferHandler Tests ===

    public function testBufferHandlerBuffersRecords(): void
    {
        $file = $this->tempDir . '/buffer.log';
        $innerHandler = new StreamHandler($file);
        $handler = new BufferHandler($innerHandler, flushOnShutdown: false);

        $handler->handle($this->createRecord('Buffered'));

        // File should not exist yet
        $this->assertFileDoesNotExist($file);

        $handler->flush();
        $handler->close();

        $this->assertFileExists($file);
        $this->assertStringContainsString('Buffered', file_get_contents($file));
    }

    public function testBufferHandlerFlushOnLimit(): void
    {
        $file = $this->tempDir . '/buffer-limit.log';
        $innerHandler = new StreamHandler($file);
        $handler = new BufferHandler(
            $innerHandler,
            bufferLimit: 3,
            flushOnOverflow: true,
            flushOnShutdown: false,
        );

        $handler->handle($this->createRecord('One'));
        $handler->handle($this->createRecord('Two'));
        $handler->handle($this->createRecord('Three'));
        // Buffer full, should auto-flush
        $handler->handle($this->createRecord('Four'));

        $this->assertFileExists($file);
        $handler->close();
    }

    public function testBufferHandlerFlushOnError(): void
    {
        $file = $this->tempDir . '/buffer-error.log';
        $innerHandler = new StreamHandler($file);
        $handler = new BufferHandler(
            $innerHandler,
            flushOnError: true,
            flushOnShutdown: false,
        );

        $handler->handle($this->createRecord('Info', Level::Info));
        $this->assertFileDoesNotExist($file);

        $handler->handle($this->createRecord('Error', Level::Error));
        // Should have flushed on error
        $this->assertFileExists($file);

        $handler->close();
    }

    public function testBufferHandlerClear(): void
    {
        $file = $this->tempDir . '/buffer-clear.log';
        $innerHandler = new StreamHandler($file);
        $handler = new BufferHandler($innerHandler, flushOnShutdown: false);

        $handler->handle($this->createRecord('Should be cleared'));
        $handler->clear();
        $handler->close();

        // File should not exist because buffer was cleared
        $this->assertFileDoesNotExist($file);
    }
}
