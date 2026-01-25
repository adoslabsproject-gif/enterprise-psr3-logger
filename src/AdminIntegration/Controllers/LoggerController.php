<?php

declare(strict_types=1);

namespace Senza1dio\EnterprisePSR3Logger\AdminIntegration\Controllers;

use PDO;
use AdosLabs\AdminPanel\Controllers\BaseController;
use AdosLabs\AdminPanel\Http\Response;
use AdosLabs\AdminPanel\Services\AuditService;
use AdosLabs\AdminPanel\Services\SessionService;

/**
 * Logger Admin Controller
 *
 * Complete logging management:
 * - Channel configuration with levels
 * - Log file management (view, download, delete)
 * - Telegram notifications
 * - PHP errors viewer
 * - Database log viewer
 */
final class LoggerController extends BaseController
{
    /**
     * Available log levels in order of severity
     */
    private const LEVELS = [
        'debug' => 100,
        'info' => 200,
        'notice' => 250,
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
        'alert' => 550,
        'emergency' => 600,
    ];

    /**
     * Predefined channels with metadata
     */
    private const CHANNELS = [
        'debug_general' => [
            'name' => 'Debug General',
            'description' => 'General debug information (debug, info, notice)',
            'icon' => 'bug',
            'color' => 'gray',
        ],
        'error_general' => [
            'name' => 'Error General',
            'description' => 'Application errors (warning to emergency)',
            'icon' => 'alert-triangle',
            'color' => 'red',
        ],
        'api' => [
            'name' => 'API',
            'description' => 'API requests, responses, and errors',
            'icon' => 'globe',
            'color' => 'blue',
        ],
        'security' => [
            'name' => 'Security',
            'description' => 'Authentication, authorization, threats',
            'icon' => 'shield',
            'color' => 'purple',
        ],
        'database' => [
            'name' => 'Database',
            'description' => 'Queries, connections, errors',
            'icon' => 'database',
            'color' => 'orange',
        ],
        'auth' => [
            'name' => 'Authentication',
            'description' => 'Login, logout, session events',
            'icon' => 'user',
            'color' => 'green',
        ],
        'queue' => [
            'name' => 'Queue/Jobs',
            'description' => 'Background jobs and queue processing',
            'icon' => 'layers',
            'color' => 'cyan',
        ],
        'mail' => [
            'name' => 'Mail',
            'description' => 'Email sending and delivery',
            'icon' => 'mail',
            'color' => 'pink',
        ],
        'cache' => [
            'name' => 'Cache',
            'description' => 'Cache operations (verbose)',
            'icon' => 'zap',
            'color' => 'yellow',
        ],
        'performance' => [
            'name' => 'Performance',
            'description' => 'Timing and performance metrics',
            'icon' => 'activity',
            'color' => 'indigo',
        ],
    ];

    public function __construct(
        PDO $pdo,
        SessionService $sessionService,
        AuditService $auditService
    ) {
        parent::__construct($pdo, $sessionService, $auditService);
    }

    /**
     * Main Logger Dashboard
     * GET /admin/logger
     */
    public function index(): Response
    {
        // Get all channel configurations
        $channels = $this->getChannelsConfig();

        // Get Telegram config
        $telegram = $this->getTelegramConfig();

        // Get log files for today
        $logFiles = $this->getLogFiles();

        // Get recent database logs
        $recentLogs = $this->getRecentLogs(20);

        // Get PHP errors
        $phpErrors = $this->getPhpErrors(10);

        // Get stats
        $stats = $this->getLogStats();

        return $this->view('logger/index', [
            'channels' => $channels,
            'telegram' => $telegram,
            'log_files' => $logFiles,
            'recent_logs' => $recentLogs,
            'php_errors' => $phpErrors,
            'stats' => $stats,
            'levels' => self::LEVELS,
            'page_title' => 'Logging Dashboard',
        ]);
    }

