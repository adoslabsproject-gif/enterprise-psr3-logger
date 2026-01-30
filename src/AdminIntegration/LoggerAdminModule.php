<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\AdminIntegration;

use AdosLabs\AdminPanel\Core\AdminModuleInterface;
use AdosLabs\AdminPanel\Database\Pool\DatabasePool;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * PSR3 Logger Admin Module
 *
 * Integrates Enterprise PSR3 Logger with Admin Panel.
 * Auto-discovered via composer.json extra.admin-panel.
 *
 * Features:
 * - Channel configuration with individual log levels
 * - Log file management (view, download, delete)
 * - Telegram notifications with separate level
 * - PHP errors viewer (protected)
 * - Database log viewer with filters
 */
final class LoggerAdminModule implements AdminModuleInterface
{
    private LoggerInterface $logger;
    private ?DatabasePool $dbPool = null;
    private ?PDO $pdo = null;

    /**
     * @param DatabasePool|PDO $db Database connection (DatabasePool from admin panel or raw PDO)
     * @param LoggerInterface|null $logger PSR-3 logger
     */
    public function __construct(
        DatabasePool|PDO $db,
        ?LoggerInterface $logger = null,
    ) {
        if ($db instanceof DatabasePool) {
            $this->dbPool = $db;
        } else {
            $this->pdo = $db;
        }
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Execute a SQL statement
     */
    private function exec(string $sql): void
    {
        if ($this->dbPool !== null) {
            $this->dbPool->execute($sql);
        } else {
            $this->pdo->exec($sql);
        }
    }

    /**
     * Get the database driver name
     */
    private function getDriverName(): string
    {
        if ($this->dbPool !== null) {
            // Acquire a connection to check driver
            $conn = $this->dbPool->acquire();

            try {
                $driver = $conn->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);

                return $driver;
            } finally {
                $conn->release();
            }
        }

        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function getName(): string
    {
        return 'PSR3 Logger';
    }

    public function getDescription(): string
    {
        return 'Enterprise logging with channel-based filtering, Telegram notifications, and complete log management';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getTabs(): array
    {
        // URLs are relative - the sidebar renderer adds the admin base path
        return [
            [
                'id' => 'logger',
                'label' => 'Logs',
                'url' => '/logger',
                'icon' => 'file-text',
                'priority' => 50,
                'children' => [
                    [
                        'id' => 'logger-dashboard',
                        'label' => 'Dashboard',
                        'url' => '/logger',
                        'icon' => 'activity',
                    ],
                    [
                        'id' => 'logger-security',
                        'label' => 'Security Log',
                        'url' => '/logger/security',
                        'icon' => 'shield',
                    ],
                    [
                        'id' => 'logger-telegram',
                        'label' => 'Telegram',
                        'url' => '/logger/telegram',
                        'icon' => 'send',
                    ],
                ],
            ],
        ];
    }

    public function getRoutes(): array
    {
        $controller = Controllers\LoggerController::class;

        // Routes are relative to admin base path (e.g., /x-abc123/)
        // The router matches against $relativePath which excludes the base prefix
        return [
            // Main Dashboard (channels config + log files list)
            ['method' => 'GET', 'path' => '/logger', 'handler' => [$controller, 'index']],

            // View specific log file
            ['method' => 'GET', 'path' => '/logger/view', 'handler' => [$controller, 'viewFile']],

            // Security log database viewer
            ['method' => 'GET', 'path' => '/logger/security', 'handler' => [$controller, 'securityLog']],

            // Channel configuration (AJAX)
            ['method' => 'POST', 'path' => '/logger/channel/update', 'handler' => [$controller, 'updateChannel']],

            // File actions
            ['method' => 'POST', 'path' => '/logger/file/clear', 'handler' => [$controller, 'clearFile']],
            ['method' => 'GET', 'path' => '/logger/file/download', 'handler' => [$controller, 'downloadFile']],

            // Telegram configuration page
            ['method' => 'GET', 'path' => '/logger/telegram', 'handler' => [$controller, 'telegram']],
            ['method' => 'POST', 'path' => '/logger/telegram/update', 'handler' => [$controller, 'updateTelegram']],
            ['method' => 'POST', 'path' => '/logger/telegram/test', 'handler' => [$controller, 'testTelegram']],

            // JavaScript Error Logging API (public endpoint, no auth required)
            ['method' => 'POST', 'path' => '/api/log/js-error', 'handler' => [$controller, 'logJsError'], 'public' => true],
        ];
    }

    public function getViewsPath(): ?string
    {
        return __DIR__ . '/Views';
    }

    public function getAssetsPath(): ?string
    {
        return dirname(__DIR__, 2) . '/public';
    }

    public function install(): void
    {
        $this->logger->info('Installing PSR3 Logger admin module');

        // NOTE: Tables created by migrations in enterprise-admin-panel:
        // - log_channels: channel configuration
        // - log_telegram_config: Telegram notification settings
        // - security_log: audit trail for security channel (DatabaseHandler compatible)

        // Run migration to create config entries
        $this->runMigration();

        // Assets are served directly from vendor via /module-assets/ route
        // No need to copy files

        $this->logger->info('PSR3 Logger admin module installed');
    }

    public function uninstall(): void
    {
        $this->logger->warning('Uninstalling PSR3 Logger admin module');
        // Config entries are kept for potential re-installation
    }

    public function getConfigSchema(): array
    {
        return [
            [
                'key' => 'log_files_retention_days',
                'label' => 'Log Files Retention',
                'type' => 'integer',
                'default' => 30,
                'description' => 'Days to keep log files before auto-deletion',
            ],
            [
                'key' => 'log_database_retention_days',
                'label' => 'Database Logs Retention',
                'type' => 'integer',
                'default' => 7,
                'description' => 'Days to keep logs in database',
            ],
        ];
    }

    public function getDependencies(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'logger.view',
            'logger.configure',
            'logger.clear',
            'logger.download',
        ];
    }

    // NOTE: Tables are created by enterprise-admin-panel migrations:
    // - log_channels, log_telegram_config: config tables
    // - security_log: audit trail for security channel (only channel with DB logging)

    private function runMigration(): void
    {
        $migrationFile = dirname(__DIR__, 2) . '/database/migrations/001_logger_config.sql';

        if (!file_exists($migrationFile)) {
            $this->logger->warning('Migration file not found', ['file' => $migrationFile]);

            return;
        }

        try {
            $sql = file_get_contents($migrationFile);

            // Split by semicolon and execute each statement
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                fn ($s) => !empty($s) && !str_starts_with($s, '--'),
            );

            foreach ($statements as $statement) {
                if (empty($statement)) {
                    continue;
                }
                $this->exec($statement);
            }

            $this->logger->info('Migration executed successfully');
        } catch (\PDOException|\Exception $e) {
            $this->logger->error('Migration failed', ['error' => $e->getMessage()]);
        }
    }
}
