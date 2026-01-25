<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger;

use AdosLabs\EnterprisePSR3Logger\Formatters\DetailedLineFormatter;
use AdosLabs\EnterprisePSR3Logger\Formatters\JsonFormatter;
use AdosLabs\EnterprisePSR3Logger\Formatters\PrettyFormatter;
use AdosLabs\EnterprisePSR3Logger\Handlers\FilterHandler;
use AdosLabs\EnterprisePSR3Logger\Handlers\RotatingFileHandler;
use AdosLabs\EnterprisePSR3Logger\Handlers\StreamHandler;
use AdosLabs\EnterprisePSR3Logger\Processors\ExecutionTimeProcessor;
use AdosLabs\EnterprisePSR3Logger\Processors\HostnameProcessor;
use AdosLabs\EnterprisePSR3Logger\Processors\MemoryProcessor;
use AdosLabs\EnterprisePSR3Logger\Processors\RequestProcessor;
use Monolog\Level;

/**
 * Logger Factory
 *
 * Factory methods for common logging configurations.
 * Provides pre-configured setups for different environments.
 *
 * AVAILABLE CONFIGURATIONS:
 * - development(): Colorful, human-readable output to stdout
 * - production(): JSON output to file with rotation
 * - container(): JSON to stdout for Docker/Kubernetes
 * - minimal(): Simple file logging
 * - full(): Everything enabled
 *
 * USAGE:
 * ```php
 * // Development (colored output to terminal)
 * $logger = LoggerFactory::development('my-app');
 *
 * // Production (JSON to rotating files)
 * $logger = LoggerFactory::production('my-app', '/var/log/app');
 *
 * // Container (JSON to stdout)
 * $logger = LoggerFactory::container('my-app');
 * ```
 */
class LoggerFactory
{
    /**
     * Create a development logger
     *
     * Features:
     * - Pretty formatted output with colors
     * - Outputs to stdout
     * - All log levels enabled
     * - Shows memory, execution time, request info
     *
     * @param string $channel Channel name
     * @param bool $useColors Use ANSI colors
     * @return Logger
     */
    public static function development(
        string $channel = 'app',
        bool $useColors = true,
    ): Logger {
        $handler = new StreamHandler('php://stdout', Level::Debug);
        $handler->setFormatter(new PrettyFormatter(useColors: $useColors));

        $logger = new Logger($channel, [$handler]);

        $logger->addProcessor(new RequestProcessor());
        $logger->addProcessor(new MemoryProcessor());
        $logger->addProcessor(new ExecutionTimeProcessor());

        return $logger;
    }

    /**
     * Create a production logger
     *
     * Features:
     * - JSON formatted output
     * - Daily rotating files
     * - Separate error log
     * - 14 days retention
     * - Request tracking
     * - Host info for distributed systems
     *
     * @param string $channel Channel name
     * @param string $logDir Log directory path
     * @param Level $minLevel Minimum log level
     * @param int $maxFiles Maximum files to retain
     * @param bool $compress Compress rotated files
     * @return Logger
     */
    public static function production(
        string $channel = 'app',
        string $logDir = '/var/log/app',
        Level $minLevel = Level::Info,
        int $maxFiles = 14,
        bool $compress = true,
    ): Logger {
        // Main log (all levels)
        $mainHandler = new RotatingFileHandler(
            filename: "{$logDir}/{$channel}.log",
            level: $minLevel,
            rotationType: RotatingFileHandler::ROTATION_DAILY,
            maxFiles: $maxFiles,
            compress: $compress,
        );
        $mainHandler->setFormatter(new JsonFormatter());

        // Error log (errors only)
        $errorHandler = new FilterHandler(
            new RotatingFileHandler(
                filename: "{$logDir}/{$channel}-error.log",
                level: Level::Error,
                rotationType: RotatingFileHandler::ROTATION_DAILY,
                maxFiles: $maxFiles * 2, // Keep error logs longer
                compress: $compress,
            ),
            minLevel: Level::Error,
        );
        $errorHandler->getHandler()->setFormatter(new JsonFormatter());

        $logger = new Logger($channel, [$mainHandler, $errorHandler]);

        $logger->addProcessor(new RequestProcessor());
        $logger->addProcessor(new HostnameProcessor(environment: 'production'));
        $logger->addProcessor(new ExecutionTimeProcessor());

        return $logger;
    }

