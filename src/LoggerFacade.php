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
     * @var array<string, Logger> Registered loggers per channel
     */
    private static array $loggers = [];

    /**
     * @var callable|null Factory for creating new loggers
     */
    private static $loggerFactory = null;

    /**
     * Set the logger factory
     *
     * @param callable $factory function(string $channel): Logger
     */
    public static function setLoggerFactory(callable $factory): void
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
        if (!isset(self::$loggers[$channel])) {
            if (self::$loggerFactory !== null) {
                self::$loggers[$channel] = (self::$loggerFactory)($channel);
            } else {
                // Default: Create logger from LoggerRegistry
                self::$loggers[$channel] = LoggerRegistry::get($channel) ?? new Logger($channel);
            }
        }

        return self::$loggers[$channel];
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
