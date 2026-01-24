<?php

declare(strict_types=1);

namespace Senza1dio\EnterprisePSR3Logger\Tests;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Senza1dio\EnterprisePSR3Logger\Formatters\DetailedLineFormatter;
use Senza1dio\EnterprisePSR3Logger\Formatters\JsonFormatter;
use Senza1dio\EnterprisePSR3Logger\Formatters\LineFormatter;
use Senza1dio\EnterprisePSR3Logger\Handlers\RotatingFileHandler;
use Senza1dio\EnterprisePSR3Logger\Handlers\StreamHandler;

/**
 * Security-focused tests to verify protection against common attacks
 */
class SecurityTest extends TestCase
{
    private function createRecord(
        string $message = 'Test',
        array $context = []
    ): LogRecord {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'security',
            level: Level::Info,
            message: $message,
            context: $context,
            extra: []
        );
    }

    // === Path Traversal Tests ===

    public function testStreamHandlerBlocksParentDirectoryTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StreamHandler('/var/log/../../../etc/passwd');
    }

    public function testStreamHandlerBlocksWindowsTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StreamHandler('C:\\logs\\..\\..\\windows\\system32\\config');
    }

    public function testRotatingHandlerBlocksTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RotatingFileHandler('/var/log/../../etc/shadow');
    }

    public function testStreamHandlerBlocksNullByteInjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StreamHandler("/var/log/app.log\x00.txt");
    }

    public function testRotatingHandlerBlocksNullByteInjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RotatingFileHandler("/var/log/app\x00.log");
    }

    // === Log Injection Tests ===

    public function testLineFormatterSanitizesNewlines(): void
    {
        $formatter = new LineFormatter();
        $record = $this->createRecord("Line1\nLine2\rLine3\r\nLine4");

        $output = $formatter->format($record);

        // Newlines should be replaced, not present as actual newlines in message
        $lines = explode("\n", trim($output));
        // Should be single line (or at most 2 if formatter adds newline at end)
        $this->assertLessThanOrEqual(2, count($lines));
    }

    public function testLineFormatterStripsAnsiSequences(): void
    {
        $formatter = new LineFormatter();
        $malicious = "Normal text \033[31mRED\033[0m more text";
        $record = $this->createRecord($malicious);

        $output = $formatter->format($record);

        // ANSI escape sequences should be stripped
        $this->assertStringNotContainsString("\033[", $output);
    }

    public function testLineFormatterStripsControlCharacters(): void
    {
        $formatter = new LineFormatter();
        $malicious = "Text with\x00null\x07bell\x08backspace";
        $record = $this->createRecord($malicious);

        $output = $formatter->format($record);

        $this->assertStringNotContainsString("\x00", $output);
        $this->assertStringNotContainsString("\x07", $output);
        $this->assertStringNotContainsString("\x08", $output);
    }

    public function testJsonFormatterEscapesSpecialCharacters(): void
    {
        $formatter = new JsonFormatter();
        $record = $this->createRecord(
            "Message with \"quotes\" and <script>alert('xss')</script>"
        );

        $output = $formatter->format($record);
        $decoded = json_decode($output, true);

        // JSON should be valid
        $this->assertNotNull($decoded);
        // Original message preserved (escaped in JSON)
        $this->assertStringContainsString('quotes', $decoded['message']);
        $this->assertStringContainsString('<script>', $decoded['message']);
    }

    public function testContextSanitization(): void
    {
        $formatter = new LineFormatter();
        $record = $this->createRecord('Test', [
            'malicious' => "value\nwith\nnewlines",
            'safe' => 'normal value',
        ]);

        $output = $formatter->format($record);

        // Context is JSON-encoded, which escapes newlines
        $this->assertStringContainsString('\\n', $output);
    }

    // === Large Input Tests ===

    public function testHandlesLargeMessage(): void
    {
        $formatter = new LineFormatter();
        $largeMessage = str_repeat('A', 100000);
        $record = $this->createRecord($largeMessage);

        $output = $formatter->format($record);

        $this->assertNotEmpty($output);
    }

    public function testHandlesLargeContext(): void
    {
        $formatter = new LineFormatter();
        $formatter->setMaxContextLength(1000);

        $largeContext = [];
        for ($i = 0; $i < 1000; $i++) {
            $largeContext["key_$i"] = str_repeat('x', 100);
        }

        $record = $this->createRecord('Test', $largeContext);
        $output = $formatter->format($record);

        // Should be truncated
        $this->assertStringContainsString('...', $output);
    }

    public function testHandlesDeeplyNestedContext(): void
    {
        $formatter = new JsonFormatter();

        // Create deeply nested structure
        $nested = ['level' => 0];
        $current = &$nested;
        for ($i = 1; $i < 50; $i++) {
            $current['child'] = ['level' => $i];
            $current = &$current['child'];
        }

        $record = $this->createRecord('Test', ['nested' => $nested]);
        $output = $formatter->format($record);

        // Should not crash, should produce valid JSON
        $decoded = json_decode($output, true);
        $this->assertNotNull($decoded);
    }

    // === Exception Information Disclosure ===

    public function testExceptionPathsAreIncluded(): void
    {
        // Note: This is a documentation test - exceptions do include paths
        // In production, you may want to filter sensitive paths

        $formatter = new JsonFormatter();
        $exception = new \RuntimeException('Test error');
        $record = $this->createRecord('Error', ['exception' => $exception]);

        $output = $formatter->format($record);

        // Exception is normalized and included
        $this->assertStringContainsString('RuntimeException', $output);
    }

    // === Unicode Tests ===

    public function testHandlesUnicode(): void
    {
        $formatter = new LineFormatter();
        $record = $this->createRecord('Unicode: 你好世界 🎉 العربية');

        $output = $formatter->format($record);

        $this->assertStringContainsString('你好世界', $output);
        $this->assertStringContainsString('🎉', $output);
        $this->assertStringContainsString('العربية', $output);
    }

    public function testHandlesMalformedUtf8(): void
    {
        $formatter = new JsonFormatter();
        $malformed = "Valid text \xFF\xFE invalid bytes";
        $record = $this->createRecord($malformed);

        $output = $formatter->format($record);

        // Should produce valid JSON (invalid UTF-8 is handled)
        $decoded = json_decode($output, true);
        // Either decoded successfully or error is captured
        $this->assertTrue($decoded !== null || str_contains($output, 'json_encode_error'));
    }

    // === Concurrent Access Tests ===

    public function testFileHandlerDoesNotCorrupt(): void
    {
        $tempFile = sys_get_temp_dir() . '/concurrent-test-' . uniqid() . '.log';

        try {
            $handler = new StreamHandler($tempFile, useLocking: true);

            // Write multiple records
            for ($i = 0; $i < 100; $i++) {
                $record = $this->createRecord("Message $i");
                $handler->handle($record);
            }

            $handler->close();

            // Verify file is not corrupted
            $content = file_get_contents($tempFile);
            $lines = array_filter(explode("\n", $content));
            $this->assertGreaterThanOrEqual(100, count($lines));
        } finally {
            @unlink($tempFile);
        }
    }
}
