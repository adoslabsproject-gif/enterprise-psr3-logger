<?php
/**
 * Database Logs Viewer
 *
 * CSP Compliant: No inline styles or scripts
 * Requires: /css/logger.css, /js/logger.js
 *
 * @var array $logs Database logs
 * @var int $total Total count
 * @var int $page Current page
 * @var int $per_page Items per page
 * @var int $pages Total pages
 * @var array $filters Active filters
 * @var array $channels Available channels
 * @var array $levels Available levels
 * @var string $admin_base_path Admin base path
 * @var string $csrf_input CSRF hidden input
 */

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
?>

<!-- Filters -->
<div class="eap-card">
    <div class="eap-card__header">
        <span class="eap-card__title">$ grep --filter logs</span>
        <span class="eap-text--muted"><?= number_format($total) ?> total entries</span>
    </div>
    <div class="eap-card__body">
        <form method="GET" action="<?= $admin_base_path ?>/logger/database" class="eap-filter-form">
            <div class="eap-filter-row">
                <div class="eap-filter-group">
                    <label class="eap-form__label">Channel</label>
                    <select name="channel" class="eap-form__select">
                        <option value="">All Channels</option>
                        <?php foreach ($channels as $ch): ?>
                            <option value="<?= htmlspecialchars($ch) ?>" <?= $filters['channel'] === $ch ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ch) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="eap-filter-group">
                    <label class="eap-form__label">Level</label>
                    <select name="level" class="eap-form__select">
                        <option value="">All Levels</option>
                        <?php foreach ($levels as $lvl): ?>
                            <option value="<?= $lvl ?>" <?= $filters['level'] === $lvl ? 'selected' : '' ?>>
                                <?= ucfirst($lvl) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="eap-filter-group">
                    <label class="eap-form__label">Search</label>
                    <input type="text" name="search" class="eap-form__input"
                           value="<?= htmlspecialchars($filters['search'] ?? '') ?>"
                           placeholder="Search in messages...">
                </div>
                <div class="eap-filter-group">
                    <label class="eap-form__label">From</label>
                    <input type="datetime-local" name="from" class="eap-form__input"
                           value="<?= htmlspecialchars($filters['from'] ?? '') ?>">
                </div>
                <div class="eap-filter-group">
                    <label class="eap-form__label">To</label>
                    <input type="datetime-local" name="to" class="eap-form__input"
                           value="<?= htmlspecialchars($filters['to'] ?? '') ?>">
                </div>
            </div>
            <div class="eap-filter-actions">
                <button type="submit" class="eap-btn eap-btn--primary">Apply Filters</button>
                <a href="<?= $admin_base_path ?>/logger/database" class="eap-btn eap-btn--ghost">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Logs Table -->
<div class="eap-card">
    <div class="eap-card__header">
        <span class="eap-card__title">$ tail -f logs.db</span>
        <div class="eap-card__actions">
            <button type="button" class="eap-btn eap-btn--danger eap-btn--sm" id="clear-logs-btn">
                Clear Old Logs
            </button>
        </div>
    </div>
    <div class="eap-card__body eap-card__body--flush">
        <?php if (empty($logs)): ?>
            <div class="eap-empty-state">
                <p>No logs found matching your filters</p>
            </div>
        <?php else: ?>
            <div class="eap-log-table-wrapper">
                <table class="eap-table eap-table--logs">
                    <thead class="eap-table__head">
                        <tr>
                            <th class="eap-table__th eap-table__th--time">Time</th>
                            <th class="eap-table__th eap-table__th--level">Level</th>
                            <th class="eap-table__th eap-table__th--channel">Channel</th>
                            <th class="eap-table__th">Message</th>
                            <th class="eap-table__th eap-table__th--actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr class="eap-table__row eap-log-row eap-log-row--<?= strtolower($log['level']) ?>">
                                <td class="eap-table__td eap-text--mono">
                                    <?= date('H:i:s', strtotime($log['created_at'])) ?>
                                    <div class="eap-text--tiny eap-text--muted">
                                        <?= date('Y-m-d', strtotime($log['created_at'])) ?>
                                    </div>
                                </td>
                                <td class="eap-table__td">
                                    <span class="eap-level-badge eap-level-badge--<?= strtolower($log['level']) ?>">
                                        <?= strtoupper($log['level']) ?>
                                    </span>
                                </td>
                                <td class="eap-table__td">
                                    <code class="eap-code"><?= htmlspecialchars($log['channel']) ?></code>
                                </td>
                                <td class="eap-table__td eap-log-message">
                                    <div class="eap-log-message__text"><?= htmlspecialchars(substr($log['message'], 0, 150)) ?><?= strlen($log['message']) > 150 ? '...' : '' ?></div>
                                    <?php if (!empty($log['context'])): ?>
                                        <button type="button" class="eap-btn eap-btn--ghost eap-btn--xs toggle-context" data-log-id="<?= $log['id'] ?>">
                                            Show Context
                                        </button>
                                        <pre class="eap-log-context" id="context-<?= $log['id'] ?>" hidden><?= htmlspecialchars(json_encode($log['context'], JSON_PRETTY_PRINT)) ?></pre>
                                    <?php endif; ?>
                                </td>
                                <td class="eap-table__td">
                                    <?php if (!empty($log['request_id'])): ?>
                                        <span class="eap-badge eap-badge--info" title="Request ID: <?= htmlspecialchars($log['request_id']) ?>">
                                            <?= substr($log['request_id'], 0, 8) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Pagination -->
<?php if ($pages > 1): ?>
    <div class="eap-pagination">
        <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($filters, ['page' => $page - 1])) ?>" class="eap-btn eap-btn--ghost">
                &laquo; Previous
            </a>
        <?php endif; ?>

        <div class="eap-pagination__info">
            Page <?= $page ?> of <?= $pages ?>
        </div>

        <?php if ($page < $pages): ?>
            <a href="?<?= http_build_query(array_merge($filters, ['page' => $page + 1])) ?>" class="eap-btn eap-btn--ghost">
                Next &raquo;
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Clear Logs Modal -->
<div id="clear-modal" class="eap-modal" hidden>
    <div class="eap-modal__backdrop"></div>
    <div class="eap-modal__content">
        <div class="eap-modal__header">
            <h3>Clear Old Logs</h3>
            <button type="button" class="eap-modal__close">&times;</button>
        </div>
        <div class="eap-modal__body">
            <form id="clear-form" method="POST" action="<?= $admin_base_path ?>/logger/database/clear">
                <?= $csrf_input ?>
                <div class="eap-form__group">
                    <label class="eap-form__label">Delete logs older than:</label>
                    <select name="older_than" class="eap-form__select">
                        <option value="1 day">1 day</option>
                        <option value="3 days">3 days</option>
                        <option value="7 days" selected>7 days</option>
                        <option value="14 days">14 days</option>
                        <option value="30 days">30 days</option>
                    </select>
                </div>
                <p class="eap-text--warning">This action cannot be undone!</p>
            </form>
        </div>
        <div class="eap-modal__footer">
            <button type="button" class="eap-btn eap-btn--ghost eap-modal__cancel">Cancel</button>
            <button type="submit" form="clear-form" class="eap-btn eap-btn--danger">Delete Logs</button>
        </div>
    </div>
</div>

<!-- Link external CSS and JS (published to /public/modules/psr3-logger/) -->
<link rel="stylesheet" href="/modules/psr3-logger/css/logger.css">
<script src="/modules/psr3-logger/js/logger.js" defer></script>
