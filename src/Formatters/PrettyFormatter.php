<?php

declare(strict_types=1);

namespace Senza1dio\EnterprisePSR3Logger\Formatters;

use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;

/**
 * Pretty Formatter
 *
 * Human-readable multi-line format for development and debugging.
 * Provides maximum clarity with structured visual output.
 *
 * OUTPUT FORMAT:
 * ```
 * ┌──────────────────────────────────────────────────────────────────────────────
 * │ 2024-01-15 10:30:00.123456 │ ERROR │ app.security
 * ├──────────────────────────────────────────────────────────────────────────────
 * │ MESSAGE: Database connection failed after 3 retries
 * ├──────────────────────────────────────────────────────────────────────────────
 * │ CONTEXT:
 * │   host ........... db.example.com
 * │   port ........... 5432
 * │   database ....... production
 * │   error .......... Connection refused
 * │   attempts ....... 3
 * ├──────────────────────────────────────────────────────────────────────────────
 * │ EXTRA:
 * │   request_id ..... abc-123-def-456
 * │   user_id ........ 12345
 * │   ip ............. 192.168.1.100
 * │   memory_usage ... 45.2 MB
 * ├──────────────────────────────────────────────────────────────────────────────
 * │ EXCEPTION: PDOException
 * │   Message: SQLSTATE[HY000] [2002] Connection refused
 * │   Code: 2002
 * │   File: /var/www/app/src/Database/Connection.php:45
 * │   Trace:
 * │     #0 /var/www/app/src/Database/Connection.php:45 → PDO->__construct()
 * │     #1 /var/www/app/src/Repository/UserRepo.php:23 → Connection->connect()
 * │     #2 /var/www/app/src/Controller/AuthController.php:89 → UserRepo->find()
 * └──────────────────────────────────────────────────────────────────────────────
 * ```
 */
class PrettyFormatter extends NormalizerFormatter implements FormatterInterface
{
    private const BOX_WIDTH = 80;
    private const CORNER_TOP_LEFT = '┌';
    private const CORNER_TOP_RIGHT = '┐';
    private const CORNER_BOTTOM_LEFT = '└';
    private const CORNER_BOTTOM_RIGHT = '┘';
    private const HORIZONTAL = '─';
    private const VERTICAL = '│';
    private const T_LEFT = '├';
    private const T_RIGHT = '┤';

    /** @var array<string, string> ANSI color codes for log levels */
    private const LEVEL_COLORS = [
        'DEBUG' => "\033[36m",      // Cyan
        'INFO' => "\033[32m",       // Green
        'NOTICE' => "\033[34m",     // Blue
        'WARNING' => "\033[33m",    // Yellow
        'ERROR' => "\033[31m",      // Red
        'CRITICAL' => "\033[1;31m", // Bold Red
        'ALERT' => "\033[1;35m",    // Bold Magenta
        'EMERGENCY' => "\033[1;37;41m", // White on Red
    ];

    private const RESET_COLOR = "\033[0m";
    private const BOLD = "\033[1m";
    private const DIM = "\033[2m";

    private bool $useColors;
    private bool $includeStackTraces;
    private int $maxTraceDepth;
    private int $keyPadding;

    /**
     * @param bool $useColors Enable ANSI colors (for terminal output)
     * @param bool $includeStackTraces Include exception stack traces
     * @param int $maxTraceDepth Maximum stack trace frames to show
     * @param string|null $dateFormat Date format (null = Y-m-d H:i:s.u)
     */
    public function __construct(
        bool $useColors = true,
        bool $includeStackTraces = true,
        int $maxTraceDepth = 10,
        ?string $dateFormat = null,
    ) {
        parent::__construct($dateFormat ?? 'Y-m-d H:i:s.u');

        $this->useColors = $useColors;
        $this->includeStackTraces = $includeStackTraces;
        $this->maxTraceDepth = max(1, $maxTraceDepth);
        $this->keyPadding = 15;
    }

    /**
     * Enable/disable ANSI colors
     */
    public function setUseColors(bool $useColors): self
    {
        $this->useColors = $useColors;

        return $this;
    }

    /**
     * Set key padding for alignment
     */
    public function setKeyPadding(int $padding): self
    {
        $this->keyPadding = max(10, min(40, $padding));

        return $this;
    }

