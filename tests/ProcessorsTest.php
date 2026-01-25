<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Tests;

use AdosLabs\EnterprisePSR3Logger\Processors\ContextProcessor;
use AdosLabs\EnterprisePSR3Logger\Processors\ExecutionTimeProcessor;
use AdosLabs\EnterprisePSR3Logger\Processors\HostnameProcessor;
use AdosLabs\EnterprisePSR3Logger\Processors\MemoryProcessor;
use AdosLabs\EnterprisePSR3Logger\Processors\RequestProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class ProcessorsTest extends TestCase
{
    private function createRecord(): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Test message',
            context: [],
            extra: [],
        );
    }

    // === ContextProcessor Tests ===

    public function testContextProcessorAddsContext(): void
    {
        $processor = new ContextProcessor(['app' => 'test', 'version' => '1.0']);
        $record = $processor($this->createRecord());

        $this->assertEquals('test', $record->extra['app']);
        $this->assertEquals('1.0', $record->extra['version']);
    }

    public function testContextProcessorSet(): void
    {
        $processor = new ContextProcessor();
        $processor->set('key', 'value');

        $record = $processor($this->createRecord());

        $this->assertEquals('value', $record->extra['key']);
    }

    public function testContextProcessorMerge(): void
    {
        $processor = new ContextProcessor(['a' => 1]);
        $processor->merge(['b' => 2, 'c' => 3]);

        $record = $processor($this->createRecord());

        $this->assertEquals(1, $record->extra['a']);
        $this->assertEquals(2, $record->extra['b']);
        $this->assertEquals(3, $record->extra['c']);
    }

    public function testContextProcessorRemove(): void
    {
        $processor = new ContextProcessor(['keep' => 1, 'remove' => 2]);
        $processor->remove('remove');

        $record = $processor($this->createRecord());

        $this->assertEquals(1, $record->extra['keep']);
        $this->assertArrayNotHasKey('remove', $record->extra);
    }

    public function testContextProcessorClear(): void
    {
        $processor = new ContextProcessor(['a' => 1, 'b' => 2]);
        $processor->clear();

        $record = $processor($this->createRecord());

        $this->assertArrayNotHasKey('a', $record->extra);
        $this->assertArrayNotHasKey('b', $record->extra);
    }

    public function testContextProcessorAddToContext(): void
    {
        $processor = new ContextProcessor(['key' => 'value'], addToExtra: false);
        $record = $processor($this->createRecord());

        $this->assertArrayNotHasKey('key', $record->extra);
        $this->assertEquals('value', $record->context['key']);
    }

    // === MemoryProcessor Tests ===

    public function testMemoryProcessorAddsUsage(): void
    {
        $processor = new MemoryProcessor();
        $record = $processor($this->createRecord());

        $this->assertArrayHasKey('memory_usage', $record->extra);
        $this->assertArrayHasKey('memory_peak', $record->extra);
    }

    public function testMemoryProcessorFormatted(): void
    {
        $processor = new MemoryProcessor(formatBytes: true);
        $record = $processor($this->createRecord());

        // Should contain unit suffix
        $this->assertMatchesRegularExpression('/\d+(\.\d+)? [KMGT]?B/', $record->extra['memory_usage']);
    }

    public function testMemoryProcessorRaw(): void
    {
        $processor = new MemoryProcessor(formatBytes: false);
        $record = $processor($this->createRecord());

        // Should be integer
        $this->assertIsInt($record->extra['memory_usage']);
    }

    public function testMemoryProcessorIncludeLimit(): void
    {
        $processor = new MemoryProcessor(includeLimit: true);
        $record = $processor($this->createRecord());

        $this->assertArrayHasKey('memory_limit', $record->extra);
    }

    public function testMemoryProcessorIncludePercent(): void
    {
        $processor = new MemoryProcessor(includePercent: true);
        $record = $processor($this->createRecord());

        $this->assertArrayHasKey('memory_percent', $record->extra);
        $this->assertIsFloat($record->extra['memory_percent']);
    }

    // === ExecutionTimeProcessor Tests ===

    public function testExecutionTimeProcessorAddsTime(): void
    {
        $processor = new ExecutionTimeProcessor();
        $record = $processor($this->createRecord());

        $this->assertArrayHasKey('execution_time_ms', $record->extra);
        $this->assertArrayHasKey('execution_time', $record->extra);
    }

    public function testExecutionTimeProcessorStart(): void
    {
        $processor = new ExecutionTimeProcessor();

        // Wait a bit
        usleep(10000); // 10ms

        $processor->start();
        $record = $processor($this->createRecord());

        // Should be very small since we just started
        $this->assertLessThan(100, $record->extra['execution_time_ms']);
    }

    public function testExecutionTimeProcessorGetElapsed(): void
    {
        $processor = new ExecutionTimeProcessor();
        $processor->start();

        usleep(10000); // 10ms

        $elapsed = $processor->getElapsedMs();
        $this->assertGreaterThan(5, $elapsed);
    }

    // === HostnameProcessor Tests ===

    public function testHostnameProcessorAddsHostname(): void
    {
        $processor = new HostnameProcessor();
        $record = $processor($this->createRecord());

        $this->assertArrayHasKey('hostname', $record->extra);
        // server_ip is only included if SERVER_ADDR is available
        // (to avoid blocking DNS lookups)
    }

    public function testHostnameProcessorWithManualServerIp(): void
    {
        $processor = new HostnameProcessor();
        $processor->setServerIp('192.168.1.1');
        $record = $processor($this->createRecord());

        $this->assertArrayHasKey('hostname', $record->extra);
        $this->assertArrayHasKey('server_ip', $record->extra);
        $this->assertEquals('192.168.1.1', $record->extra['server_ip']);
    }

    public function testHostnameProcessorPhpVersion(): void
    {
        $processor = new HostnameProcessor(includePhpVersion: true);
        $record = $processor($this->createRecord());

        $this->assertArrayHasKey('php_version', $record->extra);
        $this->assertEquals(PHP_VERSION, $record->extra['php_version']);
    }

    public function testHostnameProcessorEnvironment(): void
    {
        $processor = new HostnameProcessor(environment: 'testing');
        $record = $processor($this->createRecord());

        $this->assertEquals('testing', $record->extra['environment']);
    }

    // === RequestProcessor Tests ===

    public function testRequestProcessorAddsRequestId(): void
    {
        $processor = new RequestProcessor();
        $record = $processor($this->createRecord());

        $this->assertArrayHasKey('request_id', $record->extra);
        $this->assertNotEmpty($record->extra['request_id']);
    }

    public function testRequestProcessorSetRequestId(): void
    {
        $processor = new RequestProcessor();
        $processor->setRequestId('custom-id-123');

        $record = $processor($this->createRecord());

        $this->assertEquals('custom-id-123', $record->extra['request_id']);
    }

    public function testRequestProcessorGeneratesUuid(): void
    {
        $processor = new RequestProcessor();
        $id = $processor->getRequestId();

        // Should look like UUID v4
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
        );
    }

    public function testRequestProcessorCachesRequestId(): void
    {
        $processor = new RequestProcessor();

        $id1 = $processor->getRequestId();
        $id2 = $processor->getRequestId();

        $this->assertEquals($id1, $id2);
    }

    public function testRequestProcessorCliMode(): void
    {
        $processor = new RequestProcessor();
        $record = $processor($this->createRecord());

        // In CLI, should have sapi field
        $this->assertEquals('cli', $record->extra['sapi']);
    }
}
