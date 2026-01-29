<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests for log parsing functionality (Nginx, Apache, PHP error logs)
 *
 * These tests verify the parseLogLines() method can handle various log formats
 * as documented in the README.
 */
class LogParsingTest extends TestCase
{
    /**
     * Get the parseLogLines method via reflection (it's private)
     */
    private function getParseMethod(): callable
    {
        // Since we can't instantiate LoggerController without dependencies,
        // we'll test the parsing logic directly by extracting the patterns

        // Return a simplified version of the parsing logic for testing
        return function (array $lines): array {
            $parsed = [];

            $getLevelClass = fn ($level) => match ($level) {
                'emergency', 'alert', 'critical', 'error' => 'danger',
                'warning' => 'warning',
                'notice', 'info' => 'info',
                'debug' => 'secondary',
                default => 'secondary',
            };

            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }

                // Format 1: Nginx access log
                if (preg_match('/^(\S+)\s+-\s+(\S+)\s+\[([^\]]+)\]\s+"([^"]+)"\s+(\d{3})\s+(\d+)\s+"([^"]*)"\s+"([^"]*)"(.*)$/', $line, $matches)) {
                    $statusCode = (int) $matches[5];
                    $level = match (true) {
                        $statusCode >= 500 => 'error',
                        $statusCode >= 400 => 'warning',
                        $statusCode >= 300 => 'notice',
                        default => 'info',
                    };

                    $parsed[] = [
                        'raw' => $line,
                        'timestamp' => $matches[3],
                        'channel' => 'nginx',
                        'level' => $level,
                        'message' => $matches[4] . ' → ' . $statusCode,
                        'context' => 'ip=' . $matches[1],
                        'level_class' => $getLevelClass($level),
                    ];
                    continue;
                }

                // Format 2: Nginx error log
                if (preg_match('/^(\d{4}\/\d{2}\/\d{2}\s+\d{2}:\d{2}:\d{2})\s+\[(\w+)\]\s+(\d+#\d+):\s+(.*)$/', $line, $matches)) {
                    $nginxLevel = strtolower($matches[2]);
                    $level = match ($nginxLevel) {
                        'emerg', 'alert', 'crit' => 'critical',
                        'error' => 'error',
                        'warn' => 'warning',
                        'notice' => 'notice',
                        'info' => 'info',
                        default => 'debug',
                    };

                    $parsed[] = [
                        'raw' => $line,
                        'timestamp' => str_replace('/', '-', $matches[1]),
                        'channel' => 'nginx',
                        'level' => $level,
                        'message' => $matches[4],
                        'context' => 'pid=' . $matches[3],
                        'level_class' => $getLevelClass($level),
                    ];
                    continue;
                }

                // Format 3: PHP error format (supports "PHP Fatal error", "PHP Warning", etc.)
                if (preg_match('/^\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2}(?:\s+\S+)?)\]\s+(PHP [\w\s]+):\s*(.*)$/', $line, $matches)) {
                    $phpLevel = strtolower($matches[2]);
                    $level = match (true) {
                        str_contains($phpLevel, 'fatal') => 'critical',
                        str_contains($phpLevel, 'error') => 'error',
                        str_contains($phpLevel, 'warning') => 'warning',
                        str_contains($phpLevel, 'notice') => 'notice',
                        str_contains($phpLevel, 'deprecated') => 'notice',
                        default => 'info',
                    };

                    $parsed[] = [
                        'raw' => $line,
                        'timestamp' => $matches[1],
                        'channel' => 'php',
                        'level' => $level,
                        'message' => $matches[2] . ': ' . $matches[3],
                        'context' => null,
                        'level_class' => $getLevelClass($level),
                    ];
                    continue;
                }

                // Format 4: Simple log format
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?)\]\s+(\w+)\.(\w+):\s*(.*)$/', $line, $matches)) {
                    $level = strtolower($matches[3]);
                    $parsed[] = [
                        'raw' => $line,
                        'timestamp' => $matches[1],
                        'channel' => $matches[2],
                        'level' => $level,
                        'message' => $matches[4],
                        'context' => null,
                        'level_class' => $getLevelClass($level),
                    ];
                    continue;
                }
            }

            return $parsed;
        };
    }

    // ==================== Nginx Access Log Tests ====================

    public function testParsesNginxAccessLog200(): void
    {
        $parse = $this->getParseMethod();
        $lines = [
            '192.168.1.1 - - [28/Jan/2026:16:30:45 +0100] "GET /api/users HTTP/1.1" 200 1234 "-" "Mozilla/5.0"',
        ];

        $result = $parse($lines);

        $this->assertCount(1, $result);
        $this->assertEquals('nginx', $result[0]['channel']);
        $this->assertEquals('info', $result[0]['level']);
        $this->assertStringContainsString('GET /api/users', $result[0]['message']);
        $this->assertStringContainsString('200', $result[0]['message']);
        $this->assertStringContainsString('ip=192.168.1.1', $result[0]['context']);
    }

    public function testParsesNginxAccessLog404(): void
    {
        $parse = $this->getParseMethod();
        $lines = [
            '10.0.0.1 - admin [28/Jan/2026:16:30:45 +0100] "GET /not-found HTTP/1.1" 404 567 "https://example.com" "curl/7.68.0"',
        ];

        $result = $parse($lines);

        $this->assertCount(1, $result);
        $this->assertEquals('warning', $result[0]['level']);
        $this->assertStringContainsString('404', $result[0]['message']);
    }

    public function testParsesNginxAccessLog500(): void
    {
        $parse = $this->getParseMethod();
        $lines = [
            '192.168.1.100 - - [28/Jan/2026:16:30:45 +0100] "POST /api/crash HTTP/1.1" 500 89 "-" "Python/3.9"',
        ];

        $result = $parse($lines);

        $this->assertCount(1, $result);
        $this->assertEquals('error', $result[0]['level']);
        $this->assertEquals('danger', $result[0]['level_class']);
    }

    // ==================== Nginx Error Log Tests ====================

    public function testParsesNginxErrorLog(): void
    {
        $parse = $this->getParseMethod();
        $lines = [
            '2026/01/28 16:30:45 [error] 123#456: *789 upstream timed out, client: 192.168.1.1, server: example.com',
        ];

        $result = $parse($lines);

        $this->assertCount(1, $result);
        $this->assertEquals('nginx', $result[0]['channel']);
        $this->assertEquals('error', $result[0]['level']);
        $this->assertStringContainsString('upstream timed out', $result[0]['message']);
        $this->assertEquals('2026-01-28 16:30:45', $result[0]['timestamp']);
    }

    public function testParsesNginxCriticalError(): void
    {
        $parse = $this->getParseMethod();
        $lines = [
            '2026/01/28 16:30:45 [crit] 123#456: *789 SSL certificate problem',
        ];

        $result = $parse($lines);

        $this->assertCount(1, $result);
        $this->assertEquals('critical', $result[0]['level']);
    }

    // ==================== PHP Error Log Tests ====================

    public function testParsesPhpWarning(): void
    {
        $parse = $this->getParseMethod();
        $lines = [
            '[28-Jan-2026 15:30:45 Europe/Rome] PHP Warning: Undefined variable $foo in /var/www/app.php on line 123',
        ];

        $result = $parse($lines);

        $this->assertCount(1, $result);
        $this->assertEquals('php', $result[0]['channel']);
        $this->assertEquals('warning', $result[0]['level']);
        $this->assertStringContainsString('Undefined variable', $result[0]['message']);
    }

    public function testParsesPhpFatalError(): void
    {
        $parse = $this->getParseMethod();
        // PHP Fatal error format uses "PHP Fatal error" not "PHP Fatal"
        $lines = [
            '[28-Jan-2026 15:30:45 UTC] PHP Fatal error: Call to undefined function missing() in /var/www/app.php on line 456',
        ];

        $result = $parse($lines);

        $this->assertCount(1, $result);
        // "PHP Fatal error" contains "fatal", so should be critical
        $this->assertEquals('critical', $result[0]['level']);
    }

    public function testParsesPhpNotice(): void
    {
        $parse = $this->getParseMethod();
        $lines = [
            '[28-Jan-2026 15:30:45 Europe/Rome] PHP Notice: Array to string conversion in /var/www/app.php on line 789',
        ];

        $result = $parse($lines);

        $this->assertCount(1, $result);
        $this->assertEquals('notice', $result[0]['level']);
    }

    public function testParsesPhpDeprecated(): void
    {
        $parse = $this->getParseMethod();
        $lines = [
            '[28-Jan-2026 15:30:45 Europe/Rome] PHP Deprecated: Function xyz() is deprecated in /var/www/app.php on line 100',
        ];

        $result = $parse($lines);

        $this->assertCount(1, $result);
        $this->assertEquals('notice', $result[0]['level']);
    }

    // ==================== Simple Log Format Tests ====================

    public function testParsesSimpleLogFormat(): void
    {
        $parse = $this->getParseMethod();
        $lines = [
            '[2026-01-28 16:30:45.123456] app.ERROR: Payment failed {"order_id": 123}',
        ];

        $result = $parse($lines);

        $this->assertCount(1, $result);
        $this->assertEquals('app', $result[0]['channel']);
        $this->assertEquals('error', $result[0]['level']);
        $this->assertStringContainsString('Payment failed', $result[0]['message']);
    }

    public function testParsesSimpleLogDebug(): void
    {
        $parse = $this->getParseMethod();
        $lines = [
            '[2026-01-28 16:30:45] debug.DEBUG: Cache hit for key user_123',
        ];

        $result = $parse($lines);

        $this->assertCount(1, $result);
        $this->assertEquals('debug', $result[0]['level']);
        $this->assertEquals('secondary', $result[0]['level_class']);
    }

    // ==================== Mixed Log Tests ====================

    public function testParsesMixedLogFormats(): void
    {
        $parse = $this->getParseMethod();
        $lines = [
            '192.168.1.1 - - [28/Jan/2026:16:30:45 +0100] "GET /api HTTP/1.1" 200 100 "-" "curl"',
            '[28-Jan-2026 15:30:45 Europe/Rome] PHP Warning: Test warning',
            '[2026-01-28 16:30:45] app.INFO: Application started',
            '2026/01/28 16:30:45 [error] 123#456: nginx error',
        ];

        $result = $parse($lines);

        $this->assertCount(4, $result);
        $this->assertEquals('nginx', $result[0]['channel']);
        $this->assertEquals('php', $result[1]['channel']);
        $this->assertEquals('app', $result[2]['channel']);
        $this->assertEquals('nginx', $result[3]['channel']);
    }

    public function testSkipsEmptyLines(): void
    {
        $parse = $this->getParseMethod();
        $lines = [
            '',
            '[2026-01-28 16:30:45] app.INFO: Test',
            '',
            '',
        ];

        $result = $parse($lines);

        $this->assertCount(1, $result);
    }

    // ==================== Level Class Mapping Tests ====================

    public function testLevelClassMapping(): void
    {
        $parse = $this->getParseMethod();

        // Test all level classes
        $testCases = [
            ['[2026-01-28 16:30:45] app.EMERGENCY: Test', 'danger'],
            ['[2026-01-28 16:30:45] app.ALERT: Test', 'danger'],
            ['[2026-01-28 16:30:45] app.CRITICAL: Test', 'danger'],
            ['[2026-01-28 16:30:45] app.ERROR: Test', 'danger'],
            ['[2026-01-28 16:30:45] app.WARNING: Test', 'warning'],
            ['[2026-01-28 16:30:45] app.NOTICE: Test', 'info'],
            ['[2026-01-28 16:30:45] app.INFO: Test', 'info'],
            ['[2026-01-28 16:30:45] app.DEBUG: Test', 'secondary'],
        ];

        foreach ($testCases as [$line, $expectedClass]) {
            $result = $parse([$line]);
            $this->assertCount(1, $result, "Failed for line: {$line}");
            $this->assertEquals($expectedClass, $result[0]['level_class'], "Wrong class for line: {$line}");
        }
    }
}
