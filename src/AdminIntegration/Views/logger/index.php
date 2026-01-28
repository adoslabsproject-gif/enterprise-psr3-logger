<?php
/**
 * Logger Dashboard View - FILE-BASED LOGGING
 *
 * Features:
 * - Toggle per channel (enabled/disabled)
 * - Level selector with explicit Save button
 * - Auto-reset to WARNING after configurable hours when level < WARNING
 * - Visual indicators for debug mode and time remaining
 *
 * @var array $channels Channel configurations with enabled/level/auto_reset_at
 * @var array $log_files Available log files
 * @var string $today Today's date
 * @var string $logs_path Path to logs directory
 * @var array $levels Available log levels
 * @var string $page_title Page title
 * @var string $admin_base_path Admin base path
 * @var string $csrf_input CSRF hidden input
 * @var string $csrf_token CSRF token value
 * @var int $auto_reset_hours Hours before auto-reset to WARNING (default: 2)
 */
$autoResetHours = $auto_reset_hours ?? 8;

$icons = [
    'box' => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
    'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    'globe' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
    'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
    'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'mail' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
    'layers' => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
    'zap' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
    'alert-triangle' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    'send' => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
    'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'check' => '<polyline points="20 6 9 17 4 12"/>',
];

$getIcon = fn ($icon) => $icons[$icon] ?? $icons['box'];

// Levels that trigger auto-reset (below WARNING)
$debugLevels = ['debug', 'info', 'notice'];
?>

<!-- Page Header -->
<div class="eap-page-header">
    <div class="eap-page-header__content">
        <h1 class="eap-page-title">Logging Dashboard</h1>
        <p class="eap-page-subtitle">Configure channels and browse log files</p>
    </div>
    <div class="eap-page-header__actions">
        <a href="<?= htmlspecialchars($admin_base_path) ?>/logger/telegram" class="eap-btn eap-btn--secondary">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <?= $getIcon('send') ?>
            </svg>
            Telegram Config
        </a>
    </div>
</div>

<!-- Auto-Reset Info Banner -->
<div class="eap-logger-info-banner">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <?= $getIcon('clock') ?>
    </svg>
    <span>
        Levels below <strong>WARNING</strong> (debug, info, notice) automatically reset to WARNING after
        <strong><?= $autoResetHours ?> hour<?= $autoResetHours > 1 ? 's' : '' ?></strong> to prevent forgotten debug logging.
    </span>
</div>

