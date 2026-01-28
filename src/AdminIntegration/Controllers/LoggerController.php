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
 * File-based logging system with:
 * - Daily log files per channel (app-2026-01-27.log)
 * - php_errors.log viewer
 * - Telegram notifications configuration
 * - Log file browser with pagination
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

    /**
     * Levels that trigger auto-reset (below WARNING)
     */
    private const DEBUG_LEVELS = ['debug', 'info', 'notice'];

    /**
     * Hours before auto-reset to WARNING
     */
    private const AUTO_RESET_HOURS = 8;

    private const CHANNELS = [
        'app' => [
            'name' => 'Application',
            'description' => 'General application logs, startup, config, user actions',
            'icon' => 'box',
            'color' => 'blue',
            'file_prefix' => 'app',
        ],
        'security' => [
            'name' => 'Security',
            'description' => 'Auth, login/logout, sessions, threats, 2FA (also in DB)',
            'icon' => 'shield',
            'color' => 'purple',
            'file_prefix' => 'security',
        ],
        'api' => [
            'name' => 'API',
            'description' => 'API requests, responses, webhooks, rate limits',
            'icon' => 'globe',
            'color' => 'cyan',
            'file_prefix' => 'api',
        ],
        'performance' => [
            'name' => 'Performance',
            'description' => 'DB pool metrics, slow queries, cache stats, throughput',
            'icon' => 'zap',
            'color' => 'yellow',
            'file_prefix' => 'performance',
        ],
        'php_errors' => [
            'name' => 'PHP Errors',
            'description' => 'PHP runtime errors, warnings, notices, deprecations',
            'icon' => 'alert-triangle',
            'color' => 'red',
            'file_prefix' => 'php_errors',
        ],
    ];

    private string $logsPath;
    private \PDO $pdo;
    private ?\AdosLabs\AdminPanel\Database\Pool\PooledConnection $pooledConnection = null;

    public function __construct(
        DatabasePool $db,
        SessionService $sessionService,
        AuditService $auditService,
    ) {
        parent::__construct($db, $sessionService, $auditService);

        // Acquire connection from pool (will be released in destructor)
        $this->pooledConnection = $db->acquire();
        $this->pdo = $this->pooledConnection->getPdo();

        // Set logs path
        $projectRoot = defined('EAP_PROJECT_ROOT') ? EAP_PROJECT_ROOT : getcwd();
        $this->logsPath = $projectRoot . '/storage/logs';

        // Ensure logs directory exists
        if (!is_dir($this->logsPath)) {
            @mkdir($this->logsPath, 0o755, true);
        }
    }

    /**
     * Release the pooled connection when controller is destroyed
     */
    public function __destruct()
    {
        if ($this->pooledConnection !== null) {
            $this->db->release($this->pooledConnection);
            $this->pooledConnection = null;
        }
    }

    /**
     * Main Logger Dashboard - Shows channels and available log files
     * GET /admin/logger
     */
    public function index(): Response
    {
        // Process any expired auto-resets
        $this->processAutoResets();

        // Get channel configurations from database
        $channels = $this->getChannelsWithConfig();

        // Get available log files grouped by channel
        $logFiles = $this->getAvailableLogFiles();

        // Get today's date for highlighting
        $today = date('Y-m-d');

        return $this->view('logger/index', [
            'channels' => $channels,
            'log_files' => $logFiles,
            'today' => $today,
            'logs_path' => $this->logsPath,
            'levels' => array_keys(self::LEVELS),
            'auto_reset_hours' => self::AUTO_RESET_HOURS,
            'page_title' => 'Logging Dashboard',
            'extra_styles' => ['/module-assets/enterprise-psr3-logger/css/logger.css'],
            'extra_scripts' => ['/module-assets/enterprise-psr3-logger/js/logger.js'],
        ]);
    }

    /**
     * View a specific log file
     * GET /admin/logger/view?file=app-2026-01-27.log
     */
    public function viewFile(): Response
    {
        $filename = $this->input('file', '');
        $page = max(1, (int) $this->input('page', 1));
        $perPage = (int) $this->input('per_page', 100);

        // Security: validate filename
        if (!preg_match('/^[a-z_]+-\d{4}-\d{2}-\d{2}\.log$/', $filename) && $filename !== 'php_errors.log') {
            return $this->error('Invalid filename');
        }

        $filepath = $this->logsPath . '/' . $filename;

        if (!file_exists($filepath)) {
            return $this->view('logger/file-view', [
                'filename' => $filename,
                'exists' => false,
                'content' => '',
                'total_lines' => 0,
                'page' => 1,
                'per_page' => $perPage,
                'pages' => 0,
                'page_title' => 'Log: ' . $filename,
                'extra_styles' => ['/module-assets/enterprise-psr3-logger/css/logger.css'],
            ]);
        }

        // Read file with pagination
        $lines = file($filepath, FILE_IGNORE_NEW_LINES) ?: [];
        $totalLines = count($lines);
        $pages = (int) ceil($totalLines / $perPage);

        // Reverse to show newest first
        $lines = array_reverse($lines);

        // Paginate
        $offset = ($page - 1) * $perPage;
        $pageLines = array_slice($lines, $offset, $perPage);

        // Parse log lines for better display
        $parsedLines = $this->parseLogLines($pageLines);

        return $this->view('logger/file-view', [
            'filename' => $filename,
            'exists' => true,
            'lines' => $parsedLines,
            'total_lines' => $totalLines,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
            'file_size' => filesize($filepath),
            'modified' => filemtime($filepath),
            'page_title' => 'Log: ' . $filename,
            'extra_styles' => ['/module-assets/enterprise-psr3-logger/css/logger.css'],
            'extra_scripts' => ['/module-assets/enterprise-psr3-logger/js/logger.js'],
        ]);
    }

    /**
     * Update channel configuration (AJAX)
     * POST /admin/logger/channel/update
     *
     * Enterprise features:
     * - Toggle only mode for enable/disable
     * - Auto-reset toggle (auto_reset_enabled)
     * - Auto-reset timer for debug levels (< WARNING) when enabled
     * - Invalidates Redis cache for log channel config
     * - Invalidates OPcache if preload script exists
     * - Logs change in security channel (file + DB audit)
     */
    public function updateChannel(): Response
    {
        $channel = $this->input('channel', '');
        $enabled = $this->input('enabled') === '1' || $this->input('enabled') === 'true';
        $level = $this->input('level', 'warning');
        $toggleOnly = $this->input('toggle_only') === '1';
        $autoResetToggle = $this->input('auto_reset_toggle') === '1';

        // Check if this is an auto-reset toggle change
        $autoResetEnabledInput = $this->input('auto_reset_enabled');
        $isAutoResetToggleChange = $autoResetEnabledInput !== null;

        if (!isset(self::CHANNELS[$channel])) {
            return $this->json(['success' => false, 'message' => 'Invalid channel']);
        }

        if (!isset(self::LEVELS[$level])) {
            return $this->json(['success' => false, 'message' => 'Invalid log level']);
        }

        try {
            // Get old values for audit diff
            $oldConfig = $this->getChannelConfig($channel);

            // Determine auto_reset_enabled value
            $autoResetEnabled = $isAutoResetToggleChange
                ? ($autoResetEnabledInput === '1' || $autoResetEnabledInput === 'true')
                : ($oldConfig['auto_reset_enabled'] ?? true);

            // Calculate auto_reset_at for debug levels ONLY if auto_reset_enabled is true
            $autoResetAt = null;
            if ($autoResetEnabled && in_array($level, self::DEBUG_LEVELS, true) && !$toggleOnly) {
                // Set auto-reset time: now + AUTO_RESET_HOURS
                $autoResetAt = date('Y-m-d H:i:s', time() + (self::AUTO_RESET_HOURS * 3600));
            }

            // Update log_channels table
            $stmt = $this->pdo->prepare('
                INSERT INTO log_channels (channel, min_level, enabled, description, auto_reset_enabled, auto_reset_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON CONFLICT (channel) DO UPDATE SET
                    min_level = EXCLUDED.min_level,
                    enabled = EXCLUDED.enabled,
                    auto_reset_enabled = EXCLUDED.auto_reset_enabled,
                    auto_reset_at = EXCLUDED.auto_reset_at,
                    updated_at = NOW()
            ');
            $stmt->execute([
                $channel,
                $level,
                $enabled ? 't' : 'f',
                self::CHANNELS[$channel]['description'] ?? '',
                $autoResetEnabled ? 't' : 'f',
                $autoResetAt,
            ]);

            // --- CACHE INVALIDATION (ENTERPRISE) ---

            // 1. Invalidate Redis cache for this channel config
            $this->invalidateChannelCache($channel);

            // 2. Invalidate OPcache if available (for preloaded config)
            $this->invalidateOpcache();

            // --- SECURITY LOGGING ---

            // 3. Log to security channel file (skip for toggle-only)
            if (!$toggleOnly && !$isAutoResetToggleChange) {
                $this->logToSecurityChannel($channel, $enabled, $level, $oldConfig);
            }

            // 4. Log to database audit
            $auditAction = match (true) {
                $toggleOnly => 'logger.channel_toggled',
                $isAutoResetToggleChange => 'logger.channel_auto_reset_toggled',
                default => 'logger.channel_updated',
            };

            $this->audit($auditAction, [
                'channel' => $channel,
                'enabled' => $enabled,
                'level' => $level,
                'auto_reset_enabled' => $autoResetEnabled,
                'old_enabled' => $oldConfig['enabled'] ?? true,
                'old_level' => $oldConfig['min_level'] ?? 'warning',
                'old_auto_reset_enabled' => $oldConfig['auto_reset_enabled'] ?? true,
                'auto_reset_at' => $autoResetAt,
            ]);

            return $this->json([
                'success' => true,
                'channel' => $channel,
                'enabled' => $enabled,
                'level' => $level,
                'auto_reset_enabled' => $autoResetEnabled,
                'auto_reset_at' => $autoResetAt,
            ]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => 'Failed to update channel: ' . $e->getMessage()]);
        }
    }

    /**
     * Get current channel config from database
     */
    private function getChannelConfig(string $channel): array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT min_level, enabled, auto_reset_enabled, auto_reset_at FROM log_channels WHERE channel = ?');
            $stmt->execute([$channel]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $row ?: ['min_level' => 'warning', 'enabled' => true, 'auto_reset_enabled' => true, 'auto_reset_at' => null];
        } catch (\Exception $e) {
            return ['min_level' => 'warning', 'enabled' => true, 'auto_reset_enabled' => true, 'auto_reset_at' => null];
        }
    }

    /**
     * Process expired auto-resets
     * Called on each page load to reset channels that exceeded their debug time
     * Only processes channels with auto_reset_enabled = true
     */
    private function processAutoResets(): void
    {
        try {
            // Find channels with expired auto_reset_at AND auto_reset_enabled = true
            $stmt = $this->pdo->query("
                SELECT channel, min_level
                FROM log_channels
                WHERE auto_reset_enabled = true
                  AND auto_reset_at IS NOT NULL
                  AND auto_reset_at <= NOW()
                  AND min_level IN ('debug', 'info', 'notice')
            ");

            $expiredChannels = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($expiredChannels)) {
                return;
            }

            // Reset each expired channel to WARNING
            foreach ($expiredChannels as $row) {
                $channel = $row['channel'];
                $oldLevel = $row['min_level'];

                $this->pdo->prepare("
                    UPDATE log_channels
                    SET min_level = 'warning', auto_reset_at = NULL, updated_at = NOW()
                    WHERE channel = ?
                ")->execute([$channel]);

                // Log the auto-reset
                $this->logAutoReset($channel, $oldLevel);
                $this->audit('logger.channel_auto_reset', [
                    'channel' => $channel,
                    'old_level' => $oldLevel,
                    'new_level' => 'warning',
                    'reason' => 'Auto-reset after ' . self::AUTO_RESET_HOURS . ' hours',
                ]);

                // Invalidate cache
                $this->invalidateChannelCache($channel);
            }
        } catch (\Exception $e) {
            // Silently fail - don't break the page
        }
    }

    /**
     * Log auto-reset event to security channel
     */
    private function logAutoReset(string $channel, string $oldLevel): void
    {
        $timestamp = date('Y-m-d H:i:s.u');

        $logEntry = sprintf(
            "[%s] security.INFO: Log channel auto-reset to WARNING\n" .
            "    | Channel: %s\n" .
            "    | Previous Level: %s\n" .
            "    | Reason: Exceeded %d hour limit for debug-level logging\n" .
            "    | Action: Automatic security measure\n",
            $timestamp,
            $channel,
            $oldLevel,
            self::AUTO_RESET_HOURS,
        );

        $securityLogFile = $this->logsPath . '/security-' . date('Y-m-d') . '.log';
        file_put_contents($securityLogFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Invalidate Redis cache for channel configuration
     */
    private function invalidateChannelCache(string $channel): void
    {
        try {
            // Try to get Redis from container or direct connection
            $redisHost = $_ENV['REDIS_HOST'] ?? 'localhost';
            $redisPort = (int) ($_ENV['REDIS_PORT'] ?? 6379);

            $redis = new \Redis();
            if ($redis->connect($redisHost, $redisPort, 0.5)) {
                // Invalidate specific channel cache
                $redis->del('eap_log_channel:' . $channel);
                $redis->del('eap_log_channels_config');
                // Invalidate any config cache that might include log settings
                $redis->del('eap_config:logging');
                $redis->close();
            }
        } catch (\Exception $e) {
            // Redis not available, that's ok - config will be read from DB
        }
    }

    /**
     * Invalidate OPcache for preloaded configuration
     */
    private function invalidateOpcache(): void
    {
        if (!function_exists('opcache_reset')) {
            return;
        }

        // Only invalidate specific files, not entire cache
        $projectRoot = defined('EAP_PROJECT_ROOT') ? EAP_PROJECT_ROOT : getcwd();
        $configFiles = [
            $projectRoot . '/config/logging.php',
            $projectRoot . '/vendor/ados-labs/enterprise-psr3-logger/config/logging.php',
        ];

        foreach ($configFiles as $file) {
            if (file_exists($file)) {
                opcache_invalidate($file, true);
            }
        }
    }

    /**
     * Log configuration change to security channel file
     */
    private function logToSecurityChannel(string $channel, bool $enabled, string $level, array $oldConfig): void
    {
        $user = $this->getUser();
        $timestamp = date('Y-m-d H:i:s.u');

        // Build detailed log entry
        $changes = [];
        if (($oldConfig['enabled'] ?? true) !== $enabled) {
            $changes[] = sprintf('enabled: %s → %s', $oldConfig['enabled'] ? 'true' : 'false', $enabled ? 'true' : 'false');
        }
        if (($oldConfig['min_level'] ?? 'info') !== $level) {
            $changes[] = sprintf('level: %s → %s', $oldConfig['min_level'] ?? 'info', $level);
        }

        if (empty($changes)) {
            return; // No actual changes
        }

        $logEntry = sprintf(
            "[%s] security.WARNING: Log channel configuration changed\n" .
            "    | Channel: %s\n" .
            "    | Changes: %s\n" .
            "    | User: %s (ID: %d)\n" .
            "    | IP: %s\n" .
            "    | User-Agent: %s\n",
            $timestamp,
            $channel,
            implode(', ', $changes),
            $user['email'] ?? 'unknown',
            $user['id'] ?? 0,
            $this->getClientIp(),
            $this->getUserAgent() ?? 'unknown',
        );

        // Write to security log file
        $securityLogFile = $this->logsPath . '/security-' . date('Y-m-d') . '.log';
        file_put_contents($securityLogFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Clear/delete a log file
     * POST /admin/logger/file/clear
     */
    public function clearFile(): Response
    {
        $filename = $this->input('file', '');

        // Security: validate filename
        if (!preg_match('/^[a-z_]+-\d{4}-\d{2}-\d{2}\.log$/', $filename) && $filename !== 'php_errors.log') {
            return $this->json(['success' => false, 'message' => 'Invalid filename']);
        }

        $filepath = $this->logsPath . '/' . $filename;

        if (file_exists($filepath)) {
            file_put_contents($filepath, '');
            $this->audit('logger.file_cleared', ['file' => $filename]);

            return $this->json([
                'success' => true,
                'message' => 'Log file cleared',
            ]);
        }

        return $this->json(['success' => false, 'message' => 'File not found']);
    }

    /**
     * Download a log file
     * GET /admin/logger/file/download?file=app-2026-01-27.log
     */
    public function downloadFile(): Response
    {
        $filename = $this->input('file', '');

        // Security: validate filename
        if (!preg_match('/^[a-z_]+-\d{4}-\d{2}-\d{2}\.log$/', $filename) && $filename !== 'php_errors.log') {
            return $this->error('Invalid filename');
        }

        $filepath = $this->logsPath . '/' . $filename;

        if (!file_exists($filepath)) {
            return $this->error('File not found');
        }

        return Response::download($filepath, $filename);
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
            if ($this->isAjax()) {
                return $this->json(['success' => false, 'message' => 'Bot token and Chat ID are required']);
            }

            return $this->error('Bot token and Chat ID are required when Telegram is enabled');
        }

        try {
            $stmt = $this->pdo->prepare('
                UPDATE log_telegram_config
                SET enabled = ?,
                    bot_token = ?,
                    chat_id = ?,
                    min_level = ?,
                    notify_channels = ?,
                    updated_at = NOW()
                WHERE id = 1
            ');
            $stmt->execute([
                $enabled ? 't' : 'f',
                $botToken,
                $chatId,
                $level,
                json_encode($channels) ?: '["*"]',
            ]);

            $this->audit('logger.telegram_updated', [
                'enabled' => $enabled,
                'level' => $level,
            ]);

            // Log to security channel
            $this->logTelegramConfigChange($enabled, $level);

            if ($this->isAjax()) {
                return $this->json([
                    'success' => true,
                    'enabled' => $enabled,
                ]);
            }

            return $this->redirect($this->adminUrl('logger/telegram'));
        } catch (\Exception $e) {
            if ($this->isAjax()) {
                return $this->json(['success' => false, 'message' => $e->getMessage()]);
            }

            return $this->error('Failed to update Telegram config: ' . $e->getMessage());
        }
    }

    /**
     * Log Telegram config change to security channel
     */
    private function logTelegramConfigChange(bool $enabled, string $level): void
    {
        $user = $this->getUser();
        $timestamp = date('Y-m-d H:i:s.u');

        $logEntry = sprintf(
            "[%s] security.WARNING: Telegram notification settings changed\n" .
            "    | Enabled: %s\n" .
            "    | Min Level: %s\n" .
            "    | User: %s (ID: %d)\n" .
            "    | IP: %s\n",
            $timestamp,
            $enabled ? 'true' : 'false',
            $level,
            $user['email'] ?? 'unknown',
            $user['id'] ?? 0,
            $this->getClientIp(),
        );

        $securityLogFile = $this->logsPath . '/security-' . date('Y-m-d') . '.log';
        file_put_contents($securityLogFile, $logEntry, FILE_APPEND | LOCK_EX);
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
            return $this->json(['success' => false, 'message' => 'Bot token and Chat ID are required']);
        }

        try {
            $message = "🔔 *Test Message*\n\n";
            $message .= "Enterprise Logger connected successfully!\n";
            $message .= 'Time: ' . date('Y-m-d H:i:s') . "\n";
            $message .= 'Server: ' . gethostname();

            $result = $this->sendTelegramMessage($botToken, $chatId, $message);

            if ($result) {
                return $this->json(['success' => true, 'message' => 'Test message sent successfully']);
            } else {
                return $this->json(['success' => false, 'message' => 'Failed to send test message']);
            }
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => 'Telegram error: ' . $e->getMessage()]);
        }
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    private function getChannelsWithConfig(): array
    {
        $channels = [];

        // Get all channel configs from database
        $dbChannels = [];

        try {
            $stmt = $this->pdo->query('SELECT channel, min_level, enabled, auto_reset_enabled, auto_reset_at FROM log_channels');
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $dbChannels[$row['channel']] = $row;
            }
        } catch (\Exception $e) {
            // Table might not exist yet
        }

        foreach (self::CHANNELS as $key => $meta) {
            $dbConfig = $dbChannels[$key] ?? null;

            $channels[$key] = array_merge($meta, [
                'key' => $key,
                'enabled' => $dbConfig ? (bool) $dbConfig['enabled'] : true,
                'level' => $dbConfig['min_level'] ?? 'warning',  // Default to WARNING (safe)
                'auto_reset_enabled' => $dbConfig ? (bool) $dbConfig['auto_reset_enabled'] : true,  // Default ON
                'auto_reset_at' => $dbConfig['auto_reset_at'] ?? null,
            ]);
        }

        return $channels;
    }

    private function getAvailableLogFiles(): array
    {
        $files = [];

        if (!is_dir($this->logsPath)) {
            return $files;
        }

        $allFiles = scandir($this->logsPath) ?: [];

        foreach ($allFiles as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (!str_ends_with($file, '.log')) {
                continue;
            }

            $filepath = $this->logsPath . '/' . $file;
            $size = filesize($filepath);
            $modified = filemtime($filepath);

            // Extract channel from filename (e.g., "app-2026-01-27.log" -> "app")
            $channel = 'unknown';
            if (preg_match('/^([a-z_]+)-\d{4}-\d{2}-\d{2}\.log$/', $file, $matches)) {
                $channel = $matches[1];
            } elseif ($file === 'php_errors.log') {
                $channel = 'php_errors';
            }

            // Extract date
            $date = null;
            if (preg_match('/(\d{4}-\d{2}-\d{2})/', $file, $matches)) {
                $date = $matches[1];
            }

            $files[] = [
                'name' => $file,
                'channel' => $channel,
                'date' => $date,
                'size' => $size,
                'size_human' => $this->formatBytes($size),
                'modified' => $modified,
                'modified_human' => date('Y-m-d H:i:s', $modified),
                'color' => self::CHANNELS[$channel]['color'] ?? 'gray',
            ];
        }

        // Sort by modified date descending
        usort($files, fn ($a, $b) => $b['modified'] <=> $a['modified']);

        return $files;
    }

    /**
     * Parse log file content into structured entries
     *
     * Supports two formats:
     * 1. Single-line JSON: [2026-01-27 15:30:45.123] channel.LEVEL: message {"context":"data"}
     * 2. Multi-line human-readable:
     *    [2026-01-27 15:30:45.123] channel.LEVEL: message
     *        | Key: Value
     *        | Key2: Value2
     */
    private function parseLogLines(array $lines): array
    {
        $parsed = [];
        $currentEntry = null;
        $detailLines = [];

        $finalizeEntry = function () use (&$parsed, &$currentEntry, &$detailLines) {
            if ($currentEntry !== null) {
                // If we have detail lines, add them as context
                if (!empty($detailLines)) {
                    $currentEntry['details'] = $detailLines;
                    $currentEntry['context'] = implode("\n", $detailLines);
                }
                $parsed[] = $currentEntry;
                $detailLines = [];
            }
        };

        foreach ($lines as $line) {
            // Check if this is a detail line (starts with whitespace and |)
            if (preg_match('/^\s+\|\s*(.*)$/', $line, $detailMatch)) {
                if ($currentEntry !== null) {
                    $detailLines[] = trim($detailMatch[1]);
                }
                continue;
            }

            // Check if this is a new log entry header
            // Format: [2026-01-27 15:30:45.123] channel.LEVEL: message
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?)\]\s+(\w+)\.(\w+):\s*(.*)$/', $line, $matches)) {
                // Finalize previous entry
                $finalizeEntry();

                $message = $matches[4];
                $context = null;

                // Extract JSON context if present at end of message
                if (preg_match('/^(.*?)\s+(\{.*\}|\[.*\])$/', $message, $contextMatches)) {
                    $message = $contextMatches[1];
                    $context = $contextMatches[2];
                }

                $level = strtolower($matches[3]);

                $currentEntry = [
                    'raw' => $line,
                    'timestamp' => $matches[1],
                    'channel' => $matches[2],
                    'level' => $level,
                    'message' => $message,
                    'context' => $context,
                    'details' => [],
                    'level_class' => match ($level) {
                        'emergency', 'alert', 'critical', 'error' => 'danger',
                        'warning' => 'warning',
                        'notice', 'info' => 'info',
                        'debug' => 'secondary',
                        default => 'secondary',
                    },
                ];
                continue;
            }

            // Check for PHP error format: [27-Jan-2026 15:30:45 Europe/Rome] PHP Warning: message
            if (preg_match('/^\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2}(?:\s+\S+)?)\]\s+(PHP \w+):\s*(.*)$/', $line, $matches)) {
                $finalizeEntry();

                $phpLevel = strtolower($matches[2]);
                $level = match (true) {
                    str_contains($phpLevel, 'fatal') => 'critical',
                    str_contains($phpLevel, 'error') => 'error',
                    str_contains($phpLevel, 'warning') => 'warning',
                    str_contains($phpLevel, 'notice') => 'notice',
                    str_contains($phpLevel, 'deprecated') => 'notice',
                    default => 'info',
                };

                $currentEntry = [
                    'raw' => $line,
                    'timestamp' => $matches[1],
                    'channel' => 'php',
                    'level' => $level,
                    'message' => $matches[2] . ': ' . $matches[3],
                    'context' => null,
                    'details' => [],
                    'level_class' => match ($level) {
                        'critical', 'error' => 'danger',
                        'warning' => 'warning',
                        'notice' => 'info',
                        default => 'secondary',
                    },
                ];
                continue;
            }

            // If it's a non-empty line that doesn't match any pattern, treat as continuation
            if (trim($line) !== '' && $currentEntry !== null) {
                $detailLines[] = trim($line);
            }
        }

        // Finalize last entry
        $finalizeEntry();

        return $parsed;
    }

    private function getTelegramConfig(): array
    {
        try {
            $stmt = $this->pdo->query('SELECT * FROM log_telegram_config WHERE id = 1');
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                return [
                    'enabled' => (bool) $row['enabled'],
                    'bot_token' => $row['bot_token'] ?? '',
                    'chat_id' => $row['chat_id'] ?? '',
                    'level' => $row['min_level'] ?? 'error',
                    'channels' => json_decode($row['notify_channels'] ?? '["*"]', true) ?? ['*'],
                ];
            }
        } catch (\Exception $e) {
            // Table might not exist
        }

        return [
            'enabled' => false,
            'bot_token' => '',
            'chat_id' => '',
            'level' => 'error',
            'channels' => ['*'],
        ];
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

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    private function isAjax(): bool
    {
        return $this->request?->getHeaderLine('X-Requested-With') === 'XMLHttpRequest'
            || str_contains($this->request?->getHeaderLine('Accept') ?? '', 'application/json');
    }
}
