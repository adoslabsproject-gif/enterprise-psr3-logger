<?php
/**
 * Logger Dashboard
 *
 * CSP Compliant: No inline styles or scripts
 * Requires: /css/logger.css, /js/logger.js
 *
 * @var array $channels Channel configurations
 * @var array $telegram Telegram config
 * @var array $log_files Log files list
 * @var array $recent_logs Recent database logs
 * @var array $php_errors Recent PHP errors
 * @var array $stats Log statistics
 * @var array $levels Available log levels
 * @var string $admin_base_path Admin base path
 * @var string $csrf_input CSRF hidden input
 * @var string $csrf_token CSRF token value
 */

// Helper function for formatting bytes
if (!function_exists('formatBytes')) {
    function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}

$levelColors = [
    'debug' => '#6b7280',
    'info' => '#3b82f6',
    'notice' => '#8b5cf6',
    'warning' => '#f59e0b',
    'error' => '#ef4444',
    'critical' => '#dc2626',
    'alert' => '#b91c1c',
    'emergency' => '#7f1d1d',
];

$channelColors = [
    'gray' => ['bg' => '#374151', 'text' => '#9ca3af'],
    'red' => ['bg' => '#7f1d1d', 'text' => '#fca5a5'],
    'blue' => ['bg' => '#1e3a8a', 'text' => '#93c5fd'],
    'purple' => ['bg' => '#581c87', 'text' => '#c4b5fd'],
    'orange' => ['bg' => '#7c2d12', 'text' => '#fdba74'],
    'green' => ['bg' => '#14532d', 'text' => '#86efac'],
    'cyan' => ['bg' => '#164e63', 'text' => '#67e8f9'],
    'pink' => ['bg' => '#831843', 'text' => '#f9a8d4'],
    'yellow' => ['bg' => '#713f12', 'text' => '#fde047'],
    'indigo' => ['bg' => '#312e81', 'text' => '#a5b4fc'],
];
?>

<!-- Stats Overview -->
<div class="eap-stats-row">
    <div class="eap-stat-card">
        <div class="eap-stat-card__icon eap-stat-card__icon--primary">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
        </div>
        <div class="eap-stat-card__content">
            <div class="eap-stat-card__value"><?= number_format($stats['total_today']) ?></div>
            <div class="eap-stat-card__label">Logs Today</div>
        </div>
    </div>
    <div class="eap-stat-card eap-stat-card--danger">
        <div class="eap-stat-card__icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
        </div>
        <div class="eap-stat-card__content">
            <div class="eap-stat-card__value"><?= number_format($stats['errors_today']) ?></div>
            <div class="eap-stat-card__label">Errors Today</div>
        </div>
    </div>
    <div class="eap-stat-card">
        <div class="eap-stat-card__icon eap-stat-card__icon--success">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
            </svg>
        </div>
        <div class="eap-stat-card__content">
            <div class="eap-stat-card__value"><?= count($log_files) ?></div>
            <div class="eap-stat-card__label">Log Files</div>
        </div>
    </div>
    <div class="eap-stat-card">
        <div class="eap-stat-card__icon eap-stat-card__icon--info">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
            </svg>
        </div>
        <div class="eap-stat-card__content">
            <div class="eap-stat-card__value"><?= count(array_filter($channels, fn($c) => $c['enabled'])) ?></div>
            <div class="eap-stat-card__label">Active Channels</div>
        </div>
    </div>
</div>

