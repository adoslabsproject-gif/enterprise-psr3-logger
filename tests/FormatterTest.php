<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Tests;

use AdosLabs\EnterprisePSR3Logger\Formatters\DetailedLineFormatter;
use AdosLabs\EnterprisePSR3Logger\Formatters\JsonFormatter;
use AdosLabs\EnterprisePSR3Logger\Formatters\LineFormatter;
use AdosLabs\EnterprisePSR3Logger\Formatters\PrettyFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class FormatterTest extends TestCase
{
    private function createRecord(
        string $message = 'Test message',
        Level $level = Level::Info,
        string $channel = 'test',
        array $context = [],
        array $extra = [],
    ): LogRecord {
        return new LogRecord(
            datetime: new \DateTimeImmutable('2024-01-15 10:30:00'),
            channel: $channel,
            level: $level,
            message: $message,
            context: $context,
            extra: $extra,
        );
    }

    // === LineFormatter Tests ===

    public function testLineFormatterBasic(): void
    {
        $formatter = new LineFormatter();
        $record = $this->createRecord();

        $output = $formatter->format($record);

        $this->assertStringContainsString('2024-01-15', $output);
        $this->assertStringContainsString('test', $output);
        // Level name is padded and may be mixed case
        $this->assertStringContainsString('Info', $output);
        $this->assertStringContainsString('Test message', $output);
    }

    public function testLineFormatterWithContext(): void
    {
        $formatter = new LineFormatter();
        $record = $this->createRecord(context: ['user_id' => 123]);

        $output = $formatter->format($record);

        $this->assertStringContainsString('user_id', $output);
        $this->assertStringContainsString('123', $output);
    }

    public function testLineFormatterEnhanced(): void
    {
        $formatter = new LineFormatter();
        $formatter->useEnhancedFormat();
        $record = $this->createRecord(context: ['key' => 'value']);

        $output = $formatter->format($record);

        // Enhanced format uses key=value style
        $this->assertStringContainsString('key=value', $output);
    }

    public function testLineFormatterIgnoreEmptyContext(): void
    {
        $formatter = new LineFormatter(ignoreEmptyContextAndExtra: true);
        $record = $this->createRecord();

        $output = $formatter->format($record);

        // Should not have empty [] brackets
        $this->assertStringNotContainsString('[] []', $output);
    }

    // === JsonFormatter Tests ===

    public function testJsonFormatterBasic(): void
    {
        $formatter = new JsonFormatter();
        $record = $this->createRecord();

        $output = $formatter->format($record);
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertEquals('Test message', $decoded['message']);
        $this->assertEquals('info', $decoded['level']);
        $this->assertEquals('test', $decoded['channel']);
    }

    public function testJsonFormatterWithContext(): void
    {
        $formatter = new JsonFormatter();
        $record = $this->createRecord(context: ['user_id' => 123, 'action' => 'login']);

        $output = $formatter->format($record);
        $decoded = json_decode($output, true);

        $this->assertEquals(123, $decoded['context']['user_id']);
        $this->assertEquals('login', $decoded['context']['action']);
    }

    public function testJsonFormatterNewlineAppended(): void
    {
        $formatter = new JsonFormatter(appendNewline: true);
        $record = $this->createRecord();

        $output = $formatter->format($record);

        $this->assertStringEndsWith("\n", $output);
    }

    public function testJsonFormatterNoNewline(): void
    {
        $formatter = new JsonFormatter(appendNewline: false);
        $record = $this->createRecord();

        $output = $formatter->format($record);

        $this->assertFalse(str_ends_with($output, "\n"));
    }

    public function testJsonFormatterFieldFiltering(): void
    {
        $formatter = new JsonFormatter();
        $formatter->setExcludeFields(['extra']);
        $record = $this->createRecord(extra: ['should_be_excluded' => true]);

        $output = $formatter->format($record);
        $decoded = json_decode($output, true);

        $this->assertArrayNotHasKey('extra', $decoded);
    }

    public function testJsonFormatterBatch(): void
    {
        $formatter = new JsonFormatter(batchMode: JsonFormatter::BATCH_MODE_NEWLINES);
        $records = [
            $this->createRecord('Message 1'),
            $this->createRecord('Message 2'),
        ];

        $output = $formatter->formatBatch($records);
        $lines = array_filter(explode("\n", $output));

        $this->assertCount(2, $lines);
    }

    // === DetailedLineFormatter Tests ===

    public function testDetailedLineFormatterBasic(): void
    {
        $formatter = new DetailedLineFormatter();
        $record = $this->createRecord();

        $output = $formatter->format($record);

        $this->assertStringContainsString('2024-01-15', $output);
        $this->assertStringContainsString('Info', $output); // Level name
        $this->assertStringContainsString('test', $output);
        $this->assertStringContainsString('Test message', $output);
    }

    public function testDetailedLineFormatterMultiLine(): void
    {
        $formatter = new DetailedLineFormatter(multiLine: true);
        $record = $this->createRecord(context: ['key' => 'value']);

        $output = $formatter->format($record);

        // Multi-line format uses special characters
        $this->assertStringContainsString('▶', $output);
        $this->assertStringContainsString('key=value', $output);
    }

    public function testDetailedLineFormatterException(): void
    {
        $formatter = new DetailedLineFormatter();
        $exception = new \RuntimeException('Test error');
        $record = $this->createRecord(
            message: 'Error occurred',
            level: Level::Error,
            context: ['exception' => $exception],
        );

        $output = $formatter->format($record);

        $this->assertStringContainsString('RuntimeException', $output);
        $this->assertStringContainsString('Test error', $output);
    }

    // === PrettyFormatter Tests ===

    public function testPrettyFormatterBasic(): void
    {
        $formatter = new PrettyFormatter(useColors: false);
        $record = $this->createRecord();

        $output = $formatter->format($record);

        // Box drawing characters
        $this->assertStringContainsString('┌', $output);
        $this->assertStringContainsString('└', $output);
        $this->assertStringContainsString('│', $output);
        $this->assertStringContainsString('Test message', $output);
    }

    public function testPrettyFormatterWithContext(): void
    {
        $formatter = new PrettyFormatter(useColors: false);
        $record = $this->createRecord(context: ['user_id' => 123, 'action' => 'login']);

        $output = $formatter->format($record);

        $this->assertStringContainsString('CONTEXT:', $output);
        $this->assertStringContainsString('user_id', $output);
        $this->assertStringContainsString('123', $output);
    }

    public function testPrettyFormatterWithException(): void
    {
        $formatter = new PrettyFormatter(useColors: false, includeStackTraces: true);
        $exception = new \RuntimeException('Test exception', 500);
        $record = $this->createRecord(
            level: Level::Error,
            context: ['exception' => $exception],
        );

        $output = $formatter->format($record);

        $this->assertStringContainsString('EXCEPTION:', $output);
        $this->assertStringContainsString('RuntimeException', $output);
        $this->assertStringContainsString('Test exception', $output);
        $this->assertStringContainsString('500', $output);
        $this->assertStringContainsString('Trace:', $output);
    }

    public function testPrettyFormatterBatch(): void
    {
        $formatter = new PrettyFormatter(useColors: false);
        $records = [
            $this->createRecord('Message 1'),
            $this->createRecord('Message 2'),
        ];

        $output = $formatter->formatBatch($records);

        $this->assertStringContainsString('Message 1', $output);
        $this->assertStringContainsString('Message 2', $output);
        // Should have multiple boxes
        $this->assertGreaterThan(1, substr_count($output, '┌'));
    }
}
