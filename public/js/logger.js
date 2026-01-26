/**
 * PSR-3 Logger Admin Integration JavaScript
 *
 * CSP-compliant - external script file
 */
(function() {
    'use strict';

    // Get config from hidden inputs (CSP-safe way)
    var adminBasePathEl = document.getElementById('logger-admin-base-path');
    var csrfTokenEl = document.getElementById('logger-csrf-token');

    if (!adminBasePathEl) {
        // Not on logger page
        return;
    }

    var adminBasePath = adminBasePathEl.value;
    var csrfToken = csrfTokenEl ? csrfTokenEl.value : '';

    // ==========================================================================
    // Channel Management
    // ==========================================================================

    function initChannelManagement() {
        // Channel toggle switches
        document.querySelectorAll('.channel-toggle').forEach(function(toggle) {
            toggle.addEventListener('change', function() {
                var channel = this.dataset.channel;
                var card = this.closest('.eap-logger-channel-card');
                var levelSelect = card.querySelector('.channel-level');

                card.classList.toggle('eap-logger-channel-card--disabled', !this.checked);
                saveChannel(channel, this.checked, levelSelect.value);
            });
        });

        // Channel level selects
        document.querySelectorAll('.channel-level').forEach(function(select) {
            select.addEventListener('change', function() {
                var channel = this.dataset.channel;
                var card = this.closest('.eap-logger-channel-card');
                var toggle = card.querySelector('.channel-toggle');

                saveChannel(channel, toggle.checked, this.value);
            });
        });
    }

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
            showToast(
                data.success ? 'Channel updated' : 'Error: ' + (data.message || 'Failed'),
                data.success ? 'success' : 'error'
            );
        })
        .catch(function(err) {
            showToast('Network error: ' + err.message, 'error');
        });
    }

    // ==========================================================================
    // Log Selection & Deletion
    // ==========================================================================

    function initLogManagement() {
        var selectAll = document.getElementById('select-all');
        var deleteBtn = document.getElementById('delete-selected');

        if (!selectAll || !deleteBtn) {
            return;
        }

        // Select all checkbox
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.log-select').forEach(function(cb) {
                cb.checked = selectAll.checked;
            });
            updateDeleteBtn();
        });

        // Individual checkboxes
        document.querySelectorAll('.log-select').forEach(function(cb) {
            cb.addEventListener('change', updateDeleteBtn);
        });

        // Delete selected button
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
                } else {
                    showToast('Error: ' + (data.message || 'Failed'), 'error');
                }
            })
            .catch(function(err) {
                showToast('Network error: ' + err.message, 'error');
            });
        });

        // Single delete buttons
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
                    } else {
                        showToast('Error: ' + (data.message || 'Failed'), 'error');
                    }
                })
                .catch(function(err) {
                    showToast('Network error: ' + err.message, 'error');
                });
            });
        });

        function updateDeleteBtn() {
            var count = document.querySelectorAll('.log-select:checked').length;
            deleteBtn.disabled = count === 0;
            deleteBtn.textContent = count > 0 ? 'Delete Selected (' + count + ')' : 'Delete Selected';
        }
    }

    // ==========================================================================
    // Context Modal
    // ==========================================================================

    function initContextModal() {
        var modal = document.getElementById('context-modal');
        var content = document.getElementById('context-content');

        if (!modal || !content) {
            return;
        }

        document.querySelectorAll('.eap-logger-context-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                content.textContent = this.dataset.context;
                modal.classList.remove('hidden');
            });
        });
    }

    // ==========================================================================
    // Clear Logs Modal
    // ==========================================================================

    function initClearModal() {
        var clearBtn = document.getElementById('clear-old-logs');
        var modal = document.getElementById('clear-modal');

        if (!clearBtn || !modal) {
            return;
        }

        clearBtn.addEventListener('click', function() {
            modal.classList.remove('hidden');
        });
    }

    // ==========================================================================
    // Modal Close Handlers
    // ==========================================================================

    function initModalCloseHandlers() {
        // Close buttons
        document.querySelectorAll('.eap-logger-modal__close, .modal-close').forEach(function(el) {
            el.addEventListener('click', function() {
                var overlay = this.closest('.eap-logger-modal-overlay');
                if (overlay) {
                    overlay.classList.add('hidden');
                }
            });
        });

        // Click on overlay background
        document.querySelectorAll('.eap-logger-modal-overlay').forEach(function(overlay) {
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) {
                    overlay.classList.add('hidden');
                }
            });
        });

        // Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.eap-logger-modal-overlay').forEach(function(m) {
                    m.classList.add('hidden');
                });
            }
        });
    }

    // ==========================================================================
    // Telegram Configuration Page
    // ==========================================================================

    function initTelegramConfig() {
        var enabledCheckbox = document.getElementById('telegram-enabled');
        var settingsDiv = document.getElementById('telegram-settings');
        var notifyAllCheckbox = document.getElementById('notify-all');
        var channelCheckboxes = document.querySelectorAll('.eap-logger-channel-checkbox__input');
        var testBtn = document.getElementById('test-btn');
        var testResult = document.getElementById('test-result');

        if (!enabledCheckbox) {
            return;
        }

        // Toggle settings visibility
        enabledCheckbox.addEventListener('change', function() {
            if (settingsDiv) {
                settingsDiv.style.display = this.checked ? 'block' : 'none';
            }
        });

        // Toggle channel checkboxes
        if (notifyAllCheckbox) {
            notifyAllCheckbox.addEventListener('change', function() {
                var labels = document.querySelectorAll('#channel-list .eap-logger-channel-checkbox');
                labels.forEach(function(label) {
                    label.classList.toggle('eap-logger-channel-checkbox--disabled', notifyAllCheckbox.checked);
                });
                channelCheckboxes.forEach(function(cb) {
                    cb.disabled = notifyAllCheckbox.checked;
                    if (notifyAllCheckbox.checked) cb.checked = false;
                });
            });
        }

        // Test connection
        if (testBtn) {
            testBtn.addEventListener('click', function() {
                var botToken = document.getElementById('bot-token');
                var chatId = document.getElementById('chat-id');

                if (!botToken || !chatId || !botToken.value || !chatId.value) {
                    showTestResult(false, 'Please enter bot token and chat ID first');
                    return;
                }

                testBtn.disabled = true;
                testBtn.textContent = 'Testing...';

                var formData = new FormData();
                formData.append('_csrf_token', csrfToken);
                formData.append('bot_token', botToken.value);
                formData.append('chat_id', chatId.value);

                fetch(adminBasePath + '/logger/telegram/test', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    showTestResult(data.success, data.message || (data.success ? 'Test message sent!' : 'Failed'));
                })
                .catch(function(err) {
                    showTestResult(false, 'Network error: ' + err.message);
                })
                .finally(function() {
                    testBtn.disabled = false;
                    testBtn.textContent = 'Test Connection';
                });
            });
        }

        function showTestResult(success, message) {
            if (!testResult) return;
            testResult.className = 'eap-logger-test-result show ' + (success ? 'eap-logger-test-result--success' : 'eap-logger-test-result--error');
            testResult.textContent = message;
        }
    }

    // ==========================================================================
    // Toast Notifications
    // ==========================================================================

    function showToast(message, type) {
        var toast = document.createElement('div');
        toast.className = 'eap-logger-toast eap-logger-toast--' + type;
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(function() {
            toast.remove();
        }, 3000);
    }

    // ==========================================================================
    // Initialize
    // ==========================================================================

    function init() {
        initChannelManagement();
        initLogManagement();
        initContextModal();
        initClearModal();
        initModalCloseHandlers();
        initTelegramConfig();
    }

    // Run on DOMContentLoaded or immediately if already loaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
