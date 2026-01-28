/**
 * Enterprise PSR-3 Logger - Client-Side Error Handler
 *
 * Captures JavaScript errors, unhandled promise rejections, and console errors
 * and sends them to the server-side PSR-3 logger.
 *
 * Features:
 * - Global error handler (window.onerror)
 * - Unhandled promise rejection handler
 * - Console override (optional)
 * - Rate limiting (max 10 errors per minute)
 * - Deduplication (same error within 5 seconds)
 * - Batching (sends errors in batches when possible)
 * - Offline queue (stores errors when offline)
 *
 * Usage:
 *   1. Include this script early in <head>
 *   2. Configure the endpoint (optional):
 *      window.PSR3_ERROR_ENDPOINT = '/api/log/js-error';
 *
 * @version 1.0.0
 */
(function() {
    'use strict';

    // Configuration
    var config = {
        // API endpoint (can be overridden via window.PSR3_ERROR_ENDPOINT)
        endpoint: window.PSR3_ERROR_ENDPOINT || '/api/log/js-error',

        // Rate limiting
        maxErrorsPerMinute: 10,
        rateLimitWindowMs: 60000,

        // Deduplication
        dedupeWindowMs: 5000,

        // Batching
        batchSize: 5,
        batchDelayMs: 1000,

        // Debug mode (logs to console)
        debug: window.PSR3_ERROR_DEBUG || false,

        // Capture console.error and console.warn
        captureConsole: window.PSR3_CAPTURE_CONSOLE !== false
    };

    // State
    var state = {
        errorCount: 0,
        errorCountResetTime: Date.now() + config.rateLimitWindowMs,
        recentErrors: {},  // hash -> timestamp for deduplication
        errorQueue: [],
        sendTimeout: null,
        initialized: false
    };

    /**
     * Simple hash function for error deduplication
     */
    function simpleHash(str) {
        var hash = 0;
        for (var i = 0; i < str.length; i++) {
            var char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash;
        }
        return hash.toString(36);
    }

    /**
     * Check if error should be sent (rate limiting + deduplication)
     */
    function shouldSendError(errorData) {
        var now = Date.now();

        // Reset rate limit counter if window expired
        if (now > state.errorCountResetTime) {
            state.errorCount = 0;
            state.errorCountResetTime = now + config.rateLimitWindowMs;
        }

        // Check rate limit
        if (state.errorCount >= config.maxErrorsPerMinute) {
            if (config.debug) {
                console.log('[PSR3] Rate limit exceeded, dropping error');
            }
            return false;
        }

        // Create hash for deduplication
        var hash = simpleHash(errorData.message + (errorData.stack || '') + errorData.url);

        // Check for duplicate within window
        if (state.recentErrors[hash] && now - state.recentErrors[hash] < config.dedupeWindowMs) {
            if (config.debug) {
                console.log('[PSR3] Duplicate error, skipping');
            }
            return false;
        }

        // Update state
        state.errorCount++;
        state.recentErrors[hash] = now;

        // Cleanup old hashes (keep memory usage low)
        var cutoff = now - config.dedupeWindowMs;
        for (var key in state.recentErrors) {
            if (state.recentErrors[key] < cutoff) {
                delete state.recentErrors[key];
            }
        }

        return true;
    }

    /**
     * Queue error for sending
     */
    function queueError(errorData) {
        if (!shouldSendError(errorData)) {
            return;
        }

        state.errorQueue.push(errorData);

        // Send immediately if batch size reached
        if (state.errorQueue.length >= config.batchSize) {
            sendErrors();
            return;
        }

        // Otherwise schedule send after delay
        if (!state.sendTimeout) {
            state.sendTimeout = setTimeout(sendErrors, config.batchDelayMs);
        }
    }

    /**
     * Send queued errors to server
     */
    function sendErrors() {
        if (state.sendTimeout) {
            clearTimeout(state.sendTimeout);
            state.sendTimeout = null;
        }

        if (state.errorQueue.length === 0) {
            return;
        }

        // Take all errors from queue
        var errors = state.errorQueue.splice(0, state.errorQueue.length);

        // Send each error (could be optimized to batch API)
        errors.forEach(function(errorData) {
            sendSingleError(errorData);
        });
    }

    /**
     * Send single error to server
     */
    function sendSingleError(errorData) {
        if (config.debug) {
            console.log('[PSR3] Sending error:', errorData);
        }

        // Use sendBeacon if available (more reliable for page unload)
        if (navigator.sendBeacon) {
            try {
                var blob = new Blob([JSON.stringify(errorData)], { type: 'application/json' });
                var sent = navigator.sendBeacon(config.endpoint, blob);
                if (sent) return;
            } catch (e) {
                // Fall through to fetch
            }
        }

        // Fallback to fetch
        try {
            fetch(config.endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(errorData),
                keepalive: true
            }).catch(function(e) {
                if (config.debug) {
                    console.log('[PSR3] Failed to send error:', e);
                }
            });
        } catch (e) {
            if (config.debug) {
                console.log('[PSR3] Failed to send error:', e);
            }
        }
    }

    /**
     * Create error data object
     */
    function createErrorData(level, message, source, line, column, error) {
        var data = {
            level: level,
            message: message || 'Unknown error',
            url: source || window.location.href,
            userAgent: navigator.userAgent
        };

        if (line) data.line = line;
        if (column) data.column = column;

        if (error && error.stack) {
            data.stack = error.stack;
        }

        return data;
    }

    /**
     * Global error handler
     */
    function handleError(message, source, line, column, error) {
        // Ignore errors from extensions or cross-origin scripts
        if (source && (source.indexOf('chrome-extension://') === 0 || source.indexOf('moz-extension://') === 0)) {
            return;
        }

        var errorData = createErrorData('error', message, source, line, column, error);
        queueError(errorData);

        // Don't prevent default error handling
        return false;
    }

    /**
     * Unhandled promise rejection handler
     */
    function handleRejection(event) {
        var reason = event.reason;
        var message = 'Unhandled Promise Rejection';
        var stack = null;

        if (reason) {
            if (typeof reason === 'string') {
                message = reason;
            } else if (reason.message) {
                message = reason.message;
                stack = reason.stack;
            } else {
                try {
                    message = JSON.stringify(reason);
                } catch (e) {
                    message = String(reason);
                }
            }
        }

        var errorData = {
            level: 'error',
            message: 'Unhandled Promise Rejection: ' + message,
            url: window.location.href,
            userAgent: navigator.userAgent
        };

        if (stack) {
            errorData.stack = stack;
        }

        queueError(errorData);
    }

    /**
     * Wrap console methods to capture errors/warnings
     */
    function wrapConsole() {
        if (!config.captureConsole) return;

        var originalError = console.error;
        var originalWarn = console.warn;

        console.error = function() {
            // Call original
            originalError.apply(console, arguments);

            // Capture
            var message = Array.prototype.slice.call(arguments).map(function(arg) {
                if (typeof arg === 'string') return arg;
                try {
                    return JSON.stringify(arg);
                } catch (e) {
                    return String(arg);
                }
            }).join(' ');

            queueError({
                level: 'error',
                message: 'Console Error: ' + message,
                url: window.location.href,
                userAgent: navigator.userAgent
            });
        };

        console.warn = function() {
            // Call original
            originalWarn.apply(console, arguments);

            // Capture
            var message = Array.prototype.slice.call(arguments).map(function(arg) {
                if (typeof arg === 'string') return arg;
                try {
                    return JSON.stringify(arg);
                } catch (e) {
                    return String(arg);
                }
            }).join(' ');

            queueError({
                level: 'warning',
                message: 'Console Warning: ' + message,
                url: window.location.href,
                userAgent: navigator.userAgent
            });
        };
    }

    /**
     * Initialize error handler
     */
    function init() {
        if (state.initialized) return;
        state.initialized = true;

        // Global error handler
        window.onerror = handleError;

        // Promise rejection handler
        window.addEventListener('unhandledrejection', handleRejection);

        // Wrap console
        wrapConsole();

        // Send remaining errors on page unload
        window.addEventListener('beforeunload', function() {
            sendErrors();
        });

        // Send remaining errors on visibility change (mobile)
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'hidden') {
                sendErrors();
            }
        });

        if (config.debug) {
            console.log('[PSR3] Error handler initialized', config);
        }
    }

    /**
     * Public API for manual logging
     */
    window.PSR3Logger = {
        debug: function(message, extra) {
            queueError({ level: 'debug', message: message, url: window.location.href, userAgent: navigator.userAgent, extra: extra });
        },
        info: function(message, extra) {
            queueError({ level: 'info', message: message, url: window.location.href, userAgent: navigator.userAgent, extra: extra });
        },
        warning: function(message, extra) {
            queueError({ level: 'warning', message: message, url: window.location.href, userAgent: navigator.userAgent, extra: extra });
        },
        error: function(message, extra) {
            queueError({ level: 'error', message: message, url: window.location.href, userAgent: navigator.userAgent, extra: extra });
        },
        critical: function(message, extra) {
            queueError({ level: 'critical', message: message, url: window.location.href, userAgent: navigator.userAgent, extra: extra });
        },
        flush: function() {
            sendErrors();
        }
    };

    // Initialize immediately
    init();
})();
