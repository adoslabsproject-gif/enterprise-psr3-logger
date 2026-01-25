<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger;

use Psr\Log\LoggerInterface;

/**
 * Global Logger Registry
 *
 * Provides global access to registered logger instances.
 * Useful for applications that need a simple way to access loggers
 * without dependency injection.
 *
 * USAGE:
 * ```php
 * // Register a logger
 * LoggerRegistry::register($logger, 'app', setAsDefault: true);
 *
 * // Get the logger
 * $logger = LoggerRegistry::get('app');
 * $logger->info('Hello');
 *
 * // Get default logger
 * LoggerRegistry::get()->info('Using default');
 * ```
 */
final class LoggerRegistry
{
    /** @var array<string, LoggerInterface> */
    private static array $loggers = [];

    private static ?string $defaultChannel = null;

    /**
     * Register a logger instance
     *
     * @param LoggerInterface $logger Logger instance
     * @param string $channel Channel name
     * @param bool $setAsDefault Set as default logger
     */
    public static function register(
        LoggerInterface $logger,
        string $channel = 'app',
        bool $setAsDefault = false,
    ): void {
        self::$loggers[$channel] = $logger;

        if ($setAsDefault || self::$defaultChannel === null) {
            self::$defaultChannel = $channel;
        }
    }

    /**
     * Get a registered logger
     *
     * @param string|null $channel Channel name (null = default)
     * @return LoggerInterface|null
     */
    public static function get(?string $channel = null): ?LoggerInterface
    {
        $channel ??= self::$defaultChannel ?? 'app';

        return self::$loggers[$channel] ?? null;
    }

    /**
     * Check if a logger is registered
     *
     * @param string|null $channel Channel name
     * @return bool
     */
    public static function has(?string $channel = null): bool
    {
        $channel ??= self::$defaultChannel ?? 'app';

        return isset(self::$loggers[$channel]);
    }

    /**
     * Get default channel name
     */
    public static function getDefaultChannel(): ?string
    {
        return self::$defaultChannel;
    }

    /**
     * Set default channel
     */
    public static function setDefaultChannel(string $channel): void
    {
        self::$defaultChannel = $channel;
    }

    /**
     * Get all registered channels
     *
     * @return array<string>
     */
    public static function getChannels(): array
    {
        return array_keys(self::$loggers);
    }

    /**
     * Clear all registered loggers
     */
    public static function clear(): void
    {
        self::$loggers = [];
        self::$defaultChannel = null;
    }
}
