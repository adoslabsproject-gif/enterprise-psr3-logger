<?php

declare(strict_types=1);

namespace Senza1dio\EnterprisePSR3Logger\Tests;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Senza1dio\EnterprisePSR3Logger\Logger;

class LoggerTest extends TestCase
{
    private TestHandler $testHandler;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->testHandler = new TestHandler();
        $this->logger = new Logger('test', [$this->testHandler]);
    }

    public function testLoggerImplementsPsrInterface(): void
    {
        $this->assertInstanceOf(\Psr\Log\LoggerInterface::class, $this->logger);
    }

    public function testLogInfo(): void
    {
        $this->logger->info('Test message');

        $this->assertTrue($this->testHandler->hasInfoRecords());
        $this->assertTrue($this->testHandler->hasInfo('Test message'));
    }

    public function testLogError(): void
    {
        $this->logger->error('Error occurred');

        $this->assertTrue($this->testHandler->hasErrorRecords());
        $this->assertTrue($this->testHandler->hasError('Error occurred'));
    }

    public function testLogWithContext(): void
    {
        $this->logger->info('User logged in', ['user_id' => 123]);

        $records = $this->testHandler->getRecords();
        $this->assertCount(1, $records);
        $this->assertEquals(['user_id' => 123], $records[0]->context);
    }

    public function testGlobalContext(): void
    {
        $this->logger->setGlobalContext(['app' => 'test-app']);
        $this->logger->info('Message');

        $records = $this->testHandler->getRecords();
        $this->assertArrayHasKey('app', $records[0]->context);
        $this->assertEquals('test-app', $records[0]->context['app']);
    }

    public function testAddGlobalContext(): void
    {
        $this->logger->setGlobalContext(['key1' => 'value1']);
        $this->logger->addGlobalContext('key2', 'value2');
        $this->logger->info('Message');

        $records = $this->testHandler->getRecords();
        $this->assertEquals('value1', $records[0]->context['key1']);
        $this->assertEquals('value2', $records[0]->context['key2']);
    }

    public function testContextMerging(): void
    {
        $this->logger->setGlobalContext(['global' => 'value']);
        $this->logger->info('Message', ['local' => 'value']);

        $records = $this->testHandler->getRecords();
        $this->assertArrayHasKey('global', $records[0]->context);
        $this->assertArrayHasKey('local', $records[0]->context);
    }

    public function testLocalContextOverridesGlobal(): void
    {
        $this->logger->setGlobalContext(['key' => 'global']);
        $this->logger->info('Message', ['key' => 'local']);

        $records = $this->testHandler->getRecords();
        $this->assertEquals('local', $records[0]->context['key']);
    }

    public function testChannel(): void
    {
        $this->assertEquals('test', $this->logger->getChannel());
    }

    public function testWithContext(): void
    {
        $childLogger = $this->logger->withContext(['child' => 'context']);
        $childLogger->info('Child message');

        $records = $this->testHandler->getRecords();
        $this->assertArrayHasKey('child', $records[0]->context);
    }

    public function testWithChannel(): void
    {
        $childLogger = $this->logger->withChannel('child');

        $this->assertEquals('test.child', $childLogger->getChannel());
    }

    public function testSamplingRateZeroLogsNothing(): void
    {
        $this->logger->setSamplingRate(0.0);

        for ($i = 0; $i < 100; $i++) {
            $this->logger->info('Message ' . $i);
        }

        $this->assertCount(0, $this->testHandler->getRecords());
    }

    public function testSamplingRateOneLogsEverything(): void
    {
        $this->logger->setSamplingRate(1.0);

        for ($i = 0; $i < 10; $i++) {
            $this->logger->info('Message ' . $i);
        }

        $this->assertCount(10, $this->testHandler->getRecords());
    }

    public function testLevelSamplingRate(): void
    {
        $this->logger->setLevelSamplingRate(LogLevel::DEBUG, 0.0);
        $this->logger->setSamplingRate(1.0);

        $this->logger->debug('Debug message');
        $this->logger->info('Info message');

        $records = $this->testHandler->getRecords();
        $this->assertCount(1, $records);
        $this->assertEquals('Info message', $records[0]->message);
    }

    public function testAllLogLevels(): void
    {
        $this->logger->emergency('Emergency');
        $this->logger->alert('Alert');
        $this->logger->critical('Critical');
        $this->logger->error('Error');
        $this->logger->warning('Warning');
        $this->logger->notice('Notice');
        $this->logger->info('Info');
        $this->logger->debug('Debug');

        $this->assertCount(8, $this->testHandler->getRecords());
    }

    public function testStackTraceIncludedForErrors(): void
    {
        $this->logger->setIncludeStackTraces(true);
        $this->logger->error('Error occurred');

        $records = $this->testHandler->getRecords();
        $this->assertArrayHasKey('stack_trace', $records[0]->context);
    }

    public function testStackTraceNotIncludedForInfo(): void
    {
        $this->logger->setIncludeStackTraces(true);
        $this->logger->info('Info message');

        $records = $this->testHandler->getRecords();
        $this->assertArrayNotHasKey('stack_trace', $records[0]->context);
    }

    public function testDisableStackTraces(): void
    {
        $this->logger->setIncludeStackTraces(false);
        $this->logger->error('Error occurred');

        $records = $this->testHandler->getRecords();
        $this->assertArrayNotHasKey('stack_trace', $records[0]->context);
    }

    public function testExceptionInContextNotDuplicatedWithStackTrace(): void
    {
        $this->logger->setIncludeStackTraces(true);
        $this->logger->error('Error', ['exception' => new \Exception('Test')]);

        $records = $this->testHandler->getRecords();
        $this->assertArrayHasKey('exception', $records[0]->context);
        $this->assertArrayNotHasKey('stack_trace', $records[0]->context);
    }

    public function testContextSanitization(): void
    {
        $this->logger->setMaxContextDepth(2);

        $deepContext = [
            'level1' => [
                'level2' => [
                    'level3' => 'should be truncated',
                ],
            ],
        ];

        $this->logger->info('Message', $deepContext);

        $records = $this->testHandler->getRecords();
        $context = $records[0]->context;

        $this->assertArrayHasKey('level1', $context);
        $this->assertArrayHasKey('level2', $context['level1']);
        $this->assertArrayHasKey('_truncated', $context['level1']['level2']);
    }

    public function testStringableMessage(): void
    {
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'Stringable message';
            }
        };

        $this->logger->info($stringable);

        $records = $this->testHandler->getRecords();
        $this->assertEquals('Stringable message', $records[0]->message);
    }

    public function testExceptionSerialization(): void
    {
        $exception = new \RuntimeException('Test exception', 123);

        $this->logger->error('Error', ['error' => $exception]);

        $records = $this->testHandler->getRecords();
        $serialized = $records[0]->context['error'];

        $this->assertIsArray($serialized);
        $this->assertEquals('RuntimeException', $serialized['class']);
        $this->assertEquals('Test exception', $serialized['message']);
        $this->assertEquals(123, $serialized['code']);
    }

    public function testDateTimeSerialization(): void
    {
        $date = new \DateTimeImmutable('2024-01-15 10:30:00');

        $this->logger->info('Message', ['date' => $date]);

        $records = $this->testHandler->getRecords();
        $this->assertStringContainsString('2024-01-15', $records[0]->context['date']);
    }
}
