#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Enterprise PSR-3 Logger - Installation Script
 *
 * This script:
 * 1. Detects database driver (PostgreSQL, MySQL, SQLite)
 * 2. Creates the logs table
 * 3. Verifies should_log() is available
 * 4. Shows integration status with other enterprise packages
 *
 * USAGE:
 *   php vendor/senza1dio/enterprise-psr3-logger/setup/install.php [options]
 *
 * OPTIONS:
 *   --driver=pgsql|mysql|sqlite   Database driver (auto-detect from env)
 *   --host=localhost              Database host
 *   --port=5432                   Database port
 *   --database=myapp              Database name
 *   --username=user               Database username
 *   --password=secret             Database password
 *   --table=logs                  Table name (default: logs)
 *   --skip-table                  Skip table creation
 *   --help                        Show this help
 *
 * ENVIRONMENT VARIABLES:
 *   DB_DRIVER, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
 *
 * @package senza1dio/enterprise-psr3-logger
 */

// ANSI colors
const COLOR_RESET = "\033[0m";
const COLOR_GREEN = "\033[32m";
const COLOR_RED = "\033[31m";
const COLOR_YELLOW = "\033[33m";
const COLOR_CYAN = "\033[36m";
const COLOR_BOLD = "\033[1m";

/**
 * Print colored message
 */
function println(string $message, string $color = ''): void
{
    echo $color . $message . COLOR_RESET . PHP_EOL;
}

/**
 * Print success message
 */
function success(string $message): void
{
    println("  ✓ " . $message, COLOR_GREEN);
}

/**
 * Print error message
 */
function error(string $message): void
{
    println("  ✗ " . $message, COLOR_RED);
}

/**
 * Print warning message
 */
function warning(string $message): void
{
    println("  ⚠ " . $message, COLOR_YELLOW);
}

/**
 * Print info message
 */
function info(string $message): void
{
    println("  → " . $message, COLOR_CYAN);
}

/**
 * Parse command line arguments
 */
function parseArgs(array $argv): array
{
    $args = [
        'driver' => getenv('DB_DRIVER') ?: null,
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => getenv('DB_PORT') ?: null,
        'database' => getenv('DB_DATABASE') ?: null,
        'username' => getenv('DB_USERNAME') ?: null,
        'password' => getenv('DB_PASSWORD') ?: '',
        'table' => 'logs',
        'skip-table' => false,
        'help' => false,
    ];

    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--')) {
            $parts = explode('=', substr($arg, 2), 2);
            $key = $parts[0];
            $value = $parts[1] ?? true;

            if (array_key_exists($key, $args)) {
                $args[$key] = $value;
            }
        }
    }

    // Auto-detect port if not set
    if ($args['port'] === null && $args['driver'] !== null) {
        $args['port'] = match ($args['driver']) {
            'pgsql', 'postgresql' => '5432',
            'mysql' => '3306',
            default => null,
        };
    }

    return $args;
}

/**
 * Show help message
 */
function showHelp(): void
{
    println("\n" . COLOR_BOLD . "Enterprise PSR-3 Logger - Installation" . COLOR_RESET);
    println("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n");

    println("USAGE:", COLOR_CYAN);
    println("  php setup/install.php [options]\n");

    println("OPTIONS:", COLOR_CYAN);
    println("  --driver=DRIVER     Database driver: pgsql, mysql, sqlite");
    println("  --host=HOST         Database host (default: localhost)");
    println("  --port=PORT         Database port (auto-detect from driver)");
    println("  --database=NAME     Database name");
    println("  --username=USER     Database username");
    println("  --password=PASS     Database password");
    println("  --table=NAME        Table name (default: logs)");
    println("  --skip-table        Skip table creation");
    println("  --help              Show this help\n");

    println("ENVIRONMENT VARIABLES:", COLOR_CYAN);
    println("  DB_DRIVER, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD\n");

    println("EXAMPLES:", COLOR_CYAN);
    println("  # PostgreSQL with OrbStack");
    println("  php setup/install.php --driver=pgsql --database=myapp --username=admin --password=secret\n");

    println("  # MySQL");
    println("  php setup/install.php --driver=mysql --port=3306 --database=myapp --username=root\n");

    println("  # Using environment variables");
    println("  export DB_DRIVER=pgsql DB_DATABASE=myapp DB_USERNAME=admin DB_PASSWORD=secret");
    println("  php setup/install.php\n");
}

/**
 * Create PDO connection
 */
