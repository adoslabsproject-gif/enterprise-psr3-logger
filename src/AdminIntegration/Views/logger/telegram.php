<?php
/**
 * Telegram Configuration View
 *
 * Features:
 * - Telegram bot configuration
 * - Level selection (separate from channel levels)
 * - Channel selection for notifications
 * - Test connection button
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

<div class="eap-page">
    <div class="eap-page__header">
        <h1 class="eap-page__title">Telegram Notifications</h1>
        <p class="eap-page__subtitle">Configure Telegram bot for log notifications</p>
    </div>

    <div class="eap-grid eap-grid--2" style="gap: 24px;">
        <!-- Configuration Form -->
        <div class="eap-card">
            <div class="eap-card__header">
                <h2 class="eap-card__title">Bot Configuration</h2>
                <span class="eap-badge <?= $config['enabled'] ? 'eap-badge--success' : 'eap-badge--secondary' ?>">
                    <?= $config['enabled'] ? 'Enabled' : 'Disabled' ?>
                </span>
            </div>
            <div class="eap-card__body">
                <form id="telegram-form" method="POST" action="<?= htmlspecialchars($admin_base_path) ?>/logger/telegram/update">
                    <?= $csrf_input ?>

                    <div class="eap-form-group">
                        <label class="eap-toggle eap-toggle--lg">
                            <input type="checkbox" name="enabled" value="1" <?= $config['enabled'] ? 'checked' : '' ?>>
                            <span class="eap-toggle__slider"></span>
                            <span class="eap-toggle__label">Enable Telegram Notifications</span>
                        </label>
                        <p class="eap-form-help">When enabled, logs matching the configured level will be sent to Telegram</p>
                    </div>

                    <div class="eap-form-group">
                        <label class="eap-label">Bot Token <span class="eap-required">*</span></label>
                        <input type="password" name="bot_token" id="bot_token" class="eap-input"
                               value="<?= htmlspecialchars($config['bot_token'] ?? '') ?>"
                               placeholder="123456789:ABCdefGhIJKlmNoPQRstuVWXyz">
                        <p class="eap-form-help">Get this from <a href="https://t.me/BotFather" target="_blank">@BotFather</a></p>
                    </div>

                    <div class="eap-form-group">
                        <label class="eap-label">Chat ID <span class="eap-required">*</span></label>
                        <input type="text" name="chat_id" id="chat_id" class="eap-input"
                               value="<?= htmlspecialchars($config['chat_id'] ?? '') ?>"
                               placeholder="-1001234567890">
                        <p class="eap-form-help">Channel, group or personal chat ID. Use <a href="https://t.me/userinfobot" target="_blank">@userinfobot</a> to find it.</p>
                    </div>

                    <div class="eap-form-group">
                        <label class="eap-label">Minimum Level for Telegram</label>
                        <select name="level" class="eap-select">
                            <?php foreach ($levels as $level): ?>
                            <option value="<?= $level ?>" <?= ($config['level'] ?? 'error') === $level ? 'selected' : '' ?>>
                                <?= ucfirst($level) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="eap-form-help">
                            <strong>This level is SEPARATE from channel levels.</strong><br>
                            Only logs at this level or higher will be sent to Telegram, regardless of channel settings.
                        </p>
                    </div>

                    <div class="eap-form-group">
                        <label class="eap-label">Notify for Channels</label>
                        <div class="eap-checkbox-group">
                            <label class="eap-checkbox-label">
                                <input type="checkbox" name="channels[]" value="*"
                                       <?= in_array('*', $config['channels'] ?? ['*']) ? 'checked' : '' ?>
                                       onchange="toggleAllChannels(this)">
                                <span>All Channels</span>
                            </label>
                            <?php foreach ($channels as $key => $channel): ?>
                            <label class="eap-checkbox-label channel-option" style="<?= in_array('*', $config['channels'] ?? ['*']) ? 'opacity: 0.5;' : '' ?>">
                                <input type="checkbox" name="channels[]" value="<?= htmlspecialchars($key) ?>"
                                       <?= in_array($key, $config['channels'] ?? []) ? 'checked' : '' ?>
                                       <?= in_array('*', $config['channels'] ?? ['*']) ? 'disabled' : '' ?>>
                                <span><?= htmlspecialchars($channel['name']) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="eap-button-group">
                        <button type="submit" class="eap-btn eap-btn--primary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            Save Configuration
                        </button>
                        <button type="button" class="eap-btn eap-btn--secondary" id="test-telegram">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                            Test Connection
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Help & Info -->
        <div>
            <div class="eap-card">
                <div class="eap-card__header">
                    <h2 class="eap-card__title">How it Works</h2>
                </div>
                <div class="eap-card__body">
                    <div class="eap-info-block">
                        <h4>Level Hierarchy</h4>
                        <p>Telegram notifications use a <strong>separate level</strong> from channel configurations:</p>
                        <ol>
                            <li><strong>Channel Level</strong>: Controls what gets logged to database</li>
                            <li><strong>Telegram Level</strong>: Controls what gets sent to Telegram</li>
                        </ol>
                        <p>Example: If a channel is set to "warning" but Telegram is set to "error", only error+ logs will be sent to Telegram.</p>
                    </div>

                    <div class="eap-info-block" style="margin-top: 16px;">
                        <h4>Log Levels (lowest to highest)</h4>
                        <div class="eap-level-list">
                            <div class="eap-level-item">
                                <span class="eap-badge eap-badge--secondary">DEBUG</span>
                                <span>Detailed debug information</span>
                            </div>
                            <div class="eap-level-item">
                                <span class="eap-badge eap-badge--info">INFO</span>
                                <span>Interesting events</span>
                            </div>
                            <div class="eap-level-item">
                                <span class="eap-badge eap-badge--info">NOTICE</span>
                                <span>Normal but significant events</span>
                            </div>
                            <div class="eap-level-item">
                                <span class="eap-badge eap-badge--warning">WARNING</span>
                                <span>Exceptional occurrences</span>
                            </div>
                            <div class="eap-level-item">
                                <span class="eap-badge eap-badge--danger">ERROR</span>
                                <span>Runtime errors</span>
                            </div>
                            <div class="eap-level-item">
                                <span class="eap-badge eap-badge--danger">CRITICAL</span>
                                <span>Critical conditions</span>
                            </div>
                            <div class="eap-level-item">
                                <span class="eap-badge eap-badge--danger">ALERT</span>
                                <span>Action must be taken immediately</span>
                            </div>
                            <div class="eap-level-item">
                                <span class="eap-badge eap-badge--danger">EMERGENCY</span>
                                <span>System is unusable</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="eap-card" style="margin-top: 16px;">
                <div class="eap-card__header">
                    <h2 class="eap-card__title">Setup Instructions</h2>
                </div>
                <div class="eap-card__body">
                    <div class="eap-steps">
                        <div class="eap-step">
                            <div class="eap-step__number">1</div>
                            <div class="eap-step__content">
                                <strong>Create a Bot</strong>
                                <p>Message <a href="https://t.me/BotFather" target="_blank">@BotFather</a> and send <code>/newbot</code></p>
                            </div>
                        </div>
                        <div class="eap-step">
                            <div class="eap-step__number">2</div>
                            <div class="eap-step__content">
                                <strong>Get Bot Token</strong>
                                <p>BotFather will give you a token like <code>123456:ABC...</code></p>
                            </div>
                        </div>
                        <div class="eap-step">
                            <div class="eap-step__number">3</div>
                            <div class="eap-step__content">
                                <strong>Get Chat ID</strong>
                                <p>Add the bot to a group/channel, or message <a href="https://t.me/userinfobot" target="_blank">@userinfobot</a></p>
                            </div>
                        </div>
                        <div class="eap-step">
                            <div class="eap-step__number">4</div>
                            <div class="eap-step__content">
                                <strong>Test Connection</strong>
                                <p>Enter token and chat ID, then click "Test Connection"</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.eap-required {
    color: #ef4444;
}
.eap-checkbox-group {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}
.eap-checkbox-label {
    display: flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    font-size: 13px;
}
.eap-checkbox-label input {
    width: 16px;
    height: 16px;
}
.eap-info-block {
    padding: 16px;
    background: var(--eap-bg-secondary);
    border-radius: 8px;
}
.eap-info-block h4 {
    margin: 0 0 8px 0;
    font-size: 14px;
}
.eap-info-block p {
    margin: 0 0 8px 0;
    font-size: 13px;
    color: var(--eap-text-muted);
}
.eap-info-block ol {
    margin: 8px 0;
    padding-left: 20px;
}
.eap-info-block li {
    font-size: 13px;
    margin-bottom: 4px;
}
.eap-level-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 12px;
}
.eap-level-item {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 12px;
}
.eap-level-item .eap-badge {
    width: 80px;
    text-align: center;
}
.eap-steps {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.eap-step {
    display: flex;
    gap: 12px;
}
.eap-step__number {
    width: 28px;
    height: 28px;
    background: var(--eap-primary);
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
}
.eap-step__content strong {
    display: block;
    font-size: 14px;
    margin-bottom: 4px;
}
.eap-step__content p {
    margin: 0;
    font-size: 13px;
    color: var(--eap-text-muted);
}
.eap-step__content code {
    background: var(--eap-bg-secondary);
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 12px;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var adminBasePath = <?= json_encode($admin_base_path) ?>;
    var csrfToken = <?= json_encode($csrf_token ?? '') ?>;

    // Toggle all channels
    window.toggleAllChannels = function(checkbox) {
        var channelOptions = document.querySelectorAll('.channel-option');
        channelOptions.forEach(function(label) {
            var input = label.querySelector('input');
            input.disabled = checkbox.checked;
            label.style.opacity = checkbox.checked ? '0.5' : '1';
            if (checkbox.checked) {
                input.checked = false;
            }
        });
    };

    // Test Telegram button
    document.getElementById('test-telegram').addEventListener('click', function() {
        var btn = this;
        var botToken = document.getElementById('bot_token').value;
        var chatId = document.getElementById('chat_id').value;

        if (!botToken || !chatId) {
            showToast('Please enter Bot Token and Chat ID first', 'error');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<svg class="spin" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/></svg> Testing...';

        var formData = new FormData();
        formData.append('_csrf_token', csrfToken);
        formData.append('bot_token', botToken);
        formData.append('chat_id', chatId);

        fetch(adminBasePath + '/logger/telegram/test', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showToast('Test message sent successfully! Check your Telegram.', 'success');
            } else {
                showToast('Error: ' + (data.message || 'Failed to send test message'), 'error');
            }
        })
        .catch(function(err) {
            showToast('Error: ' + err.message, 'error');
        })
        .finally(function() {
            btn.disabled = false;
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Test Connection';
        });
    });

    function showToast(message, type) {
        var toast = document.createElement('div');
        toast.className = 'eap-toast eap-toast--' + type;
        toast.textContent = message;
        toast.style.cssText = 'position: fixed; bottom: 20px; right: 20px; padding: 12px 20px; border-radius: 8px; color: white; z-index: 9999;';
        toast.style.background = type === 'success' ? '#22c55e' : type === 'error' ? '#ef4444' : '#3b82f6';
        document.body.appendChild(toast);
        setTimeout(function() {
            toast.remove();
        }, 4000);
    }
});
</script>

<style>
.spin {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>