    /**
     * Update channel configuration
     * POST /admin/logger/channel/update
     */
    public function updateChannel(): Response
    {
        $channel = $this->input('channel', '');
        $enabled = $this->input('enabled') === '1' || $this->input('enabled') === 'true';
        $level = $this->input('level', 'info');

        if (!isset(self::CHANNELS[$channel])) {
            return $this->error('Invalid channel');
        }

        if (!isset(self::LEVELS[$level])) {
            return $this->error('Invalid log level');
        }

        try {
            // Update enabled status
            $this->setConfig("log_channel_{$channel}_enabled", $enabled ? 'true' : 'false', 'boolean');

            // Update level
            $this->setConfig("log_channel_{$channel}_level", $level, 'string');

            $this->audit('logger.channel_updated', [
                'channel' => $channel,
                'enabled' => $enabled,
                'level' => $level,
            ]);

            if ($this->isAjax()) {
                return $this->success(['channel' => $channel, 'enabled' => $enabled, 'level' => $level]);
            }

            return $this->redirect($this->adminUrl('logger'));
        } catch (\Exception $e) {
            return $this->error('Failed to update channel: ' . $e->getMessage());
        }
    }

    /**
     * Update Telegram configuration
     * POST /admin/logger/telegram/update
     */
    public function updateTelegram(): Response
    {
        $enabled = $this->input('enabled') === '1' || $this->input('enabled') === 'true';
        $botToken = $this->input('bot_token', '');
        $chatId = $this->input('chat_id', '');
        $level = $this->input('level', 'error');
        $channels = $this->input('channels', []);
        $rateLimit = (int) $this->input('rate_limit', 10);

        if ($enabled && (empty($botToken) || empty($chatId))) {
            return $this->error('Bot token and Chat ID are required when Telegram is enabled');
        }

        try {
            $this->setConfig('log_telegram_enabled', $enabled ? 'true' : 'false', 'boolean');
            $this->setConfig('log_telegram_bot_token', $botToken, 'string');
            $this->setConfig('log_telegram_chat_id', $chatId, 'string');
            $this->setConfig('log_telegram_level', $level, 'string');
            $this->setConfig('log_telegram_channels', json_encode($channels), 'json');
            $this->setConfig('log_telegram_rate_limit', (string) $rateLimit, 'integer');

            $this->audit('logger.telegram_updated', [
                'enabled' => $enabled,
                'level' => $level,
                'channels_count' => count($channels),
            ]);

            if ($this->isAjax()) {
                return $this->success(['enabled' => $enabled]);
            }

            return $this->redirect($this->adminUrl('logger'));
        } catch (\Exception $e) {
            return $this->error('Failed to update Telegram config: ' . $e->getMessage());
        }
    }

    /**
     * Test Telegram connection
     * POST /admin/logger/telegram/test
     */
    public function testTelegram(): Response
    {
        $botToken = $this->input('bot_token', '');
        $chatId = $this->input('chat_id', '');

        if (empty($botToken) || empty($chatId)) {
            return $this->error('Bot token and Chat ID are required');
        }

        try {
            $message = "🔔 *Test Message*\n\n";
            $message .= "Enterprise Logger connected successfully!\n";
            $message .= "Time: " . date('Y-m-d H:i:s') . "\n";
            $message .= "Server: " . gethostname();

            $result = $this->sendTelegramMessage($botToken, $chatId, $message);

            if ($result) {
                return $this->success(['message' => 'Test message sent successfully']);
            } else {
                return $this->error('Failed to send test message');
            }
        } catch (\Exception $e) {
            return $this->error('Telegram error: ' . $e->getMessage());
        }
    }

    /**
     * View log file contents
     * GET /admin/logger/file/view
     */
    public function viewFile(): Response
    {
        $filename = $this->input('file', '');

        if (empty($filename) || !$this->isValidLogFile($filename)) {
            return $this->error('Invalid file');
        }

        $filepath = $this->getLogFilePath($filename);

        if (!file_exists($filepath)) {
            return $this->error('File not found');
        }

        $content = file_get_contents($filepath);
        $lines = explode("\n", $content);
        $totalLines = count($lines);

        // Get last 500 lines for display
        $displayLines = array_slice($lines, -500);

        return $this->view('logger/file-view', [
            'filename' => $filename,
            'content' => implode("\n", $displayLines),
            'total_lines' => $totalLines,
            'file_size' => filesize($filepath),
            'modified' => filemtime($filepath),
            'page_title' => 'View Log: ' . $filename,
        ]);
    }