<!-- Log Channels Configuration -->
<div class="eap-card">
    <div class="eap-card__header">
        <span class="eap-card__title">$ log_channels --configure</span>
    </div>
    <div class="eap-card__body">
        <div class="eap-channel-grid">
            <?php foreach ($channels as $key => $channel): ?>
                <?php
                $colors = $channelColors[$channel['color']] ?? $channelColors['gray'];
                $channelBg = $colors['bg'];
                $channelText = $colors['text'];
                ?>
                <div class="eap-channel-card" data-channel-bg="<?= $channelBg ?>" data-channel-text="<?= $channelText ?>">
                    <div class="eap-channel-card__header">
                        <div class="eap-channel-card__icon">
                            <?php
                            $icons = [
                                'bug' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>',
                                'alert-triangle' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>',
                                'globe' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>',
                                'shield' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>',
                                'database' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>',
                                'user' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>',
                                'layers' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>',
                                'mail' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>',
                                'zap' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>',
                                'activity' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>',
                            ];
                            ?>
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20">
                                <?= $icons[$channel['icon']] ?? $icons['activity'] ?>
                            </svg>
                        </div>
                        <div class="eap-channel-card__title"><?= htmlspecialchars($channel['name']) ?></div>
                        <label class="eap-channel-card__toggle">
                            <input type="checkbox"
                                   data-channel="<?= $key ?>"
                                   class="channel-toggle"
                                   <?= $channel['enabled'] ? 'checked' : '' ?>>
                            <span class="eap-toggle-slider"></span>
                        </label>
                    </div>
                    <div class="eap-channel-card__description"><?= htmlspecialchars($channel['description']) ?></div>
                    <div class="eap-channel-card__footer">
                        <label class="eap-channel-card__level-label">Min Level:</label>
                        <select class="eap-channel-card__level-select channel-level" data-channel="<?= $key ?>">
                            <?php foreach ($levels as $level => $value): ?>
                                <option value="<?= $level ?>" <?= $channel['level'] === $level ? 'selected' : '' ?>>
                                    <?= ucfirst($level) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Telegram Notifications -->