    /**
     * Create a container-optimized logger
     *
     * Features:
     * - JSON output to stdout (for log aggregation)
     * - No file management needed
     * - Compact format
     * - Request and host tracking
     *
     * @param string $channel Channel name
     * @param Level $minLevel Minimum log level
     * @param string|null $environment Environment name
     * @return Logger
     */
    public static function container(
        string $channel = 'app',
        Level $minLevel = Level::Info,
        ?string $environment = null,
    ): Logger {
        $handler = new StreamHandler('php://stdout', $minLevel);
        $handler->setFormatter(new JsonFormatter(
            appendNewline: true,
            ignoreEmptyContextAndExtra: true,
        ));

        $logger = new Logger($channel, [$handler]);

        $logger->addProcessor(new RequestProcessor());
        $logger->addProcessor(new HostnameProcessor(environment: $environment));

        return $logger;
    }

    /**
     * Create a minimal logger
     *
     * Features:
     * - Simple line format
     * - Single file
     * - No rotation
     * - No processors
     *
     * @param string $channel Channel name
     * @param string $logFile Log file path
     * @param Level $minLevel Minimum log level
     * @return Logger
     */
    public static function minimal(
        string $channel = 'app',
        string $logFile = '/var/log/app.log',
        Level $minLevel = Level::Info,
    ): Logger {
        $handler = new StreamHandler($logFile, $minLevel, useLocking: true);
        $handler->setFormatter(new DetailedLineFormatter(multiLine: false));

        return new Logger($channel, [$handler]);
    }

    /**
     * Create a fully-featured logger
     *
     * Features:
     * - All processors enabled
     * - Multiple output formats
     * - File rotation
     * - Compression
     *
     * @param string $channel Channel name
     * @param string $logDir Log directory
     * @param bool $alsoPrintToStdout Also print to stdout
     * @return Logger
     */
    public static function full(
        string $channel = 'app',
        string $logDir = '/var/log/app',
        bool $alsoPrintToStdout = false,
    ): Logger {
        $handlers = [];

        // Main JSON log
        $jsonHandler = new RotatingFileHandler(
            filename: "{$logDir}/{$channel}.json.log",
            level: Level::Debug,
            rotationType: RotatingFileHandler::ROTATION_DAILY,
            maxFiles: 30,
            compress: true,
        );
        $jsonHandler->setFormatter(new JsonFormatter());
        $handlers[] = $jsonHandler;

        // Human-readable log
        $textHandler = new RotatingFileHandler(
            filename: "{$logDir}/{$channel}.log",
            level: Level::Info,
            rotationType: RotatingFileHandler::ROTATION_DAILY,
            maxFiles: 14,
        );
        $textHandler->setFormatter(new DetailedLineFormatter());
        $handlers[] = $textHandler;

        // Error log
        $errorHandler = new FilterHandler(
            new RotatingFileHandler(
                filename: "{$logDir}/{$channel}-error.log",
                level: Level::Error,
                rotationType: RotatingFileHandler::ROTATION_DAILY,
                maxFiles: 60,
                compress: true,
            ),
            minLevel: Level::Error,
        );
        $handlers[] = $errorHandler;

        // Stdout (optional)
        if ($alsoPrintToStdout) {
            $stdoutHandler = new StreamHandler('php://stdout', Level::Debug);
            $stdoutHandler->setFormatter(new PrettyFormatter(useColors: true));
            $handlers[] = $stdoutHandler;
        }

        $logger = new Logger($channel, $handlers);

        // All processors
        $logger->addProcessor(new RequestProcessor());
        $logger->addProcessor(new HostnameProcessor(includePhpVersion: true));
        $logger->addProcessor(new MemoryProcessor(includePeak: true, includePercent: true));
        $logger->addProcessor(new ExecutionTimeProcessor());

        return $logger;
    }

