<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Processors;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Request Processor
 *
 * Enriches log records with HTTP request information.
 * Automatically captures request context for web applications.
 *
 * ADDED FIELDS (in extra):
 * - request_id: Unique request identifier (generated or from header)
 * - http_method: HTTP method (GET, POST, etc.)
 * - url: Request URL (path + query string)
 * - ip: Client IP address
 * - user_agent: User agent string (truncated)
 * - referrer: Referrer URL (if present)
 *
 * USAGE:
 * ```php
 * $logger->addProcessor(new RequestProcessor());
 * // or with custom request ID header:
 * $logger->addProcessor(new RequestProcessor('X-Correlation-ID'));
 * ```
 *
 * SECURITY:
 * - IP addresses are anonymized if configured
 * - User agents are truncated to prevent log injection
 * - Sensitive headers are never logged
 */
class RequestProcessor implements ProcessorInterface
{
    /** Maximum URL length to prevent log bloat */
    private const MAX_URL_LENGTH = 500;

    /** Maximum referrer length */
    private const MAX_REFERRER_LENGTH = 200;

    /** Request ID validation pattern (alphanumeric + hyphen only, max 64 chars) */
    private const REQUEST_ID_PATTERN = '/^[a-zA-Z0-9\-]{1,64}$/';

    private readonly string $requestIdHeader;
    private readonly bool $anonymizeIp;
    private readonly int $userAgentMaxLength;
    private ?string $cachedRequestId = null;

    /**
     * SHARED cache across all RequestProcessor instances WITH DEFAULT SETTINGS
     *
     * Within a single HTTP request, $_SERVER data doesn't change.
     * This cache persists for the entire request lifecycle (not TTL-based).
     *
     * Call resetSharedCache() between requests in long-running processes
     * (Swoole, RoadRunner, ReactPHP, etc.).
     *
     * @var array<string, mixed>|null
     */
    private static ?array $sharedRequestCache = null;

    /** @var array<string, mixed>|null Instance-specific cached request data (for custom settings) */
    private ?array $cachedData = null;

    /** Whether this instance uses custom settings that differ from defaults */
    private bool $hasCustomSettings = false;

    /**
     * @var array<string> List of trusted proxy headers for IP detection.
     *
     * SECURITY WARNING: X-Forwarded-For and similar headers can be spoofed
     * by clients. Only trust these headers if your application is behind
     * a reverse proxy that you control and that overwrites these headers.
     *
     * Set to ['REMOTE_ADDR'] only if you don't use a reverse proxy.
     */
    private array $trustedProxyHeaders;

    /**
     * @var bool If true, ONLY use REMOTE_ADDR (safe default for direct connections)
     */
    private readonly bool $trustProxyHeaders;

    /**
     * @param string $requestIdHeader Header containing request/correlation ID
     * @param bool $anonymizeIp Anonymize last octet of IP address
     * @param int $userAgentMaxLength Maximum user agent length to log
     * @param array<string>|null $trustedProxyHeaders Headers to trust for IP detection.
     *                                                Default is ['REMOTE_ADDR'] only (SAFE). Set explicitly to trust proxy headers.
     * @param bool $trustProxyHeaders If false (default), only REMOTE_ADDR is used.
     *                                Set to true AND provide trustedProxyHeaders to trust proxy headers.
     */
    public function __construct(
        string $requestIdHeader = 'X-Request-ID',
        bool $anonymizeIp = false,
        int $userAgentMaxLength = 200,
        ?array $trustedProxyHeaders = null,
        bool $trustProxyHeaders = false,
    ) {
        $this->requestIdHeader = $requestIdHeader;
        $this->anonymizeIp = $anonymizeIp;
        $this->userAgentMaxLength = $userAgentMaxLength;
        $this->trustProxyHeaders = $trustProxyHeaders;

        // SECURITY: Default to ONLY REMOTE_ADDR (safe for direct connections)
        // User must explicitly enable proxy header trust
        if ($trustProxyHeaders && $trustedProxyHeaders !== null) {
            $this->trustedProxyHeaders = $trustedProxyHeaders;
            $this->hasCustomSettings = true;
        } else {
            // Safe default: only trust direct connection IP
            $this->trustedProxyHeaders = ['REMOTE_ADDR'];
        }

        // Check if custom settings differ from defaults
        if ($anonymizeIp || $userAgentMaxLength !== 200 || $requestIdHeader !== 'X-Request-ID') {
            $this->hasCustomSettings = true;
        }
    }

