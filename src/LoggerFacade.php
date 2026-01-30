<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger;

/**
 * Logger Facade - Static API for Channel-Based Logging
 *
 * ENTERPRISE GALAXY: Static facade for channel-based logging with clean syntax
 *
 * Usage:
 * ```php
 * use AdosLabs\EnterprisePSR3Logger\LoggerFacade as Logger;
 *
 * Logger::security('warning', 'Failed login', ['ip' => '1.2.3.4']);
 * Logger::api('info', 'HTTP Request', ['method' => 'GET', 'uri' => '/api/users']);
 * Logger::database('error', 'Query failed', ['query' => $sql]);
 * Logger::email('debug', 'Email sent', ['to' => 'user@example.com']);
 * ```
 *
 * Channel methods:
 * - Logger::default($level, $message, $context = [])
 * - Logger::security($level, $message, $context = [])
 * - Logger::api($level, $message, $context = [])
 * - Logger::database($level, $message, $context = [])
 * - Logger::email($level, $message, $context = [])
 * - Logger::debug_general($level, $message, $context = [])
 * - Logger::performance($level, $message, $context = [])
 * - Logger::js_errors($level, $message, $context = [])
 *
 * Convenience methods (channel = 'default'):
 * - Logger::error($message, $context = [])
 * - Logger::warning($message, $context = [])
 * - Logger::info($message, $context = [])
 * - Logger::debug($message, $context = [])
 */
class LoggerFacade
{
    /**
     * Default timezone for logs (Europe/Rome = CET/CEST)
     */
    private const DEFAULT_TIMEZONE = 'Europe/Rome';

    /**
     * @var array<string, Logger> Registered loggers per channel
     */
    private static array $loggers = [];

    /**
     * Factory for creating new loggers
     * @var (\Closure(string): Logger)|null
     */
    private static ?\Closure $loggerFactory = null;

    /**
     * @var bool Whether timezone has been initialized
     */
    private static bool $timezoneInitialized = false;

    /**
     * Set the logger factory
     *
     * @param \Closure(string): Logger $factory function(string $channel): Logger
     */
    public static function setLoggerFactory(\Closure $factory): void
    {
        self::$loggerFactory = $factory;
    }

    /**
     * Get or create logger for channel
     *
     * @param string $channel Channel name
     * @return Logger
     */
    private static function getLogger(string $channel): Logger
    {
        // Ensure timezone is set before any logging
        self::ensureTimezone();

        if (!isset(self::$loggers[$channel])) {
            if (self::$loggerFactory !== null) {
                self::$loggers[$channel] = (self::$loggerFactory)($channel);
            } else {
                // Try LoggerRegistry first
                $registeredLogger = LoggerRegistry::get($channel);

                if ($registeredLogger instanceof Logger) {
                    self::$loggers[$channel] = $registeredLogger;
                } else {
                    // Create logger with default RotatingFileHandler
                    self::$loggers[$channel] = self::createDefaultLogger($channel);
                }
            }
        }

        return self::$loggers[$channel];
    }

    /**
     * Create a default logger with RotatingFileHandler
     *
     * This is used when no logger is registered in LoggerRegistry.
     * Writes to storage/logs/{channel}-{date}.log
     *
     * @param string $channel Channel name
     * @return Logger
     */
    private static function createDefaultLogger(string $channel): Logger
    {
        // Determine logs path
        $logsPath = self::getLogsPath();

        // Create rotating file handler
        $handler = new Handlers\RotatingFileHandler(
            filename: $logsPath . '/' . $channel . '.log',
            level: \Monolog\Level::Debug,
            rotationType: Handlers\RotatingFileHandler::ROTATION_DAILY,
            maxFiles: 14,
        );

        // Use DetailedLineFormatter for human-readable logs
        $handler->setFormatter(new Formatters\DetailedLineFormatter());

        $logger = new Logger($channel, [$handler]);

        // Add standard processors
        $logger->addProcessor(new Processors\RequestProcessor());
        $logger->addProcessor(new Processors\MemoryProcessor());

        return $logger;
    }

    /**
     * Get logs path from environment or default
     *
     * Priority:
     * 1. LOG_PATH environment variable
     * 2. EAP_PROJECT_ROOT/storage/logs
     * 3. getcwd()/storage/logs
     *
     * @return string Logs directory path
     */
    private static function getLogsPath(): string
    {
        // Check environment variable
        $logPath = $_ENV['LOG_PATH'] ?? getenv('LOG_PATH');
        if (is_string($logPath) && $logPath !== '') {
            return $logPath;
        }

        // Check project root constant
        if (defined('EAP_PROJECT_ROOT')) {
            return EAP_PROJECT_ROOT . '/storage/logs';
        }

        // Default to current working directory
        return getcwd() . '/storage/logs';
    }