    /**
     * Format a single log record
     */
    public function format(LogRecord $record): string
    {
        $output = [];

        // Top border with header
        $output[] = $this->topBorder();
        $output[] = $this->headerLine($record);
        $output[] = $this->separator();

        // Message
        $output[] = $this->formatSection('MESSAGE', $record->message);

        // Context (if not empty)
        $context = $this->normalize($record->context);
        if (!empty($context) && is_array($context)) {
            $output[] = $this->separator();
            $output[] = $this->formatKeyValueSection('CONTEXT', $context);
        }

        // Extra (if not empty)
        $extra = $this->normalize($record->extra);
        if (!empty($extra) && is_array($extra)) {
            $output[] = $this->separator();
            $output[] = $this->formatKeyValueSection('EXTRA', $extra);
        }

        // Exception (if present)
        if (isset($record->context['exception']) && $record->context['exception'] instanceof \Throwable) {
            $output[] = $this->separator();
            $output[] = $this->formatException($record->context['exception']);
        }

        // Bottom border
        $output[] = $this->bottomBorder();
        $output[] = ''; // Empty line after each record

        return implode("\n", $output) . "\n";
    }

    /**
     * Format a batch of records
     *
     * @param array<LogRecord> $records
     */
    public function formatBatch(array $records): string
    {
        $output = '';

        foreach ($records as $record) {
            $output .= $this->format($record);
        }

        return $output;
    }

    /**
     * Create top border line
     */
    private function topBorder(): string
    {
        return self::CORNER_TOP_LEFT . str_repeat(self::HORIZONTAL, self::BOX_WIDTH - 2) . self::CORNER_TOP_RIGHT;
    }

    /**
     * Create bottom border line
     */
    private function bottomBorder(): string
    {
        return self::CORNER_BOTTOM_LEFT . str_repeat(self::HORIZONTAL, self::BOX_WIDTH - 2) . self::CORNER_BOTTOM_RIGHT;
    }

    /**
     * Create separator line
     */
    private function separator(): string
    {
        return self::T_LEFT . str_repeat(self::HORIZONTAL, self::BOX_WIDTH - 2) . self::T_RIGHT;
    }

    /**
     * Format header line with timestamp, level, and channel
     */
    private function headerLine(LogRecord $record): string
    {
        $timestamp = $record->datetime->format($this->dateFormat);
        $level = $record->level->name;
        $channel = $record->channel;

        $levelFormatted = $this->colorize($level, self::LEVEL_COLORS[$level] ?? '');
        $timestampFormatted = $this->colorize($timestamp, self::DIM);
        $channelFormatted = $this->colorize($channel, self::BOLD);

        return self::VERTICAL . ' ' . $timestampFormatted . ' ' . self::VERTICAL . ' ' .
               $levelFormatted . ' ' . self::VERTICAL . ' ' . $channelFormatted;
    }

    /**
     * Format a simple section (label + text)
     */
    private function formatSection(string $label, string $text): string
    {
        $labelFormatted = $this->colorize($label . ':', self::BOLD);

        // Wrap long messages
        $maxWidth = self::BOX_WIDTH - 4;
        $lines = $this->wrapText($text, $maxWidth);

        $output = self::VERTICAL . ' ' . $labelFormatted . ' ' . array_shift($lines);

        foreach ($lines as $line) {
            $output .= "\n" . self::VERTICAL . '   ' . $line;
        }

        return $output;
    }

    /**
     * Format a key-value section (CONTEXT or EXTRA)
     *
     * @param array<string, mixed> $data
     */
    private function formatKeyValueSection(string $label, array $data): string
    {
        $labelFormatted = $this->colorize($label . ':', self::BOLD);
        $output = self::VERTICAL . ' ' . $labelFormatted;

        foreach ($data as $key => $value) {
            // Skip exception in context (handled separately)
            if ($key === 'exception' && $value instanceof \Throwable) {
                continue;
            }

            $keyFormatted = str_pad($key, $this->keyPadding);
            $dots = $this->colorize(str_repeat('.', max(1, $this->keyPadding - strlen($key) + 3)), self::DIM);
            $valueFormatted = $this->formatValue($value);

            $output .= "\n" . self::VERTICAL . '   ' . $keyFormatted . ' ' . $dots . ' ' . $valueFormatted;
        }

        return $output;
    }

