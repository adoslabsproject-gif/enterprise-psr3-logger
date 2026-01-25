<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\AdminIntegration;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use AdosLabs\AdminPanel\Core\AdminModuleInterface;
use AdosLabs\AdminPanel\Database\Pool\DatabasePool;

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
        ?LoggerInterface $logger = null
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

        // Create logs table if not exists
        $this->createLogsTable();

        // Run migration to create config entries
        $this->runMigration();

        // Publish assets to admin panel public folder
        $this->publishAssets();

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

    /**
     * Publish module assets to admin panel public folder
     *
     * Copies CSS and JS files to /public/modules/psr3-logger/
     * so they can be served statically.
     */
    private function publishAssets(): void
    {
        $sourceDir = dirname(__DIR__, 2) . '/public';
        $targetDir = $this->findAdminPanelPublicDir() . '/modules/psr3-logger';

        if (!is_dir($sourceDir)) {
            $this->logger->warning('Source assets directory not found', ['path' => $sourceDir]);
            return;
        }

        // Create target directories
        $targetCssDir = $targetDir . '/css';
        $targetJsDir = $targetDir . '/js';

        if (!is_dir($targetCssDir)) {
            @mkdir($targetCssDir, 0755, true);
        }
        if (!is_dir($targetJsDir)) {
            @mkdir($targetJsDir, 0755, true);
        }

        // Copy CSS files
        foreach (glob($sourceDir . '/css/*.css') as $file) {
            $dest = $targetCssDir . '/' . basename($file);
            if (copy($file, $dest)) {
                $this->logger->debug('Published CSS', ['file' => basename($file)]);
            }
        }

        // Copy JS files
        foreach (glob($sourceDir . '/js/*.js') as $file) {
            $dest = $targetJsDir . '/' . basename($file);
            if (copy($file, $dest)) {
                $this->logger->debug('Published JS', ['file' => basename($file)]);
            }
        }

        $this->logger->info('Assets published to ' . $targetDir);
    }

    /**
     * Find the admin panel's public directory
     */
    private function findAdminPanelPublicDir(): string
    {
        // Method 1: Check DOCUMENT_ROOT
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($docRoot && is_dir($docRoot)) {
            return $docRoot;
        }

        // Method 2: Look for admin-panel in vendor
        $vendorPaths = [
            dirname(__DIR__, 5) . '/public',           // Standard vendor location
            dirname(__DIR__, 4) . '/public',           // If installed as workspace package
            getcwd() . '/public',                      // Current working directory
        ];

        foreach ($vendorPaths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        // Method 3: Fallback to current project public
        return getcwd() . '/public';
    }

    /**
     * Create the logs table with proper indexes
     */
    private function createLogsTable(): void
    {
        // Detect driver
        $driver = $this->getDriverName();

        $sql = match ($driver) {
            'pgsql' => <<<SQL
                CREATE TABLE IF NOT EXISTS logs (
                    id BIGSERIAL PRIMARY KEY,
                    channel VARCHAR(100) NOT NULL,
                    level VARCHAR(20) NOT NULL,
                    level_value SMALLINT NOT NULL DEFAULT 0,
                    message TEXT NOT NULL,
                    context JSONB DEFAULT '{}'::JSONB,
                    extra JSONB DEFAULT '{}'::JSONB,
                    request_id VARCHAR(64),
                    user_id BIGINT,
                    ip_address VARCHAR(45),
                    user_agent TEXT,
                    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
                );

                CREATE INDEX IF NOT EXISTS idx_logs_channel ON logs(channel);
                CREATE INDEX IF NOT EXISTS idx_logs_level ON logs(level_value);
                CREATE INDEX IF NOT EXISTS idx_logs_created_at ON logs(created_at DESC);
                CREATE INDEX IF NOT EXISTS idx_logs_request_id ON logs(request_id);
                CREATE INDEX IF NOT EXISTS idx_logs_user_id ON logs(user_id);
                CREATE INDEX IF NOT EXISTS idx_logs_channel_time ON logs(channel, created_at DESC);
                CREATE INDEX IF NOT EXISTS idx_logs_level_time ON logs(level_value, created_at DESC);
                CREATE INDEX IF NOT EXISTS idx_logs_channel_level_time ON logs(channel, level_value, created_at DESC);
                CREATE INDEX IF NOT EXISTS idx_logs_ip ON logs(ip_address) WHERE ip_address IS NOT NULL;
                CREATE INDEX IF NOT EXISTS idx_logs_context ON logs USING GIN (context jsonb_path_ops);
            SQL,

            'mysql' => <<<SQL
                CREATE TABLE IF NOT EXISTS logs (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    channel VARCHAR(100) NOT NULL,
                    level VARCHAR(20) NOT NULL,
                    level_value SMALLINT NOT NULL DEFAULT 0,
                    message TEXT NOT NULL,
                    context JSON DEFAULT NULL,
                    extra JSON DEFAULT NULL,
                    request_id VARCHAR(64),
                    user_id BIGINT UNSIGNED,
                    ip_address VARCHAR(45),
                    user_agent TEXT,
                    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

                    INDEX idx_logs_channel (channel),
                    INDEX idx_logs_level (level_value),
                    INDEX idx_logs_created_at (created_at),
                    INDEX idx_logs_request_id (request_id),
                    INDEX idx_logs_user_id (user_id),
                    INDEX idx_logs_channel_time (channel, created_at),
                    INDEX idx_logs_level_time (level_value, created_at),
                    INDEX idx_logs_channel_level_time (channel, level_value, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            'sqlite' => <<<SQL
                CREATE TABLE IF NOT EXISTS logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    channel VARCHAR(100) NOT NULL,
                    level VARCHAR(20) NOT NULL,
                    level_value INTEGER NOT NULL DEFAULT 0,
                    message TEXT NOT NULL,
                    context TEXT DEFAULT '{}',
                    extra TEXT DEFAULT '{}',
                    request_id VARCHAR(64),
                    user_id INTEGER,
                    ip_address VARCHAR(45),
                    user_agent TEXT,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                );

                CREATE INDEX IF NOT EXISTS idx_logs_channel ON logs(channel);
                CREATE INDEX IF NOT EXISTS idx_logs_level ON logs(level_value);
                CREATE INDEX IF NOT EXISTS idx_logs_created_at ON logs(created_at);
            SQL,

            default => throw new \RuntimeException("Unsupported database driver: {$driver}"),
        };

        try {
            // Execute multiple statements
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($statements as $statement) {
                if (!empty($statement)) {
                    $this->exec($statement);
                }
            }
            $this->logger->info('Logs table created/verified');
        } catch (\PDOException|\Exception $e) {
            $this->logger->error('Failed to create logs table', ['error' => $e->getMessage()]);
        }
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
                $this->exec($statement);
            }

            $this->logger->info('Migration executed successfully');
        } catch (\PDOException|\Exception $e) {
            $this->logger->error('Migration failed', ['error' => $e->getMessage()]);
        }
    }
}
