<?php
/**
 * Log Channels Configuration
 *
 * CSP Compliant: No inline styles or scripts
 * Requires: /css/logger.css, /js/logger.js
 *
 * @var array $channels Channel configurations
 * @var array $log_levels Available log levels
 * @var string $admin_base_path Admin base path
 * @var string $csrf_input CSRF hidden input
 */
?>

<div class="eap-card">
    <div class="eap-card__header">
        <span class="eap-card__title">Log Channels Configuration</span>
        <button type="button" class="eap-btn eap-btn--primary eap-btn--sm" data-action="add-channel">
            + Add Channel
        </button>
    </div>
    <div class="eap-card__body eap-card__body--flush">
        <?php if (empty($channels)): ?>
            <div class="eap-table__empty">
                <p>No channels configured</p>
                <p class="eap-text--sm eap-text--muted">Add a channel to control log levels</p>
            </div>
        <?php else: ?>
            <table class="eap-table">
                <thead class="eap-table__head">
                    <tr>
                        <th class="eap-table__th">Channel</th>
                        <th class="eap-table__th">Min Level</th>
                        <th class="eap-table__th">Status</th>
                        <th class="eap-table__th">Logs</th>
                        <th class="eap-table__th">Last Log</th>
                        <th class="eap-table__th">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($channels as $channel): ?>
                        <tr class="eap-table__row">
                            <td class="eap-table__td">
                                <code class="eap-channel-name"><?= htmlspecialchars($channel['channel']) ?></code>
                                <?php if ($channel['description']): ?>
                                    <div class="eap-text--sm eap-text--muted"><?= htmlspecialchars($channel['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="eap-table__td">
                                <span class="eap-level-badge eap-level-badge--<?= strtolower($channel['min_level']) ?>">
                                    <?= ucfirst($channel['min_level']) ?>
                                </span>
                            </td>
                            <td class="eap-table__td">
                                <?php if ($channel['enabled']): ?>
                                    <span class="eap-badge eap-badge--success">Active</span>
                                <?php else: ?>
                                    <span class="eap-badge eap-badge--neutral">Disabled</span>
                                <?php endif; ?>
                            </td>
                            <td class="eap-table__td">
                                <span class="eap-text--mono"><?= number_format($channel['log_count'] ?? 0) ?></span>
                            </td>
                            <td class="eap-table__td">
                                <?php if ($channel['last_log']): ?>
                                    <span class="eap-text--sm eap-text--muted">
                                        <?= date('M j, H:i', strtotime($channel['last_log'])) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="eap-text--muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="eap-table__td">
                                <button type="button" class="eap-btn eap-btn--ghost eap-btn--sm"
                                        data-action="edit-channel"
                                        data-channel="<?= htmlspecialchars($channel['channel']) ?>"
                                        data-level="<?= $channel['min_level'] ?>"
                                        data-enabled="<?= $channel['enabled'] ? 'true' : 'false' ?>"
                                        data-description="<?= htmlspecialchars($channel['description'] ?? '') ?>">
                                    Edit
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Level Reference -->
<div class="eap-card">
    <div class="eap-card__header">
        <span class="eap-card__title">Log Level Reference</span>
    </div>
    <div class="eap-card__body">
        <div class="eap-level-reference">
            <?php
            $levelInfo = [
                'debug' => ['value' => 100, 'desc' => 'Detailed debug information'],
                'info' => ['value' => 200, 'desc' => 'Interesting events'],
                'notice' => ['value' => 250, 'desc' => 'Normal but significant events'],
                'warning' => ['value' => 300, 'desc' => 'Exceptional occurrences that are not errors'],
                'error' => ['value' => 400, 'desc' => 'Runtime errors that do not require immediate action'],
                'critical' => ['value' => 500, 'desc' => 'Critical conditions'],
                'alert' => ['value' => 550, 'desc' => 'Action must be taken immediately'],
                'emergency' => ['value' => 600, 'desc' => 'System is unusable'],
            ];
foreach ($levelInfo as $level => $info):
    ?>
                <div class="eap-level-item">
                    <span class="eap-level-badge eap-level-badge--<?= $level ?>"><?= ucfirst($level) ?></span>
                    <span class="eap-text--mono eap-text--sm"><?= $info['value'] ?></span>
                    <span class="eap-text--muted"><?= $info['desc'] ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Add/Edit Channel Modal -->
<dialog id="add-channel-modal" class="eap-modal">
    <form method="POST" action="<?= $admin_base_path ?>/logger/channels/update" class="eap-modal__content">
        <?= $csrf_input ?? '' ?>
        <div class="eap-modal__header">
            <h3 class="eap-modal__title" id="modal-title">Add Channel</h3>
            <button type="button" class="eap-modal__close">&times;</button>
        </div>
        <div class="eap-modal__body">
            <div class="eap-form__group">
                <label class="eap-form__label">Channel Name</label>
                <input type="text" name="channel" id="channel-name" class="eap-form__input" required placeholder="e.g., api, security, database">
            </div>
            <div class="eap-form__group">
                <label class="eap-form__label">Minimum Log Level</label>
                <select name="min_level" id="channel-level" class="eap-form__select">
                    <?php foreach ($log_levels as $lvl): ?>
                        <option value="<?= $lvl ?>"><?= ucfirst($lvl) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="eap-form__hint">Only logs at this level or higher will be recorded</div>
            </div>
            <div class="eap-form__group">
                <label class="eap-form__label">Description</label>
                <input type="text" name="description" id="channel-desc" class="eap-form__input" placeholder="What is this channel for?">
            </div>
            <div class="eap-form__group">
                <label class="eap-form__checkbox">
                    <input type="checkbox" name="enabled" id="channel-enabled" checked>
                    <span>Channel Enabled</span>
                </label>
            </div>
        </div>
        <div class="eap-modal__footer">
            <button type="button" class="eap-btn eap-btn--secondary eap-modal__cancel">Cancel</button>
            <button type="submit" class="eap-btn eap-btn--primary">Save Channel</button>
        </div>
    </form>
</dialog>

<!-- Link external CSS and JS (published to /public/modules/psr3-logger/) -->
<link rel="stylesheet" href="/modules/psr3-logger/css/logger.css">
<script src="/modules/psr3-logger/js/logger.js" defer></script>
