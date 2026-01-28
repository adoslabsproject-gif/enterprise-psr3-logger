<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Security;

use AdosLabs\EnterprisePSR3Logger\LoggerFacade as Logger;

/**
 * Secure Error Handler
 *
 * Sanitizes error messages for client responses while logging full details server-side.
 * Prevents information disclosure through error messages.
 *
 * @version 1.0.0
 */
final class SecureErrorHandler
{
    /**
     * Patterns that indicate sensitive information in error messages
     */
    private const SENSITIVE_PATTERNS = [
        // File paths
        '/\/[\w\/\-\.]+\.(php|env|ini|conf|key|pem)/i',
        // Database connection strings
        '/mysql:|pgsql:|sqlite:|mongodb:|redis:/i',
        // Stack traces
        '/^#\d+ /',
        '/ in \/[\w\/]+\.php on line \d+/',
        '/ at \/[\w\/]+\.php:\d+/',
        // SQL errors with table/column info
        '/table [\'"`]?\w+[\'"`]? doesn\'t exist/i',
        '/unknown column [\'"`]?\w+[\'"`]?/i',
        '/SQLSTATE\[\w+\]/',
        // Server info
        '/nginx|apache|php-fpm/i',
        // Credentials/tokens
        '/api[_-]?key|secret|password|token|credential/i',
        // IP addresses
        '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/',
    ];

    /**
     * Generic error messages per error type
     */
    private const GENERIC_MESSAGES = [
        'database' => 'A database error occurred. Please try again later.',
        'connection' => 'Unable to connect to the service. Please try again later.',
        'validation' => 'The provided data is invalid.',
        'permission' => 'You do not have permission to perform this action.',
        'not_found' => 'The requested resource was not found.',
        'timeout' => 'The operation timed out. Please try again.',
        'rate_limit' => 'Too many requests. Please wait before trying again.',
        'config' => 'A configuration error occurred. Please contact support.',
        'default' => 'An unexpected error occurred. Please try again later.',
    ];

    /**
     * Whether to expose detailed errors (development mode)
     */
    private bool $debug;

    public function __construct(?bool $debug = null)
    {
        $this->debug = $debug ?? $this->isDebugMode();
    }

    /**
     * Handle an exception and return safe error response data
     *
     * @param \Throwable $e The exception
     * @param string $context Context for logging (e.g., "telegram_update", "channel_config")
     * @return array{message: string, code: string}
     */
    public function handle(\Throwable $e, string $context = 'general'): array
    {
        // Always log full error server-side
        $this->logError($e, $context);

        // Return sanitized response
        return [
            'message' => $this->getSafeMessage($e),
            'code' => $this->getErrorCode($e),
        ];
    }

    /**
     * Get a safe message for client response
     */
    public function getSafeMessage(\Throwable $e): string
    {
        // In debug mode, return actual message if it's not sensitive
        if ($this->debug) {
            $message = $e->getMessage();
            if (!$this->containsSensitiveInfo($message)) {
                return $message;
            }
        }

        // Determine error type and return generic message
        return $this->getGenericMessage($e);
    }

    /**
     * Get error code for client
     */
    public function getErrorCode(\Throwable $e): string
    {
        return match (true) {
            $e instanceof \PDOException => 'DATABASE_ERROR',
            $e instanceof \InvalidArgumentException => 'VALIDATION_ERROR',
            $e instanceof \RuntimeException => 'RUNTIME_ERROR',
            str_contains(strtolower($e->getMessage()), 'permission') => 'PERMISSION_DENIED',
            str_contains(strtolower($e->getMessage()), 'not found') => 'NOT_FOUND',
            str_contains(strtolower($e->getMessage()), 'timeout') => 'TIMEOUT',
            default => 'INTERNAL_ERROR',
        };
    }

    /**
     * Sanitize a message string
     *
     * Removes or masks sensitive information from an error message.
     */
    public function sanitize(string $message): string
    {
        if (!$this->containsSensitiveInfo($message)) {
            return $message;
        }

        // Replace file paths
        $message = preg_replace('/\/[\w\/\-\.]+\.(php|env|ini|conf|key|pem)/i', '[path]', $message) ?? $message;

        // Replace IP addresses
        $message = preg_replace('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', '[ip]', $message) ?? $message;

        // Replace stack trace references
        $message = preg_replace('/ in \/[\w\/]+\.php on line \d+/', '', $message) ?? $message;
        $message = preg_replace('/ at \/[\w\/]+\.php:\d+/', '', $message) ?? $message;

        // Replace SQL details
        $message = preg_replace('/SQLSTATE\[\w+\]:\s*/', '', $message) ?? $message;

        return trim($message);
    }

    /**
     * Check if message contains sensitive information
     */
    private function containsSensitiveInfo(string $message): bool
    {
        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get generic message based on exception type
     */
    private function getGenericMessage(\Throwable $e): string
    {
        $message = strtolower($e->getMessage());

        return match (true) {
            $e instanceof \PDOException => self::GENERIC_MESSAGES['database'],
            str_contains($message, 'connection') => self::GENERIC_MESSAGES['connection'],
            str_contains($message, 'invalid') || str_contains($message, 'validation') => self::GENERIC_MESSAGES['validation'],
            str_contains($message, 'permission') || str_contains($message, 'denied') => self::GENERIC_MESSAGES['permission'],
            str_contains($message, 'not found') => self::GENERIC_MESSAGES['not_found'],
            str_contains($message, 'timeout') => self::GENERIC_MESSAGES['timeout'],
            str_contains($message, 'rate') || str_contains($message, 'limit') => self::GENERIC_MESSAGES['rate_limit'],
            str_contains($message, 'config') => self::GENERIC_MESSAGES['config'],
            default => self::GENERIC_MESSAGES['default'],
        };
    }

    /**
     * Log full error details server-side
     */
    private function logError(\Throwable $e, string $context): void
    {
        try {
            Logger::channel('error')->error('Error in ' . $context, [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
        } catch (\Throwable $logError) {
            // Fallback to error_log if Logger fails
            error_log(sprintf(
                '[%s] Error in %s: %s in %s:%d',
                date('Y-m-d H:i:s'),
                $context,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));
        }
    }

    /**
     * Determine if running in debug mode
     */
    private function isDebugMode(): bool
    {
        $debug = $_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: 'false';

        return filter_var($debug, FILTER_VALIDATE_BOOLEAN);
    }
}