    /**
     * Create a logger from configuration array
     *
     * @param array{
     *     channel?: string,
     *     level?: string,
     *     handlers?: array<array{type: string, ...}>,
     *     processors?: array<string>,
     *     context?: array<string, mixed>
     * } $config Configuration array
     * @return Logger
     */
    public static function fromConfig(array $config): Logger
    {
        $channel = $config['channel'] ?? 'app';
        $handlers = [];
        $processors = [];

        // Parse handlers
        foreach ($config['handlers'] ?? [] as $handlerConfig) {
            $handler = self::createHandlerFromConfig($handlerConfig);
            if ($handler !== null) {
                $handlers[] = $handler;
            }
        }

        // Parse processors
        foreach ($config['processors'] ?? [] as $processorName) {
            $processor = self::createProcessor($processorName);
            if ($processor !== null) {
                $processors[] = $processor;
            }
        }

        // Default handler if none specified
        if (empty($handlers)) {
            $handlers[] = new StreamHandler('php://stderr', Level::Debug);
        }

        $logger = new Logger($channel, $handlers, $processors);

        // Set global context
        if (!empty($config['context'])) {
            $logger->setGlobalContext($config['context']);
        }

        return $logger;
    }

    /**
     * Create a handler from configuration
     *
     * @param array<string, mixed> $config
     * @return \Monolog\Handler\HandlerInterface|null
     */
    private static function createHandlerFromConfig(array $config): ?\Monolog\Handler\HandlerInterface
    {
        $type = $config['type'] ?? 'stream';
        $level = self::parseLevel($config['level'] ?? 'debug');

        switch ($type) {
            case 'stream':
                $handler = new StreamHandler(
                    $config['path'] ?? 'php://stderr',
                    $level,
                );
                break;

            case 'rotating':
                $handler = new RotatingFileHandler(
                    filename: $config['path'] ?? '/var/log/app.log',
                    level: $level,
                    rotationType: $config['rotation'] ?? RotatingFileHandler::ROTATION_DAILY,
                    maxFiles: $config['max_files'] ?? 14,
                    compress: $config['compress'] ?? false,
                );
                break;

            case 'syslog':
                $handler = new Handlers\SyslogHandler(
                    $config['ident'] ?? 'app',
                    $config['facility'] ?? LOG_USER,
                    $level,
                );
                break;

            case 'errorlog':
                $handler = new Handlers\ErrorLogHandler($level);
                break;

            default:
                return null;
        }

        // Set formatter if specified
        if (isset($config['formatter'])) {
            $formatter = self::createFormatter($config['formatter']);
            if ($formatter !== null) {
                $handler->setFormatter($formatter);
            }
        }

        return $handler;
    }

    /**
     * Create a formatter by name
     */
    private static function createFormatter(string $name): ?\Monolog\Formatter\FormatterInterface
    {
        return match ($name) {
            'json' => new JsonFormatter(),
            'line' => new Formatters\LineFormatter(),
            'detailed' => new DetailedLineFormatter(),
            'pretty' => new PrettyFormatter(),
            default => null,
        };
    }

    /**
     * Create a processor by name
     */
    private static function createProcessor(string $name): ?\Monolog\Processor\ProcessorInterface
    {
        return match ($name) {
            'request' => new RequestProcessor(),
            'memory' => new MemoryProcessor(),
            'execution_time' => new ExecutionTimeProcessor(),
            'hostname' => new HostnameProcessor(),
            default => null,
        };
    }

    /**
     * Parse level string to Level enum
     */
    private static function parseLevel(string $level): Level
    {
        return match (strtolower($level)) {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning', 'warn' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => Level::Debug,
        };
    }
}
