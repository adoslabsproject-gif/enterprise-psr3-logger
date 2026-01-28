<?php
/**
 * Telegram Configuration View
 *
 * CSP-compliant version - no inline styles or scripts
 *
 * @var array $config Telegram configuration
 * @var array $channels Available channels
 * @var array $levels Available log levels
 * @var string $page_title Page title
 * @var string $admin_base_path Admin base path
 * @var string $csrf_input CSRF hidden input
 * @var string $csrf_token CSRF token value
 */
?>

<!-- Page Header -->
<div class="eap-page-header">
    <a href="<?= esc($admin_base_path) ?>/logger" class="eap-btn eap-btn--ghost eap-btn--sm eap-logger-back-link">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        Back to Logger
    </a>
    <h1 class="eap-page-title">Telegram Notifications</h1>
    <p class="eap-page-subtitle">Receive log alerts directly in Telegram</p>
</div>

<div class="eap-logger-telegram-page">
    <div class="eap-logger-telegram-grid">
        <!-- Configuration Form -->
        <div>
            <div class="eap-card">
                <div class="eap-card__header">
                    <span class="eap-card__title">Bot Configuration</span>
                </div>
                <div class="eap-card__body">
                    <form method="POST" action="<?= esc($admin_base_path) ?>/logger/telegram/update" id="telegram-form">
                        <?= $csrf_input ?>

                        <!-- Enable Toggle -->
                        <div class="eap-logger-enable-toggle">
                            <label class="eap-logger-enable-toggle__switch">
                                <input type="checkbox" name="enabled" id="telegram-enabled" value="1" class="eap-logger-enable-toggle__input" <?= ($config['enabled'] ?? false) ? 'checked' : '' ?>>
                                <span class="eap-logger-enable-toggle__slider"></span>
                            </label>
                            <div class="eap-logger-enable-toggle__text">
                                <h4 class="eap-logger-enable-toggle__title">Enable Telegram Notifications</h4>
                                <p class="eap-logger-enable-toggle__desc">Send log alerts to your Telegram chat</p>
                            </div>
                        </div>

                        <div id="telegram-settings" class="<?= ($config['enabled'] ?? false) ? '' : 'hidden' ?>">
                            <div class="eap-logger-form-section">
                                <h3 class="eap-logger-form-section__title">Connection</h3>

                                <div class="eap-logger-form-group">
                                    <label class="eap-logger-form-label" for="bot-token">Bot Token *</label>
                                    <div class="eap-input-password-wrapper">
                                        <input type="password" name="bot_token" id="bot-token" class="eap-logger-form-input"
                                               value="<?= esc($config['bot_token'] ?? '') ?>"
                                               placeholder="123456789:ABCdefGHIjklMNOpqrsTUVwxyz">
                                        <button type="button" class="eap-input-password-toggle" data-target="bot-token" aria-label="Toggle password visibility">
                                            <svg class="eap-icon-eye" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            <svg class="eap-icon-eye-off hidden" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                        </button>
                                    </div>
                                    <p class="eap-logger-form-hint">Get this from <a href="https://t.me/BotFather" target="_blank">@BotFather</a></p>
                                </div>

                                <div class="eap-logger-form-group">
                                    <label class="eap-logger-form-label" for="chat-id">Chat ID *</label>
                                    <input type="text" name="chat_id" id="chat-id" class="eap-logger-form-input"
                                           value="<?= esc($config['chat_id'] ?? '') ?>"
                                           placeholder="-1001234567890">
                                    <p class="eap-logger-form-hint">Use <a href="https://t.me/userinfobot" target="_blank">@userinfobot</a> to find your ID</p>
                                </div>
                            </div>

                            <div class="eap-logger-form-section">
                                <h3 class="eap-logger-form-section__title">Notification Settings</h3>

                                <div class="eap-logger-form-group">
                                    <label class="eap-logger-form-label" for="min-level">Minimum Level</label>
                                    <select name="level" id="min-level" class="eap-logger-form-select">
                                        <?php foreach ($levels as $level): ?>
                                        <option value="<?= $level ?>" <?= ($config['level'] ?? 'error') === $level ? 'selected' : '' ?>>
                                            <?= ucfirst($level) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="eap-logger-form-hint">Only logs at this level or higher will be sent. Recommended: <strong>error</strong></p>
                                </div>

                                <div class="eap-logger-form-group">
                                    <label class="eap-logger-form-label">Channels to Notify</label>

                                    <label class="eap-logger-channel-checkbox">
                                        <input type="checkbox" name="channels[]" value="*" id="notify-all" class="eap-logger-channel-checkbox__input"
                                               <?= in_array('*', $config['channels'] ?? ['*']) ? 'checked' : '' ?>>
                                        <span><strong>All channels</strong></span>
                                    </label>

                                    <div class="eap-logger-channel-checkboxes" id="channel-list">
                                        <?php foreach ($channels as $key => $ch): ?>
                                        <label class="eap-logger-channel-checkbox <?= in_array('*', $config['channels'] ?? ['*']) ? 'eap-logger-channel-checkbox--disabled' : '' ?>">
                                            <input type="checkbox" name="channels[]" value="<?= esc($key) ?>"
                                                   class="eap-logger-channel-checkbox__input"
                                                   <?= in_array($key, $config['channels'] ?? []) ? 'checked' : '' ?>
                                                   <?= in_array('*', $config['channels'] ?? ['*']) ? 'disabled' : '' ?>>
                                            <span><?= esc($ch['name'] ?? $key) ?></span>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <div id="test-result" class="eap-logger-test-result"></div>
                        </div>

                        <div class="eap-button-group">
                            <button type="submit" class="eap-btn eap-btn--primary">Save Configuration</button>
                            <button type="button" class="eap-btn eap-btn--secondary" id="test-btn">Test Connection</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Help Section -->
        <div>
            <div class="eap-logger-info-card">
                <h3 class="eap-logger-info-card__title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                    How Levels Work
                </h3>
                <div class="eap-logger-info-card__content">
                    <p><strong>Telegram has a separate level from channels.</strong></p>
                    <p>Example: Channel "api" logs everything from <strong>warning</strong> and up. But Telegram set to <strong>error</strong> will only notify you for errors and above.</p>
                    <p>This prevents spam while still keeping detailed logs in the database.</p>
                </div>
            </div>

            <div class="eap-card">
                <div class="eap-card__header">
                    <span class="eap-card__title">Setup Instructions</span>
                </div>
                <div class="eap-card__body">
                    <div class="eap-logger-steps">
                        <div class="eap-logger-step">
                            <div class="eap-logger-step__number">1</div>
                            <div class="eap-logger-step__content">
                                <h4 class="eap-logger-step__title">Create a Bot</h4>
                                <p class="eap-logger-step__desc">Open Telegram, search for <a href="https://t.me/BotFather" target="_blank">@BotFather</a> and send <code>/newbot</code></p>
                            </div>
                        </div>

                        <div class="eap-logger-step">
                            <div class="eap-logger-step__number">2</div>
                            <div class="eap-logger-step__content">
                                <h4 class="eap-logger-step__title">Copy Bot Token</h4>
                                <p class="eap-logger-step__desc">BotFather will give you a token like <code>123456:ABC...</code></p>
                            </div>
                        </div>

                        <div class="eap-logger-step">
                            <div class="eap-logger-step__number">3</div>
                            <div class="eap-logger-step__content">
                                <h4 class="eap-logger-step__title">Get Chat ID</h4>
                                <p class="eap-logger-step__desc">Message <a href="https://t.me/userinfobot" target="_blank">@userinfobot</a> to get your numeric ID</p>
                            </div>
                        </div>

                        <div class="eap-logger-step">
                            <div class="eap-logger-step__number">4</div>
                            <div class="eap-logger-step__content">
                                <h4 class="eap-logger-step__title">Start the Bot</h4>
                                <p class="eap-logger-step__desc">Find your bot and click <strong>Start</strong> before it can message you</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Hidden data for JavaScript -->
<input type="hidden" id="logger-admin-base-path" value="<?= esc($admin_base_path) ?>">
<input type="hidden" id="logger-csrf-token" value="<?= esc($csrf_token ?? '') ?>">
