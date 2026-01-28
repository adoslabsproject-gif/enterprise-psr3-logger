<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\AdminIntegration\Controllers;

use AdosLabs\AdminPanel\Controllers\BaseController;
use AdosLabs\AdminPanel\Database\Pool\DatabasePool;
use AdosLabs\AdminPanel\Http\Response;
use AdosLabs\AdminPanel\Services\AuditService;
use AdosLabs\AdminPanel\Services\SessionService;
use AdosLabs\EnterprisePSR3Logger\LoggerFacade as Logger;
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

    /**
     * Channel definitions - must match database log_channels table
     * These channels are configurable via the admin panel
     *
     * 'allowed_levels' restricts which levels can be selected:
     * - 'error' channel: only error+ (no debug/info/notice/warning)
     * - 'default' channel: only warning+ (no debug/info/notice)
     * - other channels: all levels available
     */
    private const CHANNELS = [
        'default' => [
            'name' => 'Default',
            'description' => 'General application logs, uncategorized events',
            'icon' => 'box',
            'color' => 'blue',
            'file_prefix' => 'default',
            'allowed_levels' => ['warning', 'error', 'critical', 'alert', 'emergency'],
        ],
        'security' => [
            'name' => 'Security',
            'description' => 'Auth, login/logout, sessions, 2FA, threats (+ database)',
            'icon' => 'shield',
            'color' => 'purple',
            'file_prefix' => 'security',
            'allowed_levels' => null,
        ],
        'api' => [
            'name' => 'API',
            'description' => 'API requests, responses, webhooks, rate limits',
            'icon' => 'globe',
            'color' => 'cyan',
            'file_prefix' => 'api',
            'allowed_levels' => ['warning', 'error', 'critical', 'alert', 'emergency'],
        ],
        'database' => [
            'name' => 'Database',
            'description' => 'Database queries, slow queries, connection pool',
            'icon' => 'database',
            'color' => 'green',
            'file_prefix' => 'database',
            'allowed_levels' => ['warning', 'error', 'critical', 'alert', 'emergency'],
        ],
        'email' => [
            'name' => 'Email',
            'description' => 'Email sending, SMTP errors, notifications',
            'icon' => 'mail',
            'color' => 'orange',
            'file_prefix' => 'email',
            'allowed_levels' => ['warning', 'error', 'critical', 'alert', 'emergency'],
        ],
        'performance' => [
            'name' => 'Performance',
            'description' => 'Performance metrics, slow operations, throughput',
            'icon' => 'zap',
            'color' => 'yellow',
            'file_prefix' => 'performance',
            'allowed_levels' => ['warning', 'error', 'critical', 'alert', 'emergency'],
        ],
        'error' => [
            'name' => 'Error',
            'description' => 'Application errors, exceptions, failures (+ database)',
            'icon' => 'alert-triangle',
            'color' => 'red',
            'file_prefix' => 'error',
            'allowed_levels' => ['error', 'critical', 'alert', 'emergency'],
        ],
    ];

    /**
     * Special channels (not in database, file-only)
     */
    private const SPECIAL_CHANNELS = [
        'php_errors' => [
            'name' => 'PHP Errors',
            'description' => 'PHP runtime errors, warnings, notices, deprecations',
            'icon' => 'alert-triangle',
            'color' => 'red',
            'file_prefix' => 'php_errors',
        ],
        'php-fpm' => [
            'name' => 'PHP-FPM',
            'description' => 'PHP-FPM access logs, slow requests, pool status',
            'icon' => 'layers',
            'color' => 'gray',
            'file_prefix' => 'php-fpm',
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
        // Ensure timezone is set from environment or default
        $this->ensureTimezone();

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
            'timezone' => date_default_timezone_get(),
            'server_time' => date('Y-m-d H:i:s'),
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
        // Ensure timezone is set from environment or default
        $this->ensureTimezone();

        $filename = $this->input('file', '');
        $page = max(1, (int) $this->input('page', 1));
        $perPage = (int) $this->input('per_page', 100);

        // Security: validate filename
        if (!$this->isValidLogFilename($filename)) {
            return $this->json(['error' => 'Invalid filename', 'code' => 'ERROR']);
        }

        // Try to find the file - first in logsPath, then check system paths
        $filepath = $this->resolveLogFilePath($filename);

        if ($filepath === null || !file_exists($filepath)) {
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

        // Keep chronological order (oldest first, newest last)
        // Page 1 = first entries, last page = most recent entries

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

        // Validate level is allowed for this channel
        $allowedLevels = self::CHANNELS[$channel]['allowed_levels'] ?? null;
        if ($allowedLevels !== null && !in_array($level, $allowedLevels, true)) {
            return $this->json([
                'success' => false,
                'message' => "Level '{$level}' is not allowed for channel '{$channel}'. Allowed: " . implode(', ', $allowedLevels),
            ]);
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

            // 3. Log to security channel via Logger (always log changes)
            $user = $this->getUser();
            if (!$toggleOnly && !$isAutoResetToggleChange) {
                Logger::channel('security')->warning('Log channel configuration changed', [
                    'channel' => $channel,
                    'old_level' => $oldConfig['min_level'] ?? 'warning',
                    'new_level' => $level,
                    'old_enabled' => $oldConfig['enabled'] ?? true,
                    'new_enabled' => $enabled,
                    'user_id' => $user['id'] ?? 0,
                    'user_email' => $user['email'] ?? 'unknown',
                    'ip' => $this->getClientIp(),
                ]);
            } elseif ($toggleOnly) {
                Logger::channel('security')->warning('Log channel toggled', [
                    'channel' => $channel,
                    'enabled' => $enabled,
                    'user_id' => $user['id'] ?? 0,
                    'ip' => $this->getClientIp(),
                ]);
            } elseif ($isAutoResetToggleChange) {
                Logger::channel('security')->warning('Log channel auto-reset toggled', [
                    'channel' => $channel,
                    'auto_reset_enabled' => $autoResetEnabled,
                    'user_id' => $user['id'] ?? 0,
                    'ip' => $this->getClientIp(),
                ]);
            }

            // 5. Log to security channel file (skip for toggle-only)
            if (!$toggleOnly && !$isAutoResetToggleChange) {
                $this->logToSecurityChannel($channel, $enabled, $level, $oldConfig);
            }

            // 6. Log to database audit
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

                // Log to security channel via Logger
                Logger::channel('security')->warning('Log channel auto-reset to WARNING', [
                    'channel' => $channel,
                    'old_level' => $oldLevel,
                    'new_level' => 'warning',
                    'reason' => 'Exceeded ' . self::AUTO_RESET_HOURS . ' hour limit',
                    'action' => 'automatic',
                ]);

                // Log the auto-reset to file
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
        if (!$this->isValidLogFilename($filename)) {
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
        if (!$this->isValidLogFilename($filename)) {
            return $this->error('Invalid filename');
        }

        $filepath = $this->resolveLogFilePath($filename);

        if ($filepath === null || !file_exists($filepath)) {
            return $this->error('File not found');
        }

        return Response::download($filepath, $filename);
    }

    /**
     * Delete a log file
     * POST /admin/logger/file/delete
     */
    public function deleteFile(): Response
    {
        $filename = $this->input('file', '');

        // Security: validate filename
        if (!$this->isValidLogFilename($filename)) {
            return $this->json(['success' => false, 'message' => 'Invalid filename']);
        }

        // Only allow deleting files in logsPath (not system files)
        $filepath = $this->logsPath . '/' . $filename;

        if (!file_exists($filepath)) {
            return $this->json(['success' => false, 'message' => 'File not found']);
        }

        if (@unlink($filepath)) {
            $this->audit('logger.file_deleted', ['file' => $filename]);

            Logger::channel('security')->warning('Log file deleted', [
                'file' => $filename,
                'user_id' => $this->getUser()['id'] ?? 0,
                'ip' => $this->getClientIp(),
            ]);

            return $this->json([
                'success' => true,
                'message' => 'File deleted',
            ]);
        }

        return $this->json(['success' => false, 'message' => 'Failed to delete file']);
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
                // PostgreSQL returns 't'/'f' for boolean, normalize to bool
                $row['enabled'] = $row['enabled'] === true || $row['enabled'] === 't' || $row['enabled'] === '1';
                $row['auto_reset_enabled'] = $row['auto_reset_enabled'] === true || $row['auto_reset_enabled'] === 't' || $row['auto_reset_enabled'] === '1';
                $dbChannels[$row['channel']] = $row;
            }
        } catch (\Exception $e) {
            // Table might not exist yet, use defaults
        }

        // Build channel list from database config merged with static metadata
        foreach (self::CHANNELS as $key => $meta) {
            $dbConfig = $dbChannels[$key] ?? null;

            $channels[$key] = array_merge($meta, [
                'key' => $key,
                'enabled' => $dbConfig !== null ? $dbConfig['enabled'] : true,
                'level' => $dbConfig['min_level'] ?? 'warning',  // Default to WARNING (safe)
                'auto_reset_enabled' => $dbConfig !== null ? $dbConfig['auto_reset_enabled'] : true,  // Default ON
                'auto_reset_at' => $dbConfig['auto_reset_at'] ?? null,
                'allowed_levels' => $meta['allowed_levels'] ?? null,
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
            } elseif (str_starts_with($file, 'php-fpm')) {
                $channel = 'php-fpm';
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
                'color' => self::CHANNELS[$channel]['color'] ?? self::SPECIAL_CHANNELS[$channel]['color'] ?? 'gray',
            ];
        }

        // Add system log files (php_errors.log from ini_get, php-fpm logs)
        $this->addSystemLogFiles($files);

        // Sort by modified date descending
        usort($files, fn ($a, $b) => $b['modified'] <=> $a['modified']);

        return $files;
    }

    /**
     * Add system log files (PHP error log, php-fpm logs) to the file list
     */
    private function addSystemLogFiles(array &$files): void
    {
        // PHP error log from ini_get
        $phpErrorLog = ini_get('error_log');
        if ($phpErrorLog && file_exists($phpErrorLog) && is_readable($phpErrorLog)) {
            // Check if not already in list
            $alreadyListed = false;
            foreach ($files as $f) {
                if (realpath($this->logsPath . '/' . $f['name']) === realpath($phpErrorLog)) {
                    $alreadyListed = true;
                    break;
                }
            }

            if (!$alreadyListed) {
                $size = filesize($phpErrorLog);
                $modified = filemtime($phpErrorLog);
                $files[] = [
                    'name' => basename($phpErrorLog),
                    'channel' => 'php_errors',
                    'date' => null,
                    'size' => $size,
                    'size_human' => $this->formatBytes($size),
                    'modified' => $modified,
                    'modified_human' => date('Y-m-d H:i:s', $modified),
                    'color' => self::SPECIAL_CHANNELS['php_errors']['color'],
                    'full_path' => $phpErrorLog,
                ];
            }
        }

        // Common php-fpm log locations
        $phpFpmPaths = [
            '/var/log/php-fpm/error.log',
            '/var/log/php-fpm/www-error.log',
            '/var/log/php-fpm/access.log',
            '/var/log/php-fpm/www-access.log',
            '/var/log/php-fpm.log',
            '/var/log/php7.4-fpm.log',
            '/var/log/php8.0-fpm.log',
            '/var/log/php8.1-fpm.log',
            '/var/log/php8.2-fpm.log',
            '/var/log/php8.3-fpm.log',
            '/usr/local/var/log/php-fpm.log',
        ];

        foreach ($phpFpmPaths as $path) {
            if (file_exists($path) && is_readable($path)) {
                $size = filesize($path);
                $modified = filemtime($path);
                $files[] = [
                    'name' => basename($path),
                    'channel' => 'php-fpm',
                    'date' => null,
                    'size' => $size,
                    'size_human' => $this->formatBytes($size),
                    'modified' => $modified,
                    'modified_human' => date('Y-m-d H:i:s', $modified),
                    'color' => self::SPECIAL_CHANNELS['php-fpm']['color'],
                    'full_path' => $path,
                ];
            }
        }
    }

    /**
     * Parse log file content into structured entries
     *
     * Supports multiple formats:
     * 1. Simple format: [2026-01-27 15:30:45.123] channel.LEVEL: message {"context":"data"}
     * 2. Enhanced format: [2026-01-27 15:30:45.123] [LEVEL] channel | message | context
     * 3. DetailedLineFormatter multi-line format:
     *    [2026-01-27 15:30:45.123456] [WRN] [channel] [pid:123] [mem:2MB]
     *      ▶ Message here
     *      │ key=value key2=value2
     *      └ Exception info
     * 4. PHP error format: [27-Jan-2026 15:30:45 Europe/Rome] PHP Warning: message
     */
    private function parseLogLines(array $lines): array
    {
        $parsed = [];
        $currentEntry = null;
        $detailLines = [];

        // Level abbreviation mapping for DetailedLineFormatter
        $levelAbbrevMap = [
            'DBG' => 'debug',
            'INF' => 'info',
            'NTC' => 'notice',
            'WRN' => 'warning',
            'ERR' => 'error',
            'CRT' => 'critical',
            'ALT' => 'alert',
            'EMG' => 'emergency',
        ];

        $getLevelClass = fn ($level) => match ($level) {
            'emergency', 'alert', 'critical', 'error' => 'danger',
            'warning' => 'warning',
            'notice', 'info' => 'info',
            'debug' => 'secondary',
            default => 'secondary',
        };

        $finalizeEntry = function () use (&$parsed, &$currentEntry, &$detailLines) {
            if ($currentEntry !== null) {
                // Merge detail lines into a single context string for inline display
                if (!empty($detailLines)) {
                    $currentEntry['details'] = $detailLines;
                    // If there's already context, append detail lines
                    if ($currentEntry['context']) {
                        $currentEntry['context'] .= "\n" . implode("\n", $detailLines);
                    } else {
                        $currentEntry['context'] = implode("\n", $detailLines);
                    }
                }
                $parsed[] = $currentEntry;
                $detailLines = [];
            }
        };

        foreach ($lines as $line) {
            // Skip empty lines
            if (trim($line) === '') {
                continue;
            }

            // Check for DetailedLineFormatter continuation lines (▶, │, └)
            if (preg_match('/^\s+([▶│└])\s*(.*)$/', $line, $contMatch)) {
                if ($currentEntry !== null) {
                    $symbol = $contMatch[1];
                    $content = trim($contMatch[2]);

                    if ($symbol === '▶') {
                        // This is the message line
                        $currentEntry['message'] = $content;
                    } else {
                        // │ or └ are context/extra lines - add to details
                        if ($content !== '') {
                            $detailLines[] = $content;
                        }
                    }
                }
                continue;
            }

            // Check for old-style detail line (starts with whitespace and |)
            if (preg_match('/^\s+\|\s*(.*)$/', $line, $detailMatch)) {
                if ($currentEntry !== null) {
                    $detailLines[] = trim($detailMatch[1]);
                }
                continue;
            }

            // --- NEW ENTRY PATTERNS ---

            // Format 1: DetailedLineFormatter header (with full level name or abbreviation)
            // [2026-01-28 10:07:15.970494] [Warning] [security] [pid:62850] [mem:2MB]
            // or [2026-01-28 10:07:15.970494] [WRN] [security] [pid:62850] [mem:2MB]
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?)\]\s+\[(\w+)\]\s+\[(\w+)\](.*)$/', $line, $matches)) {
                $finalizeEntry();

                $levelText = $matches[2];
                $levelUpper = strtoupper($levelText);
                // Try abbreviation map first, then try full level name
                if (isset($levelAbbrevMap[$levelUpper])) {
                    $level = $levelAbbrevMap[$levelUpper];
                } else {
                    // Full level name like "Warning", "Error", etc.
                    $level = strtolower($levelText);
                }
                $channel = $matches[3];
                $metadata = trim($matches[4]);

                // Parse metadata (pid, mem, etc.) and look for message after brackets
                $extraInfo = [];
                $inlineMessage = '';
                $inlineContext = null;

                // Check if there's content after the metadata brackets
                // Pattern: [pid:123] [mem:2MB] may be followed by message
                $remainingAfterBrackets = $metadata;
                while (preg_match('/^\s*\[([^\]]+)\](.*)$/', $remainingAfterBrackets, $metaMatch)) {
                    $extraInfo[] = $metaMatch[1];
                    $remainingAfterBrackets = $metaMatch[2];
                }

                // If there's text after all brackets, it could be the message
                $remainingAfterBrackets = trim($remainingAfterBrackets);
                if ($remainingAfterBrackets !== '') {
                    // Check if it has context (separated by |)
                    if (str_contains($remainingAfterBrackets, ' | ')) {
                        $parts = explode(' | ', $remainingAfterBrackets, 2);
                        $inlineMessage = trim($parts[0]);
                        $inlineContext = trim($parts[1]);
                    } else {
                        $inlineMessage = $remainingAfterBrackets;
                    }
                }

                $currentEntry = [
                    'raw' => $line,
                    'timestamp' => $matches[1],
                    'channel' => $channel,
                    'level' => $level,
                    'message' => $inlineMessage, // May be empty, populated from ▶ line or inline
                    'context' => $inlineContext,
                    'details' => !empty($extraInfo) ? $extraInfo : [],
                    'level_class' => $getLevelClass($level),
                ];
                continue;
            }

            // Format 2: Enhanced format - [2026-01-27 15:30:45.123] [LEVEL] channel | message | context
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?)\]\s+\[(\w+)\]\s+(\w+)\s*\|\s*(.*)$/', $line, $matches)) {
                $finalizeEntry();

                $level = strtolower(trim($matches[2]));
                $channel = $matches[3];
                $rest = $matches[4];

                // Split message and context by |
                $parts = explode(' | ', $rest, 2);
                $message = trim($parts[0]);
                $context = isset($parts[1]) ? trim($parts[1]) : null;

                $currentEntry = [
                    'raw' => $line,
                    'timestamp' => $matches[1],
                    'channel' => $channel,
                    'level' => $level,
                    'message' => $message,
                    'context' => $context,
                    'details' => [],
                    'level_class' => $getLevelClass($level),
                ];
                continue;
            }

            // Format 3: Simple format - [2026-01-27 15:30:45.123] channel.LEVEL: message {"context"}
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?)\]\s+(\w+)\.(\w+):\s*(.*)$/', $line, $matches)) {
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
                    'level_class' => $getLevelClass($level),
                ];
                continue;
            }

            // Format 4: PHP error format - [27-Jan-2026 15:30:45 Europe/Rome] PHP Warning: message
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
                    'level_class' => $getLevelClass($level),
                ];
                continue;
            }

            // If it's a non-empty line that doesn't match any pattern, treat as continuation
            if ($currentEntry !== null) {
                $trimmedLine = trim($line);

                // If current entry has no message yet, this line might be the message
                if (empty($currentEntry['message'])) {
                    // Check if line has context (separated by |)
                    if (str_contains($trimmedLine, ' | ')) {
                        $parts = explode(' | ', $trimmedLine, 2);
                        $currentEntry['message'] = trim($parts[0]);
                        // Parse the context part as key=value pairs
                        $contextPart = trim($parts[1]);
                        if ($contextPart !== '') {
                            $detailLines[] = $contextPart;
                        }
                    } else {
                        $currentEntry['message'] = $trimmedLine;
                    }
                } else {
                    // Already have a message, this is additional context
                    $detailLines[] = $trimmedLine;
                }
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

    /**
     * Validate log filename for security
     */
    private function isValidLogFilename(string $filename): bool
    {
        // Allowed formats:
        // - channel-YYYY-MM-DD.log (e.g., security-2026-01-28.log)
        // - php_errors.log, php-errors.log, php_error.log
        // - php-fpm*.log
        // - error.log, access.log (system logs)
        $validPatterns = [
            '/^[a-z_]+-\d{4}-\d{2}-\d{2}\.log$/',  // channel-date.log
            '/^php[_-]?errors?\.log$/i',            // php_errors.log, php-error.log, phperrors.log
            '/^php-fpm.*\.log$/i',                  // php-fpm*.log
            '/^(error|access|www-error|www-access)\.log$/i',  // system logs
        ];

        foreach ($validPatterns as $pattern) {
            if (preg_match($pattern, $filename)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve log file path - check logsPath first, then system paths
     */
    private function resolveLogFilePath(string $filename): ?string
    {
        // 1. Check in application logs directory
        $appPath = $this->logsPath . '/' . $filename;
        if (file_exists($appPath) && is_readable($appPath)) {
            return $appPath;
        }

        // 2. Check PHP error log from ini
        if (preg_match('/^php[_-]?errors?\.log$/i', $filename)) {
            $phpErrorLog = ini_get('error_log');
            if ($phpErrorLog && file_exists($phpErrorLog) && is_readable($phpErrorLog)) {
                return $phpErrorLog;
            }
        }

        // 3. Check common system log paths
        $systemPaths = [
            '/var/log/' . $filename,
            '/var/log/php/' . $filename,
            '/var/log/php-fpm/' . $filename,
            '/usr/local/var/log/' . $filename,
        ];

        foreach ($systemPaths as $path) {
            if (file_exists($path) && is_readable($path)) {
                return $path;
            }
        }

        // 4. Return app path even if doesn't exist (for error handling)
        return $appPath;
    }

    /**
     * Ensure timezone is properly set from environment or config
     *
     * Priority:
     * 1. APP_TIMEZONE environment variable
     * 2. date.timezone from php.ini
     * 3. Default to Europe/Rome
     */
    private function ensureTimezone(): void
    {
        $timezone = $_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: null;

        if ($timezone === null) {
            $iniTimezone = ini_get('date.timezone');
            if ($iniTimezone) {
                $timezone = $iniTimezone;
            }
        }

        if ($timezone === null) {
            $timezone = 'Europe/Rome';
        }

        try {
            date_default_timezone_set($timezone);
        } catch (\Throwable $e) {
            date_default_timezone_set('Europe/Rome');
        }
    }
}
