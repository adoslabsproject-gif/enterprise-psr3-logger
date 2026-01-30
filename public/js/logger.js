/**
 * PSR-3 Logger Admin Integration JavaScript
 *
 * Features:
 * - Toggle for enable/disable channel (instant save)
 * - Level selector with explicit Save button
 * - Auto-reset countdown timer for debug levels
 * - STATELESS CSRF: Token remains valid for 60 minutes
 * - Secure JSON response validation
 *
 * CSP-compliant - external script file
 * @version 2.0.0
 */
(function() {
    'use strict';

    // Get config from hidden inputs (CSP-safe way)
    var adminBasePathEl = document.getElementById('logger-admin-base-path');
    var csrfTokenEl = document.getElementById('logger-csrf-token');
    var autoResetHoursEl = document.getElementById('logger-auto-reset-hours');

    if (!adminBasePathEl) {
        // Not on logger page
        return;
    }

    var adminBasePath = adminBasePathEl.value;
    var csrfToken = csrfTokenEl ? csrfTokenEl.value : '';
    var autoResetHours = autoResetHoursEl ? parseInt(autoResetHoursEl.value, 10) : 8;

    /**
     * Safe JSON response parser with Content-Type validation
     *
     * @param {Response} response - Fetch Response object
     * @returns {Promise<Object>} Parsed JSON or error object
     */
    function parseJsonResponse(response) {
        var contentType = response.headers.get('content-type') || '';

        // Validate Content-Type is JSON
        if (!contentType.includes('application/json')) {
            // Non-JSON response - might be an error page
            return response.text().then(function(text) {
                // Check if response looks like HTML error page
                if (text.includes('<!DOCTYPE') || text.includes('<html')) {
                    throw new Error('Server returned an error page. Please refresh and try again.');
                }
                throw new Error('Invalid response format from server');
            });
        }

        return response.json().catch(function(e) {
            throw new Error('Invalid JSON response: ' + e.message);
        });
    }

    /**
     * Make a secure POST request with CSRF token
     *
     * @param {string} url - Endpoint URL
     * @param {string} body - URL-encoded body
     * @returns {Promise<Object>} Parsed JSON response
     */
    function securePost(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken,
                'Accept': 'application/json'
            },
            body: body
        }).then(parseJsonResponse);
    }

    // Debug levels that trigger auto-reset
    var debugLevels = ['debug', 'info', 'notice'];

    // ==========================================================================
    // Auto-Reset Toggle - Enable/disable automatic reset to WARNING after 8h
    // ==========================================================================

    function initAutoResetToggles() {
        document.querySelectorAll('.auto-reset-toggle').forEach(function(toggle) {
            toggle.addEventListener('change', function() {
                var channel = this.dataset.channel;
                var card = this.closest('.eap-logger-channel-card');
                var channelToggle = card.querySelector('.channel-toggle');
                var levelSelect = card.querySelector('.channel-level');
                var autoResetHint = card.querySelector('.eap-logger-channel-card__auto-reset-hint');
                var autoResetCountdown = card.querySelector('.eap-logger-channel-card__auto-reset-countdown');
                var isEnabled = this.checked;

                // Update hint text
                if (autoResetHint) {
                    autoResetHint.textContent = isEnabled
                        ? 'After ' + autoResetHours + 'h if level < WARNING'
                        : 'Disabled - level stays until manually changed';
                }

                // Save immediately
                saveAutoResetToggle(channel, channelToggle ? channelToggle.checked : true, levelSelect ? levelSelect.value : 'warning', isEnabled, card);
            });
        });
    }

    /**
     * Save auto-reset toggle change (instant)
     */
    function saveAutoResetToggle(channel, enabled, level, autoResetEnabled, card) {
        securePost(adminBasePath + '/logger/channel/update',
            '_csrf_token=' + encodeURIComponent(csrfToken) +
            '&channel=' + encodeURIComponent(channel) +
            '&enabled=' + (enabled ? '1' : '0') +
            '&level=' + encodeURIComponent(level) +
            '&auto_reset_enabled=' + (autoResetEnabled ? '1' : '0')
        )
        .then(function(data) {
            if (data.success) {
                var message = autoResetEnabled ? 'Auto-reset enabled' : 'Auto-reset disabled';
                showToast(message, 'success');

                // Update countdown display
                var countdown = card.querySelector('.eap-logger-channel-card__auto-reset-countdown');
                if (data.auto_reset_at && autoResetEnabled) {
                    if (countdown) {
                        countdown.classList.remove('hidden');
                        countdown.dataset.resetTimestamp = new Date(data.auto_reset_at).getTime() / 1000;
                        updateSingleTimer(countdown);
                    }
                } else if (countdown) {
                    countdown.classList.add('hidden');
                }
            } else {
                showToast('Error: ' + (data.message || 'Failed'), 'error');
                // Revert toggle
                var toggle = card.querySelector('.auto-reset-toggle');
                if (toggle) {
                    toggle.checked = !toggle.checked;
                }
            }
        })
        .catch(function(err) {
            showToast('Network error: ' + err.message, 'error');
        });
    }

    // ==========================================================================
    // Channel Management - Toggle & Level with Save Button
    // ==========================================================================

    function initChannelManagement() {
        // Channel toggle switches (instant save for enable/disable)
        document.querySelectorAll('.channel-toggle').forEach(function(toggle) {
            toggle.addEventListener('change', function() {
                var channel = this.dataset.channel;
                var card = this.closest('.eap-logger-channel-card');
                var levelSelect = card.querySelector('.channel-level');

                card.classList.toggle('eap-logger-channel-card--disabled', !this.checked);

                // Save immediately for toggle
                saveChannelToggle(channel, this.checked, levelSelect ? levelSelect.value : 'warning');
            });
        });

        // Channel level selects - show Save button on change
        document.querySelectorAll('.channel-level').forEach(function(select) {
            select.addEventListener('change', function() {
                var channel = this.dataset.channel;
                var card = this.closest('.eap-logger-channel-card');
                var saveBtn = card.querySelector('.channel-save-btn');
                var debugWarning = card.querySelector('.eap-logger-channel-card__debug-warning');
                var originalLevel = this.dataset.original;
                var newLevel = this.value;

                // Show/hide save button based on whether level changed (CSP-compliant)
                if (newLevel !== originalLevel) {
                    saveBtn.classList.remove('hidden');
                    saveBtn.classList.add('eap-btn--pulse');
                } else {
                    saveBtn.classList.add('hidden');
                    saveBtn.classList.remove('eap-btn--pulse');
                }

                // Show/hide debug warning (CSP-compliant)
                var isDebugLevel = debugLevels.indexOf(newLevel) !== -1;
                if (debugWarning) {
                    debugWarning.classList.toggle('hidden', !isDebugLevel);
                }

                // Update card debug mode class
                card.classList.toggle('eap-logger-channel-card--debug-mode', isDebugLevel);
            });
        });

        // Save buttons for level changes
        document.querySelectorAll('.channel-save-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var channel = this.dataset.channel;
                var card = this.closest('.eap-logger-channel-card');
                var toggle = card.querySelector('.channel-toggle');
                var levelSelect = card.querySelector('.channel-level');
                var autoResetToggle = card.querySelector('.auto-reset-toggle');
                var autoResetEnabled = autoResetToggle ? autoResetToggle.checked : true;

                saveChannelLevel(channel, toggle ? toggle.checked : true, levelSelect.value, autoResetEnabled, btn, levelSelect, card);
            });
        });

        // Database level selects (for channels with DB handler, like security)
        document.querySelectorAll('.channel-db-level').forEach(function(select) {
            select.addEventListener('change', function() {
                var channel = this.dataset.channel;
                var card = this.closest('.eap-logger-channel-card');
                var saveBtn = card.querySelector('.channel-db-save-btn');
                var originalLevel = this.dataset.original;
                var newLevel = this.value;

                // Show/hide save button based on whether level changed
                if (newLevel !== originalLevel) {
                    saveBtn.classList.remove('hidden');
                    saveBtn.classList.add('eap-btn--pulse');
                } else {
                    saveBtn.classList.add('hidden');
                    saveBtn.classList.remove('eap-btn--pulse');
                }
            });
        });

        // Save buttons for DB level changes
        document.querySelectorAll('.channel-db-save-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var channel = this.dataset.channel;
                var card = this.closest('.eap-logger-channel-card');
                var dbLevelSelect = card.querySelector('.channel-db-level');

                saveDbLevel(channel, dbLevelSelect.value, btn, dbLevelSelect);
            });
        });
    }

    /**
     * Save database level (separate from file level)
     */
    function saveDbLevel(channel, dbLevel, btn, select) {
        var originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="eap-spinner"></span> Saving...';

        securePost(adminBasePath + '/logger/channel/update',
            '_csrf_token=' + encodeURIComponent(csrfToken) +
            '&channel=' + encodeURIComponent(channel) +
            '&db_level=' + encodeURIComponent(dbLevel)
        )
        .then(function(data) {
            if (data.success) {
                // Update original value
                select.dataset.original = dbLevel;
                // Hide save button
                btn.classList.add('hidden');
                btn.classList.remove('eap-btn--pulse');
                showToast('DB level saved: ' + dbLevel.charAt(0).toUpperCase() + dbLevel.slice(1), 'success');
            } else {
                showToast('Error: ' + (data.message || 'Failed'), 'error');
            }
        })
        .catch(function(err) {
            showToast('Network error: ' + err.message, 'error');
        })
        .finally(function() {
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
    }

    /**
     * Save channel toggle (enable/disable) - instant
     */
    function saveChannelToggle(channel, enabled, level) {
        securePost(adminBasePath + '/logger/channel/update',
            '_csrf_token=' + encodeURIComponent(csrfToken) +
            '&channel=' + encodeURIComponent(channel) +
            '&enabled=' + (enabled ? '1' : '0') +
            '&level=' + encodeURIComponent(level) +
            '&toggle_only=1'
        )
        .then(function(data) {
            showToast(
                data.success ? 'Channel ' + (enabled ? 'enabled' : 'disabled') : 'Error: ' + (data.message || 'Failed'),
                data.success ? 'success' : 'error'
            );
        })
        .catch(function(err) {
            showToast('Network error: ' + err.message, 'error');
        });
    }

    /**
     * Save channel level with explicit Save button
     */
    function saveChannelLevel(channel, enabled, level, autoResetEnabled, btn, select, card) {
        var originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="eap-spinner"></span> Saving...';

        securePost(adminBasePath + '/logger/channel/update',
            '_csrf_token=' + encodeURIComponent(csrfToken) +
            '&channel=' + encodeURIComponent(channel) +
            '&enabled=' + (enabled ? '1' : '0') +
            '&level=' + encodeURIComponent(level) +
            '&auto_reset_enabled=' + (autoResetEnabled ? '1' : '0')
        )
        .then(function(data) {
            if (data.success) {
                // Update original value
                select.dataset.original = level;
                // Hide save button (CSP-compliant)
                btn.classList.add('hidden');
                btn.classList.remove('eap-btn--pulse');

                // Check if new level is a debug level (requires auto-reset)
                var isDebugLevel = debugLevels.indexOf(level.toLowerCase()) !== -1;
                var autoResetToggle = card.querySelector('.eap-logger-channel-card__auto-reset-toggle');
                var autoResetInfo = card.querySelector('.eap-logger-channel-card__auto-reset-info');
                var countdown = card.querySelector('.eap-logger-channel-card__auto-reset-countdown');

                // Toggle auto-reset section visibility based on level
                if (isDebugLevel) {
                    // Show auto-reset toggle, hide info
                    if (autoResetToggle) autoResetToggle.classList.remove('hidden');
                    if (autoResetInfo) autoResetInfo.classList.add('hidden');
                    // Add debug mode class to card
                    card.classList.add('eap-logger-channel-card--debug-mode');
                } else {
                    // Hide auto-reset toggle, show info
                    if (autoResetToggle) autoResetToggle.classList.add('hidden');
                    if (autoResetInfo) {
                        autoResetInfo.classList.remove('hidden');
                        // Update the info text with new level
                        var infoText = autoResetInfo.querySelector('.auto-reset-info-text');
                        if (infoText) {
                            infoText.textContent = 'Level is ' + level.charAt(0).toUpperCase() + level.slice(1) + ' - no auto-reset needed';
                        }
                    }
                    // Remove debug mode class from card
                    card.classList.remove('eap-logger-channel-card--debug-mode');
                    // Hide countdown since no auto-reset for warning+
                    if (countdown) countdown.classList.add('hidden');
                }

                // Update auto-reset countdown if debug level and has reset time
                if (isDebugLevel && data.auto_reset_at && autoResetEnabled) {
                    if (countdown) {
                        countdown.classList.remove('hidden');
                        countdown.dataset.resetTimestamp = new Date(data.auto_reset_at).getTime() / 1000;
                        updateSingleTimer(countdown);
                    }
                } else if (countdown) {
                    countdown.classList.add('hidden');
                }

                showToast('Level saved: ' + level.charAt(0).toUpperCase() + level.slice(1), 'success');
            } else {
                showToast('Error: ' + (data.message || 'Failed'), 'error');
            }
        })
        .catch(function(err) {
            showToast('Network error: ' + err.message, 'error');
        })
        .finally(function() {
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
    }

    /**
     * Update or create auto-reset countdown display for a card
     */
    function updateAutoResetDisplay(card, resetTimestamp) {
        var existingDiv = card.querySelector('.eap-logger-channel-card__auto-reset-countdown');
        var timestamp = typeof resetTimestamp === 'string' ? new Date(resetTimestamp).getTime() / 1000 : resetTimestamp;

        if (!existingDiv) {
            // Create new auto-reset countdown display
            var autoResetToggleDiv = card.querySelector('.eap-logger-channel-card__auto-reset-toggle');
            var div = document.createElement('div');
            div.className = 'eap-logger-channel-card__auto-reset-countdown';
            div.dataset.resetTimestamp = timestamp;
            div.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>' +
                '<span>Resets to WARNING in <strong class="auto-reset-time">calculating...</strong></span>';
            if (autoResetToggleDiv) {
                autoResetToggleDiv.parentNode.insertBefore(div, autoResetToggleDiv.nextSibling);
            }
            existingDiv = div;
        } else {
            existingDiv.dataset.resetTimestamp = timestamp;
            existingDiv.classList.remove('hidden');
        }

        // Update the timer immediately
        updateSingleTimer(existingDiv);
    }

    // ==========================================================================
    // Auto-Reset Countdown Timers
    // ==========================================================================

    function initAutoResetTimers() {
        // Update all timers every minute
        setInterval(updateAllTimers, 60000);
        // Initial update
        updateAllTimers();
    }

    function updateAllTimers() {
        document.querySelectorAll('.eap-logger-channel-card__auto-reset-countdown').forEach(updateSingleTimer);
    }

    function updateSingleTimer(el) {
        var timestamp = parseInt(el.dataset.resetTimestamp, 10);
        if (!timestamp) return;

        var now = Math.floor(Date.now() / 1000);
        var remaining = timestamp - now;

        var timeEl = el.querySelector('.auto-reset-time');
        if (!timeEl) return;

        if (remaining <= 0) {
            timeEl.textContent = 'now';
            // Reload page to reflect the reset
            setTimeout(function() { location.reload(); }, 2000);
        } else {
            var hours = Math.floor(remaining / 3600);
            var minutes = Math.floor((remaining % 3600) / 60);
            timeEl.textContent = hours + 'h ' + minutes + 'm';
        }
    }

    // ==========================================================================
    // Log File Management
    // ==========================================================================

    function initFileClearButtons() {
        document.querySelectorAll('.clear-file-btn').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var filename = this.dataset.file;
                if (!confirm('Clear all contents of "' + filename + '"?')) return;

                securePost(adminBasePath + '/logger/file/clear',
                    '_csrf_token=' + encodeURIComponent(csrfToken) +
                    '&file=' + encodeURIComponent(filename)
                )
                .then(function(data) {
                    showToast(
                        data.success ? 'File cleared' : 'Error: ' + (data.message || 'Failed'),
                        data.success ? 'success' : 'error'
                    );
                    if (data.success) {
                        // Update file size in card
                        var card = btn.closest('.eap-logger-file-card');
                        if (card) {
                            var sizeEl = card.querySelector('.eap-logger-file-card__size');
                            if (sizeEl) sizeEl.textContent = '0 B';
                        }
                    }
                })
                .catch(function(err) {
                    showToast('Network error: ' + err.message, 'error');
                });
            });
        });
    }

    // ==========================================================================
    // File View - Per Page Selector
    // ==========================================================================

    function initPerPageSelector() {
        var select = document.getElementById('per-page-select');
        if (!select) return;

        select.addEventListener('change', function() {
            var url = new URL(window.location.href);
            url.searchParams.set('per_page', this.value);
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        });
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

        // Context buttons
        document.querySelectorAll('.eap-logger-context-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var ctx = this.dataset.context;
                try {
                    var parsed = JSON.parse(ctx);
                    content.textContent = JSON.stringify(parsed, null, 2);
                } catch (e) {
                    content.textContent = ctx;
                }
                modal.classList.remove('hidden');
            });
        });

        // Close modal on overlay click
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.classList.add('hidden');
            }
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

        // Toggle settings visibility (CSP-compliant)
        enabledCheckbox.addEventListener('change', function() {
            if (settingsDiv) {
                settingsDiv.classList.toggle('hidden', !this.checked);
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

                securePost(adminBasePath + '/logger/telegram/test',
                    '_csrf_token=' + encodeURIComponent(csrfToken) +
                    '&bot_token=' + encodeURIComponent(botToken.value) +
                    '&chat_id=' + encodeURIComponent(chatId.value)
                )
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

        // Trigger animation
        setTimeout(function() { toast.classList.add('show'); }, 10);

        // Remove after delay
        setTimeout(function() {
            toast.classList.remove('show');
            setTimeout(function() { toast.remove(); }, 300);
        }, 3000);
    }

    // ==========================================================================
    // PHP Errors Clear Form
    // ==========================================================================

    function initPhpErrorsClearForm() {
        var form = document.getElementById('php-errors-clear-form');
        if (!form) return;

        form.addEventListener('submit', function(e) {
            if (!confirm('Are you sure you want to clear the PHP errors log?')) {
                e.preventDefault();
            }
        });
    }

    // ==========================================================================
    // Bulk Actions for Log Files
    // ==========================================================================

    function initBulkActions() {
        var selectAllCheckbox = document.getElementById('select-all-files');
        var fileCheckboxes = document.querySelectorAll('.file-checkbox');
        var selectedCountEl = document.getElementById('selected-count');
        var bulkDownloadBtn = document.getElementById('bulk-download-btn');
        var bulkClearBtn = document.getElementById('bulk-clear-btn');
        var bulkDeleteBtn = document.getElementById('bulk-delete-btn');

        if (!selectAllCheckbox) return;

        function updateBulkActionsState() {
            var checked = document.querySelectorAll('.file-checkbox:checked');
            var count = checked.length;

            if (selectedCountEl) selectedCountEl.textContent = count;

            if (bulkDownloadBtn) bulkDownloadBtn.disabled = count === 0;
            if (bulkClearBtn) bulkClearBtn.disabled = count === 0;
            if (bulkDeleteBtn) bulkDeleteBtn.disabled = count === 0;
        }

        function getSelectedFiles() {
            var files = [];
            document.querySelectorAll('.file-checkbox:checked').forEach(function(cb) {
                files.push(cb.dataset.file);
            });
            return files;
        }

        // Select all checkbox
        selectAllCheckbox.addEventListener('change', function() {
            fileCheckboxes.forEach(function(cb) {
                cb.checked = selectAllCheckbox.checked;
            });
            updateBulkActionsState();
        });

        // Individual file checkboxes
        fileCheckboxes.forEach(function(cb) {
            cb.addEventListener('change', function() {
                var allChecked = document.querySelectorAll('.file-checkbox:checked').length === fileCheckboxes.length;
                selectAllCheckbox.checked = allChecked;
                updateBulkActionsState();
            });
        });

        // Bulk download
        if (bulkDownloadBtn) {
            bulkDownloadBtn.addEventListener('click', function() {
                var files = getSelectedFiles();
                if (files.length === 0) return;

                // Download each file (browser will handle multiple downloads)
                files.forEach(function(file, index) {
                    setTimeout(function() {
                        var a = document.createElement('a');
                        a.href = adminBasePath + '/logger/file/download?file=' + encodeURIComponent(file);
                        a.download = file;
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                    }, index * 200); // Stagger downloads
                });

                showToast('Downloading ' + files.length + ' file(s)', 'success');
            });
        }

        // Bulk clear
        if (bulkClearBtn) {
            bulkClearBtn.addEventListener('click', function() {
                var files = getSelectedFiles();
                if (files.length === 0) return;

                if (!confirm('Clear contents of ' + files.length + ' selected file(s)? This cannot be undone.')) return;

                var cleared = 0;
                var errors = 0;

                files.forEach(function(file) {
                    securePost(adminBasePath + '/logger/file/clear',
                        '_csrf_token=' + encodeURIComponent(csrfToken) +
                        '&file=' + encodeURIComponent(file)
                    )
                    .then(function(data) {
                        if (data.success) {
                            cleared++;
                            // Update file size in card
                            var card = document.querySelector('[data-filename="' + file + '"]');
                            if (card) {
                                var sizeEl = card.querySelector('.eap-logger-file-card__size');
                                if (sizeEl) sizeEl.textContent = '0 B';
                            }
                        } else {
                            errors++;
                        }
                    })
                    .catch(function() { errors++; })
                    .finally(function() {
                        if (cleared + errors === files.length) {
                            var msg = cleared + ' file(s) cleared';
                            if (errors > 0) msg += ', ' + errors + ' error(s)';
                            showToast(msg, errors > 0 ? 'error' : 'success');
                        }
                    });
                });
            });
        }

        // Bulk delete
        if (bulkDeleteBtn) {
            bulkDeleteBtn.addEventListener('click', function() {
                var files = getSelectedFiles();
                if (files.length === 0) return;

                if (!confirm('DELETE ' + files.length + ' selected file(s)? This cannot be undone.')) return;

                var deleted = 0;
                var errors = 0;

                files.forEach(function(file) {
                    securePost(adminBasePath + '/logger/file/delete',
                        '_csrf_token=' + encodeURIComponent(csrfToken) +
                        '&file=' + encodeURIComponent(file)
                    )
                    .then(function(data) {
                        if (data.success) {
                            deleted++;
                            // Remove card from UI
                            var card = document.querySelector('[data-filename="' + file + '"]');
                            if (card) card.remove();
                        } else {
                            errors++;
                        }
                    })
                    .catch(function() { errors++; })
                    .finally(function() {
                        if (deleted + errors === files.length) {
                            var msg = deleted + ' file(s) deleted';
                            if (errors > 0) msg += ', ' + errors + ' error(s)';
                            showToast(msg, errors > 0 ? 'error' : 'success');

                            // Reset selection
                            selectAllCheckbox.checked = false;
                            updateBulkActionsState();
                        }
                    });
                });
            });
        }
    }

    // ==========================================================================
    // Initialize
    // ==========================================================================

    /**
     * Initialize password visibility toggles
     */
    function initPasswordToggles() {
        var toggleButtons = document.querySelectorAll('.eap-input-password-toggle');

        toggleButtons.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var targetId = btn.getAttribute('data-target');
                var input = document.getElementById(targetId);

                if (!input) return;

                var eyeIcon = btn.querySelector('.eap-icon-eye');
                var eyeOffIcon = btn.querySelector('.eap-icon-eye-off');

                if (input.type === 'password') {
                    input.type = 'text';
                    if (eyeIcon) eyeIcon.classList.add('hidden');
                    if (eyeOffIcon) eyeOffIcon.classList.remove('hidden');
                } else {
                    input.type = 'password';
                    if (eyeIcon) eyeIcon.classList.remove('hidden');
                    if (eyeOffIcon) eyeOffIcon.classList.add('hidden');
                }
            });
        });
    }

    // ==========================================================================
    // Security Log Expandable Rows
    // ==========================================================================

    function initSecurityLogExpand() {
        document.querySelectorAll('.eap-logger-expand-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var logId = this.dataset.logId;
                var detailsRow = document.querySelector('[data-details-for="' + logId + '"]');

                if (detailsRow) {
                    var isExpanded = !detailsRow.classList.contains('hidden');
                    detailsRow.classList.toggle('hidden');
                    // Toggle expanded class on button for icon rotation
                    this.classList.toggle('eap-logger-expand-btn--expanded', !isExpanded);
                }
            });
        });
    }

    function init() {
        initAutoResetToggles();
        initChannelManagement();
        initAutoResetTimers();
        initFileClearButtons();
        initPerPageSelector();
        initContextModal();
        initModalCloseHandlers();
        initTelegramConfig();
        initPhpErrorsClearForm();
        initBulkActions();
        initPasswordToggles();
        initSecurityLogExpand();
        initSecurityDbLevel();
    }

    /**
     * Initialize Security Log DB Level selector
     */
    function initSecurityDbLevel() {
        var dbLevelSelect = document.getElementById('security-db-level');
        var saveBtn = document.getElementById('save-db-level-btn');
        var statusEl = document.getElementById('db-level-status');

        if (!dbLevelSelect || !saveBtn) return;

        var originalValue = dbLevelSelect.dataset.original || 'warning';

        // Show save button on change
        dbLevelSelect.addEventListener('change', function() {
            if (dbLevelSelect.value !== originalValue) {
                saveBtn.classList.remove('hidden');
                statusEl.textContent = '';
                statusEl.className = 'eap-logger-db-level-status';
            } else {
                saveBtn.classList.add('hidden');
            }
        });

        // Save button click
        saveBtn.addEventListener('click', function() {
            var newLevel = dbLevelSelect.value;
            saveBtn.disabled = true;
            statusEl.textContent = 'Saving...';
            statusEl.className = 'eap-logger-db-level-status';

            var formData = new FormData();
            formData.append('channel', 'security');
            formData.append('db_level', newLevel);
            formData.append('csrf_token', csrfToken);

            fetch(adminBasePath + '/logger/channel/update', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(parseJsonResponse)
            .then(function(data) {
                saveBtn.disabled = false;
                if (data.success) {
                    originalValue = newLevel;
                    dbLevelSelect.dataset.original = newLevel;
                    saveBtn.classList.add('hidden');
                    statusEl.textContent = 'Saved';
                    statusEl.className = 'eap-logger-db-level-status eap-logger-db-level-status--success';
                    setTimeout(function() {
                        statusEl.textContent = '';
                    }, 3000);
                } else {
                    statusEl.textContent = data.message || 'Error saving';
                    statusEl.className = 'eap-logger-db-level-status eap-logger-db-level-status--error';
                }
            })
            .catch(function(error) {
                saveBtn.disabled = false;
                statusEl.textContent = 'Error: ' + error.message;
                statusEl.className = 'eap-logger-db-level-status eap-logger-db-level-status--error';
            });
        });
    }

    // Run on DOMContentLoaded or immediately if already loaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
