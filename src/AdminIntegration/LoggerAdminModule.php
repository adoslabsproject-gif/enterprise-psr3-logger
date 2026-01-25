<?php

declare(strict_types=1);

namespace Senza1dio\EnterprisePSR3Logger\AdminIntegration;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use AdosLabs\AdminPanel\Core\AdminModuleInterface;

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

    public function __construct(
        private PDO $pdo,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
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
        return [
            [
                'id' => 'logger',
                'label' => 'Logs',
                'url' => '/admin/logger',
                'icon' => 'file-text',
                'priority' => 50,
                'children' => [
                    [
                        'id' => 'logger-dashboard',
                        'label' => 'Dashboard',
                        'url' => '/admin/logger',
                        'icon' => 'activity',
                    ],
                    [
                        'id' => 'logger-database',
                        'label' => 'Database Logs',
                        'url' => '/admin/logger/database',
                        'icon' => 'database',
                    ],
                    [
                        'id' => 'logger-php-errors',
                        'label' => 'PHP Errors',
                        'url' => '/admin/logger/php-errors',
                        'icon' => 'alert-triangle',
                    ],
                ],
            ],
        ];
    }

    public function getRoutes(): array
    {
        $controller = Controllers\LoggerController::class;

        return [
            // Dashboard
            ['method' => 'GET', 'path' => '/admin/logger', 'handler' => [$controller, 'index']],

            // Channel configuration
            ['method' => 'POST', 'path' => '/admin/logger/channel/update', 'handler' => [$controller, 'updateChannel']],

            // Telegram configuration
            ['method' => 'POST', 'path' => '/admin/logger/telegram/update', 'handler' => [$controller, 'updateTelegram']],
            ['method' => 'POST', 'path' => '/admin/logger/telegram/test', 'handler' => [$controller, 'testTelegram']],

            // File management
            ['method' => 'GET', 'path' => '/admin/logger/file/view', 'handler' => [$controller, 'viewFile']],
            ['method' => 'GET', 'path' => '/admin/logger/file/download', 'handler' => [$controller, 'downloadFile']],
            ['method' => 'POST', 'path' => '/admin/logger/file/delete', 'handler' => [$controller, 'deleteFiles']],

            // PHP errors
            ['method' => 'GET', 'path' => '/admin/logger/php-errors', 'handler' => [$controller, 'phpErrors']],
            ['method' => 'POST', 'path' => '/admin/logger/php-errors/clear', 'handler' => [$controller, 'clearPhpErrors']],

            // Database logs
            ['method' => 'GET', 'path' => '/admin/logger/database', 'handler' => [$controller, 'databaseLogs']],
            ['method' => 'POST', 'path' => '/admin/logger/database/clear', 'handler' => [$controller, 'clearDatabaseLogs']],
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

        // Run migration to create config entries
        $this->runMigration();

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
                fn($s) => !empty($s) && !str_starts_with($s, '--')
            );

            foreach ($statements as $statement) {
                if (empty($statement)) {
                    continue;
                }
                $this->pdo->exec($statement);
            }

            $this->logger->info('Migration executed successfully');
        } catch (\PDOException $e) {
            $this->logger->error('Migration failed', ['error' => $e->getMessage()]);
        }
    }
}
