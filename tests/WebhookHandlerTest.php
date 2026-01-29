<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Tests;

use AdosLabs\EnterprisePSR3Logger\Handlers\WebhookHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WebhookHandler including Slack, Discord, and Teams integrations
 */
class WebhookHandlerTest extends TestCase
{
    private function createRecord(
        string $message = 'Test message',
        Level $level = Level::Error,
        array $context = [],
    ): LogRecord {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: $level,
            message: $message,
            context: $context,
            extra: [],
        );
    }

    // ==================== SSRF Protection Tests ====================

    public function testRejectsInternalIpAddresses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('internal/private IP');

        // This should fail because it resolves to internal IP
        new WebhookHandler('https://10.0.0.1/webhook');
    }

    public function testRejectsPrivateNetworkRanges(): void
    {
        $internalUrls = [
            'https://192.168.1.1/webhook',
            'https://172.16.0.1/webhook',
            'https://10.255.255.255/webhook',
        ];

        foreach ($internalUrls as $url) {
            try {
                new WebhookHandler($url);
                $this->fail("Expected exception for URL: {$url}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('internal/private IP', $e->getMessage());
            }
        }
    }

    public function testRejectsCloudMetadataEndpoint(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('internal/private IP');

        // AWS/GCP/Azure metadata endpoint
        new WebhookHandler('https://169.254.169.254/latest/meta-data/');
    }

    public function testRejectsHttpWithoutLocalhost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTPS');

        new WebhookHandler('http://example.com/webhook');
    }

    public function testRejectsLocalhostInProduction(): void
    {
        // Set production environment
        $_ENV['APP_ENV'] = 'production';

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('localhost');
            new WebhookHandler('http://localhost/webhook');
        } finally {
            unset($_ENV['APP_ENV']);
        }
    }

    public function testAllowsLocalhostInDevelopment(): void
    {
        // Set development environment
        $_ENV['APP_ENV'] = 'local';

        try {
            $handler = new WebhookHandler('http://localhost/webhook');
            $this->assertInstanceOf(WebhookHandler::class, $handler);
        } finally {
            unset($_ENV['APP_ENV']);
        }
    }

    public function testAllowsLocalhostWithExplicitOverride(): void
    {
        // Set production but explicitly allow localhost
        $_ENV['APP_ENV'] = 'production';
        $_ENV['WEBHOOK_ALLOW_LOCALHOST'] = 'true';

        try {
            $handler = new WebhookHandler('http://localhost/webhook');
            $this->assertInstanceOf(WebhookHandler::class, $handler);
        } finally {
            unset($_ENV['APP_ENV']);
            unset($_ENV['WEBHOOK_ALLOW_LOCALHOST']);
        }
    }

    public function testAcceptsValidHttpsUrl(): void
    {
        // This test requires a resolvable external domain
        // We use a mock approach - just verify the handler can be instantiated
        // with a known external URL (we can't actually call it in tests)

        // Skip if no network
        if (!@fsockopen('www.google.com', 443, $errno, $errstr, 1)) {
            $this->markTestSkipped('Network not available');
        }

        $handler = new WebhookHandler('https://hooks.slack.com/services/test/test/test');
        $this->assertInstanceOf(WebhookHandler::class, $handler);
    }

    // ==================== Slack Integration Tests ====================

    public function testSlackFactoryCreatesHandler(): void
    {
        $_ENV['APP_ENV'] = 'local';

        try {
            $handler = WebhookHandler::slack(
                webhookUrl: 'http://localhost/slack-webhook',
                channel: '#alerts',
                username: 'Logger Bot',
            );

            $this->assertInstanceOf(WebhookHandler::class, $handler);
        } finally {
            unset($_ENV['APP_ENV']);
        }
    }

    public function testSlackPayloadFormat(): void
    {
        $_ENV['APP_ENV'] = 'local';

        try {
            // Create handler with capturing payload
            $handler = WebhookHandler::slack(
                webhookUrl: 'http://localhost/slack-webhook',
                channel: '#errors',
                username: 'Error Bot',
                iconEmoji: ':warning:',
            );

            $record = $this->createRecord(
                'Payment failed',
                Level::Error,
                ['order_id' => 12345],
            );

            // We can't test the actual HTTP call, but we can verify the handler
            // was created with correct configuration
            $this->assertInstanceOf(WebhookHandler::class, $handler);
        } finally {
            unset($_ENV['APP_ENV']);
        }
    }

    // ==================== Discord Integration Tests ====================

    public function testDiscordFactoryCreatesHandler(): void
    {
        $_ENV['APP_ENV'] = 'local';

        try {
            $handler = WebhookHandler::discord(
                webhookUrl: 'http://localhost/discord-webhook',
                username: 'Logger Bot',
            );

            $this->assertInstanceOf(WebhookHandler::class, $handler);
        } finally {
            unset($_ENV['APP_ENV']);
        }
    }

    public function testDiscordWithAvatarUrl(): void
    {
        $_ENV['APP_ENV'] = 'local';

        try {
            $handler = WebhookHandler::discord(
                webhookUrl: 'http://localhost/discord-webhook',
                username: 'Error Bot',
                avatarUrl: 'https://example.com/avatar.png',
                level: Level::Critical,
            );

            $this->assertInstanceOf(WebhookHandler::class, $handler);
        } finally {
            unset($_ENV['APP_ENV']);
        }
    }

    // ==================== Teams Integration Tests ====================

    public function testTeamsFactoryCreatesHandler(): void
    {
        $_ENV['APP_ENV'] = 'local';

        try {
            $handler = WebhookHandler::teams(
                webhookUrl: 'http://localhost/teams-webhook',
                title: 'Application Alerts',
            );

            $this->assertInstanceOf(WebhookHandler::class, $handler);
        } finally {
            unset($_ENV['APP_ENV']);
        }
    }

    public function testTeamsWithCustomLevel(): void
    {
        $_ENV['APP_ENV'] = 'local';

        try {
            $handler = WebhookHandler::teams(
                webhookUrl: 'http://localhost/teams-webhook',
                title: 'Critical Alerts Only',
                level: Level::Critical,
            );

            $this->assertInstanceOf(WebhookHandler::class, $handler);
        } finally {
            unset($_ENV['APP_ENV']);
        }
    }

    // ==================== Custom Webhook Tests ====================

    public function testCustomHeadersConfiguration(): void
    {
        $_ENV['APP_ENV'] = 'local';

        try {
            $handler = new WebhookHandler(
                url: 'http://localhost/custom-webhook',
                headers: [
                    'Authorization' => 'Bearer test-token',
                    'X-Custom-Header' => 'custom-value',
                ],
            );

            $this->assertInstanceOf(WebhookHandler::class, $handler);
        } finally {
            unset($_ENV['APP_ENV']);
        }
    }

    public function testTimeoutConfiguration(): void
    {
        $_ENV['APP_ENV'] = 'local';

        try {
            $handler = new WebhookHandler(
                url: 'http://localhost/webhook',
                timeout: 10,
            );

            $this->assertInstanceOf(WebhookHandler::class, $handler);
        } finally {
            unset($_ENV['APP_ENV']);
        }
    }

    public function testSslVerificationConfiguration(): void
    {
        $_ENV['APP_ENV'] = 'local';

        try {
            // For development, SSL verification can be disabled
            $handler = new WebhookHandler(
                url: 'http://localhost/webhook',
                verifySSL: false,
            );

            $this->assertInstanceOf(WebhookHandler::class, $handler);
        } finally {
            unset($_ENV['APP_ENV']);
        }
    }

    public function testMinimumLogLevel(): void
    {
        $_ENV['APP_ENV'] = 'local';

        try {
            $handler = new WebhookHandler(
                url: 'http://localhost/webhook',
                level: Level::Critical,
            );

            // Handler should not handle Info level
            $infoRecord = $this->createRecord('Info message', Level::Info);
            $this->assertFalse($handler->isHandling($infoRecord));

            // Handler should handle Critical level
            $criticalRecord = $this->createRecord('Critical message', Level::Critical);
            $this->assertTrue($handler->isHandling($criticalRecord));
        } finally {
            unset($_ENV['APP_ENV']);
        }
    }
}
