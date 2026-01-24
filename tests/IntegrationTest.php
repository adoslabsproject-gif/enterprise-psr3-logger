<?php

declare(strict_types=1);

namespace Senza1dio\EnterprisePSR3Logger\Tests;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Senza1dio\EnterprisePSR3Logger\Logger;
use Senza1dio\EnterprisePSR3Logger\LoggerFactory;
use Senza1dio\EnterprisePSR3Logger\LoggerManager;
use Senza1dio\EnterprisePSR3Logger\LoggerRegistry;
use Senza1dio\EnterprisePSR3Logger\Handlers\StreamHandler;
use Senza1dio\EnterprisePSR3Logger\Formatters\JsonFormatter;
use Senza1dio\EnterprisePSR3Logger\Processors\RequestProcessor;
use Senza1dio\EnterprisePSR3Logger\Processors\ContextProcessor;

/**
 * Integration tests simulating real-world usage patterns
 * Including compatibility with WordPress and major PHP frameworks
 */
class IntegrationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/psr3-integration-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        LoggerRegistry::clear();
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        if ($files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        @rmdir($this->tempDir);
        LoggerRegistry::clear();
    }

    // === PSR-3 Compliance Tests ===

    public function testPsr3Interface(): void
    {
        $logger = new Logger('test');
        $this->assertInstanceOf(LoggerInterface::class, $logger);
    }

    public function testPsr3AllLevels(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);

        $logger->emergency('emergency');
        $logger->alert('alert');
        $logger->critical('critical');
        $logger->error('error');
        $logger->warning('warning');
        $logger->notice('notice');
        $logger->info('info');
        $logger->debug('debug');

        $this->assertCount(8, $handler->getRecords());
    }

    public function testPsr3LogMethod(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);

        $logger->log(LogLevel::INFO, 'Using log method');

        $this->assertTrue($handler->hasInfo('Using log method'));
    }

    public function testPsr3ContextInterpolation(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);

        // PSR-3 recommends supporting {placeholder} interpolation
        // Our implementation passes context through without interpolation
        // which is a valid PSR-3 implementation
        $logger->info('User {user} logged in', ['user' => 'john']);

        $records = $handler->getRecords();
        $this->assertEquals(['user' => 'john'], $records[0]->context);
    }

    // === WordPress Compatibility ===

    /**
     * WordPress uses a simple PSR-3 interface for logging
     * This test ensures our logger works as a drop-in replacement
     */
    public function testWordPressCompatibility(): void
    {
        $logFile = $this->tempDir . '/wordpress.log';
        $logger = LoggerFactory::minimal('wordpress', $logFile, Level::Debug);

        // Typical WordPress logging patterns
        $logger->debug('Loading plugin: my-plugin');
        $logger->info('Plugin activated', ['plugin' => 'my-plugin', 'version' => '1.0']);
        $logger->warning('Deprecated function called', ['function' => 'get_magic_quotes_gpc']);
        $logger->error('Database connection failed', [
            'host' => 'localhost',
            'error' => 'Connection refused',
        ]);

        $logger->close();

        $content = file_get_contents($logFile);
        $this->assertStringContainsString('Loading plugin', $content);
        $this->assertStringContainsString('Plugin activated', $content);
        $this->assertStringContainsString('Database connection', $content);
    }

    /**
     * WordPress plugins often log user actions
     * This simulates WooCommerce-style order logging
     */
    public function testWooCommerceStyleLogging(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('woocommerce', [$handler]);

        // Order created
        $logger->info('Order created', [
            'order_id' => 12345,
            'customer_id' => 456,
            'total' => '99.99',
            'currency' => 'USD',
        ]);

        // Payment processed
        $logger->info('Payment processed', [
            'order_id' => 12345,
            'gateway' => 'stripe',
            'transaction_id' => 'ch_xxx123',
        ]);

        // Error scenario
        $logger->error('Payment failed', [
            'order_id' => 12346,
            'gateway' => 'paypal',
            'error' => 'Insufficient funds',
        ]);

        $records = $handler->getRecords();
        $this->assertCount(3, $records);
        $this->assertEquals(12345, $records[0]->context['order_id']);
    }

    // === Laravel Compatibility ===

    /**
     * Laravel uses channels extensively
     * This tests our multi-channel support
     */
    public function testLaravelChannelSupport(): void
    {
        $manager = new LoggerManager();

        $singleFile = $this->tempDir . '/laravel.log';
        $dailyFile = $this->tempDir . '/daily.log';

        $manager->setDefaultHandler(new StreamHandler($singleFile));

        // Get channels
        $single = $manager->channel('single');
        $daily = $manager->channel('daily');
        $stack = $manager->channel('stack');

        $single->info('Single channel log');
        $daily->info('Daily channel log');
        $stack->info('Stack channel log');

        $manager->closeAll();

        $content = file_get_contents($singleFile);
        $this->assertStringContainsString('Single channel log', $content);
    }

    /**
     * Laravel-style context with user info
     */
    public function testLaravelContextualLogging(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('app', [$handler]);

        // Add user context like Laravel's Log::withContext()
        $logger->setGlobalContext([
            'user_id' => 123,
            'session_id' => 'sess_abc123',
        ]);

        $logger->info('User viewed dashboard');
        $logger->info('User updated profile');

        $records = $handler->getRecords();
        foreach ($records as $record) {
            $this->assertEquals(123, $record->context['user_id']);
            $this->assertEquals('sess_abc123', $record->context['session_id']);
        }
    }

    // === Symfony Compatibility ===

    /**
     * Symfony uses Monolog directly, our package extends it
     */
    public function testSymfonyMonologCompatibility(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('symfony', [$handler]);

        // Access underlying Monolog
        $monolog = $logger->getMonolog();
        $this->assertInstanceOf(\Monolog\Logger::class, $monolog);

        // Use Monolog features
        $logger->info('Request handled', [
            '_controller' => 'App\\Controller\\HomeController::index',
            '_route' => 'homepage',
        ]);

        $this->assertTrue($handler->hasInfoRecords());
    }

    // === Slim/Lumen Micro-framework Compatibility ===

    public function testMicroframeworkMiddlewarePattern(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('api', [$handler]);
        $logger->addProcessor(new RequestProcessor());

        // Simulate middleware logging
        $logger->info('Incoming request', [
            'method' => 'POST',
            'path' => '/api/users',
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $logger->info('Request processed', [
            'status' => 201,
            'duration_ms' => 45.5,
        ]);

        $records = $handler->getRecords();
        $this->assertCount(2, $records);
        // Request processor adds request_id
        $this->assertArrayHasKey('request_id', $records[0]->extra);
    }

    // === Global Helper Functions ===

    public function testGlobalLoggerRegistry(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('app', [$handler]);

        LoggerRegistry::register($logger, 'app', setAsDefault: true);

        $this->assertTrue(LoggerRegistry::has('app'));
        $this->assertEquals('app', LoggerRegistry::getDefaultChannel());

        $retrieved = LoggerRegistry::get();
        $this->assertSame($logger, $retrieved);

        $retrieved->info('From registry');
        $this->assertTrue($handler->hasInfo('From registry'));
    }

    public function testMultipleChannelRegistry(): void
    {
        $appHandler = new TestHandler();
        $securityHandler = new TestHandler();

        LoggerRegistry::register(new Logger('app', [$appHandler]), 'app', setAsDefault: true);
        LoggerRegistry::register(new Logger('security', [$securityHandler]), 'security');

        LoggerRegistry::get('app')->info('App message');
        LoggerRegistry::get('security')->warning('Security warning');

        $this->assertTrue($appHandler->hasInfo('App message'));
        $this->assertTrue($securityHandler->hasWarning('Security warning'));
    }

    // === Factory Tests ===

    public function testFactoryDevelopment(): void
    {
        $logger = LoggerFactory::development('dev-app', useColors: false);

        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertEquals('dev-app', $logger->getChannel());
    }

    public function testFactoryProduction(): void
    {
        $logger = LoggerFactory::production(
            channel: 'prod-app',
            logDir: $this->tempDir,
            compress: false
        );

        $logger->info('Production log entry');
        $logger->error('Production error');
        $logger->close();

        // Check files were created
        $files = glob($this->tempDir . '/prod-app*.log');
        $this->assertNotEmpty($files);
    }

    public function testFactoryContainer(): void
    {
        // Capture stdout
        ob_start();

        $handler = new StreamHandler('php://output');
        $handler->setFormatter(new JsonFormatter());

        $logger = new Logger('container', [$handler]);
        $logger->info('Container log');
        $logger->close();

        $output = ob_get_clean();

        // Should be valid JSON
        $decoded = json_decode($output, true);
        $this->assertNotNull($decoded);
        $this->assertEquals('Container log', $decoded['message']);
    }

    public function testFactoryFromConfig(): void
    {
        $logger = LoggerFactory::fromConfig([
            'channel' => 'config-test',
            'handlers' => [
                [
                    'type' => 'stream',
                    'path' => $this->tempDir . '/config.log',
                    'level' => 'info',
                ],
            ],
            'processors' => ['request', 'memory'],
            'context' => ['app_version' => '2.0.0'],
        ]);

        $logger->info('Config-based logger');
        $logger->close();

        $content = file_get_contents($this->tempDir . '/config.log');
        $this->assertStringContainsString('Config-based logger', $content);
    }

    // === Exception Logging ===

    public function testExceptionLogging(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('exception-test', [$handler]);
        $logger->setIncludeStackTraces(false); // Avoid sanitization

        try {
            throw new \RuntimeException('Something went wrong', 500);
        } catch (\Throwable $e) {
            $logger->error('Caught exception', ['exception' => $e]);
        }

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        // Context is sanitized, exception becomes array
        $this->assertArrayHasKey('exception', $records[0]->context);
        $exData = $records[0]->context['exception'];
        $this->assertEquals('RuntimeException', $exData['class']);
        $this->assertEquals('Something went wrong', $exData['message']);
    }

    public function testChainedException(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('chain-test', [$handler]);
        $logger->setIncludeStackTraces(false);

        try {
            try {
                throw new \InvalidArgumentException('Invalid input');
            } catch (\Throwable $e) {
                throw new \RuntimeException('Processing failed', 0, $e);
            }
        } catch (\Throwable $e) {
            $logger->error('Chain exception', ['exception' => $e]);
        }

        $records = $handler->getRecords();
        $exData = $records[0]->context['exception'];
        // Exception is serialized as array
        $this->assertEquals('RuntimeException', $exData['class']);
        $this->assertEquals('Processing failed', $exData['message']);
    }

    // === Performance Tests ===

    public function testBatchLogging(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('batch', [$handler]);

        $start = microtime(true);

        for ($i = 0; $i < 1000; $i++) {
            $logger->info("Message $i", ['index' => $i]);
        }

        $duration = microtime(true) - $start;

        // Should complete in reasonable time (less than 2 seconds)
        $this->assertLessThan(2.0, $duration);
        $this->assertCount(1000, $handler->getRecords());
    }

    public function testSamplingReducesVolume(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('sampling', [$handler]);
        $logger->setSamplingRate(0.5);

        for ($i = 0; $i < 1000; $i++) {
            $logger->info("Sampled message $i");
        }

        $count = count($handler->getRecords());

        // With 50% sampling, should have roughly 400-600 records
        // Using wide range due to randomness
        $this->assertGreaterThan(300, $count);
        $this->assertLessThan(700, $count);
    }
}
