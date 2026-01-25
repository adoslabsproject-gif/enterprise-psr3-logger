<?php

/**
 * ENTERPRISE PSR-3 LOGGER: should_log() Stub Function
 *
 * This is a STUB implementation that always returns true.
 * It exists to ensure the function is available in the global namespace.
 *
 * ⚠️ YOU SHOULD OVERRIDE THIS IN YOUR BOOTSTRAP ⚠️
 *
 * To override, define your own should_log() function BEFORE including this file:
 *
 * ```php
 * // your-bootstrap.php
 *
 * // Define your implementation FIRST
 * function should_log(string $channel, string $level): bool {
 *     // Your custom logic here
 *     return true;
 * }
 *
 * // Then load Composer autoload (this stub will be skipped)
 * require 'vendor/autoload.php';
 * ```
 *
 * The function_exists() check below ensures your implementation takes precedence.
 */

if (!function_exists('should_log')) {
    /**
     * STUB: Check if logging is enabled for channel and level
     *
     * ⚠️ DEFAULT IMPLEMENTATION: Logs everything (no filtering)
     *
     * Override this function in your bootstrap to implement custom logic:
     * - Fetch configuration from database
     * - Use Redis/APCu cache for performance
     * - Enable/disable channels/levels dynamically
     *
     * @param string $channel Channel name (e.g., 'default', 'security', 'api')
     * @param string $level PSR-3 log level (debug, info, notice, warning, error, critical, alert, emergency)
     * @return bool True if should log, false to skip
     */
    function should_log(string $channel, string $level): bool
    {
        // STUB IMPLEMENTATION: Always log (no filtering)
        // Override this in your bootstrap for production use
        return true;
    }
}
