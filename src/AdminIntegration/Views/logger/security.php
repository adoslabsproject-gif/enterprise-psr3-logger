<?php
/**
 * Security Log Database Viewer
 *
 * Displays security channel logs from the database (security_log table)
 * with filtering, pagination, and detailed context expansion.
 *
 * @var array $logs Log entries from database
 * @var int $total Total log count
 * @var int $page Current page
 * @var int $per_page Logs per page
 * @var int $pages Total pages
 * @var array $levels Available log levels
 * @var string $level_filter Current level filter
 * @var string $search Current search term
 * @var string $date_from Date from filter
 * @var string $date_to Date to filter
 * @var string $page_title Page title
 * @var string $admin_base_path Admin base path
 */

// Build query string for pagination/filtering
$buildUrl = function ($newPage = null, $newLevel = null, $newSearch = null) use ($page, $per_page, $level_filter, $search, $date_from, $date_to, $admin_base_path) {
    $params = array_filter([
        'page' => $newPage ?? $page,
        'per_page' => $per_page,
        'level' => $newLevel !== null ? $newLevel : $level_filter,
        'search' => $newSearch !== null ? $newSearch : $search,
        'from' => $date_from,
        'to' => $date_to,
    ], fn($v) => $v !== '' && $v !== null);

    return $admin_base_path . '/logger/security?' . http_build_query($params);
};

$getLevelBadgeClass = fn($level) => match (strtolower($level)) {
    'emergency', 'alert', 'critical', 'error' => 'eap-badge--danger',
    'warning' => 'eap-badge--warning',
    'notice', 'info' => 'eap-badge--info',
    'debug' => 'eap-badge--secondary',
    default => 'eap-badge--secondary',
};
?>

<!-- Page Header -->
<div class="eap-page-header">
    <div class="eap-page-header__content">
        <h1 class="eap-page-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
            Security Log
        </h1>
        <p class="eap-page-subtitle">
            Database audit trail for security events
            <?php if ($total > 0): ?>
            | <?= number_format($total) ?> entries
            <?php endif; ?>
        </p>
    </div>
    <div class="eap-page-header__actions">
        <a href="<?= esc($admin_base_path) ?>/logger" class="eap-btn eap-btn--ghost">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="19" y1="12" x2="5" y2="12"/>
                <polyline points="12 19 5 12 12 5"/>
            </svg>
            Back to Logs
        </a>
    </div>
</div>

<!-- DB Recording Level Configuration -->
<div class="eap-card eap-logger-db-level-card">
    <div class="eap-card__body">
        <div class="eap-logger-db-level-config">
            <div class="eap-logger-db-level-config__info">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <ellipse cx="12" cy="5" rx="9" ry="3"/>
                    <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/>
                    <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
                </svg>
                <div>
                    <strong>Database Recording Level</strong>
                    <p>Minimum level for security events to be recorded in the database. Events below this level are only written to log files.</p>
                </div>
            </div>
            <div class="eap-logger-db-level-config__control">
                <select id="security-db-level" class="eap-form-select" data-channel="security" data-original="<?= esc($db_min_level ?? 'warning') ?>">
                    <?php foreach ($levels as $level): ?>
                    <option value="<?= $level ?>" <?= ($db_min_level ?? 'warning') === $level ? 'selected' : '' ?>>
                        <?= ucfirst($level) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="save-db-level-btn" class="eap-btn eap-btn--primary eap-btn--sm hidden">
                    Save
                </button>
                <span id="db-level-status" class="eap-logger-db-level-status"></span>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="eap-card eap-logger-filters-card">
    <div class="eap-card__body">
        <form method="GET" action="<?= esc($admin_base_path) ?>/logger/security" class="eap-logger-filters">
            <div class="eap-logger-filters__row">
                <!-- Level Filter -->
                <div class="eap-logger-filters__group">
                    <label class="eap-form-label">Min Level</label>
                    <select name="level" class="eap-form-select">
                        <option value="">All Levels</option>
                        <?php foreach ($levels as $level): ?>
                        <option value="<?= $level ?>" <?= $level_filter === $level ? 'selected' : '' ?>>
                            <?= ucfirst($level) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Date Range -->
                <div class="eap-logger-filters__group">
                    <label class="eap-form-label">From</label>
                    <input type="date" name="from" class="eap-form-input" value="<?= esc($date_from) ?>">
                </div>
                <div class="eap-logger-filters__group">
                    <label class="eap-form-label">To</label>
                    <input type="date" name="to" class="eap-form-input" value="<?= esc($date_to) ?>">
                </div>

                <!-- Search -->
                <div class="eap-logger-filters__group eap-logger-filters__group--search">
                    <label class="eap-form-label">Search</label>
                    <input type="text" name="search" class="eap-form-input" placeholder="Search messages..." value="<?= esc($search) ?>">
                </div>

                <!-- Submit -->
                <div class="eap-logger-filters__group eap-logger-filters__group--actions">
                    <button type="submit" class="eap-btn eap-btn--primary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"/>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                        Filter
                    </button>
                    <?php if ($level_filter !== '' || $search !== '' || $date_from !== '' || $date_to !== ''): ?>
                    <a href="<?= esc($admin_base_path) ?>/logger/security" class="eap-btn eap-btn--ghost">Clear</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if (empty($logs)): ?>
