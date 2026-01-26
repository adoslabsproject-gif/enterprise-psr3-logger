<?php
/**
 * Logger Dashboard View
 *
 * CSP-compliant version - no inline styles or scripts
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

<!-- Page Header -->
<div class="eap-page-header">
    <h1 class="eap-page-title">Logging Dashboard</h1>
    <p class="eap-page-subtitle">Configure channels and monitor application logs</p>
</div>

<!-- Channel Cards Grid -->
<div class="eap-logger-channel-grid">
    <?php foreach ($channels as $key => $channel): ?>
    <div class="eap-logger-channel-card eap-logger-channel-card--<?= htmlspecialchars($channel['color'] ?? 'blue') ?> <?= !$channel['enabled'] ? 'eap-logger-channel-card--disabled' : '' ?>" data-channel="<?= htmlspecialchars($key) ?>">
        <div class="eap-logger-channel-card__accent"></div>

        <div class="eap-logger-channel-card__header">
            <div class="eap-logger-channel-card__info">
                <div class="eap-logger-channel-card__icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <?= $getIcon($channel['icon'] ?? 'box') ?>
                    </svg>
                </div>
                <div>
                    <h3 class="eap-logger-channel-card__name"><?= htmlspecialchars($channel['name']) ?></h3>
                    <span class="eap-logger-channel-card__key"><?= htmlspecialchars($key) ?></span>
                </div>
            </div>

            <label class="eap-logger-switch">
                <input type="checkbox" class="eap-logger-switch__input channel-toggle" data-channel="<?= htmlspecialchars($key) ?>" <?= $channel['enabled'] ? 'checked' : '' ?>>
                <span class="eap-logger-switch__slider"></span>
            </label>
        </div>

        <p class="eap-logger-channel-card__desc"><?= htmlspecialchars($channel['description']) ?></p>

        <div class="eap-logger-channel-card__controls">
            <select class="eap-logger-channel-card__level-select channel-level" data-channel="<?= htmlspecialchars($key) ?>">
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
    <div class="eap-logger-logs-header">
        <h2 class="eap-logger-logs-title">
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
    <form method="GET" class="eap-logger-filters">
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

        <div class="eap-form-group eap-logger-filter-buttons">
            <button type="submit" class="eap-btn eap-btn--primary eap-btn--sm">Filter</button>
            <a href="<?= htmlspecialchars($admin_base_path) ?>/logger" class="eap-btn eap-btn--ghost eap-btn--sm">Reset</a>
        </div>
    </form>

    <!-- Logs Table -->
    <div class="eap-table-container">
        <table class="eap-logger-table">
            <thead>
                <tr>
                    <th class="eap-logger-table__col--checkbox">
                        <input type="checkbox" id="select-all">
                    </th>
                    <th class="eap-logger-table__col--time">Time</th>
                    <th class="eap-logger-table__col--channel">Channel</th>
                    <th class="eap-logger-table__col--level">Level</th>
                    <th>Message</th>
                    <th class="eap-logger-table__col--actions"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="6">
                        <div class="eap-logger-empty-state">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                            </svg>
                            <p>No logs found</p>
                            <?php if (!empty(array_filter($filters))): ?>
                            <p class="eap-logger-empty-state__hint">Try adjusting your filters</p>
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
                    <td class="eap-logger-log-time">
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
                    <td class="eap-logger-log-message">
                        <?= htmlspecialchars($log['message']) ?>
                        <?php if (!empty($log['context']) && is_array($log['context']) && count($log['context']) > 0): ?>
                        <button type="button" class="eap-logger-context-btn" data-context="<?= htmlspecialchars(json_encode($log['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?>">
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
    <div class="eap-logger-pagination">
        <div class="eap-logger-pagination__info">
            Showing <?= number_format(($page - 1) * $per_page + 1) ?> - <?= number_format(min($page * $per_page, $total)) ?> of <?= number_format($total) ?>
        </div>
        <div class="eap-logger-pagination__links">
            <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($filters, ['page' => $page - 1])) ?>" class="eap-logger-pagination__link">Prev</a>
            <?php endif; ?>

            <?php
            $start = max(1, $page - 2);
            $end = min($pages, $page + 2);
            for ($i = $start; $i <= $end; $i++): ?>
            <a href="?<?= http_build_query(array_merge($filters, ['page' => $i])) ?>"
               class="eap-logger-pagination__link <?= $i === $page ? 'eap-logger-pagination__link--active' : '' ?>">
                <?= $i ?>
            </a>
            <?php endfor; ?>

            <?php if ($page < $pages): ?>
            <a href="?<?= http_build_query(array_merge($filters, ['page' => $page + 1])) ?>" class="eap-logger-pagination__link">Next</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Context Modal -->
<div id="context-modal" class="eap-logger-modal-overlay hidden">
    <div class="eap-logger-modal">
        <div class="eap-logger-modal__header">
            <h3 class="eap-logger-modal__title">Log Context</h3>
            <button type="button" class="eap-logger-modal__close">&times;</button>
        </div>
        <div class="eap-logger-modal__body">
            <pre id="context-content"></pre>
        </div>
    </div>
</div>

<!-- Clear Logs Modal -->
<div id="clear-modal" class="eap-logger-modal-overlay hidden">
    <div class="eap-logger-modal">
        <div class="eap-logger-modal__header">
            <h3 class="eap-logger-modal__title">Clear Old Logs</h3>
            <button type="button" class="eap-logger-modal__close">&times;</button>
        </div>
        <form id="clear-form" method="POST" action="<?= htmlspecialchars($admin_base_path) ?>/logger/logs/clear">
            <?= $csrf_input ?>
            <div class="eap-logger-modal__body">
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
            <div class="eap-logger-modal__footer">
                <button type="button" class="eap-btn eap-btn--ghost modal-close">Cancel</button>
                <button type="submit" class="eap-btn eap-btn--danger">Clear Logs</button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden data for JavaScript -->
<input type="hidden" id="logger-admin-base-path" value="<?= htmlspecialchars($admin_base_path) ?>">
<input type="hidden" id="logger-csrf-token" value="<?= htmlspecialchars($csrf_token ?? '') ?>">
