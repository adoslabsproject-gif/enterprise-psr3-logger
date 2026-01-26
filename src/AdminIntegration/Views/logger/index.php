<?php
/**
 * Logger Dashboard View
 *
 * Features:
 * - Channel configuration cards with level selection
 * - Full logs viewer with filters and bulk actions
 *
 * @var array $channels Channel configurations with enabled/level
 * @var array $logs Database logs
 * @var int $total Total logs count
 * @var int $page Current page
 * @var int $per_page Items per page
 * @var int $pages Total pages
 * @var array $filters Current filters
 * @var array $available_channels Channels found in logs table
 * @var array $levels Available log levels
 * @var string $page_title Page title
 * @var string $admin_base_path Admin base path
 * @var string $csrf_input CSRF hidden input
 * @var string $csrf_token CSRF token value
 */

$getLevelColor = function ($level) {
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

$getChannelColor = function ($color) {
    return match($color) {
        'blue' => '#3b82f6',
        'purple' => '#8b5cf6',
        'cyan' => '#06b6d4',
        'orange' => '#f97316',
        'green' => '#22c55e',
        'pink' => '#ec4899',
        'indigo' => '#6366f1',
        'yellow' => '#eab308',
        'red' => '#ef4444',
        default => '#6b7280'
    };
};

$getIcon = function ($icon) {
    $icons = [
        'box' => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'globe' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
        'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'mail' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
        'layers' => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
        'zap' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
    ];
    return $icons[$icon] ?? $icons['box'];
};
?>

<div class="eap-page">
    <div class="eap-page__header">
        <h1 class="eap-page__title">Logging Dashboard</h1>
        <p class="eap-page__subtitle">Configure channels and view application logs</p>
    </div>

    <!-- Channel Configuration Cards -->
    <div class="eap-section">
        <div class="eap-section__header">
            <h2 class="eap-section__title">Log Channels</h2>
            <span class="eap-badge eap-badge--info"><?= count(array_filter($channels, fn($c) => $c['enabled'])) ?> / <?= count($channels) ?> active</span>
        </div>

        <div class="eap-grid eap-grid--4" style="gap: 16px;">
            <?php foreach ($channels as $key => $channel): ?>
            <div class="eap-card eap-channel-card"
                 data-channel="<?= htmlspecialchars($key) ?>"
                 style="border-left: 4px solid <?= $getChannelColor($channel['color'] ?? 'blue') ?>; <?= !$channel['enabled'] ? 'opacity: 0.6;' : '' ?>">
                <div class="eap-card__body" style="padding: 16px;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 12px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 36px; height: 36px; border-radius: 8px; background: <?= $getChannelColor($channel['color'] ?? 'blue') ?>15; color: <?= $getChannelColor($channel['color'] ?? 'blue') ?>; display: flex; align-items: center; justify-content: center;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <?= $getIcon($channel['icon'] ?? 'box') ?>
                                </svg>
                            </div>
                            <div>
                                <h4 style="margin: 0; font-size: 14px; font-weight: 600;"><?= htmlspecialchars($channel['name']) ?></h4>
                                <span style="font-size: 11px; color: var(--eap-text-muted);"><?= htmlspecialchars($key) ?></span>
                            </div>
                        </div>
                        <label class="eap-toggle eap-toggle--sm">
                            <input type="checkbox" class="channel-toggle" data-channel="<?= htmlspecialchars($key) ?>" <?= $channel['enabled'] ? 'checked' : '' ?>>
                            <span class="eap-toggle__slider"></span>
                        </label>
                    </div>

                    <p style="font-size: 12px; color: var(--eap-text-muted); margin: 0 0 12px 0; min-height: 32px;">
                        <?= htmlspecialchars($channel['description']) ?>
                    </p>

                    <div style="display: flex; align-items: center; gap: 8px;">
                        <label style="font-size: 11px; font-weight: 500; color: var(--eap-text-muted);">Min Level:</label>
                        <select class="eap-select eap-select--sm channel-level" data-channel="<?= htmlspecialchars($key) ?>" style="flex: 1; font-size: 12px;">
                            <?php foreach ($levels as $level): ?>
                            <option value="<?= $level ?>" <?= $channel['level'] === $level ? 'selected' : '' ?>>
                                <?= ucfirst($level) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Logs Viewer -->
    <div class="eap-card" style="margin-top: 24px;">
        <div class="eap-card__header" style="flex-wrap: wrap; gap: 16px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <h2 class="eap-card__title" style="margin: 0;">Database Logs</h2>
                <span class="eap-badge eap-badge--secondary"><?= number_format($total) ?> total</span>
            </div>

            <!-- Bulk Actions -->
            <div class="eap-button-group" style="margin-left: auto;">
                <button type="button" class="eap-btn eap-btn--sm eap-btn--danger" id="delete-selected" disabled>
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    <span>Delete Selected</span>
                </button>
                <button type="button" class="eap-btn eap-btn--sm eap-btn--secondary" id="clear-old-logs">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <span>Clear Old Logs</span>
                </button>
            </div>
        </div>

        <!-- Filters -->
        <div class="eap-card__body" style="border-bottom: 1px solid var(--eap-border-color); padding: 12px 16px;">
            <form method="GET" action="" class="eap-filters" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
                <div class="eap-form-group" style="margin: 0; flex: 0 0 150px;">
                    <label class="eap-label" style="font-size: 11px; margin-bottom: 4px;">Channel</label>
                    <select name="channel" class="eap-select eap-select--sm">
                        <option value="">All Channels</option>
                        <?php foreach ($available_channels as $ch): ?>
                        <option value="<?= htmlspecialchars($ch) ?>" <?= ($filters['channel'] ?? '') === $ch ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ch) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="eap-form-group" style="margin: 0; flex: 0 0 120px;">
                    <label class="eap-label" style="font-size: 11px; margin-bottom: 4px;">Level</label>
                    <select name="level" class="eap-select eap-select--sm">
                        <option value="">All Levels</option>
                        <?php foreach ($levels as $level): ?>
                        <option value="<?= $level ?>" <?= ($filters['level'] ?? '') === $level ? 'selected' : '' ?>>
                            <?= ucfirst($level) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="eap-form-group" style="margin: 0; flex: 0 0 140px;">
                    <label class="eap-label" style="font-size: 11px; margin-bottom: 4px;">From</label>
                    <input type="date" name="from" class="eap-input eap-input--sm" value="<?= htmlspecialchars($filters['from'] ?? '') ?>">
                </div>

                <div class="eap-form-group" style="margin: 0; flex: 0 0 140px;">
                    <label class="eap-label" style="font-size: 11px; margin-bottom: 4px;">To</label>
                    <input type="date" name="to" class="eap-input eap-input--sm" value="<?= htmlspecialchars($filters['to'] ?? '') ?>">
                </div>

                <div class="eap-form-group" style="margin: 0; flex: 1; min-width: 200px;">
                    <label class="eap-label" style="font-size: 11px; margin-bottom: 4px;">Search</label>
                    <input type="text" name="search" class="eap-input eap-input--sm" placeholder="Search in message or context..." value="<?= htmlspecialchars($filters['search'] ?? '') ?>">
                </div>

                <div class="eap-button-group" style="flex: 0 0 auto;">
                    <button type="submit" class="eap-btn eap-btn--sm eap-btn--primary">Filter</button>
                    <a href="<?= htmlspecialchars($admin_base_path) ?>/logger" class="eap-btn eap-btn--sm eap-btn--secondary">Reset</a>
                </div>
            </form>
        </div>

        <!-- Logs Table -->
        <div class="eap-card__body eap-card__body--flush">
            <div class="eap-table-container">
                <table class="eap-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;">
                                <input type="checkbox" id="select-all" class="eap-checkbox">
                            </th>
                            <th style="width: 160px;">Time</th>
                            <th style="width: 120px;">Channel</th>
                            <th style="width: 100px;">Level</th>
                            <th>Message</th>
                            <th style="width: 60px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr data-id="<?= $log['id'] ?>">
                            <td>
                                <input type="checkbox" class="eap-checkbox log-select" value="<?= $log['id'] ?>">
                            </td>
                            <td style="white-space: nowrap; font-size: 12px; color: var(--eap-text-muted);">
                                <?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?>
                            </td>
                            <td>
                                <span class="eap-badge eap-badge--outline"><?= htmlspecialchars($log['channel']) ?></span>
                            </td>
                            <td>
                                <span class="eap-badge eap-badge--<?= $getLevelColor($log['level']) ?>">
                                    <?= strtoupper($log['level']) ?>
                                </span>
                            </td>
                            <td>
                                <div style="max-width: 400px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($log['message']) ?>">
                                    <?= htmlspecialchars($log['message']) ?>
                                </div>
                                <?php if (!empty($log['context']) && is_array($log['context']) && count($log['context']) > 0): ?>
                                <button type="button" class="eap-btn eap-btn--xs eap-btn--ghost show-context"
                                        data-context="<?= htmlspecialchars(json_encode($log['context'], JSON_PRETTY_PRINT)) ?>">
                                    + context
                                </button>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="eap-btn eap-btn--xs eap-btn--danger delete-log" data-id="<?= $log['id'] ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 48px; color: var(--eap-text-muted);">
                                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" style="margin-bottom: 12px; opacity: 0.5;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                                <p style="margin: 0;">No logs found</p>
                                <?php if (!empty(array_filter($filters))): ?>
                                <p style="margin: 8px 0 0 0; font-size: 12px;">Try adjusting your filters</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
        <div class="eap-card__footer" style="display: flex; justify-content: space-between; align-items: center;">
            <div style="font-size: 13px; color: var(--eap-text-muted);">
                Showing <?= number_format(($page - 1) * $per_page + 1) ?> - <?= number_format(min($page * $per_page, $total)) ?> of <?= number_format($total) ?>
            </div>
            <div class="eap-pagination">
                <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($filters, ['page' => $page - 1])) ?>" class="eap-btn eap-btn--sm eap-btn--secondary">Previous</a>
                <?php endif; ?>

                <?php
                $start = max(1, $page - 2);
                $end = min($pages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                <a href="?<?= http_build_query(array_merge($filters, ['page' => $i])) ?>"
                   class="eap-btn eap-btn--sm <?= $i === $page ? 'eap-btn--primary' : 'eap-btn--secondary' ?>">
                    <?= $i ?>
                </a>
                <?php endfor; ?>

                <?php if ($page < $pages): ?>
                <a href="?<?= http_build_query(array_merge($filters, ['page' => $page + 1])) ?>" class="eap-btn eap-btn--sm eap-btn--secondary">Next</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Context Modal -->
<div id="context-modal" class="eap-modal" style="display: none;">
    <div class="eap-modal__backdrop"></div>
    <div class="eap-modal__content" style="max-width: 600px;">
        <div class="eap-modal__header">
            <h3 class="eap-modal__title">Log Context</h3>
            <button type="button" class="eap-modal__close">&times;</button>
        </div>
        <div class="eap-modal__body">
            <pre id="context-content" style="background: var(--eap-bg-secondary); padding: 16px; border-radius: 8px; overflow: auto; max-height: 400px; font-size: 12px;"></pre>
        </div>
    </div>
</div>

<!-- Clear Logs Modal -->
<div id="clear-modal" class="eap-modal" style="display: none;">
    <div class="eap-modal__backdrop"></div>
    <div class="eap-modal__content" style="max-width: 400px;">
        <div class="eap-modal__header">
            <h3 class="eap-modal__title">Clear Old Logs</h3>
            <button type="button" class="eap-modal__close">&times;</button>
        </div>
        <form id="clear-form" method="POST" action="<?= htmlspecialchars($admin_base_path) ?>/logger/logs/clear">
            <?= $csrf_input ?>
            <div class="eap-modal__body">
                <div class="eap-form-group">
                    <label class="eap-label">Delete logs older than:</label>
                    <select name="older_than" class="eap-select">
                        <option value="1 day">1 day</option>
                        <option value="3 days">3 days</option>
                        <option value="7 days" selected>7 days</option>
                        <option value="14 days">14 days</option>
                        <option value="30 days">30 days</option>
                        <option value="90 days">90 days</option>
                    </select>
                </div>
                <div class="eap-form-group">
                    <label class="eap-label">Channel (optional):</label>
                    <select name="channel" class="eap-select">
                        <option value="">All Channels</option>
                        <?php foreach ($available_channels as $ch): ?>
                        <option value="<?= htmlspecialchars($ch) ?>"><?= htmlspecialchars($ch) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="eap-modal__footer">
                <button type="button" class="eap-btn eap-btn--secondary eap-modal__close-btn">Cancel</button>
                <button type="submit" class="eap-btn eap-btn--danger">Clear Logs</button>
            </div>
        </form>
    </div>
</div>

<style>
.eap-section {
    margin-bottom: 24px;
}
.eap-section__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}
.eap-section__title {
    font-size: 16px;
    font-weight: 600;
    margin: 0;
}
.eap-channel-card {
    transition: all 0.2s ease;
}
.eap-channel-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.eap-toggle--sm .eap-toggle__slider {
    width: 36px;
    height: 20px;
}
.eap-toggle--sm .eap-toggle__slider:before {
    width: 16px;
    height: 16px;
}
.eap-toggle--sm input:checked + .eap-toggle__slider:before {
    transform: translateX(16px);
}
.eap-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
}
.eap-modal__backdrop {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
}
.eap-modal__content {
    position: relative;
    background: var(--eap-bg-primary);
    border-radius: 12px;
    max-height: 90vh;
    overflow: auto;
    width: 100%;
    margin: 20px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.2);
}
.eap-modal__header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--eap-border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.eap-modal__title {
    margin: 0;
    font-size: 16px;
}
.eap-modal__close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: var(--eap-text-muted);
    padding: 0;
    line-height: 1;
}
.eap-modal__body {
    padding: 20px;
}
.eap-modal__footer {
    padding: 16px 20px;
    border-top: 1px solid var(--eap-border-color);
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}
.eap-btn--xs {
    padding: 2px 6px;
    font-size: 10px;
}
.eap-btn--ghost {
    background: none;
    border: none;
    color: var(--eap-primary);
    padding: 2px 4px;
}
.eap-btn--ghost:hover {
    text-decoration: underline;
}
.eap-pagination {
    display: flex;
    gap: 4px;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var adminBasePath = <?= json_encode($admin_base_path) ?>;
    var csrfToken = <?= json_encode($csrf_token ?? '') ?>;

    // Channel toggle handlers
    document.querySelectorAll('.channel-toggle').forEach(function(toggle) {
        toggle.addEventListener('change', function() {
            var channel = this.dataset.channel;
            var card = this.closest('.eap-channel-card');
            card.style.opacity = this.checked ? '1' : '0.6';
            updateChannel(channel, this.checked,
                document.querySelector('.channel-level[data-channel="' + channel + '"]').value);
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
                showToast('Channel "' + channel + '" updated', 'success');
            } else {
                showToast('Error: ' + (data.message || 'Failed to update'), 'error');
            }
        });
    }

    // Select all checkbox
    var selectAll = document.getElementById('select-all');
    var logSelects = document.querySelectorAll('.log-select');
    var deleteBtn = document.getElementById('delete-selected');

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            logSelects.forEach(function(cb) {
                cb.checked = selectAll.checked;
            });
            updateDeleteBtn();
        });
    }

    logSelects.forEach(function(cb) {
        cb.addEventListener('change', updateDeleteBtn);
    });

    function updateDeleteBtn() {
        var selected = document.querySelectorAll('.log-select:checked');
        deleteBtn.disabled = selected.length === 0;
        deleteBtn.querySelector('span').textContent = selected.length > 0
            ? 'Delete Selected (' + selected.length + ')'
            : 'Delete Selected';
    }

    // Delete selected logs
    deleteBtn.addEventListener('click', function() {
        var selected = document.querySelectorAll('.log-select:checked');
        if (selected.length === 0) return;

        if (!confirm('Delete ' + selected.length + ' selected log(s)?')) return;

        var ids = Array.from(selected).map(function(cb) { return cb.value; });

        fetch(adminBasePath + '/logger/logs/delete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({ ids: ids, _csrf_token: csrfToken })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                selected.forEach(function(cb) {
                    cb.closest('tr').remove();
                });
                showToast('Deleted ' + data.deleted + ' log(s)', 'success');
                selectAll.checked = false;
                updateDeleteBtn();
            } else {
                showToast('Error: ' + (data.message || 'Failed to delete'), 'error');
            }
        });
    });

    // Single delete
    document.querySelectorAll('.delete-log').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.dataset.id;
            var row = this.closest('tr');

            if (!confirm('Delete this log entry?')) return;

            fetch(adminBasePath + '/logger/logs/delete', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ ids: [id], _csrf_token: csrfToken })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    row.remove();
                    showToast('Log deleted', 'success');
                }
            });
        });
    });

    // Clear old logs modal
    var clearBtn = document.getElementById('clear-old-logs');
    var clearModal = document.getElementById('clear-modal');

    clearBtn.addEventListener('click', function() {
        clearModal.style.display = 'flex';
    });

    // Show context modal
    document.querySelectorAll('.show-context').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var context = this.dataset.context;
            document.getElementById('context-content').textContent = context;
            document.getElementById('context-modal').style.display = 'flex';
        });
    });

    // Modal close handlers
    document.querySelectorAll('.eap-modal__close, .eap-modal__close-btn, .eap-modal__backdrop').forEach(function(el) {
        el.addEventListener('click', function() {
            this.closest('.eap-modal').style.display = 'none';
        });
    });

    // Escape key to close modals
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.eap-modal').forEach(function(modal) {
                modal.style.display = 'none';
            });
        }
    });

    function showToast(message, type) {
        // Simple toast notification
        var toast = document.createElement('div');
        toast.className = 'eap-toast eap-toast--' + type;
        toast.textContent = message;
        toast.style.cssText = 'position: fixed; bottom: 20px; right: 20px; padding: 12px 20px; border-radius: 8px; color: white; z-index: 9999; animation: fadeIn 0.3s ease;';
        toast.style.background = type === 'success' ? '#22c55e' : type === 'error' ? '#ef4444' : '#3b82f6';
        document.body.appendChild(toast);
        setTimeout(function() {
            toast.remove();
        }, 3000);
    }
});
</script>