<!-- Empty State -->
<div class="eap-card">
    <div class="eap-card__body">
        <div class="eap-logger-empty-state">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
            <p>No security logs found</p>
            <p class="eap-logger-empty-state__hint">
                <?php if ($level_filter !== '' || $search !== '' || $date_from !== '' || $date_to !== ''): ?>
                Try adjusting your filters
                <?php else: ?>
                Security events will appear here when logged via Logger::channel('security')
                <?php endif; ?>
            </p>
        </div>
    </div>
</div>
<?php else: ?>
<!-- Log Entries Table -->
<div class="eap-card">
    <div class="eap-card__body eap-card__body--flush">
        <div class="eap-table-responsive">
            <table class="eap-table eap-logger-security-table">
                <thead>
                    <tr>
                        <th style="width: 150px;">Timestamp</th>
                        <th style="width: 80px;">Level</th>
                        <th style="width: 120px;">IP Address</th>
                        <th style="width: 150px;">User</th>
                        <th>Message</th>
                        <th style="width: 50px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr class="eap-logger-security-row" data-log-id="<?= $log['id'] ?>">
                        <td class="eap-logger-security-timestamp">
                            <code><?= esc($log['created_at_formatted']) ?></code>
                        </td>
                        <td>
                            <span class="eap-badge <?= $getLevelBadgeClass($log['level']) ?>">
                                <?= esc(strtoupper($log['level'])) ?>
                            </span>
                        </td>
                        <td class="eap-logger-security-ip">
                            <?php if (!empty($log['ip_address'])): ?>
                            <code title="<?= esc($log['ip_address']) ?>"><?= esc($log['ip_address']) ?></code>
                            <?php else: ?>
                            <span class="eap-text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="eap-logger-security-user">
                            <?php if (!empty($log['user_email'])): ?>
                            <span title="ID: <?= esc($log['user_id'] ?? '-') ?>">
                                <?= esc(mb_substr($log['user_email'], 0, 25)) ?>
                                <?php if (mb_strlen($log['user_email'] ?? '') > 25): ?>...<?php endif; ?>
                            </span>
                            <?php elseif (!empty($log['user_id'])): ?>
                            <span>ID: <?= esc($log['user_id']) ?></span>
                            <?php else: ?>
                            <span class="eap-text-muted">anonymous</span>
                            <?php endif; ?>
                        </td>
                        <td class="eap-logger-security-message">
                            <?= esc(mb_substr($log['message'], 0, 120)) ?>
                            <?php if (mb_strlen($log['message']) > 120): ?>
                            <span class="eap-text-muted">...</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="button" class="eap-btn eap-btn--ghost eap-btn--xs eap-logger-expand-btn" data-log-id="<?= $log['id'] ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="6 9 12 15 18 9"/>
                                </svg>
                            </button>
                        </td>
                    </tr>
                    <!-- Expanded Details Row (hidden by default) -->
                    <tr class="eap-logger-security-details hidden" data-details-for="<?= $log['id'] ?>">
                        <td colspan="6">
                            <div class="eap-logger-security-details-content">
                                <!-- Attacker Identification -->
                                <div class="eap-logger-detail-section eap-logger-detail-attacker">
                                    <strong>Attacker Identification:</strong>
                                    <div class="eap-logger-attacker-grid">
                                        <div class="eap-logger-attacker-item">
                                            <span class="eap-logger-attacker-label">IP Address:</span>
                                            <code><?= esc($log['ip_address'] ?? '-') ?></code>
                                        </div>
                                        <div class="eap-logger-attacker-item">
                                            <span class="eap-logger-attacker-label">User ID:</span>
                                            <span><?= esc($log['user_id'] ?? '-') ?></span>
                                        </div>
                                        <div class="eap-logger-attacker-item">
                                            <span class="eap-logger-attacker-label">Email:</span>
                                            <span><?= esc($log['user_email'] ?? '-') ?></span>
                                        </div>
                                        <div class="eap-logger-attacker-item">
                                            <span class="eap-logger-attacker-label">Session:</span>
                                            <code><?= esc($log['session_id'] ? substr($log['session_id'], 0, 16) . '...' : '-') ?></code>
                                        </div>
                                        <div class="eap-logger-attacker-item eap-logger-attacker-item--full">
                                            <span class="eap-logger-attacker-label">User Agent:</span>
                                            <code class="eap-logger-ua"><?= esc($log['user_agent'] ?? '-') ?></code>
                                        </div>
                                    </div>
                                </div>

                                <!-- Full Message -->
                                <div class="eap-logger-detail-section">
                                    <strong>Message:</strong>
                                    <pre class="eap-logger-pre"><?= esc($log['message']) ?></pre>
                                </div>

                                <?php if (!empty($log['context'])): ?>
                                <!-- Context -->
                                <div class="eap-logger-detail-section">
                                    <strong>Additional Context:</strong>
                                    <pre class="eap-logger-pre"><?= esc(json_encode($log['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($log['extra'])): ?>
                                <!-- Extra -->
                                <div class="eap-logger-detail-section">
                                    <strong>Extra (Processors):</strong>
                                    <pre class="eap-logger-pre"><?= esc(json_encode($log['extra'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                                </div>
                                <?php endif; ?>

                                <!-- Metadata -->
                                <div class="eap-logger-detail-section eap-logger-detail-meta">
                                    <span><strong>ID:</strong> <?= $log['id'] ?></span>
                                    <span><strong>Channel:</strong> <?= esc($log['channel']) ?></span>
                                    <span><strong>Level Value:</strong> <?= $log['level_value'] ?></span>
                                    <?php if (!empty($log['request_id'])): ?>
                                    <span><strong>Request ID:</strong> <?= esc($log['request_id']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<div class="eap-logger-pagination">
    <div class="eap-logger-pagination__info">
        Showing <?= number_format(($page - 1) * $per_page + 1) ?> - <?= number_format(min($page * $per_page, $total)) ?>
        of <?= number_format($total) ?> entries
    </div>

    <div class="eap-logger-pagination__controls">
        <?php if ($page > 1): ?>
        <a href="<?= $buildUrl(1) ?>" class="eap-btn eap-btn--ghost eap-btn--sm" title="First">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="11 17 6 12 11 7"/>
                <polyline points="18 17 13 12 18 7"/>
            </svg>
        </a>
        <a href="<?= $buildUrl($page - 1) ?>" class="eap-btn eap-btn--ghost eap-btn--sm" title="Previous">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </a>
        <?php endif; ?>

        <span class="eap-logger-pagination__page">
            Page <?= $page ?> of <?= $pages ?>
        </span>

        <?php if ($page < $pages): ?>
        <a href="<?= $buildUrl($page + 1) ?>" class="eap-btn eap-btn--ghost eap-btn--sm" title="Next">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="9 18 15 12 9 6"/>
            </svg>
        </a>
        <a href="<?= $buildUrl($pages) ?>" class="eap-btn eap-btn--ghost eap-btn--sm" title="Last">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="13 17 18 12 13 7"/>
                <polyline points="6 17 11 12 6 7"/>
            </svg>
        </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Hidden data for JavaScript -->
<input type="hidden" id="logger-admin-base-path" value="<?= esc($admin_base_path) ?>">
<input type="hidden" id="logger-csrf-token" value="<?= esc($csrf_token ?? '') ?>">
