<?php
/**
 * Logger Dashboard View
 *
 * Features:
 * - Channel cards in 3-column grid with level selection
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

$getLevelBadgeClass = fn ($level) => match (strtolower($level)) {
    'emergency', 'alert', 'critical', 'error' => 'eap-badge--danger',
    'warning' => 'eap-badge--warning',
    'notice', 'info' => 'eap-badge--info',
    'debug' => 'eap-badge--secondary',
    default => 'eap-badge--secondary'
};

$getChannelColor = fn ($color) => match ($color) {
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

$getIcon = fn ($icon) => $icons[$icon] ?? $icons['box'];
?>

<style>
.eap-channel-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 32px;
}
@media (max-width: 1200px) {
    .eap-channel-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
    .eap-channel-grid { grid-template-columns: 1fr; }
}
.eap-channel-card {
    background: var(--eap-card-bg, #fff);
    border: 1px solid var(--eap-border-color, #e5e7eb);
    border-radius: 12px;
    padding: 20px;
    transition: box-shadow 0.2s, border-color 0.2s;
    position: relative;
    overflow: hidden;
}
.eap-channel-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}
.eap-channel-card--disabled {
    opacity: 0.6;
}
.eap-channel-card__accent {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
}
.eap-channel-card__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 12px;
}
.eap-channel-card__info {
    display: flex;
    align-items: center;
    gap: 12px;
}
.eap-channel-card__icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.eap-channel-card__icon svg {
    width: 20px;
    height: 20px;
}
.eap-channel-card__name {
    font-size: 15px;
    font-weight: 600;
    color: var(--eap-text-primary, #111827);
    margin: 0 0 2px 0;
}
.eap-channel-card__key {
    font-size: 12px;
    color: var(--eap-text-muted, #6b7280);
    font-family: monospace;
}
.eap-channel-card__desc {
    font-size: 13px;
    color: var(--eap-text-secondary, #4b5563);
    margin: 0 0 16px 0;
    line-height: 1.5;
}
.eap-channel-card__controls {
    display: flex;
    align-items: center;
    gap: 12px;
}
.eap-channel-card__level-select {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid var(--eap-border-color, #e5e7eb);
    border-radius: 8px;
    font-size: 13px;
    background: var(--eap-input-bg, #fff);
    color: var(--eap-text-primary, #111827);
    cursor: pointer;
}
.eap-channel-card__level-select:focus {
    outline: none;
    border-color: var(--eap-primary, #3b82f6);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}
/* Toggle switch */
.eap-switch {
    position: relative;
    width: 44px;
    height: 24px;
    flex-shrink: 0;
}
.eap-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}
.eap-switch__slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #d1d5db;
    transition: 0.3s;
    border-radius: 24px;
}
.eap-switch__slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: 0.3s;
    border-radius: 50%;
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
.eap-switch input:checked + .eap-switch__slider {
    background-color: #22c55e;
}
.eap-switch input:checked + .eap-switch__slider:before {
    transform: translateX(20px);
}
/* Logs section */
.eap-logs-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 16px;
}
.eap-logs-title {
    font-size: 18px;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 12px;
}
.eap-logs-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    padding: 16px;
    background: var(--eap-bg-secondary, #f9fafb);
    border-radius: 8px;
    margin-bottom: 16px;
}
.eap-logs-filters .eap-form-group {
    margin: 0;
    min-width: 140px;
}
.eap-logs-filters .eap-form-group--search {
    flex: 1;
    min-width: 200px;
}
.eap-logs-filters label {
    display: block;
    font-size: 11px;
    font-weight: 500;
    color: var(--eap-text-muted, #6b7280);
    margin-bottom: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.eap-logs-filters select,
.eap-logs-filters input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--eap-border-color, #e5e7eb);
    border-radius: 6px;
    font-size: 13px;
    background: var(--eap-input-bg, #fff);
}
.eap-logs-table {
    width: 100%;
    border-collapse: collapse;
}
.eap-logs-table th {
    text-align: left;
    padding: 12px 16px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--eap-text-muted, #6b7280);
    background: var(--eap-bg-secondary, #f9fafb);
    border-bottom: 1px solid var(--eap-border-color, #e5e7eb);
}
.eap-logs-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--eap-border-color, #e5e7eb);
    font-size: 13px;
    vertical-align: top;
}
.eap-logs-table tr:hover {
    background: var(--eap-bg-hover, #f9fafb);
}
.eap-log-time {
    font-family: monospace;
    font-size: 12px;
    color: var(--eap-text-muted, #6b7280);
    white-space: nowrap;
}
.eap-log-message {
    max-width: 400px;
    word-break: break-word;
}
.eap-log-context-btn {
    font-size: 11px;
    color: var(--eap-primary, #3b82f6);
    background: none;
    border: none;
    cursor: pointer;
    padding: 2px 6px;
    margin-top: 4px;
    display: inline-block;
}
.eap-log-context-btn:hover {
    text-decoration: underline;
}
.eap-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px;
    border-top: 1px solid var(--eap-border-color, #e5e7eb);
}
.eap-pagination__info {
    font-size: 13px;
    color: var(--eap-text-muted, #6b7280);
}
.eap-pagination__links {
    display: flex;
    gap: 4px;
}
.eap-pagination__link {
    padding: 6px 12px;
    border: 1px solid var(--eap-border-color, #e5e7eb);
    border-radius: 6px;
    font-size: 13px;
    text-decoration: none;
    color: var(--eap-text-primary, #111827);
    background: var(--eap-card-bg, #fff);
}
.eap-pagination__link:hover {
    background: var(--eap-bg-secondary, #f9fafb);
}
.eap-pagination__link--active {
    background: var(--eap-primary, #3b82f6);
    color: white;
    border-color: var(--eap-primary, #3b82f6);
}
/* Modal */
.eap-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}
.eap-modal-overlay.hidden { display: none; }
.eap-modal {
    background: var(--eap-card-bg, #fff);
    border-radius: 12px;
    max-width: 500px;
    width: 90%;
    max-height: 80vh;
    overflow: auto;
}
.eap-modal__header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--eap-border-color, #e5e7eb);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.eap-modal__title {
    font-size: 16px;
    font-weight: 600;
    margin: 0;
}
.eap-modal__close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: var(--eap-text-muted, #6b7280);
    line-height: 1;
}
.eap-modal__body {
    padding: 20px;
}
.eap-modal__body pre {
    background: var(--eap-bg-secondary, #f1f5f9);
    padding: 16px;
    border-radius: 8px;
    overflow: auto;
    font-size: 12px;
    margin: 0;
}
.eap-modal__footer {
    padding: 16px 20px;
    border-top: 1px solid var(--eap-border-color, #e5e7eb);
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}
.eap-toast {
    position: fixed;
    bottom: 20px;
    right: 20px;
    padding: 12px 20px;
    border-radius: 8px;
    color: white;
    font-size: 14px;
    z-index: 9999;
    animation: slideIn 0.3s ease;
}
.eap-toast--success { background: #22c55e; }
.eap-toast--error { background: #ef4444; }
@keyframes slideIn {
    from { transform: translateY(20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
.eap-empty-state {
    text-align: center;
    padding: 48px 24px;
    color: var(--eap-text-muted, #6b7280);
}
.eap-empty-state svg {
    width: 48px;
    height: 48px;
    margin-bottom: 16px;
    opacity: 0.5;
}
</style>

<!-- Page Header -->
<div class="eap-page-header">
    <h1 class="eap-page-title">Logging Dashboard</h1>
    <p class="eap-page-subtitle">Configure channels and monitor application logs</p>
</div>

<!-- Channel Cards Grid -->
<div class="eap-channel-grid">
    <?php foreach ($channels as $key => $channel): ?>
    <div class="eap-channel-card <?= !$channel['enabled'] ? 'eap-channel-card--disabled' : '' ?>" data-channel="<?= htmlspecialchars($key) ?>">
        <div class="eap-channel-card__accent" style="background: <?= $getChannelColor($channel['color'] ?? 'blue') ?>;"></div>

        <div class="eap-channel-card__header">
            <div class="eap-channel-card__info">
                <div class="eap-channel-card__icon" style="background: <?= $getChannelColor($channel['color'] ?? 'blue') ?>15; color: <?= $getChannelColor($channel['color'] ?? 'blue') ?>;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <?= $getIcon($channel['icon'] ?? 'box') ?>
                    </svg>
                </div>
                <div>
                    <h3 class="eap-channel-card__name"><?= htmlspecialchars($channel['name']) ?></h3>
                    <span class="eap-channel-card__key"><?= htmlspecialchars($key) ?></span>
                </div>
            </div>

            <label class="eap-switch">
                <input type="checkbox" class="channel-toggle" data-channel="<?= htmlspecialchars($key) ?>" <?= $channel['enabled'] ? 'checked' : '' ?>>
                <span class="eap-switch__slider"></span>
            </label>
        </div>

        <p class="eap-channel-card__desc"><?= htmlspecialchars($channel['description']) ?></p>

        <div class="eap-channel-card__controls">
            <select class="eap-channel-card__level-select channel-level" data-channel="<?= htmlspecialchars($key) ?>">
                <?php foreach ($levels as $level): ?>
                <option value="<?= $level ?>" <?= $channel['level'] === $level ? 'selected' : '' ?>>
                    <?= ucfirst($level) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Logs Section -->
<div class="eap-card">
    <div class="eap-logs-header" style="padding: 20px;">
        <h2 class="eap-logs-title">
            Database Logs
            <span class="eap-badge eap-badge--secondary"><?= number_format($total) ?></span>
        </h2>

        <div class="eap-button-group">
            <button type="button" class="eap-btn eap-btn--danger eap-btn--sm" id="delete-selected" disabled>
                Delete Selected
            </button>
            <button type="button" class="eap-btn eap-btn--secondary eap-btn--sm" id="clear-old-logs">
                Clear Old Logs
            </button>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="eap-logs-filters">
        <div class="eap-form-group">
            <label>Channel</label>
            <select name="channel">
                <option value="">All Channels</option>
                <?php foreach ($available_channels as $ch): ?>
                <option value="<?= htmlspecialchars($ch) ?>" <?= ($filters['channel'] ?? '') === $ch ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ch) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="eap-form-group">
            <label>Level</label>
            <select name="level">
                <option value="">All Levels</option>
                <?php foreach ($levels as $level): ?>
                <option value="<?= $level ?>" <?= ($filters['level'] ?? '') === $level ? 'selected' : '' ?>>
                    <?= ucfirst($level) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="eap-form-group">
            <label>From</label>
            <input type="date" name="from" value="<?= htmlspecialchars($filters['from'] ?? '') ?>">
        </div>

        <div class="eap-form-group">
            <label>To</label>
            <input type="date" name="to" value="<?= htmlspecialchars($filters['to'] ?? '') ?>">
        </div>

        <div class="eap-form-group eap-form-group--search">
            <label>Search</label>
            <input type="text" name="search" placeholder="Search in message..." value="<?= htmlspecialchars($filters['search'] ?? '') ?>">
        </div>

        <div class="eap-form-group" style="display: flex; align-items: flex-end; gap: 8px;">
            <button type="submit" class="eap-btn eap-btn--primary eap-btn--sm">Filter</button>
            <a href="<?= htmlspecialchars($admin_base_path) ?>/logger" class="eap-btn eap-btn--ghost eap-btn--sm">Reset</a>
        </div>
    </form>

    <!-- Logs Table -->
    <div class="eap-table-container">
        <table class="eap-logs-table">
            <thead>
                <tr>
                    <th style="width: 40px;">
                        <input type="checkbox" id="select-all">
                    </th>
                    <th style="width: 150px;">Time</th>
                    <th style="width: 120px;">Channel</th>
                    <th style="width: 100px;">Level</th>
                    <th>Message</th>
                    <th style="width: 60px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="6">
                        <div class="eap-empty-state">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                            </svg>
                            <p>No logs found</p>
                            <?php if (!empty(array_filter($filters))): ?>
                            <p style="font-size: 12px;">Try adjusting your filters</p>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <tr data-id="<?= $log['id'] ?>">
                    <td>
                        <input type="checkbox" class="log-select" value="<?= $log['id'] ?>">
                    </td>
                    <td class="eap-log-time">
                        <?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?>
                    </td>
                    <td>
                        <span class="eap-badge eap-badge--outline"><?= htmlspecialchars($log['channel']) ?></span>
                    </td>
                    <td>
                        <span class="eap-badge <?= $getLevelBadgeClass($log['level']) ?>">
                            <?= strtoupper($log['level']) ?>
                        </span>
                    </td>
                    <td class="eap-log-message">
                        <?= htmlspecialchars($log['message']) ?>
                        <?php if (!empty($log['context']) && is_array($log['context']) && count($log['context']) > 0): ?>
                        <button type="button" class="eap-log-context-btn" data-context="<?= htmlspecialchars(json_encode($log['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?>">
                            + context
                        </button>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button" class="eap-btn eap-btn--ghost eap-btn--xs delete-log" data-id="<?= $log['id'] ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="3 6 5 6 21 6"/>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                            </svg>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($pages > 1): ?>
    <div class="eap-pagination">
        <div class="eap-pagination__info">
            Showing <?= number_format(($page - 1) * $per_page + 1) ?> - <?= number_format(min($page * $per_page, $total)) ?> of <?= number_format($total) ?>
        </div>
        <div class="eap-pagination__links">
            <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($filters, ['page' => $page - 1])) ?>" class="eap-pagination__link">Prev</a>
            <?php endif; ?>

            <?php
            $start = max(1, $page - 2);
            $end = min($pages, $page + 2);
            for ($i = $start; $i <= $end; $i++): ?>
            <a href="?<?= http_build_query(array_merge($filters, ['page' => $i])) ?>"
               class="eap-pagination__link <?= $i === $page ? 'eap-pagination__link--active' : '' ?>">
                <?= $i ?>
            </a>
            <?php endfor; ?>

            <?php if ($page < $pages): ?>
            <a href="?<?= http_build_query(array_merge($filters, ['page' => $page + 1])) ?>" class="eap-pagination__link">Next</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Context Modal -->
<div id="context-modal" class="eap-modal-overlay hidden">
    <div class="eap-modal">
        <div class="eap-modal__header">
            <h3 class="eap-modal__title">Log Context</h3>
            <button type="button" class="eap-modal__close">&times;</button>
        </div>
        <div class="eap-modal__body">
            <pre id="context-content"></pre>
        </div>
    </div>
</div>

<!-- Clear Logs Modal -->
<div id="clear-modal" class="eap-modal-overlay hidden">
    <div class="eap-modal">
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
                <button type="button" class="eap-btn eap-btn--ghost modal-close">Cancel</button>
                <button type="submit" class="eap-btn eap-btn--danger">Clear Logs</button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    var adminBasePath = <?= json_encode($admin_base_path) ?>;
    var csrfToken = <?= json_encode($csrf_token ?? '') ?>;

    // Channel toggle/level change
    document.querySelectorAll('.channel-toggle').forEach(function(toggle) {
        toggle.addEventListener('change', function() {
            var channel = this.dataset.channel;
            var card = this.closest('.eap-channel-card');
            var levelSelect = card.querySelector('.channel-level');

            card.classList.toggle('eap-channel-card--disabled', !this.checked);
            saveChannel(channel, this.checked, levelSelect.value);
        });
    });

    document.querySelectorAll('.channel-level').forEach(function(select) {
        select.addEventListener('change', function() {
            var channel = this.dataset.channel;
            var card = this.closest('.eap-channel-card');
            var toggle = card.querySelector('.channel-toggle');

            saveChannel(channel, toggle.checked, this.value);
        });
    });

    function saveChannel(channel, enabled, level) {
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
            showToast(data.success ? 'Channel updated' : 'Error: ' + (data.message || 'Failed'), data.success ? 'success' : 'error');
        });
    }

    // Select all
    var selectAll = document.getElementById('select-all');
    var deleteBtn = document.getElementById('delete-selected');

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.log-select').forEach(function(cb) {
                cb.checked = selectAll.checked;
            });
            updateDeleteBtn();
        });
    }

    document.querySelectorAll('.log-select').forEach(function(cb) {
        cb.addEventListener('change', updateDeleteBtn);
    });

    function updateDeleteBtn() {
        var count = document.querySelectorAll('.log-select:checked').length;
        deleteBtn.disabled = count === 0;
        deleteBtn.textContent = count > 0 ? 'Delete Selected (' + count + ')' : 'Delete Selected';
    }

    // Delete selected
    deleteBtn.addEventListener('click', function() {
        var selected = document.querySelectorAll('.log-select:checked');
        if (selected.length === 0) return;
        if (!confirm('Delete ' + selected.length + ' log(s)?')) return;

        var ids = Array.from(selected).map(function(cb) { return cb.value; });

        fetch(adminBasePath + '/logger/logs/delete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ ids: ids, _csrf_token: csrfToken })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                selected.forEach(function(cb) { cb.closest('tr').remove(); });
                showToast('Deleted ' + data.deleted + ' log(s)', 'success');
                selectAll.checked = false;
                updateDeleteBtn();
            }
        });
    });

    // Single delete
    document.querySelectorAll('.delete-log').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.dataset.id;
            var row = this.closest('tr');
            if (!confirm('Delete this log?')) return;

            fetch(adminBasePath + '/logger/logs/delete', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
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

    // Context modal
    document.querySelectorAll('.eap-log-context-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.getElementById('context-content').textContent = this.dataset.context;
            document.getElementById('context-modal').classList.remove('hidden');
        });
    });

    // Clear modal
    document.getElementById('clear-old-logs').addEventListener('click', function() {
        document.getElementById('clear-modal').classList.remove('hidden');
    });

    // Close modals
    document.querySelectorAll('.eap-modal__close, .modal-close, .eap-modal-overlay').forEach(function(el) {
        el.addEventListener('click', function(e) {
            if (e.target === this) {
                this.closest('.eap-modal-overlay').classList.add('hidden');
            }
        });
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.eap-modal-overlay').forEach(function(m) {
                m.classList.add('hidden');
            });
        }
    });

    function showToast(message, type) {
        var toast = document.createElement('div');
        toast.className = 'eap-toast eap-toast--' + type;
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(function() { toast.remove(); }, 3000);
    }
})();
</script>