    /**
     * Process log record
     *
     * Uses shared static cache across all instances for maximum performance.
     * Multiple loggers with RequestProcessor share the same $_SERVER parsing result.
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        // Only process in web context
        if (PHP_SAPI === 'cli') {
            return $record->with(extra: [...$record->extra, 'request_id' => $this->getRequestId(), 'sapi' => 'cli']);
        }

        // For instances with custom settings, use instance cache
        if ($this->hasCustomSettings) {
            if ($this->cachedData === null) {
                $this->cachedData = $this->buildRequestData();
            }

            return $record->with(extra: [...$record->extra, ...$this->cachedData]);
        }

        // Use SHARED cache for default-configured instances (common case)
        // No TTL needed - within a single HTTP request, $_SERVER doesn't change
        if (self::$sharedRequestCache === null) {
            self::$sharedRequestCache = $this->buildRequestData();
        }

        return $record->with(extra: [...$record->extra, ...self::$sharedRequestCache]);
    }

    /**
     * Reset ALL caches (for long-running processes between requests)
     *
     * MUST be called between requests in long-running processes:
     * - Swoole
     * - RoadRunner
     * - ReactPHP
     * - PHP-FPM persistent workers (if reusing objects between requests)
     *
     * Example with Swoole:
     * ```php
     * $server->on('request', function ($request, $response) {
     *     RequestProcessor::resetSharedCache();
     *     // ... handle request
     * });
     * ```
     */
    public static function resetSharedCache(): void
    {
        self::$sharedRequestCache = null;
    }

