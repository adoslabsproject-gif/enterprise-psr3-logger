<?php
/**
 * Telegram Configuration View
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

<style>
.eap-telegram-page {
    max-width: 900px;
}
.eap-telegram-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
}
@media (max-width: 900px) {
    .eap-telegram-grid { grid-template-columns: 1fr; }
}
.eap-form-section {
    margin-bottom: 24px;
}
.eap-form-section__title {
    font-size: 14px;
    font-weight: 600;
    color: var(--eap-text-primary, #111827);
    margin: 0 0 16px 0;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--eap-border-color, #e5e7eb);
}
.eap-form-group {
    margin-bottom: 16px;
}
.eap-form-label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--eap-text-primary, #111827);
    margin-bottom: 6px;
}
.eap-form-input,
.eap-form-select {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--eap-border-color, #e5e7eb);
    border-radius: 8px;
    font-size: 14px;
    background: var(--eap-input-bg, #fff);
    color: var(--eap-text-primary, #111827);
    transition: border-color 0.2s, box-shadow 0.2s;
}
.eap-form-input:focus,
.eap-form-select:focus {
    outline: none;
    border-color: var(--eap-primary, #3b82f6);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}
.eap-form-hint {
    font-size: 12px;
    color: var(--eap-text-muted, #6b7280);
    margin-top: 6px;
}
.eap-form-hint a {
    color: var(--eap-primary, #3b82f6);
}
/* Toggle switch for Enable */
.eap-enable-toggle {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: var(--eap-bg-secondary, #f9fafb);
    border-radius: 10px;
    margin-bottom: 24px;
}
.eap-enable-toggle__switch {
    position: relative;
    width: 52px;
    height: 28px;
    flex-shrink: 0;
}
.eap-enable-toggle__switch input {
    opacity: 0;
    width: 0;
    height: 0;
}
.eap-enable-toggle__slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #d1d5db;
    transition: 0.3s;
    border-radius: 28px;
}
.eap-enable-toggle__slider:before {
    position: absolute;
    content: "";
    height: 22px;
    width: 22px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: 0.3s;
    border-radius: 50%;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}