<!-- Channel Cards Grid -->
<div class="eap-logger-channel-grid">
    <?php foreach ($channels as $key => $channel):
        $channelLevel = strtolower($channel['level'] ?? 'warning');
        $isDebugLevel = in_array($channelLevel, $debugLevels);
        $autoResetAt = $channel['auto_reset_at'] ?? null;
        $hasAutoReset = $isDebugLevel && $autoResetAt;
        $timeRemaining = $hasAutoReset ? max(0, strtotime($autoResetAt) - time()) : 0;
        $hoursRemaining = floor($timeRemaining / 3600);
        $minutesRemaining = floor(($timeRemaining % 3600) / 60);
        ?>
    <div class="eap-logger-channel-card eap-logger-channel-card--<?= htmlspecialchars($channel['color'] ?? 'blue') ?> <?= !$channel['enabled'] ? 'eap-logger-channel-card--disabled' : '' ?> <?= $isDebugLevel ? 'eap-logger-channel-card--debug-mode' : '' ?>"
         data-channel="<?= htmlspecialchars($key) ?>"
         data-original-level="<?= htmlspecialchars($channel['level']) ?>"
         data-auto-reset-at="<?= htmlspecialchars($autoResetAt ?? '') ?>">

        <div class="eap-logger-channel-card__accent"></div>

        <!-- Header with Toggle -->
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

            <!-- Channel Toggle (Enable/Disable) -->
            <label class="eap-logger-switch" title="<?= $channel['enabled'] ? 'Channel enabled' : 'Channel disabled' ?>">
                <input type="checkbox"
                       class="eap-logger-switch__input channel-toggle"
                       data-channel="<?= htmlspecialchars($key) ?>"
                       <?= $channel['enabled'] ? 'checked' : '' ?>>
                <span class="eap-logger-switch__slider"></span>
            </label>
        </div>

        <!-- Description -->
        <p class="eap-logger-channel-card__desc"><?= htmlspecialchars($channel['description']) ?></p>

        <!-- Auto-Reset Timer (shown when level < WARNING) -->
        <?php if ($hasAutoReset && $timeRemaining > 0): ?>
        <div class="eap-logger-channel-card__auto-reset" data-reset-timestamp="<?= strtotime($autoResetAt) ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <?= $getIcon('clock') ?>
            </svg>
            <span class="auto-reset-text">
                Auto-reset to WARNING in <strong class="auto-reset-time"><?= $hoursRemaining ?>h <?= $minutesRemaining ?>m</strong>
            </span>
        </div>
        <?php endif; ?>

        <!-- Level Selector -->
        <?php
        // Get allowed levels for this channel (null = all levels allowed)
        $allowedLevels = $channel['allowed_levels'] ?? null;
        $channelLevels = $allowedLevels !== null ? $allowedLevels : $levels;
        ?>
        <div class="eap-logger-channel-card__controls">
            <div class="eap-logger-channel-card__level-row">
                <label class="eap-logger-channel-card__level-label">Min Level:</label>
                <select class="eap-logger-channel-card__level-select channel-level"
                        data-channel="<?= htmlspecialchars($key) ?>"
                        data-original="<?= htmlspecialchars($channel['level']) ?>">
                    <?php foreach ($channelLevels as $level): ?>
                    <option value="<?= $level ?>" <?= $channel['level'] === $level ? 'selected' : '' ?>>
                        <?= ucfirst($level) ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <!-- Save Button (hidden by default, shown when level changes) -->
                <button type="button"
                        class="eap-btn eap-btn--primary eap-btn--sm channel-save-btn hidden"
                        data-channel="<?= htmlspecialchars($key) ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <?= $getIcon('check') ?>
                    </svg>
                    Save
                </button>
            </div>
        </div>

        <!-- Auto-Reset Toggle (shown for debug levels < WARNING, hidden otherwise) -->
        <?php
            $autoResetEnabled = $channel['auto_reset_enabled'] ?? true; // Default ON
        ?>
        <div class="eap-logger-channel-card__auto-reset-toggle <?= !$isDebugLevel ? 'hidden' : '' ?>">
            <label class="eap-logger-auto-reset-switch">
                <input type="checkbox"
                       class="eap-logger-auto-reset-switch__input auto-reset-toggle"
                       data-channel="<?= htmlspecialchars($key) ?>"
                       <?= $autoResetEnabled ? 'checked' : '' ?>>
                <span class="eap-logger-auto-reset-switch__slider"></span>
                <span class="eap-logger-auto-reset-switch__label">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <?= $getIcon('clock') ?>
                    </svg>
                    Auto-reset to WARNING
                </span>
            </label>
            <span class="eap-logger-channel-card__auto-reset-hint">
                <?= $autoResetEnabled ? "After {$autoResetHours}h if level < WARNING" : 'Disabled - level stays until manually changed' ?>
            </span>
        </div>

        <!-- Auto-Reset Countdown (shown when level < WARNING and auto-reset is enabled) -->
        <div class="eap-logger-channel-card__auto-reset-countdown <?= !($hasAutoReset && $timeRemaining > 0 && $autoResetEnabled) ? 'hidden' : '' ?>" data-reset-timestamp="<?= $hasAutoReset ? strtotime($autoResetAt) : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <?= $getIcon('alert-triangle') ?>
            </svg>
            <span>Resets to WARNING in <strong class="auto-reset-time"><?= $hasAutoReset ? "{$hoursRemaining}h {$minutesRemaining}m" : '' ?></strong></span>
        </div>

        <!-- Level is already WARNING or above - no auto-reset needed -->
        <div class="eap-logger-channel-card__auto-reset-info <?= $isDebugLevel ? 'hidden' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <?= $getIcon('check') ?>
            </svg>
            <span class="auto-reset-info-text">Level is <?= ucfirst($channelLevel) ?> - no auto-reset needed</span>
        </div>

        <!-- Usage Examples -->
        <div class="eap-logger-channel-card__usage">
            <code>Logger::channel('<?= htmlspecialchars($key) ?>')->info('message', $context);</code>
        </div>
        <div class="eap-logger-channel-card__usage eap-logger-channel-card__usage--alt">
            <code>if (should_log('<?= htmlspecialchars($key) ?>', 'info')) { ... }</code>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Log Files Section -->
