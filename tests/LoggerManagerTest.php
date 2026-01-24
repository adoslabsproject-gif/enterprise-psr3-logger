<?php

declare(strict_types=1);

namespace Senza1dio\EnterprisePSR3Logger\Tests;

use Monolog\Handler\TestHandler;
use PHPUnit\Framework\TestCase;
use Senza1dio\EnterprisePSR3Logger\Logger;
use Senza1dio\EnterprisePSR3Logger\LoggerManager;

class LoggerManagerTest extends TestCase
{
    private LoggerManager $manager;
    private TestHandler $testHandler;

    protected function setUp(): void
    {
        $this->manager = new LoggerManager();
        $this->testHandler = new TestHandler();
        $this->manager->setDefaultHandler($this->testHandler);
    }

    public function testGetChannel(): void
    {
        $logger = $this->manager->channel('app');

        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertEquals('app', $logger->getChannel());
    }

    public function testGetReturnsLoggerInterface(): void
    {
        $logger = $this->manager->get('app');

        $this->assertInstanceOf(\Psr\Log\LoggerInterface::class, $logger);
    }

    public function testSameChannelReturnsSameInstance(): void
    {
        $logger1 = $this->manager->channel('app');
        $logger2 = $this->manager->channel('app');

        $this->assertSame($logger1, $logger2);
    }

    public function testDifferentChannelsReturnDifferentInstances(): void
    {
        $logger1 = $this->manager->channel('app');
        $logger2 = $this->manager->channel('security');

        $this->assertNotSame($logger1, $logger2);
    }

    public function testDefaultHandlerUsed(): void
    {
        $logger = $this->manager->channel('app');
        $logger->info('Test message');

        $this->assertTrue($this->testHandler->hasInfo('Test message'));
    }

    public function testChannelSpecificHandler(): void
    {
        $securityHandler = new TestHandler();
        $this->manager->setChannelHandlers('security', [$securityHandler]);

        $appLogger = $this->manager->channel('app');
        $securityLogger = $this->manager->channel('security');

        $appLogger->info('App message');
        $securityLogger->info('Security message');

        // Default handler gets app message
        $this->assertTrue($this->testHandler->hasInfo('App message'));
        $this->assertFalse($this->testHandler->hasInfo('Security message'));

        // Security handler gets security message
        $this->assertTrue($securityHandler->hasInfo('Security message'));
        $this->assertFalse($securityHandler->hasInfo('App message'));
    }

    public function testAddChannelHandler(): void
    {
        // First get the channel (creates with default handler)
        $logger = $this->manager->channel('app');

        // Then add another handler
        $additionalHandler = new TestHandler();
        $this->manager->addChannelHandler('app', $additionalHandler);

        $logger->info('Test message');

        // Both handlers should receive the message
        $this->assertTrue($this->testHandler->hasInfo('Test message'));
        $this->assertTrue($additionalHandler->hasInfo('Test message'));
    }

    public function testGlobalContext(): void
    {
        $this->manager->setGlobalContext(['app_name' => 'test']);

        $logger = $this->manager->channel('app');
        $logger->info('Message');

        $records = $this->testHandler->getRecords();
        $this->assertEquals('test', $records[0]->context['app_name']);
    }

    public function testAddGlobalContext(): void
    {
        $this->manager->setGlobalContext(['key1' => 'value1']);
        $this->manager->addGlobalContext('key2', 'value2');

        $logger = $this->manager->channel('app');
        $logger->info('Message');

        $records = $this->testHandler->getRecords();
        $this->assertEquals('value1', $records[0]->context['key1']);
        $this->assertEquals('value2', $records[0]->context['key2']);
    }

    public function testGlobalContextAppliedToExistingLoggers(): void
    {
        $logger = $this->manager->channel('app');

        // Set global context after logger creation
        $this->manager->setGlobalContext(['late_context' => 'value']);

        $logger->info('Message');

        $records = $this->testHandler->getRecords();
        $this->assertEquals('value', $records[0]->context['late_context']);
    }

    public function testGetChannels(): void
    {
        $this->manager->channel('app');
        $this->manager->channel('security');
        $this->manager->channel('audit');

        $channels = $this->manager->getChannels();

        $this->assertContains('app', $channels);
        $this->assertContains('security', $channels);
        $this->assertContains('audit', $channels);
    }

    public function testHasChannel(): void
    {
        $this->assertFalse($this->manager->hasChannel('app'));

        $this->manager->channel('app');

        $this->assertTrue($this->manager->hasChannel('app'));
        $this->assertFalse($this->manager->hasChannel('nonexistent'));
    }

    public function testChannelInheritance(): void
    {
        $parentHandler = new TestHandler();
        $this->manager->setChannelHandlers('app', [$parentHandler]);

        // Child channel should inherit from parent
        $childLogger = $this->manager->channel('app.http');
        $childLogger->info('Child message');

        $this->assertTrue($parentHandler->hasInfo('Child message'));
    }

    public function testNestedChannelInheritance(): void
    {
        $appHandler = new TestHandler();
        $httpHandler = new TestHandler();

        $this->manager->setChannelHandlers('app', [$appHandler]);
        $this->manager->addChannelHandler('app.http', $httpHandler);

        $nestedLogger = $this->manager->channel('app.http.api');
        $nestedLogger->info('Nested message');

        // Should receive from both parent handlers
        $this->assertTrue($appHandler->hasInfo('Nested message'));
        $this->assertTrue($httpHandler->hasInfo('Nested message'));
    }

    public function testCloseAll(): void
    {
        $this->manager->channel('app');
        $this->manager->channel('security');

        // Should not throw
        $this->manager->closeAll();

        $this->assertTrue(true);
    }

    public function testAddDefaultHandler(): void
    {
        $additionalDefault = new TestHandler();
        $this->manager->addDefaultHandler($additionalDefault);

        $logger = $this->manager->channel('new-channel');
        $logger->info('Message');

        // Both default handlers should receive
        $this->assertTrue($this->testHandler->hasInfo('Message'));
        $this->assertTrue($additionalDefault->hasInfo('Message'));
    }
}
