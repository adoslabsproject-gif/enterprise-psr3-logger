<?php

declare(strict_types=1);

namespace Senza1dio\EnterprisePSR3Logger\Handlers;

use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Error Log Handler
 *
 * Writes logs to PHP's error_log() function.
 * Useful for environments where file access is restricted.
 *
 * MESSAGE TYPES:
 * - 0: System log (default) - syslog on Unix, Event Log on Windows
 * - 1: Email (requires destination parameter)
 * - 3: File (requires destination parameter)
 * - 4: SAPI handler
 *
 * USAGE:
 * ```php
 * // Use system logger
 * $handler = new ErrorLogHandler();
 *
 * // Use specific file
 * $handler = new ErrorLogHandler(
 *     messageType: ErrorLogHandler::MESSAGE_TYPE_FILE,
 *     destination: '/var/log/app-errors.log'
 * );
 * ```
 */
class ErrorLogHandler extends AbstractProcessingHandler implements HandlerInterface
{
    public const MESSAGE_TYPE_SYSTEM = 0;
    public const MESSAGE_TYPE_EMAIL = 1;
    public const MESSAGE_TYPE_FILE = 3;
    public const MESSAGE_TYPE_SAPI = 4;

    private int $messageType;
    private ?string $destination;
    private bool $expandNewlines;

    /**
     * @param Level $level Minimum log level
     * @param bool $bubble Whether to bubble to next handler
     * @param int $messageType error_log() message type
     * @param string|null $destination Destination for file/email types
     * @param bool $expandNewlines Write each line separately
     */
    public function __construct(
        Level $level = Level::Debug,
        bool $bubble = true,
        int $messageType = self::MESSAGE_TYPE_SYSTEM,
        ?string $destination = null,
        bool $expandNewlines = false,
    ) {
        parent::__construct($level, $bubble);

        $this->messageType = $messageType;
        $this->destination = $destination;
        $this->expandNewlines = $expandNewlines;

        // Validate message type
        if ($messageType === self::MESSAGE_TYPE_EMAIL && empty($destination)) {
            throw new \InvalidArgumentException('Email destination required for MESSAGE_TYPE_EMAIL');
        }

        if ($messageType === self::MESSAGE_TYPE_FILE && empty($destination)) {
            throw new \InvalidArgumentException('File destination required for MESSAGE_TYPE_FILE');
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function write(LogRecord $record): void
    {
        if ($this->formatter === null) {
            return;
        }
        $formatted = $this->formatter->format($record);

        if ($this->expandNewlines) {
            // Split multi-line messages and write each separately
            $lines = explode("\n", $formatted);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $this->writeToLog($line);
                }
            }
        } else {
            // Write as single message
            $this->writeToLog(rtrim($formatted, "\n"));
        }
    }

    /**
     * Write to error_log
     */
    private function writeToLog(string $message): void
    {
        if ($this->destination !== null) {
            error_log($message, $this->messageType, $this->destination);
        } else {
            error_log($message, $this->messageType);
        }
    }

    /**
     * Get the default formatter
     */
    protected function getDefaultFormatter(): FormatterInterface
    {
        // Use line formatter without newlines
        $formatter = new \Senza1dio\EnterprisePSR3Logger\Formatters\LineFormatter(
            format: '[%datetime%] %channel%.%level_name%: %message% %context%',
            ignoreEmptyContextAndExtra: true,
        );

        return $formatter;
    }
}
