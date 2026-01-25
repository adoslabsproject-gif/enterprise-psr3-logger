<?php
/**
 * Logger Dashboard View
 *
 * @var array $channels Channel configurations
 * @var array $telegram Telegram config
 * @var array $log_files Log files list
 * @var array $recent_logs Recent database logs
 * @var array $php_errors PHP errors
 * @var array $stats Statistics
 * @var array $levels Available log levels
 * @var string $page_title Page title
 * @var string $admin_base_path Admin base path
 * @var string $csrf_input CSRF hidden input
 */

$formatBytes = function($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
};

$getLevelColor = function($level) {
    return match(strtolower($level)) {
        'emergency', 'alert', 'critical' => 'danger',
        'error' => 'danger',
        'warning' => 'warning',
        'notice' => 'info',
        'info' => 'info',
        'debug' => 'secondary',
        default => 'secondary'
    };
};
?>

<div class="eap-page">
    <div class="eap-page__header">
        <h1 class="eap-page__title">Logging Dashboard</h1>
        <p class="eap-page__subtitle">Monitor and configure application logging</p>
    </div>

    <!-- Stats Cards -->
    <div class="eap-grid eap-grid--4">
        <div class="eap-card eap-card--stat">
            <div class="eap-stat">
                <div class="eap-stat__icon eap-stat__icon--blue">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
                <div class="eap-stat__content">
                    <div class="eap-stat__value"><?= number_format($stats['total_today'] ?? 0) ?></div>
                    <div class="eap-stat__label">Logs Today</div>
                </div>
            </div>
        </div>

        <div class="eap-card eap-card--stat">
            <div class="eap-stat">
                <div class="eap-stat__icon eap-stat__icon--red">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                </div>
                <div class="eap-stat__content">
                    <div class="eap-stat__value"><?= number_format($stats['errors_today'] ?? 0) ?></div>
                    <div class="eap-stat__label">Errors Today</div>
                </div>
            </div>
        </div>

        <div class="eap-card eap-card--stat">
            <div class="eap-stat">
                <div class="eap-stat__icon eap-stat__icon--green">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                </div>
                <div class="eap-stat__content">
                    <div class="eap-stat__value"><?= count($channels ?? []) ?></div>
                    <div class="eap-stat__label">Active Channels</div>
                </div>
            </div>
        </div>

        <div class="eap-card eap-card--stat">
            <div class="eap-stat">
                <div class="eap-stat__icon eap-stat__icon--purple">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                </div>
                <div class="eap-stat__content">
                    <div class="eap-stat__value"><?= ($telegram['enabled'] ?? false) ? 'Active' : 'Off' ?></div>
                    <div class="eap-stat__label">Telegram</div>
                </div>
            </div>
        </div>
    </div>

    <div class="eap-grid eap-grid--2">
        <!-- Channel Configuration -->
        <div class="eap-card">
            <div class="eap-card__header">
                <h2 class="eap-card__title">Log Channels</h2>
                <span class="eap-badge eap-badge--info"><?= count($channels ?? []) ?> channels</span>
            </div>
            <div class="eap-card__body">
                <div class="eap-channel-list">
                    <?php foreach ($channels ?? [] as $key => $channel): ?>
                    <div class="eap-channel-item" style="display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--eap-border-color);">
                        <div>
                            <strong><?= htmlspecialchars($channel['name'] ?? $key) ?></strong>
                            <div style="font-size: 0.85em; color: var(--eap-text-muted);"><?= htmlspecialchars($channel['description'] ?? '') ?></div>
                        </div>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <select class="eap-select eap-select--sm channel-level" data-channel="<?= htmlspecialchars($key) ?>" style="width: auto;">
                                <?php foreach ($levels as $level => $value): ?>
                                <option value="<?= $level ?>" <?= ($channel['level'] ?? 'info') === $level ? 'selected' : '' ?>>
                                    <?= ucfirst($level) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <label class="eap-toggle">
                                <input type="checkbox" class="channel-toggle" data-channel="<?= htmlspecialchars($key) ?>" <?= ($channel['enabled'] ?? true) ? 'checked' : '' ?>>
                                <span class="eap-toggle__slider"></span>
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($channels)): ?>
                    <div class="eap-empty-state" style="padding: 24px; text-align: center; color: var(--eap-text-muted);">
                        <p>No channels configured</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Telegram Configuration -->
        <div class="eap-card">
            <div class="eap-card__header">
                <h2 class="eap-card__title">Telegram Notifications</h2>
                <span class="eap-badge <?= ($telegram['enabled'] ?? false) ? 'eap-badge--success' : 'eap-badge--secondary' ?>">
                    <?= ($telegram['enabled'] ?? false) ? 'Enabled' : 'Disabled' ?>
                </span>
            </div>
            <div class="eap-card__body">
                <form id="telegram-form" method="POST" action="<?= htmlspecialchars($admin_base_path) ?>/logger/telegram/update">
                    <?= $csrf_input ?>

                    <div class="eap-form-group">
                        <label class="eap-toggle eap-toggle--lg">
                            <input type="checkbox" name="enabled" value="1" <?= ($telegram['enabled'] ?? false) ? 'checked' : '' ?>>
                            <span class="eap-toggle__slider"></span>
                            <span class="eap-toggle__label">Enable Telegram Notifications</span>
                        </label>
                    </div>

                    <div class="eap-form-group">
                        <label class="eap-label">Bot Token</label>
                        <input type="password" name="bot_token" class="eap-input"
                               value="<?= htmlspecialchars($telegram['bot_token'] ?? '') ?>"
                               placeholder="123456:ABC-DEF1234...">
                    </div>

                    <div class="eap-form-group">
                        <label class="eap-label">Chat ID</label>
                        <input type="text" name="chat_id" class="eap-input"
                               value="<?= htmlspecialchars($telegram['chat_id'] ?? '') ?>"
                               placeholder="-1001234567890">
                    </div>

                    <div class="eap-form-group">
                        <label class="eap-label">Minimum Level</label>
                        <select name="level" class="eap-select">
                            <?php foreach ($levels as $level => $value): ?>
                            <option value="<?= $level ?>" <?= ($telegram['level'] ?? 'error') === $level ? 'selected' : '' ?>>
                                <?= ucfirst($level) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="eap-form-help">Only logs at this level or higher will be sent to Telegram</p>
                    </div>

                    <div class="eap-button-group">
                        <button type="submit" class="eap-btn eap-btn--primary">Save</button>
                        <button type="button" class="eap-btn eap-btn--secondary" id="test-telegram">Test</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Recent Logs -->
    <div class="eap-card">
        <div class="eap-card__header">
            <h2 class="eap-card__title">Recent Logs</h2>
            <a href="<?= htmlspecialchars($admin_base_path) ?>/logger/database" class="eap-btn eap-btn--sm eap-btn--secondary">View All</a>
        </div>
        <div class="eap-card__body eap-card__body--flush">
            <div class="eap-table-container">
                <table class="eap-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Channel</th>
                            <th>Level</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_logs ?? [] as $log): ?>
                        <tr>
                            <td style="white-space: nowrap;">
                                <?= date('H:i:s', strtotime($log['created_at'])) ?>
                            </td>
                            <td>
                                <span class="eap-badge eap-badge--outline"><?= htmlspecialchars($log['channel']) ?></span>
                            </td>
                            <td>
                                <span class="eap-badge eap-badge--<?= $getLevelColor($log['level']) ?>">
                                    <?= strtoupper($log['level']) ?>
                                </span>
                            </td>
                            <td style="max-width: 400px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars(substr($log['message'], 0, 100)) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recent_logs)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 24px; color: var(--eap-text-muted);">No logs recorded yet</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var adminBasePath = <?= json_encode($admin_base_path) ?>;
    var csrfToken = <?= json_encode($csrf_token ?? '') ?>;

    // Channel toggle handlers
    document.querySelectorAll('.channel-toggle').forEach(function(toggle) {
        toggle.addEventListener('change', function() {
            updateChannel(this.dataset.channel, this.checked,
                document.querySelector('.channel-level[data-channel="' + this.dataset.channel + '"]').value);
        });
    });

    // Channel level handlers
    document.querySelectorAll('.channel-level').forEach(function(select) {
        select.addEventListener('change', function() {
            var toggle = document.querySelector('.channel-toggle[data-channel="' + this.dataset.channel + '"]');
            updateChannel(this.dataset.channel, toggle.checked, this.value);
        });
    });

    function updateChannel(channel, enabled, level) {
        fetch(adminBasePath + '/logger/channel/update', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: '_csrf_token=' + encodeURIComponent(csrfToken) + 
                  '&channel=' + encodeURIComponent(channel) +
                  '&enabled=' + (enabled ? '1' : '0') + 
                  '&level=' + encodeURIComponent(level)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                console.log('Channel updated:', channel);
            }
        });
    }

    // Test Telegram button
    var testBtn = document.getElementById('test-telegram');
    if (testBtn) {
        testBtn.addEventListener('click', function() {
            var form = document.getElementById('telegram-form');
            var formData = new FormData(form);

            fetch(adminBasePath + '/logger/telegram/test', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                alert(data.success ? 'Test message sent!' : 'Error: ' + data.message);
            });
        });
    }
});
</script>