    /**
     * Get or generate request ID
     */
    public function getRequestId(): string
    {
        if ($this->cachedRequestId !== null) {
            return $this->cachedRequestId;
        }

        // Try to get from header
        $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $this->requestIdHeader));
        $headerValue = $_SERVER[$headerKey] ?? null;

        // Validate request ID from header (prevent log injection/overflow)
        if ($headerValue !== null && preg_match(self::REQUEST_ID_PATTERN, $headerValue)) {
            $this->cachedRequestId = $headerValue;
        } else {
            // Invalid format or missing - generate new one
            $this->cachedRequestId = $this->generateRequestId();
        }

        return $this->cachedRequestId;
    }

    /**
     * Set request ID manually (useful for CLI or custom scenarios)
     *
     * NOTE: This invalidates BOTH instance and shared cache to ensure
     * all loggers see the new request ID.
     */
    public function setRequestId(string $requestId): self
    {
        $this->cachedRequestId = $requestId;
        $this->cachedData = null; // Invalidate instance cache
        self::$sharedRequestCache = null; // Invalidate shared cache too

        return $this;
    }

    /**
     * Build request data array
     *
     * @return array<string, mixed>
     */
    private function buildRequestData(): array
    {
        $data = [
            'request_id' => $this->getRequestId(),
        ];

        // HTTP method
        if (isset($_SERVER['REQUEST_METHOD'])) {
            $data['http_method'] = $_SERVER['REQUEST_METHOD'];
        }

        // URL (path + query) - use constant for magic number
        $url = $_SERVER['REQUEST_URI'] ?? '';
        if ($url !== '') {
            // SECURITY: Sanitize URL to prevent log injection attacks
            // Remove control characters including newlines that could corrupt JSON/JSONL logs
            $url = preg_replace('/[\x00-\x1F\x7F]/', '', $url) ?? '';

            // Truncate very long URLs (use mb_substr for UTF-8 safety)
            if (mb_strlen($url, 'UTF-8') > self::MAX_URL_LENGTH) {
                $url = mb_substr($url, 0, self::MAX_URL_LENGTH, 'UTF-8') . '...';
            }
            $data['url'] = $url;
        }

        // Client IP
        $ip = $this->getClientIp();
        if ($ip !== null) {
            $data['ip'] = $this->anonymizeIp ? $this->anonymizeIpAddress($ip) : $ip;
        }

        // User Agent (use mb_substr for UTF-8 safety)
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $userAgent = $_SERVER['HTTP_USER_AGENT'];
            // SECURITY: Remove control characters to prevent log injection
            $userAgent = preg_replace('/[\x00-\x1F\x7F]/', '', $userAgent) ?? '';
            if (mb_strlen($userAgent, 'UTF-8') > $this->userAgentMaxLength) {
                $userAgent = mb_substr($userAgent, 0, $this->userAgentMaxLength, 'UTF-8') . '...';
            }
            $data['user_agent'] = $userAgent;
        }

        // Referrer (optional, use mb_substr for UTF-8 safety)
        if (isset($_SERVER['HTTP_REFERER'])) {
            $referrer = $_SERVER['HTTP_REFERER'];
            // SECURITY: Remove control characters to prevent log injection
            $referrer = preg_replace('/[\x00-\x1F\x7F]/', '', $referrer) ?? '';
            if (mb_strlen($referrer, 'UTF-8') > self::MAX_REFERRER_LENGTH) {
                $referrer = mb_substr($referrer, 0, self::MAX_REFERRER_LENGTH, 'UTF-8') . '...';
            }
            $data['referrer'] = $referrer;
        }

        return $data;
    }

    /**
     * Get client IP address, handling proxies
     *
     * SECURITY NOTE: This method trusts headers configured in $trustedProxyHeaders.
     * Ensure your proxy configuration is correct to prevent IP spoofing.
     */
    private function getClientIp(): ?string
    {
        foreach ($this->trustedProxyHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];

                // X-Forwarded-For can contain multiple IPs (client, proxy1, proxy2)
                // The first IP is the original client (if proxy is trusted)
                if (str_contains($ip, ',')) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }

                // Validate IP format to prevent injection
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * Set trusted proxy headers for IP detection
     *
     * NOTE: This marks the instance as having custom settings and invalidates
     * both instance and shared cache.
     *
     * @param array<string> $headers Headers to trust (e.g., ['REMOTE_ADDR'] for no proxy)
     * @return self
     */
    public function setTrustedProxyHeaders(array $headers): self
    {
        $this->trustedProxyHeaders = $headers;
        $this->hasCustomSettings = true; // This instance now has custom config
        $this->cachedData = null; // Invalidate instance cache
        self::$sharedRequestCache = null; // Invalidate shared cache for consistency

        return $this;
    }

    /**
     * Anonymize IP address (replace last octet with 0)
     *
     * SECURITY: Fail-safe design - if parsing fails, returns safe placeholder
     * instead of leaking the original IP address.
     */
    private function anonymizeIpAddress(string $ip): string
    {
        // IPv4 - use explode for guaranteed safe anonymization
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                $parts[3] = '0';

                return implode('.', $parts);
            }

            // Parsing failed - return safe placeholder, NOT the original IP
            return '0.0.0.0';
        }

        // IPv6 - replace last segment
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            if (!empty($parts)) {
                $parts[count($parts) - 1] = '0';

                return implode(':', $parts);
            }

            // Parsing failed - return safe placeholder
            return '::0';
        }

        // Unknown format - return marker, NOT the original IP
        return '[ANONYMIZED]';
    }

    /**
     * Generate a unique request ID
     */
    private function generateRequestId(): string
    {
        // Use random_bytes for cryptographic randomness
        try {
            $bytes = random_bytes(16);
            // Format as UUID v4
            $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
            $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);

            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
        } catch (\Exception) {
            // Fallback to uniqid
            return uniqid('req-', true);
        }
    }
}
