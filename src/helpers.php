<?php

declare(strict_types=1);

/**
 * Enterprise PSR-3 Logger - Helper Functions
 *
 * Security-focused helper functions for the logging package.
 * Auto-loaded via composer.json "files" autoload.
 */
if (!function_exists('esc')) {
    /**
     * Escape string for safe HTML output (XSS prevention)
     *
     * Uses ENT_QUOTES | ENT_HTML5 for comprehensive escaping:
     * - ENT_QUOTES: Escapes both single and double quotes
     * - ENT_HTML5: Uses HTML5 entity encoding
     * - UTF-8: Explicit encoding for consistency
     *
     * @param string|null $value The value to escape
     * @return string Escaped string safe for HTML output
     */
    function esc(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    /**
     * Escape string for HTML attribute context
     *
     * Alias for esc() - explicitly named for attribute escaping.
     * Use this when escaping values for HTML attributes.
     *
     * @param string|null $value The value to escape
     * @return string Escaped string safe for HTML attributes
     */
    function esc_attr(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    /**
     * Escape and validate URL for safe output
     *
     * Validates URL scheme and escapes for HTML output.
     * Only allows http, https, and mailto schemes.
     *
     * @param string|null $url The URL to escape
     * @return string Escaped URL or empty string if invalid
     */
    function esc_url(?string $url): string
    {
        if ($url === null || $url === '') {
            return '';
        }

        // Parse URL to validate scheme
        $parsed = parse_url($url);
        if ($parsed === false) {
            return '';
        }

        // Only allow safe schemes
        $scheme = strtolower($parsed['scheme'] ?? '');
        if ($scheme !== '' && !in_array($scheme, ['http', 'https', 'mailto', ''], true)) {
            return '';
        }

        return htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