.eap-enable-toggle__switch input:checked + .eap-enable-toggle__slider {
    background-color: #22c55e;
}
.eap-enable-toggle__switch input:checked + .eap-enable-toggle__slider:before {
    transform: translateX(24px);
}
.eap-enable-toggle__text {
    flex: 1;
}
.eap-enable-toggle__title {
    font-size: 15px;
    font-weight: 600;
    color: var(--eap-text-primary, #111827);
    margin: 0 0 2px 0;
}
.eap-enable-toggle__desc {
    font-size: 13px;
    color: var(--eap-text-muted, #6b7280);
    margin: 0;
}
/* Channel checkboxes */
.eap-channel-checkboxes {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 8px;
}
.eap-channel-checkbox {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: var(--eap-bg-secondary, #f9fafb);
    border: 1px solid var(--eap-border-color, #e5e7eb);
    border-radius: 6px;
    font-size: 13px;
    cursor: pointer;
    transition: background 0.2s, border-color 0.2s;
}
.eap-channel-checkbox:hover {
    background: var(--eap-bg-hover, #f3f4f6);
}
.eap-channel-checkbox input {
    width: 16px;
    height: 16px;
    accent-color: var(--eap-primary, #3b82f6);
}
.eap-channel-checkbox--disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
/* Info card */
.eap-info-card {
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    border: 1px solid #bfdbfe;
    border-radius: 12px;
    padding: 20px;
}
.eap-info-card__title {
    font-size: 15px;
    font-weight: 600;
    color: #1e40af;
    margin: 0 0 12px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.eap-info-card__content {
    font-size: 13px;
    color: #1e3a8a;
    line-height: 1.6;
}
.eap-info-card__content p {
    margin: 0 0 8px 0;
}
.eap-info-card__content strong {
    color: #1e40af;
}
/* Steps */
.eap-steps {
    counter-reset: step;
}
.eap-step {
    display: flex;
    gap: 16px;
    margin-bottom: 20px;
}
.eap-step:last-child {
    margin-bottom: 0;
}
.eap-step__number {
    width: 32px;
    height: 32px;
    background: var(--eap-primary, #3b82f6);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 600;
    flex-shrink: 0;
}
.eap-step__content {
    flex: 1;
    padding-top: 4px;
}
.eap-step__title {
    font-size: 14px;
    font-weight: 600;
    color: var(--eap-text-primary, #111827);
    margin: 0 0 4px 0;
}
.eap-step__desc {
    font-size: 13px;
    color: var(--eap-text-muted, #6b7280);
    margin: 0;
}
.eap-step__desc code {
    background: var(--eap-bg-secondary, #f1f5f9);
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 12px;
}
.eap-step__desc a {
    color: var(--eap-primary, #3b82f6);
}
/* Test result */
.eap-test-result {
    padding: 12px 16px;
    border-radius: 8px;
    font-size: 14px;
    margin-top: 16px;
    display: none;
}
.eap-test-result--success {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #86efac;
}
.eap-test-result--error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
}
.eap-test-result.show {
    display: block;
}
</style>

<!-- Page Header -->
<div class="eap-page-header">
    <a href="<?= htmlspecialchars($admin_base_path) ?>/logger" class="eap-btn eap-btn--ghost eap-btn--sm" style="margin-bottom: 8px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        Back to Logger
    </a>
    <h1 class="eap-page-title">Telegram Notifications</h1>
    <p class="eap-page-subtitle">Receive log alerts directly in Telegram</p>
</div>

<div class="eap-telegram-page">
    <div class="eap-telegram-grid">
        <!-- Configuration Form -->
        <div>
            <div class="eap-card">
                <div class="eap-card__header">
                    <span class="eap-card__title">Bot Configuration</span>
                </div>
                <div class="eap-card__body">
                    <form method="POST" action="<?= htmlspecialchars($admin_base_path) ?>/logger/telegram/update" id="telegram-form">
                        <?= $csrf_input ?>

                        <!-- Enable Toggle -->
                        <div class="eap-enable-toggle">
                            <label class="eap-enable-toggle__switch">
                                <input type="checkbox" name="enabled" id="telegram-enabled" value="1" <?= ($config['enabled'] ?? false) ? 'checked' : '' ?>>
                                <span class="eap-enable-toggle__slider"></span>
                            </label>
                            <div class="eap-enable-toggle__text">
                                <h4 class="eap-enable-toggle__title">Enable Telegram Notifications</h4>
                                <p class="eap-enable-toggle__desc">Send log alerts to your Telegram chat</p>
                            </div>
                        </div>

                        <div id="telegram-settings" style="<?= ($config['enabled'] ?? false) ? '' : 'display: none;' ?>">
                            <div class="eap-form-section">
                                <h3 class="eap-form-section__title">Connection</h3>

                                <div class="eap-form-group">
                                    <label class="eap-form-label" for="bot-token">Bot Token *</label>
                                    <input type="password" name="bot_token" id="bot-token" class="eap-form-input"
                                           value="<?= htmlspecialchars($config['bot_token'] ?? '') ?>"
                                           placeholder="123456789:ABCdefGHIjklMNOpqrsTUVwxyz">
                                    <p class="eap-form-hint">Get this from <a href="https://t.me/BotFather" target="_blank">@BotFather</a></p>
                                </div>

                                <div class="eap-form-group">
                                    <label class="eap-form-label" for="chat-id">Chat ID *</label>
                                    <input type="text" name="chat_id" id="chat-id" class="eap-form-input"
                                           value="<?= htmlspecialchars($config['chat_id'] ?? '') ?>"
                                           placeholder="-1001234567890">
                                    <p class="eap-form-hint">Use <a href="https://t.me/userinfobot" target="_blank">@userinfobot</a> to find your ID</p>
                                </div>
                            </div>

                            <div class="eap-form-section">
                                <h3 class="eap-form-section__title">Notification Settings</h3>

                                <div class="eap-form-group">
                                    <label class="eap-form-label" for="min-level">Minimum Level</label>
                                    <select name="level" id="min-level" class="eap-form-select">
                                        <?php foreach ($levels as $level): ?>
                                        <option value="<?= $level ?>" <?= ($config['level'] ?? 'error') === $level ? 'selected' : '' ?>>
                                            <?= ucfirst($level) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="eap-form-hint">Only logs at this level or higher will be sent. Recommended: <strong>error</strong></p>
                                </div>

                                <div class="eap-form-group">
                                    <label class="eap-form-label">Channels to Notify</label>

                                    <label class="eap-channel-checkbox" style="margin-bottom: 8px;">
                                        <input type="checkbox" name="channels[]" value="*" id="notify-all"
                                               <?= in_array('*', $config['channels'] ?? ['*']) ? 'checked' : '' ?>>
                                        <span><strong>All channels</strong></span>
                                    </label>

                                    <div class="eap-channel-checkboxes" id="channel-list">
                                        <?php foreach ($channels as $key => $ch): ?>
                                        <label class="eap-channel-checkbox <?= in_array('*', $config['channels'] ?? ['*']) ? 'eap-channel-checkbox--disabled' : '' ?>">
                                            <input type="checkbox" name="channels[]" value="<?= htmlspecialchars($key) ?>"
                                                   class="channel-cb"
                                                   <?= in_array($key, $config['channels'] ?? []) ? 'checked' : '' ?>
                                                   <?= in_array('*', $config['channels'] ?? ['*']) ? 'disabled' : '' ?>>
                                            <span><?= htmlspecialchars($ch['name'] ?? $key) ?></span>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <div id="test-result" class="eap-test-result"></div>
                        </div>

                        <div class="eap-button-group" style="margin-top: 24px;">
                            <button type="submit" class="eap-btn eap-btn--primary">Save Configuration</button>
                            <button type="button" class="eap-btn eap-btn--secondary" id="test-btn">Test Connection</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Help Section -->
        <div>
            <div class="eap-info-card" style="margin-bottom: 20px;">
                <h3 class="eap-info-card__title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                    How Levels Work
                </h3>
                <div class="eap-info-card__content">
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
                    <div class="eap-steps">
                        <div class="eap-step">
                            <div class="eap-step__number">1</div>
                            <div class="eap-step__content">
                                <h4 class="eap-step__title">Create a Bot</h4>
                                <p class="eap-step__desc">Open Telegram, search for <a href="https://t.me/BotFather" target="_blank">@BotFather</a> and send <code>/newbot</code></p>
                            </div>
                        </div>

                        <div class="eap-step">
                            <div class="eap-step__number">2</div>
                            <div class="eap-step__content">
                                <h4 class="eap-step__title">Copy Bot Token</h4>
                                <p class="eap-step__desc">BotFather will give you a token like <code>123456:ABC...</code></p>
                            </div>
                        </div>

                        <div class="eap-step">
                            <div class="eap-step__number">3</div>
                            <div class="eap-step__content">
                                <h4 class="eap-step__title">Get Chat ID</h4>
                                <p class="eap-step__desc">Message <a href="https://t.me/userinfobot" target="_blank">@userinfobot</a> to get your numeric ID</p>
                            </div>
                        </div>

                        <div class="eap-step">
                            <div class="eap-step__number">4</div>
                            <div class="eap-step__content">
                                <h4 class="eap-step__title">Start the Bot</h4>
                                <p class="eap-step__desc">Find your bot and click <strong>Start</strong> before it can message you</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var adminBasePath = <?= json_encode($admin_base_path) ?>;
    var csrfToken = <?= json_encode($csrf_token ?? '') ?>;

    var enabledCheckbox = document.getElementById('telegram-enabled');
    var settingsDiv = document.getElementById('telegram-settings');
    var notifyAllCheckbox = document.getElementById('notify-all');
    var channelCheckboxes = document.querySelectorAll('.channel-cb');
    var testBtn = document.getElementById('test-btn');
    var testResult = document.getElementById('test-result');

    // Toggle settings visibility
    enabledCheckbox.addEventListener('change', function() {
        settingsDiv.style.display = this.checked ? 'block' : 'none';
    });

    // Toggle channel checkboxes
    notifyAllCheckbox.addEventListener('change', function() {
        var labels = document.querySelectorAll('#channel-list .eap-channel-checkbox');
        labels.forEach(function(label) {
            label.classList.toggle('eap-channel-checkbox--disabled', notifyAllCheckbox.checked);
        });
        channelCheckboxes.forEach(function(cb) {
            cb.disabled = notifyAllCheckbox.checked;
            if (notifyAllCheckbox.checked) cb.checked = false;
        });
    });

    // Test connection
    testBtn.addEventListener('click', function() {
        var botToken = document.getElementById('bot-token').value;
        var chatId = document.getElementById('chat-id').value;

        if (!botToken || !chatId) {
            showResult(false, 'Please enter bot token and chat ID first');
            return;
        }

        testBtn.disabled = true;
        testBtn.textContent = 'Testing...';

        var formData = new FormData();
        formData.append('_csrf_token', csrfToken);
        formData.append('bot_token', botToken);
        formData.append('chat_id', chatId);

        fetch(adminBasePath + '/logger/telegram/test', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            showResult(data.success, data.message || (data.success ? 'Test message sent!' : 'Failed'));
        })
        .catch(function(err) {
            showResult(false, 'Network error: ' + err.message);
        })
        .finally(function() {
            testBtn.disabled = false;
            testBtn.textContent = 'Test Connection';
        });
    });

    function showResult(success, message) {
        testResult.className = 'eap-test-result show ' + (success ? 'eap-test-result--success' : 'eap-test-result--error');
        testResult.textContent = message;
    }
})();
</script>
