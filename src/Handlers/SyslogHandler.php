<?php

declare(strict_types=1);

namespace Senza1dio\EnterprisePSR3Logger\Handlers;

use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Syslog Handler
 *
 * Writes logs to the system syslog via PHP's syslog() function.
 * Standard for Unix/Linux systems and centralized log management.
 *
 * SYSLOG FACILITIES:
 * - LOG_USER (default): Generic user-level messages
 * - LOG_LOCAL0..LOG_LOCAL7: Reserved for local use
 * - LOG_DAEMON: System daemons
 * - LOG_AUTH: Security/authorization messages
 *
 * USAGE:
 * ```php
 * // Basic usage
 * $handler = new SyslogHandler('my-app');
 *
 * // Custom facility
 * $handler = new SyslogHandler('my-app', LOG_LOCAL0, Level::Warning);
 * ```
 *
 * VIEWING LOGS:
 * - Linux: /var/log/syslog or /var/log/messages
 * - macOS: /var/log/system.log or use Console.app
 * - journalctl -t my-app (systemd)
 */
class SyslogHandler extends AbstractProcessingHandler implements HandlerInterface
{
    /** @var array<string, int> Level to syslog priority mapping */
    private const LEVEL_TO_PRIORITY = [
        'DEBUG' => LOG_DEBUG,
        'INFO' => LOG_INFO,
        'NOTICE' => LOG_NOTICE,
        'WARNING' => LOG_WARNING,
        'ERROR' => LOG_ERR,
        'CRITICAL' => LOG_CRIT,
        'ALERT' => LOG_ALERT,
        'EMERGENCY' => LOG_EMERG,
    ];

    private string $ident;
    private int $facility;
    private int $logopts;
    private bool $isOpened = false;

    /**
     * @param string $ident Application identifier (appears in log)
     * @param int $facility Syslog facility (LOG_USER, LOG_LOCAL0, etc.)
     * @param Level $level Minimum log level
     * @param bool $bubble Whether to bubble to next handler
     * @param int $logopts Syslog options (LOG_PID, LOG_NDELAY, etc.)
     */
    public function __construct(
        string $ident,
        int $facility = LOG_USER,
        Level $level = Level::Debug,
        bool $bubble = true,
        int $logopts = LOG_PID,
    ) {
        parent::__construct($level, $bubble);

        $this->ident = $ident;
        $this->facility = $facility;
        $this->logopts = $logopts;
    }

    /**
     * {@inheritdoc}
     */
    protected function write(LogRecord $record): void
    {
        if (!$this->isOpened) {
            $this->openSyslog();
        }

        $priority = self::LEVEL_TO_PRIORITY[$record->level->name] ?? LOG_INFO;
        if ($this->formatter === null) {
            return;
        }
        $message = $this->formatter->format($record);

        // Remove trailing newline for syslog
        $message = rtrim($message, "\n\r");

        syslog($priority, $message);
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        if ($this->isOpened) {
            closelog();
            $this->isOpened = false;
        }

        parent::close();
    }

    /**
     * Destructor - ensure syslog connection is closed
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Open syslog connection
     */
    private function openSyslog(): void
    {
        openlog($this->ident, $this->logopts, $this->facility);
        $this->isOpened = true;
    }

    /**
     * Get the default formatter
     */
    protected function getDefaultFormatter(): FormatterInterface
    {
        // Syslog already includes timestamp, so use simpler format
        return new \Senza1dio\EnterprisePSR3Logger\Formatters\LineFormatter(
            format: '%channel%.%level_name%: %message% %context%',
            ignoreEmptyContextAndExtra: true,
        );
    }
}