<div class="eap-card eap-logger-files-section">
    <div class="eap-card__header">
        <h2 class="eap-card__title">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
            </svg>
            Log Files
        </h2>
        <span class="eap-badge eap-badge--secondary"><?= count($log_files) ?> files</span>
    </div>

    <?php if (!empty($log_files)): ?>
    <!-- Bulk Actions Bar -->
    <div class="eap-logger-bulk-actions">
        <div class="eap-logger-bulk-actions__left">
            <label class="eap-checkbox">
                <input type="checkbox" id="select-all-files" class="eap-checkbox__input">
                <span class="eap-checkbox__label">Select All</span>
            </label>
            <span class="eap-logger-bulk-actions__count">
                <span id="selected-count">0</span> selected
            </span>
        </div>
        <div class="eap-logger-bulk-actions__right">
            <button type="button" class="eap-btn eap-btn--secondary eap-btn--sm" id="bulk-download-btn" disabled>
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                Download Selected
            </button>
            <button type="button" class="eap-btn eap-btn--danger eap-btn--sm" id="bulk-clear-btn" disabled>
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                </svg>
                Clear Selected
            </button>
            <button type="button" class="eap-btn eap-btn--danger eap-btn--sm" id="bulk-delete-btn" disabled>
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                    <line x1="10" y1="11" x2="10" y2="17"/>
                    <line x1="14" y1="11" x2="14" y2="17"/>
                </svg>
                Delete Selected
            </button>
        </div>
    </div>
    <?php endif; ?>

    <div class="eap-card__body">
        <?php if (empty($log_files)): ?>
        <div class="eap-logger-empty-state">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
            </svg>
            <p>No log files found</p>
            <p class="eap-logger-empty-state__hint">Log files will appear here when your application starts logging</p>
        </div>
        <?php else: ?>
        <div class="eap-logger-files-grid">
            <?php foreach ($log_files as $file): ?>
            <?php
            $isToday = $file['date'] === $today;
                $channelColor = $file['color'] ?? 'gray';
                ?>
            <div class="eap-logger-file-card eap-logger-file-card--<?= $channelColor ?> <?= $isToday ? 'eap-logger-file-card--today' : '' ?>"
                 data-filename="<?= htmlspecialchars($file['name']) ?>">
                <!-- Checkbox for bulk selection -->
                <label class="eap-logger-file-card__checkbox">
                    <input type="checkbox" class="file-checkbox" data-file="<?= htmlspecialchars($file['name']) ?>">
                </label>
                <a href="<?= htmlspecialchars($admin_base_path) ?>/logger/view?file=<?= urlencode($file['name']) ?>" class="eap-logger-file-card__link">
                    <div class="eap-logger-file-card__header">
                        <div class="eap-logger-file-card__icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <?= $getIcon($channels[$file['channel']]['icon'] ?? 'box') ?>
                            </svg>
                        </div>
                        <div class="eap-logger-file-card__info">
                            <span class="eap-logger-file-card__channel"><?= htmlspecialchars(ucfirst($file['channel'])) ?></span>
                            <?php if ($file['date']): ?>
                            <span class="eap-logger-file-card__date"><?= htmlspecialchars($file['date']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($isToday): ?>
                        <span class="eap-badge eap-badge--success eap-logger-file-card__today-badge">Today</span>
                        <?php endif; ?>
                    </div>

                    <div class="eap-logger-file-card__name">
                        <?= htmlspecialchars($file['name']) ?>
                    </div>

                    <div class="eap-logger-file-card__meta">
                        <span class="eap-logger-file-card__size"><?= htmlspecialchars($file['size_human']) ?></span>
                        <span class="eap-logger-file-card__modified">Modified: <?= htmlspecialchars($file['modified_human']) ?></span>
                    </div>
                </a>

                <div class="eap-logger-file-card__actions">
                    <a href="<?= htmlspecialchars($admin_base_path) ?>/logger/file/download?file=<?= urlencode($file['name']) ?>"
                       class="eap-btn eap-btn--ghost eap-btn--xs" title="Download">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="7 10 12 15 17 10"/>
                            <line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                    </a>
                    <button type="button" class="eap-btn eap-btn--ghost eap-btn--xs clear-file-btn" data-file="<?= htmlspecialchars($file['name']) ?>" title="Clear">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="3 6 5 6 21 6"/>
                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                        </svg>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Logs Path Info -->
<div class="eap-logger-path-info">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
    </svg>
    <span>Logs directory: <code><?= htmlspecialchars($logs_path) ?></code></span>
</div>

<!-- Timezone Info -->
<div class="eap-logger-path-info">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <?= $getIcon('clock') ?>
    </svg>
    <span>
        Server time: <code><?= htmlspecialchars($server_time ?? date('Y-m-d H:i:s')) ?></code>
        &nbsp;|&nbsp;
        Timezone: <code><?= htmlspecialchars($timezone ?? date_default_timezone_get()) ?></code>
    </span>
</div>

<!-- Hidden data for JavaScript -->
<input type="hidden" id="logger-admin-base-path" value="<?= htmlspecialchars($admin_base_path) ?>">
<input type="hidden" id="logger-csrf-token" value="<?= htmlspecialchars($csrf_token ?? '') ?>">
<input type="hidden" id="logger-auto-reset-hours" value="<?= $autoResetHours ?>">