<div class="eap-card">
    <div class="eap-card__header">
        <span class="eap-card__title">$ telegram --notifications</span>
        <label class="eap-toggle-inline">
            <input type="checkbox" id="telegram-enabled" <?= $telegram['enabled'] ? 'checked' : '' ?>>
            <span class="eap-toggle-slider"></span>
            <span class="eap-toggle-label"><?= $telegram['enabled'] ? 'Enabled' : 'Disabled' ?></span>
        </label>
    </div>
    <div class="eap-card__body">
        <form id="telegram-form" class="eap-form eap-form--inline">
            <?= $csrf_input ?>
            <div class="eap-form__row">
                <div class="eap-form__group">
                    <label class="eap-form__label">Bot Token</label>
                    <input type="password" name="bot_token" class="eap-form__input"
                           value="<?= htmlspecialchars($telegram['bot_token']) ?>"
                           placeholder="123456789:ABC-DEF1234ghIkl-zyx57W2v1u123ew11">
                </div>
                <div class="eap-form__group">
                    <label class="eap-form__label">Chat ID</label>
                    <input type="text" name="chat_id" class="eap-form__input"
                           value="<?= htmlspecialchars($telegram['chat_id']) ?>"
                           placeholder="-1001234567890">
                </div>
            </div>
            <div class="eap-form__row">
                <div class="eap-form__group">
                    <label class="eap-form__label">Minimum Level (Telegram only)</label>
                    <select name="level" class="eap-form__select">
                        <?php foreach ($levels as $level => $value): ?>
                            <option value="<?= $level ?>" <?= $telegram['level'] === $level ? 'selected' : '' ?>>
                                <?= ucfirst($level) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="eap-form__hint">Telegram notifications use this level independently from channel levels</div>
                </div>
                <div class="eap-form__group">
                    <label class="eap-form__label">Rate Limit (per minute)</label>
                    <input type="number" name="rate_limit" class="eap-form__input"
                           value="<?= $telegram['rate_limit'] ?>" min="1" max="60">
                </div>
            </div>
            <div class="eap-form__group">
                <label class="eap-form__label">Channels to Notify</label>
                <div class="eap-checkbox-grid">
                    <?php foreach ($channels as $key => $channel): ?>
                        <label class="eap-checkbox">
                            <input type="checkbox" name="channels[]" value="<?= $key ?>"
                                   <?= in_array($key, $telegram['channels']) || in_array('*', $telegram['channels']) ? 'checked' : '' ?>>
                            <span><?= htmlspecialchars($channel['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="eap-form__actions">
                <button type="submit" class="eap-btn eap-btn--primary">Save Telegram Settings</button>
                <button type="button" class="eap-btn eap-btn--secondary" id="test-telegram">Test Connection</button>
            </div>
        </form>
    </div>
</div>

<!-- Log Files Manager -->
<div class="eap-card">
    <div class="eap-card__header">
        <span class="eap-card__title">$ ls -la storage/logs/</span>
        <div class="eap-card__actions">
            <button type="button" class="eap-btn eap-btn--danger eap-btn--sm" id="delete-selected" disabled>
                Delete Selected
            </button>
        </div>
    </div>
    <div class="eap-card__body eap-card__body--flush">
        <?php if (empty($log_files)): ?>
            <div class="eap-empty-state">
                <p>No log files found</p>
                <p class="eap-text--muted">Log files will appear here when logging starts</p>
            </div>
        <?php else: ?>
            <form id="files-form">
                <?= $csrf_input ?>
                <table class="eap-table">
                    <thead class="eap-table__head">
                        <tr>
                            <th class="eap-table__th eap-table__th--checkbox">
                                <input type="checkbox" id="select-all-files">
                            </th>
                            <th class="eap-table__th">Filename</th>
                            <th class="eap-table__th">Size</th>
                            <th class="eap-table__th">Modified</th>
                            <th class="eap-table__th">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($log_files as $file): ?>
                            <tr class="eap-table__row <?= $file['is_today'] ? 'eap-table__row--highlight' : '' ?>">
                                <td class="eap-table__td">
                                    <input type="checkbox" name="files[]" value="<?= htmlspecialchars($file['name']) ?>" class="file-checkbox">
                                </td>
                                <td class="eap-table__td">
                                    <code class="eap-code"><?= htmlspecialchars($file['name']) ?></code>
                                    <?php if ($file['is_today']): ?>
                                        <span class="eap-badge eap-badge--success">Today</span>
                                    <?php endif; ?>
                                </td>
                                <td class="eap-table__td eap-text--mono">
                                    <?= formatBytes($file['size']) ?>
                                </td>
                                <td class="eap-table__td eap-text--muted">
                                    <?= date('Y-m-d H:i:s', $file['modified']) ?>
                                </td>
                                <td class="eap-table__td">
                                    <a href="<?= $admin_base_path ?>/logger/file/view?file=<?= urlencode($file['name']) ?>"
                                       class="eap-btn eap-btn--ghost eap-btn--xs">View</a>
                                    <a href="<?= $admin_base_path ?>/logger/file/download?file=<?= urlencode($file['name']) ?>"
                                       class="eap-btn eap-btn--ghost eap-btn--xs">Download</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- PHP Errors Preview -->
<div class="eap-card">
    <div class="eap-card__header">
        <span class="eap-card__title">$ tail -f php_errors.log</span>
        <a href="<?= $admin_base_path ?>/logger/php-errors" class="eap-btn eap-btn--ghost eap-btn--sm">View Full Log</a>
    </div>
    <div class="eap-card__body">
        <?php if (empty($php_errors)): ?>
            <div class="eap-empty-state eap-empty-state--small">
                <p class="eap-text--success">No PHP errors recorded</p>
            </div>
        <?php else: ?>
            <pre class="eap-log-preview"><?php foreach ($php_errors as $line): ?><?= htmlspecialchars($line) . "\n" ?><?php endforeach; ?></pre>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Database Logs -->
<div class="eap-card">
    <div class="eap-card__header">
        <span class="eap-card__title">$ recent_logs --limit 20</span>
        <a href="<?= $admin_base_path ?>/logger/database" class="eap-btn eap-btn--ghost eap-btn--sm">View All</a>
    </div>
    <div class="eap-card__body eap-card__body--flush">
        <?php if (empty($recent_logs)): ?>
            <div class="eap-empty-state">
                <p>No logs in database</p>
            </div>
        <?php else: ?>
            <div class="eap-log-list">
                <?php foreach ($recent_logs as $log): ?>
                    <?php $levelColor = $levelColors[strtolower($log['level'])] ?? '#6b7280'; ?>
                    <div class="eap-log-item eap-log-item--<?= strtolower($log['level']) ?>">
                        <div class="eap-log-item__header">
                            <span class="eap-log-item__level eap-level-badge eap-level-badge--<?= strtolower($log['level']) ?>">
                                <?= strtoupper($log['level']) ?>
                            </span>
                            <span class="eap-log-item__channel"><?= htmlspecialchars($log['channel']) ?></span>
                            <span class="eap-log-item__time"><?= date('H:i:s', strtotime($log['created_at'])) ?></span>
                        </div>
                        <div class="eap-log-item__message"><?= htmlspecialchars(substr($log['message'], 0, 200)) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Link external CSS and JS (published to /public/modules/psr3-logger/) -->
<link rel="stylesheet" href="/modules/psr3-logger/css/logger.css">
<script src="/modules/psr3-logger/js/logger.js" defer></script>
