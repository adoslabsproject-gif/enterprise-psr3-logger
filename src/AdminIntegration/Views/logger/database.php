<?php
/**
 * Database Logs View
 */
$getLevelColor = function ($level) {
    return match(strtolower($level)) {
        'emergency', 'alert', 'critical', 'error' => 'danger',
        'warning' => 'warning',
        'notice', 'info' => 'info',
        'debug' => 'secondary',
        default => 'secondary'
    };
};
?>

<div class="eap-page">
    <div class="eap-page__header">
        <h1 class="eap-page__title">Database Logs</h1>
        <p class="eap-page__subtitle">Browse and filter application logs stored in database</p>
    </div>

    <!-- Filters -->
    <div class="eap-card">
        <div class="eap-card__body">
            <form method="GET" class="eap-filters" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: end;">
                <div class="eap-form-group" style="margin: 0;">
                    <label class="eap-label">Channel</label>
                    <select name="channel" class="eap-select">
                        <option value="">All Channels</option>
                        <?php foreach ($channels ?? [] as $ch): ?>
                        <option value="<?= htmlspecialchars($ch) ?>" <?= ($filters['channel'] ?? '') === $ch ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ch) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="eap-form-group" style="margin: 0;">
                    <label class="eap-label">Level</label>
                    <select name="level" class="eap-select">
                        <option value="">All Levels</option>
                        <?php foreach ($levels ?? [] as $lvl): ?>
                        <option value="<?= $lvl ?>" <?= ($filters['level'] ?? '') === $lvl ? 'selected' : '' ?>>
                            <?= ucfirst($lvl) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="eap-form-group" style="margin: 0;">
                    <label class="eap-label">Search</label>
                    <input type="text" name="search" class="eap-input" placeholder="Search message..."
                           value="<?= htmlspecialchars($filters['search'] ?? '') ?>">
                </div>

                <button type="submit" class="eap-btn eap-btn--primary">Filter</button>
                <a href="<?= htmlspecialchars($admin_base_path) ?>/logger/database" class="eap-btn eap-btn--secondary">Reset</a>
            </form>
        </div>
    </div>

    <!-- Logs Table -->
    <div class="eap-card">
        <div class="eap-card__header">
            <h2 class="eap-card__title">Logs</h2>
            <span class="eap-badge eap-badge--info"><?= number_format($total ?? 0) ?> total</span>
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
                            <th>Context</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs ?? [] as $log): ?>
                        <tr>
                            <td style="white-space: nowrap; font-size: 0.85em;">
                                <?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?>
                            </td>
                            <td><span class="eap-badge eap-badge--outline"><?= htmlspecialchars($log['channel']) ?></span></td>
                            <td>
                                <span class="eap-badge eap-badge--<?= $getLevelColor($log['level']) ?>">
                                    <?= strtoupper($log['level']) ?>
                                </span>
                            </td>
                            <td style="max-width: 400px; overflow: hidden; text-overflow: ellipsis;">
                                <?= htmlspecialchars($log['message']) ?>
                            </td>
                            <td>
                                <?php if (!empty($log['context'])): ?>
                                <button class="eap-btn eap-btn--xs eap-btn--secondary" onclick="alert(JSON.stringify(<?= htmlspecialchars(json_encode($log['context'])) ?>, null, 2))">View</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 24px;">No logs found</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if (($pages ?? 1) > 1): ?>
        <div class="eap-card__footer" style="display: flex; justify-content: center; gap: 8px; padding: 16px;">
            <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>&<?= http_build_query($filters) ?>" class="eap-btn eap-btn--sm eap-btn--secondary">Previous</a>
            <?php endif; ?>

            <span style="padding: 8px 12px;">Page <?= $page ?> of <?= $pages ?></span>

            <?php if ($page < $pages): ?>
            <a href="?page=<?= $page + 1 ?>&<?= http_build_query($filters) ?>" class="eap-btn eap-btn--sm eap-btn--secondary">Next</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Clear Logs -->
    <div class="eap-card">
        <div class="eap-card__header">
            <h2 class="eap-card__title">Clear Old Logs</h2>
        </div>
        <div class="eap-card__body">
            <form method="POST" action="<?= htmlspecialchars($admin_base_path) ?>/logger/database/clear" 
                  onsubmit="return confirm('Are you sure you want to delete old logs?')">
                <?= $csrf_input ?>
                <div style="display: flex; gap: 12px; align-items: center;">
                    <label>Delete logs older than:</label>
                    <select name="older_than" class="eap-select" style="width: auto;">
                        <option value="1 day">1 day</option>
                        <option value="7 days" selected>7 days</option>
                        <option value="30 days">30 days</option>
                        <option value="90 days">90 days</option>
                    </select>
                    <button type="submit" class="eap-btn eap-btn--danger">Clear Logs</button>
                </div>
            </form>
        </div>
    </div>
</div>
