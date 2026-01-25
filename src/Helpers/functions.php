<?php

declare(strict_types=1);

/**
 * Enterprise PSR-3 Logger Helper Functions
 *
 * Convenience functions for quick logging access.
 * These are optional - you can use the Logger class directly.
 */

namespace AdosLabs\EnterprisePSR3Logger;

use Psr\Log\LoggerInterface;

if (!function_exists('AdosLabs\EnterprisePSR3Logger\logger')) {
    /**
     * Get or create a logger instance
     *
     * @param string|null $channel Channel name
     * @return LoggerInterface|null
     *
     * @example
     * ```php
     * use function AdosLabs\EnterprisePSR3Logger\logger;
     *
     * logger()->info('Hello world');
     * logger('security')->warning('Suspicious activity');
     * ```
     */
    function logger(?string $channel = null): ?LoggerInterface
    {
        return LoggerRegistry::get($channel);
    }
}

if (!function_exists('AdosLabs\EnterprisePSR3Logger\log_debug')) {
    /**
     * Log a debug message
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     * @param string|null $channel Channel name
     */
    function log_debug(string $message, array $context = [], ?string $channel = null): void
    {
        LoggerRegistry::get($channel)?->debug($message, $context);
    }
}

if (!function_exists('AdosLabs\EnterprisePSR3Logger\log_info')) {
    /**
     * Log an info message
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     * @param string|null $channel Channel name
     */
    function log_info(string $message, array $context = [], ?string $channel = null): void
    {
        LoggerRegistry::get($channel)?->info($message, $context);
    }
}

if (!function_exists('AdosLabs\EnterprisePSR3Logger\log_warning')) {
    /**
     * Log a warning message
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     * @param string|null $channel Channel name
     */
    function log_warning(string $message, array $context = [], ?string $channel = null): void
    {
        LoggerRegistry::get($channel)?->warning($message, $context);
    }
}

if (!function_exists('AdosLabs\EnterprisePSR3Logger\log_error')) {
    /**
     * Log an error message
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     * @param string|null $channel Channel name
     */
    function log_error(string $message, array $context = [], ?string $channel = null): void
    {
        LoggerRegistry::get($channel)?->error($message, $context);
    }
}

if (!function_exists('AdosLabs\EnterprisePSR3Logger\log_critical')) {
    /**
     * Log a critical message
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Context data
     * @param string|null $channel Channel name
     */
    function log_critical(string $message, array $context = [], ?string $channel = null): void
    {
        LoggerRegistry::get($channel)?->critical($message, $context);
    }
}

if (!function_exists('AdosLabs\EnterprisePSR3Logger\log_exception')) {
    /**
     * Log an exception with context
     *
     * @param \Throwable $exception Exception to log
     * @param string $message Additional message
     * @param array<string, mixed> $context Extra context data
     * @param string|null $channel Channel name
     */
    function log_exception(
        \Throwable $exception,
        string $message = 'Exception occurred',
        array $context = [],
        ?string $channel = null,
    ): void {
        $context['exception'] = $exception;

        LoggerRegistry::get($channel)?->error($message, $context);
    }
}