    /**
     * Ensure timezone is properly set for logging
     *
     * Priority:
     * 1. APP_TIMEZONE environment variable
     * 2. date.timezone from php.ini (if not empty/UTC)
     * 3. Default to Europe/Rome
     *
     * This is called once per request to ensure Monolog uses local time.
     */
    private static function ensureTimezone(): void
    {
        if (self::$timezoneInitialized) {
            return;
        }

        self::$timezoneInitialized = true;

        // Check APP_TIMEZONE environment variable
        $envTimezone = $_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE');
        $timezone = is_string($envTimezone) && $envTimezone !== '' ? $envTimezone : null;

        if ($timezone === null) {
            // Check php.ini date.timezone
            $iniTimezone = ini_get('date.timezone');
            // Use ini timezone only if it's set and NOT UTC (common misconfiguration)
            if (is_string($iniTimezone) && $iniTimezone !== '' && strtoupper($iniTimezone) !== 'UTC') {
                $timezone = $iniTimezone;
            }
        }

        // Default to Europe/Rome if still not set or is UTC
        if ($timezone === null || strtoupper($timezone) === 'UTC') {
            $timezone = self::DEFAULT_TIMEZONE;
        }

        try {
            date_default_timezone_set($timezone);
        } catch (\Throwable $e) {
            // Fallback to default on any error
            error_log('LoggerFacade: Invalid timezone "' . $timezone . '", falling back to ' . self::DEFAULT_TIMEZONE);
            date_default_timezone_set(self::DEFAULT_TIMEZONE);
        }
    }

    // ========================================================================
    // CHANNEL-BASED LOGGING (static methods)
    // ========================================================================

    /**
     * Log to 'default' channel
     *
     * @param string $level Log level (debug, info, warning, error, etc.)
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     */
    public static function default(string $level, string $message, array $context = []): void
    {
        self::getLogger('default')->log($level, $message, $context);
    }

    /**
     * Log to 'security' channel
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     */
    public static function security(string $level, string $message, array $context = []): void
    {
        self::getLogger('security')->log($level, $message, $context);
    }

    /**
     * Log to 'api' channel
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     */
    public static function api(string $level, string $message, array $context = []): void
    {
        self::getLogger('api')->log($level, $message, $context);
    }

    /**
     * Log to 'database' channel
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     */
    public static function database(string $level, string $message, array $context = []): void
    {
        self::getLogger('database')->log($level, $message, $context);
    }

    /**
     * Log to 'email' channel
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     */
    public static function email(string $level, string $message, array $context = []): void
    {
        self::getLogger('email')->log($level, $message, $context);
    }

    /**
     * Log to 'debug_general' channel
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     */
    public static function debug_general(string $level, string $message, array $context = []): void
    {
        self::getLogger('debug_general')->log($level, $message, $context);
    }

    /**
     * Log to 'performance' channel
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     */
    public static function performance(string $level, string $message, array $context = []): void
    {
        self::getLogger('performance')->log($level, $message, $context);
    }

    /**
     * Log to 'js_errors' channel (JavaScript frontend errors)
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     */
    public static function js_errors(string $level, string $message, array $context = []): void
    {
        self::getLogger('js_errors')->log($level, $message, $context);
    }

    /**
     * Get logger for a specific channel (fluent API)
     *
     * Usage:
     * ```php
     * Logger::channel('security')->warning('Failed login', ['ip' => '1.2.3.4']);
     * Logger::channel('error')->error('Database connection failed', ['error' => $e->getMessage()]);
     * Logger::channel('api')->info('Request received', ['method' => 'GET']);
     * ```
     *
     * @param string $channel Channel name
     * @return Logger The logger instance for method chaining
     */
    public static function channel(string $channel): Logger
    {
        return self::getLogger($channel);
    }

    // ========================================================================
    // CONVENIENCE METHODS (channel = 'default')
    // ========================================================================

    /**
     * Log error to 'default' channel
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     */
    public static function error(string $message, array $context = []): void
    {
        self::getLogger('default')->error($message, $context);
    }

    /**
     * Log warning to 'default' channel
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     */
    public static function warning(string $message, array $context = []): void
    {
        self::getLogger('default')->warning($message, $context);
    }

    /**
     * Log info to 'default' channel
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     */
    public static function info(string $message, array $context = []): void
    {
        self::getLogger('default')->info($message, $context);
    }

    /**
     * Log debug to 'default' channel
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     */
    public static function debug(string $message, array $context = []): void
    {
        self::getLogger('default')->debug($message, $context);
    }

    /**
     * Log notice to 'default' channel
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     */
    public static function notice(string $message, array $context = []): void
    {
        self::getLogger('default')->notice($message, $context);
    }

    /**
     * Log critical to 'default' channel
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     */
    public static function critical(string $message, array $context = []): void
    {
        self::getLogger('default')->critical($message, $context);
    }

    /**
     * Log alert to 'default' channel
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     */
    public static function alert(string $message, array $context = []): void
    {
        self::getLogger('default')->alert($message, $context);
    }

    /**
     * Log emergency to 'default' channel
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     */
    public static function emergency(string $message, array $context = []): void
    {
        self::getLogger('default')->emergency($message, $context);
    }

    // ========================================================================
    // UTILITY METHODS
    // ========================================================================

    /**
     * Register a logger for a specific channel
     *
     * @param string $channel Channel name
     * @param Logger $logger Logger instance
     */
    public static function registerLogger(string $channel, Logger $logger): void
    {
        self::$loggers[$channel] = $logger;
    }

    /**
     * Clear all registered loggers (useful for testing)
     */
    public static function clearLoggers(): void
    {
        self::$loggers = [];
    }
}