    /**
     * Format exception with stack trace
     */
    private function formatException(\Throwable $exception): string
    {
        $exceptionClass = get_class($exception);
        $labelFormatted = $this->colorize('EXCEPTION:', self::BOLD);
        $classFormatted = $this->colorize($exceptionClass, self::LEVEL_COLORS['ERROR'] ?? '');

        $output = self::VERTICAL . ' ' . $labelFormatted . ' ' . $classFormatted;

        // Message
        $output .= "\n" . self::VERTICAL . '   ' .
                   $this->colorize('Message:', self::DIM) . ' ' . $exception->getMessage();

        // Code
        if ($exception->getCode() !== 0) {
            $output .= "\n" . self::VERTICAL . '   ' .
                       $this->colorize('Code:', self::DIM) . ' ' . $exception->getCode();
        }

        // File and line
        $output .= "\n" . self::VERTICAL . '   ' .
                   $this->colorize('File:', self::DIM) . ' ' .
                   $exception->getFile() . ':' . $exception->getLine();

        // Stack trace
        if ($this->includeStackTraces) {
            $output .= "\n" . self::VERTICAL . '   ' . $this->colorize('Trace:', self::DIM);

            $trace = $exception->getTrace();
            $frameCount = min(count($trace), $this->maxTraceDepth);

            for ($i = 0; $i < $frameCount; $i++) {
                $frame = $trace[$i];
                $file = $frame['file'] ?? 'unknown';
                $line = $frame['line'] ?? '?';
                $class = $frame['class'] ?? '';
                $type = $frame['type'] ?? '';
                $function = $frame['function'] ?? 'unknown';

                $call = $class . $type . $function . '()';
                $location = basename($file) . ':' . $line;

                $output .= "\n" . self::VERTICAL . '     ' .
                           $this->colorize('#' . $i, self::DIM) . ' ' .
                           $location . ' ' . $this->colorize('→', self::DIM) . ' ' . $call;
            }

            if (count($trace) > $this->maxTraceDepth) {
                $remaining = count($trace) - $this->maxTraceDepth;
                $output .= "\n" . self::VERTICAL . '     ' .
                           $this->colorize("... and {$remaining} more frames", self::DIM);
            }
        }

        // Previous exception
        $previous = $exception->getPrevious();
        if ($previous !== null) {
            $output .= "\n" . self::VERTICAL . '   ' .
                       $this->colorize('Caused by:', self::DIM) . ' ' .
                       get_class($previous) . ': ' . $previous->getMessage();
        }

        return $output;
    }

    /**
     * Format a value for display
     */
    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return $this->colorize('null', self::DIM);
        }

        if (is_bool($value)) {
            return $this->colorize($value ? 'true' : 'false', self::DIM);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            // Truncate long strings
            if (strlen($value) > 100) {
                return substr($value, 0, 100) . $this->colorize('...', self::DIM);
            }

            return $value;
        }

        if (is_array($value)) {
            if (empty($value)) {
                return $this->colorize('[]', self::DIM);
            }

            // Check if it's a simple array (non-nested)
            $isSimple = true;
            foreach ($value as $v) {
                if (is_array($v) || is_object($v)) {
                    $isSimple = false;
                    break;
                }
            }

            if ($isSimple && count($value) <= 5) {
                $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                return $json !== false ? $json : '[...]';
            }

            return $this->colorize('[array with ' . count($value) . ' items]', self::DIM);
        }

        if (is_object($value)) {
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }

            if ($value instanceof \JsonSerializable) {
                $json = json_encode($value->jsonSerialize(), JSON_UNESCAPED_SLASHES);
                if ($json !== false && strlen($json) <= 100) {
                    return $json;
                }
            }

            return $this->colorize('[' . get_class($value) . ']', self::DIM);
        }

        return $this->colorize('[' . gettype($value) . ']', self::DIM);
    }

    /**
     * Apply ANSI color code
     */
    private function colorize(string $text, string $colorCode): string
    {
        if (!$this->useColors || $colorCode === '') {
            return $text;
        }

        return $colorCode . $text . self::RESET_COLOR;
    }

    /**
     * Wrap text to fit within width
     *
     * @return array<string>
     */
    private function wrapText(string $text, int $width): array
    {
        // Handle multi-line messages
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $paragraphs = explode("\n", $text);

        $lines = [];
        foreach ($paragraphs as $paragraph) {
            if (strlen($paragraph) <= $width) {
                $lines[] = $paragraph;
            } else {
                $wrapped = wordwrap($paragraph, $width, "\n", true);
                $lines = array_merge($lines, explode("\n", $wrapped));
            }
        }

        return $lines ?: [''];
    }
}