    /**
     * Download log file
     * GET /admin/logger/file/download
     */
    public function downloadFile(): Response
    {
        $filename = $this->input('file', '');

        if (empty($filename) || !$this->isValidLogFile($filename)) {
            return $this->error('Invalid file');
        }

        $filepath = $this->getLogFilePath($filename);

        if (!file_exists($filepath)) {
            return $this->error('File not found');
        }

        $this->audit('logger.file_downloaded', ['file' => $filename]);

        return Response::download($filepath, $filename);
    }

    /**
     * Delete log files
     * POST /admin/logger/file/delete
     */
    public function deleteFiles(): Response
    {
        $files = $this->input('files', []);

        if (empty($files)) {
            return $this->error('No files selected');
        }

        $deleted = 0;
        $errors = [];

        foreach ($files as $filename) {
            if (!$this->isValidLogFile($filename)) {
                $errors[] = "Invalid file: {$filename}";
                continue;
            }

            $filepath = $this->getLogFilePath($filename);

            if (file_exists($filepath)) {
                if (unlink($filepath)) {
                    $deleted++;
                } else {
                    $errors[] = "Failed to delete: {$filename}";
                }
            }
        }

        $this->audit('logger.files_deleted', [
            'count' => $deleted,
            'files' => $files,
        ]);

        if ($this->isAjax()) {
            return $this->success([
                'deleted' => $deleted,
                'errors' => $errors,
            ]);
        }

        return $this->redirect($this->adminUrl('logger'));
    }

    /**
     * View PHP errors log
     * GET /admin/logger/php-errors
     */
    public function phpErrors(): Response
    {
        $phpErrorsFile = $this->getConfig('log_php_errors_file', 'storage/logs/php_errors.log');
        $projectRoot = $this->getProjectRoot();
        $filepath = $projectRoot . '/' . $phpErrorsFile;

        $content = '';
        $exists = false;
        $fileSize = 0;
        $modified = null;

        if (file_exists($filepath)) {
            $exists = true;
            $fileSize = filesize($filepath);
            $modified = filemtime($filepath);

            // Read last 1000 lines
            $lines = file($filepath);
            $displayLines = array_slice($lines, -1000);
            $content = implode('', $displayLines);
        }

        return $this->view('logger/php-errors', [
            'exists' => $exists,
            'content' => $content,
            'file_size' => $fileSize,
            'modified' => $modified,
            'filepath' => $phpErrorsFile,
            'page_title' => 'PHP Errors Log',
        ]);
    }

    /**
     * Clear PHP errors log
     * POST /admin/logger/php-errors/clear
     */
    public function clearPhpErrors(): Response
    {
        $phpErrorsFile = $this->getConfig('log_php_errors_file', 'storage/logs/php_errors.log');
        $projectRoot = $this->getProjectRoot();
        $filepath = $projectRoot . '/' . $phpErrorsFile;

        if (file_exists($filepath)) {
            file_put_contents($filepath, '');
            $this->audit('logger.php_errors_cleared', ['file' => $phpErrorsFile]);
        }

        if ($this->isAjax()) {
            return $this->success(['message' => 'PHP errors log cleared']);
        }

        return $this->redirect($this->adminUrl('logger/php-errors'));
    }

    /**
     * Database log viewer with filters
     * GET /admin/logger/database
     */
    public function databaseLogs(): Response
    {
        $filters = [
            'channel' => $this->input('channel'),
            'level' => $this->input('level'),
            'search' => $this->input('search'),
            'from' => $this->input('from'),
            'to' => $this->input('to'),
        ];

        $page = max(1, (int) $this->input('page', 1));
        $perPage = 50;

        $logs = $this->getLogs($filters, $page, $perPage);
        $total = $this->getLogsCount($filters);
        $availableChannels = $this->getAvailableChannels();

        return $this->view('logger/database', [
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => (int) ceil($total / $perPage),
            'filters' => $filters,
            'channels' => $availableChannels,
            'levels' => array_keys(self::LEVELS),
            'page_title' => 'Database Logs',
        ]);
    }

