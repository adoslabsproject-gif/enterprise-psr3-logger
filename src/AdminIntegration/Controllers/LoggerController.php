<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\AdminIntegration\Controllers;

use AdosLabs\AdminPanel\Controllers\BaseController;
use AdosLabs\AdminPanel\Database\Pool\DatabasePool;
use AdosLabs\AdminPanel\Http\Response;
use AdosLabs\AdminPanel\Services\AuditService;
use AdosLabs\AdminPanel\Services\SessionService;
use PDO;

/**
 * Logger Admin Controller
 *
 * Features:
 * - Channel cards with level selection (saved to admin_settings)
 * - Full logs viewer with filters and bulk actions
 * - Telegram notifications configuration
 * - PHP errors viewer
 */
final class LoggerController extends BaseController
{
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

    private const CHANNELS = [
        'app' => [
            'name' => 'Application',
            'description' => 'General application logs',
            'icon' => 'box',
            'color' => 'blue',
        ],
        'security' => [
            'name' => 'Security',
            'description' => 'Authentication, authorization, threats',
            'icon' => 'shield',
            'color' => 'purple',
        ],
        'api' => [
            'name' => 'API',
            'description' => 'API requests and responses',
            'icon' => 'globe',
            'color' => 'cyan',
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
        'mail' => [
            'name' => 'Mail',
            'description' => 'Email sending and delivery',
            'icon' => 'mail',
            'color' => 'pink',
        ],
        'queue' => [
            'name' => 'Queue/Jobs',
            'description' => 'Background jobs processing',
            'icon' => 'layers',
            'color' => 'indigo',
        ],
        'cache' => [
            'name' => 'Cache',
            'description' => 'Cache operations',
            'icon' => 'zap',
            'color' => 'yellow',
        ],
    ];

    private PDO $pdo;

    public function __construct(
        DatabasePool $db,
        SessionService $sessionService,
        AuditService $auditService,
    ) {
        parent::__construct($db, $sessionService, $auditService);
        $conn = $db->acquire();
        $this->pdo = $conn->getPdo();
    }

    /**
     * Main Logger Dashboard
     * GET /admin/logger
     */
    public function index(): Response
    {
        // Get channel configurations from admin_settings
        $channels = $this->getChannelsWithConfig();

        // Get filters from query
        $filters = [
            'channel' => $this->input('channel'),
            'level' => $this->input('level'),
            'search' => $this->input('search'),
            'from' => $this->input('from'),
            'to' => $this->input('to'),
        ];

        $page = max(1, (int) $this->input('page', 1));
        $perPage = 50;

        // Get logs with filters
        $logs = $this->getLogs($filters, $page, $perPage);
        $total = $this->getLogsCount($filters);

        // Get available channels from logs table
        $availableChannels = $this->getAvailableChannels();

        return $this->view('logger/index', [
            'channels' => $channels,
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => (int) ceil($total / $perPage),
            'filters' => $filters,
            'available_channels' => $availableChannels,
            'levels' => array_keys(self::LEVELS),
            'page_title' => 'Logging Dashboard',
            'extra_styles' => ['/module-assets/enterprise-psr3-logger/css/logger.css'],
            'extra_scripts' => ['/module-assets/enterprise-psr3-logger/js/logger.js'],
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
            // Save to admin_settings
            $this->saveSetting("log_channel_{$channel}_enabled", $enabled ? '1' : '0');
            $this->saveSetting("log_channel_{$channel}_level", $level);

            $this->audit('logger.channel_updated', [
                'channel' => $channel,
                'enabled' => $enabled,
                'level' => $level,
            ]);

            return $this->success(['channel' => $channel, 'enabled' => $enabled, 'level' => $level]);
        } catch (\Exception $e) {
            return $this->error('Failed to update channel: ' . $e->getMessage());
        }
    }

    /**
     * Delete selected logs
     * POST /admin/logger/logs/delete
     */
    public function deleteLogs(): Response
    {
        // Handle JSON body from AJAX requests
        $ids = $this->input('ids', []);

        if (empty($ids)) {
            // Try parsing JSON body
            $body = (string) ($this->request?->getBody() ?? '');
            if (!empty($body)) {
                $json = json_decode($body, true);
                $ids = $json['ids'] ?? [];
            }
        }

        if (empty($ids)) {
            return $this->error('No logs selected');
        }

        // Ensure ids are integers
        $ids = array_map('intval', (array) $ids);

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->pdo->prepare("DELETE FROM logs WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $deleted = $stmt->rowCount();

            $this->audit('logger.logs_deleted', ['count' => $deleted]);

            return $this->success(['deleted' => $deleted]);
        } catch (\Exception $e) {
            return $this->error('Failed to delete logs: ' . $e->getMessage());
        }
    }

    /**
     * Clear logs older than specified time
     * POST /admin/logger/logs/clear
     */
    public function clearLogs(): Response
    {
        $olderThan = $this->input('older_than', '7 days');
        $channel = $this->input('channel');

        try {
            $sql = "DELETE FROM logs WHERE created_at < NOW() - INTERVAL '{$olderThan}'";
            $params = [];

            if ($channel) {
                $sql .= ' AND channel = ?';
                $params[] = $channel;
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $deleted = $stmt->rowCount();

            $this->audit('logger.logs_cleared', [
                'deleted' => $deleted,
                'older_than' => $olderThan,
                'channel' => $channel,
            ]);

            if ($this->isAjax()) {
                return $this->success(['deleted' => $deleted]);
            }

            return $this->redirect($this->adminUrl('logger'));
        } catch (\Exception $e) {
            return $this->error('Failed to clear logs: ' . $e->getMessage());
        }
    }

    /**
     * Telegram configuration page
     * GET /admin/logger/telegram
     */
    public function telegram(): Response
    {
        $config = $this->getTelegramConfig();

        return $this->view('logger/telegram', [
            'config' => $config,
            'channels' => self::CHANNELS,
            'levels' => array_keys(self::LEVELS),
            'page_title' => 'Telegram Notifications',
            'extra_styles' => ['/module-assets/enterprise-psr3-logger/css/logger.css'],
            'extra_scripts' => ['/module-assets/enterprise-psr3-logger/js/logger.js'],
        ]);
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

        if ($enabled && (empty($botToken) || empty($chatId))) {
            return $this->error('Bot token and Chat ID are required when Telegram is enabled');
        }

        try {
            $this->saveSetting('log_telegram_enabled', $enabled ? '1' : '0');
            $this->saveSetting('log_telegram_bot_token', $botToken);
            $this->saveSetting('log_telegram_chat_id', $chatId);
            $this->saveSetting('log_telegram_level', $level);
            $this->saveSetting('log_telegram_channels', json_encode($channels) ?: '[]');

            $this->audit('logger.telegram_updated', [
                'enabled' => $enabled,
                'level' => $level,
            ]);

            if ($this->isAjax()) {
                return $this->success(['enabled' => $enabled]);
            }

            return $this->redirect($this->adminUrl('logger/telegram'));
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
            $message .= 'Time: ' . date('Y-m-d H:i:s') . "\n";
            $message .= 'Server: ' . gethostname();

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
     * PHP Errors page
     * GET /admin/logger/php-errors
     */
    public function phpErrors(): Response
    {
        $phpErrorsFile = $this->getSetting('log_php_errors_file', 'storage/logs/php_errors.log');
        $projectRoot = defined('EAP_PROJECT_ROOT') ? EAP_PROJECT_ROOT : getcwd();
        $filepath = $projectRoot . '/' . $phpErrorsFile;

        $content = '';
        $exists = false;
        $fileSize = 0;
        $modified = null;

        if (file_exists($filepath)) {
            $exists = true;
            $fileSize = filesize($filepath);
            $modified = filemtime($filepath);
            $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $displayLines = array_slice($lines, -500);
            $content = implode("\n", $displayLines);
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
        $phpErrorsFile = $this->getSetting('log_php_errors_file', 'storage/logs/php_errors.log');
        $projectRoot = defined('EAP_PROJECT_ROOT') ? EAP_PROJECT_ROOT : getcwd();
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

    // =========================================================================
    // Private Helpers
    // =========================================================================

    private function getChannelsWithConfig(): array
    {
        $channels = [];

        foreach (self::CHANNELS as $key => $meta) {
            $enabled = $this->getSetting("log_channel_{$key}_enabled", '1') === '1';
            $level = $this->getSetting("log_channel_{$key}_level", 'info');

            $channels[$key] = array_merge($meta, [
                'key' => $key,
                'enabled' => $enabled,
                'level' => $level,
            ]);
        }

        return $channels;
    }

    private function getTelegramConfig(): array
    {
        return [
            'enabled' => $this->getSetting('log_telegram_enabled', '0') === '1',
            'bot_token' => $this->getSetting('log_telegram_bot_token', ''),
            'chat_id' => $this->getSetting('log_telegram_chat_id', ''),
            'level' => $this->getSetting('log_telegram_level', 'error'),
            'channels' => json_decode($this->getSetting('log_telegram_channels', '[]'), true) ?? [],
        ];
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
                SELECT id, channel, level, message, context, created_at
                FROM logs
                WHERE {$whereClause}
                ORDER BY created_at DESC
                LIMIT {$perPage} OFFSET {$offset}
            ");
            $stmt->execute($params);

            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($logs as &$log) {
                $log['context'] = json_decode($log['context'] ?? '{}', true);
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
            $stmt = $this->pdo->query('SELECT DISTINCT channel FROM logs ORDER BY channel');

            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getSetting(string $key, string $default = ''): string
    {
        try {
            $stmt = $this->pdo->prepare('SELECT setting_value FROM admin_settings WHERE setting_key = ?');
            $stmt->execute([$key]);
            $result = $stmt->fetchColumn();

            return $result !== false ? $result : $default;
        } catch (\Exception $e) {
            return $default;
        }
    }

    private function saveSetting(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO admin_settings (setting_key, setting_value, updated_at)
            VALUES (?, ?, NOW())
            ON CONFLICT (setting_key) DO UPDATE SET
                setting_value = EXCLUDED.setting_value,
                updated_at = NOW()
        ');
        $stmt->execute([$key, $value]);
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