function createConnection(array $args): ?PDO
{
    $driver = $args['driver'];
    $host = $args['host'];
    $port = $args['port'];
    $database = $args['database'];
    $username = $args['username'];
    $password = $args['password'];

    if ($driver === null || $database === null) {
        return null;
    }

    try {
        $dsn = match ($driver) {
            'pgsql', 'postgresql' => "pgsql:host={$host};port={$port};dbname={$database}",
            'mysql' => "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            'sqlite' => "sqlite:{$database}",
            default => throw new Exception("Unsupported driver: {$driver}"),
        };

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        return new PDO($dsn, $username, $password, $options);
    } catch (PDOException $e) {
        error("Database connection failed: " . $e->getMessage());
        return null;
    }
}

/**
 * Create logs table
 */
function createLogsTable(PDO $pdo, string $driver, string $tableName): bool
{
    $sql = match ($driver) {
        'pgsql', 'postgresql' => <<<SQL
            CREATE TABLE IF NOT EXISTS {$tableName} (
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
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            );

            CREATE INDEX IF NOT EXISTS idx_{$tableName}_channel ON {$tableName}(channel);
            CREATE INDEX IF NOT EXISTS idx_{$tableName}_level ON {$tableName}(level_value);
            CREATE INDEX IF NOT EXISTS idx_{$tableName}_created_at ON {$tableName}(created_at);
            CREATE INDEX IF NOT EXISTS idx_{$tableName}_request_id ON {$tableName}(request_id);
            CREATE INDEX IF NOT EXISTS idx_{$tableName}_user_id ON {$tableName}(user_id);
            CREATE INDEX IF NOT EXISTS idx_{$tableName}_channel_level_time ON {$tableName}(channel, level_value, created_at DESC);
        SQL,

        'mysql' => <<<SQL
            CREATE TABLE IF NOT EXISTS {$tableName} (
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
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

                INDEX idx_{$tableName}_channel (channel),
                INDEX idx_{$tableName}_level (level_value),
                INDEX idx_{$tableName}_created_at (created_at),
                INDEX idx_{$tableName}_request_id (request_id),
                INDEX idx_{$tableName}_user_id (user_id),
                INDEX idx_{$tableName}_channel_level_time (channel, level_value, created_at DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL,

        'sqlite' => <<<SQL
            CREATE TABLE IF NOT EXISTS {$tableName} (
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

            CREATE INDEX IF NOT EXISTS idx_{$tableName}_channel ON {$tableName}(channel);
            CREATE INDEX IF NOT EXISTS idx_{$tableName}_level ON {$tableName}(level_value);
            CREATE INDEX IF NOT EXISTS idx_{$tableName}_created_at ON {$tableName}(created_at);
        SQL,

        default => throw new Exception("Unsupported driver: {$driver}"),
    };

    try {
        // Execute multiple statements
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if (!empty($stmt)) {
                $pdo->exec($stmt);
            }
        }
        return true;
    } catch (PDOException $e) {
        error("Failed to create table: " . $e->getMessage());
        return false;
    }
}

/**
 * Check integration status
 */
function checkIntegration(): array
{
    $status = [
        'bootstrap' => false,
        'admin_panel' => false,
        'should_log' => false,
        'log_config_service' => false,
    ];

    // Check enterprise-bootstrap
    if (class_exists(\Senza1dio\EnterpriseBootstrap\Core\Application::class)) {
        $status['bootstrap'] = true;
    }

    // Check enterprise-admin-panel
    if (class_exists(\AdosLabs\AdminPanel\Services\LogConfigService::class)) {
        $status['admin_panel'] = true;
        $status['log_config_service'] = true;
    }

    // Check should_log() function
    if (function_exists('should_log')) {
        $status['should_log'] = true;
    }

    return $status;
}

// ============================================================================
// MAIN
// ============================================================================

println("\n" . COLOR_BOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" . COLOR_RESET);
println(COLOR_BOLD . "  Enterprise PSR-3 Logger - Installation" . COLOR_RESET);
println(COLOR_BOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" . COLOR_RESET . "\n");

$args = parseArgs($argv);

if ($args['help']) {
    showHelp();
    exit(0);
}

// Step 1: Check Composer autoload
println("Step 1: Checking Composer autoload...", COLOR_CYAN);

$autoloadPaths = [
    __DIR__ . '/../../../autoload.php',           // vendor/senza1dio/enterprise-psr3-logger/setup/
    __DIR__ . '/../vendor/autoload.php',          // Standalone development
    getcwd() . '/vendor/autoload.php',            // Current directory
];

$autoloaded = false;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $autoloaded = true;
        success("Loaded: " . realpath($path));
        break;
    }
}

if (!$autoloaded) {
    warning("Composer autoload not found. Some checks may fail.");
}

// Step 2: Check integration with other packages
println("\nStep 2: Checking enterprise package integration...", COLOR_CYAN);

$integration = checkIntegration();

if ($integration['bootstrap']) {
    success("enterprise-bootstrap: INSTALLED");

    if ($integration['should_log']) {
        success("should_log() function: AVAILABLE (intelligent filtering enabled)");
    } else {
        warning("should_log() function: NOT FOUND (logs will not be filtered)");
    }
} else {
    warning("enterprise-bootstrap: NOT INSTALLED");
    info("Install with: composer require senza1dio/enterprise-bootstrap");
    info("This enables intelligent log filtering with should_log()");
}

if ($integration['admin_panel']) {
    success("enterprise-admin-panel: INSTALLED");
    success("LogConfigService: AVAILABLE (database-driven config)");
} else {
    warning("enterprise-admin-panel: NOT INSTALLED");
    info("Install with: composer require senza1dio/enterprise-admin-panel");
    info("This enables UI-based log channel configuration");
}

// Step 3: Database setup
if ($args['skip-table']) {
    println("\nStep 3: Skipping table creation (--skip-table)", COLOR_CYAN);
} else {
    println("\nStep 3: Database setup...", COLOR_CYAN);

    if ($args['driver'] === null) {
        warning("No database driver specified.");
        info("Use --driver=pgsql|mysql|sqlite or set DB_DRIVER environment variable");
        info("Skipping table creation. You can run migrations manually:");
        info("  PostgreSQL: psql < database/schema-postgresql.sql");
        info("  MySQL: mysql < database/schema-mysql.sql");
        info("  SQLite: sqlite3 < database/schema-sqlite.sql");
    } else {
        info("Driver: " . $args['driver']);
        info("Host: " . $args['host'] . ":" . $args['port']);
        info("Database: " . $args['database']);
        info("Table: " . $args['table']);

        $pdo = createConnection($args);

        if ($pdo !== null) {
            success("Database connection successful");

            if (createLogsTable($pdo, $args['driver'], $args['table'])) {
                success("Table '{$args['table']}' created/verified");
            }
        }
    }
}

// Step 4: Summary and next steps
println("\n" . COLOR_BOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" . COLOR_RESET);
println(COLOR_BOLD . "  Installation Summary" . COLOR_RESET);
println(COLOR_BOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" . COLOR_RESET . "\n");

$allGood = true;

if ($integration['bootstrap'] && $integration['should_log']) {
    success("Log filtering: ENTERPRISE MODE (database-driven via should_log())");
} elseif ($integration['should_log']) {
    success("Log filtering: BASIC MODE (should_log() stub - always true)");
} else {
    warning("Log filtering: DISABLED (should_log() not found)");
    $allGood = false;
}

if ($integration['admin_panel']) {
    success("Admin UI: ENABLED (configure channels at /admin/logger/channels)");
} else {
    warning("Admin UI: DISABLED (install enterprise-admin-panel for UI config)");
}

// Recommended installation order
println("\n" . COLOR_CYAN . "Recommended Installation Order:" . COLOR_RESET);
println("  1. composer require senza1dio/enterprise-admin-panel");
println("  2. php vendor/senza1dio/enterprise-admin-panel/setup/install.php");
println("  3. composer require senza1dio/enterprise-bootstrap");
println("  4. composer require senza1dio/enterprise-psr3-logger");
println("  5. php vendor/senza1dio/enterprise-psr3-logger/setup/install.php");
println("  6. Configure channels in admin panel: /admin/logger/channels");

// Quick start example
println("\n" . COLOR_CYAN . "Quick Start Example:" . COLOR_RESET);
println("  <?php");
println("  require 'vendor/autoload.php';");
println("");
println("  use Senza1dio\\EnterprisePSR3Logger\\LoggerFactory;");
println("");
println("  \$logger = LoggerFactory::production('app', '/var/log/app');");
println("  \$logger->info('Hello world!');");
println("  // should_log('app', 'info') is called automatically");

println("\n" . ($allGood ? COLOR_GREEN : COLOR_YELLOW) . "Installation complete!" . COLOR_RESET . "\n");

exit($allGood ? 0 : 1);