    /**
     * Clear old database logs
     * POST /admin/logger/database/clear
     */
    public function clearDatabaseLogs(): Response
    {
        $olderThan = $this->input('older_than', '7 days');

        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM logs
                WHERE created_at < NOW() - INTERVAL '{$olderThan}'
            ");
            $stmt->execute();
            $deleted = $stmt->rowCount();

            $this->audit('logger.database_logs_cleared', [
                'deleted' => $deleted,
                'older_than' => $olderThan,
            ]);

            if ($this->isAjax()) {
                return $this->success(['deleted' => $deleted]);
            }

            return $this->redirect($this->adminUrl('logger/database'));
        } catch (\Exception $e) {
            return $this->error('Failed to clear logs: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    private function getChannelsConfig(): array
    {
        $channels = [];

        foreach (self::CHANNELS as $key => $meta) {
            $enabled = $this->getConfig("log_channel_{$key}_enabled", 'true') === 'true';
            $level = $this->getConfig("log_channel_{$key}_level", 'info');

            $channels[$key] = array_merge($meta, [
                'key' => $key,
                'enabled' => $enabled,
                'level' => $level,
                'level_value' => self::LEVELS[$level] ?? 200,
            ]);
        }

        return $channels;
    }

    private function getTelegramConfig(): array
    {
        return [
            'enabled' => $this->getConfig('log_telegram_enabled', 'false') === 'true',
            'bot_token' => $this->getConfig('log_telegram_bot_token', ''),
            'chat_id' => $this->getConfig('log_telegram_chat_id', ''),
            'level' => $this->getConfig('log_telegram_level', 'error'),
            'channels' => json_decode($this->getConfig('log_telegram_channels', '["error_general","security"]'), true) ?? [],
            'rate_limit' => (int) $this->getConfig('log_telegram_rate_limit', '10'),
        ];
    }

    private function getLogFiles(): array
    {
        $logDir = $this->getConfig('log_files_directory', 'storage/logs');
        $projectRoot = $this->getProjectRoot();
        $fullPath = $projectRoot . '/' . $logDir;

        if (!is_dir($fullPath)) {
            return [];
        }

        $files = [];
        $iterator = new \DirectoryIterator($fullPath);

        foreach ($iterator as $file) {
            if ($file->isDot() || $file->isDir()) {
                continue;
            }

            if ($file->getExtension() !== 'log') {
                continue;
            }

            $files[] = [
                'name' => $file->getFilename(),
                'size' => $file->getSize(),
                'modified' => $file->getMTime(),
                'is_today' => date('Y-m-d', $file->getMTime()) === date('Y-m-d'),
            ];
        }

        // Sort by modification time (newest first)
        usort($files, fn($a, $b) => $b['modified'] <=> $a['modified']);

        return $files;
    }

    private function getRecentLogs(int $limit): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, channel, level, message, context, created_at
                FROM logs
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getPhpErrors(int $limit): array
    {
        $phpErrorsFile = $this->getConfig('log_php_errors_file', 'storage/logs/php_errors.log');
        $projectRoot = $this->getProjectRoot();
        $filepath = $projectRoot . '/' . $phpErrorsFile;

        if (!file_exists($filepath)) {
            return [];
        }

        $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $recentLines = array_slice($lines, -$limit);

        return array_reverse($recentLines);
    }

    private function getLogStats(): array
    {
        $stats = [
            'total_today' => 0,
            'errors_today' => 0,
            'by_channel' => [],
            'by_level' => [],
        ];

        try {
            // Total logs today
            $stmt = $this->pdo->query("
                SELECT COUNT(*) FROM logs WHERE created_at >= CURRENT_DATE
            ");
            $stats['total_today'] = (int) $stmt->fetchColumn();

            // Errors today (warning+)
            $stmt = $this->pdo->query("
                SELECT COUNT(*) FROM logs
                WHERE created_at >= CURRENT_DATE AND level_value >= 300
            ");
            $stats['errors_today'] = (int) $stmt->fetchColumn();

            // By channel today
            $stmt = $this->pdo->query("
                SELECT channel, COUNT(*) as count
                FROM logs
                WHERE created_at >= CURRENT_DATE
                GROUP BY channel
            ");
            $stats['by_channel'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            // By level today
            $stmt = $this->pdo->query("
                SELECT level, COUNT(*) as count
                FROM logs
                WHERE created_at >= CURRENT_DATE
                GROUP BY level
            ");
            $stats['by_level'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\Exception $e) {
            // Silently fail
        }

        return $stats;
    }

    private function getLogs(array $filters, int $page, int $perPage): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['channel'])) {
            $where[] = 'channel = ?';
            $params[] = $filters['channel'];
        }

        if (!empty($filters['level'])) {
            $where[] = 'level = ?';
            $params[] = $filters['level'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(message ILIKE ? OR context::text ILIKE ?)';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['from'])) {
            $where[] = 'created_at >= ?';
            $params[] = $filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[] = 'created_at <= ?';
            $params[] = $filters['to'];
        }

        $offset = ($page - 1) * $perPage;
        $whereClause = implode(' AND ', $where);

        try {
            $stmt = $this->pdo->prepare("
                SELECT id, channel, level, level_value, message, context, extra, created_at, request_id
                FROM logs
                WHERE {$whereClause}
                ORDER BY created_at DESC
                LIMIT {$perPage} OFFSET {$offset}
            ");
            $stmt->execute($params);

            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($logs as &$log) {
                $log['context'] = json_decode($log['context'] ?? '{}', true);
                $log['extra'] = json_decode($log['extra'] ?? '{}', true);
            }

            return $logs;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getLogsCount(array $filters): int
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['channel'])) {
            $where[] = 'channel = ?';
            $params[] = $filters['channel'];
        }

        if (!empty($filters['level'])) {
            $where[] = 'level = ?';
            $params[] = $filters['level'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(message ILIKE ? OR context::text ILIKE ?)';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['from'])) {
            $where[] = 'created_at >= ?';
            $params[] = $filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[] = 'created_at <= ?';
            $params[] = $filters['to'];
        }

        $whereClause = implode(' AND ', $where);

        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM logs WHERE {$whereClause}");
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function getAvailableChannels(): array
    {
        try {
            $stmt = $this->pdo->query("SELECT DISTINCT channel FROM logs ORDER BY channel");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getConfig(string $key, string $default = ''): string
    {
        try {
            $stmt = $this->pdo->prepare("SELECT config_value FROM admin_config WHERE config_key = ?");
            $stmt->execute([$key]);
            $result = $stmt->fetchColumn();
            return $result !== false ? $result : $default;
        } catch (\Exception $e) {
            return $default;
        }
    }

    private function setConfig(string $key, string $value, string $type = 'string'): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO admin_config (config_key, config_value, value_type, updated_at)
            VALUES (?, ?, ?, NOW())
            ON CONFLICT (config_key) DO UPDATE SET
                config_value = EXCLUDED.config_value,
                updated_at = NOW()
        ");
        $stmt->execute([$key, $value, $type]);
    }

    private function isValidLogFile(string $filename): bool
    {
        // Prevent directory traversal
        if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return false;
        }

        // Only .log files
        if (!str_ends_with($filename, '.log')) {
            return false;
        }

        return true;
    }

    private function getLogFilePath(string $filename): string
    {
        $logDir = $this->getConfig('log_files_directory', 'storage/logs');
        $projectRoot = $this->getProjectRoot();
        return $projectRoot . '/' . $logDir . '/' . $filename;
    }

    private function getProjectRoot(): string
    {
        // Try multiple methods to find project root
        // 1. If DOCUMENT_ROOT is set and points to public/, go up one level
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($docRoot && basename($docRoot) === 'public') {
            return dirname($docRoot);
        }

        // 2. Use Composer autoloader path if available
        if (class_exists(\Composer\Autoload\ClassLoader::class)) {
            $reflection = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
            $vendorPath = dirname($reflection->getFileName(), 2);
            return dirname($vendorPath);
        }

        // 3. Fallback: look for vendor/autoload.php going up directories
        $dir = __DIR__;
        for ($i = 0; $i < 10; $i++) {
            $dir = dirname($dir);
            if (file_exists($dir . '/vendor/autoload.php')) {
                return $dir;
            }
        }

        // 4. Last resort: current working directory
        return getcwd() ?: dirname(__DIR__, 6);
    }

    private function sendTelegramMessage(string $botToken, string $chatId, string $message): bool
    {
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    private function isAjax(): bool
    {
        return $this->request?->getHeaderLine('X-Requested-With') === 'XMLHttpRequest'
            || $this->request?->getHeaderLine('Accept') === 'application/json';
    }
}
