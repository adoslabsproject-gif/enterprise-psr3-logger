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
    private string $requestIdHeader;
    private bool $anonymizeIp;
    private int $userAgentMaxLength;
    private ?string $cachedRequestId = null;

    /** @var array<string, mixed>|null Cached request data */
    private ?array $cachedData = null;

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
    private bool $trustProxyHeaders;

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
        } else {
            // Safe default: only trust direct connection IP
            $this->trustedProxyHeaders = ['REMOTE_ADDR'];
        }
    }

    /**
     * Process log record
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        // Only process in web context
        if (PHP_SAPI === 'cli') {
            return $record->with(extra: array_merge($record->extra, [
                'request_id' => $this->getRequestId(),
                'sapi' => 'cli',
            ]));
        }

        // Use cached data for performance
        if ($this->cachedData === null) {
            $this->cachedData = $this->buildRequestData();
        }

        return $record->with(extra: array_merge($record->extra, $this->cachedData));
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
        if ($headerValue !== null) {
            // Only allow alphanumeric, hyphens, max 64 chars (UUID format compatible)
            if (preg_match('/^[a-zA-Z0-9\-]{1,64}$/', $headerValue)) {
                $this->cachedRequestId = $headerValue;
            } else {
                // Invalid format - generate new one instead of using potentially malicious value
                $this->cachedRequestId = $this->generateRequestId();
            }
        } else {
            $this->cachedRequestId = $this->generateRequestId();
        }

        return $this->cachedRequestId;
    }

    /**
     * Set request ID manually (useful for CLI or custom scenarios)
     */
    public function setRequestId(string $requestId): self
    {
        $this->cachedRequestId = $requestId;
        $this->cachedData = null; // Invalidate cache

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

        // URL (path + query)
        $url = $_SERVER['REQUEST_URI'] ?? '';
        if ($url !== '') {
            // Truncate very long URLs
            if (strlen($url) > 500) {
                $url = substr($url, 0, 500) . '...';
            }
            $data['url'] = $url;
        }

        // Client IP
        $ip = $this->getClientIp();
        if ($ip !== null) {
            $data['ip'] = $this->anonymizeIp ? $this->anonymizeIpAddress($ip) : $ip;
        }

        // User Agent
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $userAgent = $_SERVER['HTTP_USER_AGENT'];
            if (strlen($userAgent) > $this->userAgentMaxLength) {
                $userAgent = substr($userAgent, 0, $this->userAgentMaxLength) . '...';
            }
            $data['user_agent'] = $userAgent;
        }

        // Referrer (optional)
        if (isset($_SERVER['HTTP_REFERER'])) {
            $referrer = $_SERVER['HTTP_REFERER'];
            if (strlen($referrer) > 200) {
                $referrer = substr($referrer, 0, 200) . '...';
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
     * @param array<string> $headers Headers to trust (e.g., ['REMOTE_ADDR'] for no proxy)
     * @return self
     */
    public function setTrustedProxyHeaders(array $headers): self
    {
        $this->trustedProxyHeaders = $headers;
        $this->cachedData = null; // Invalidate cache

        return $this;
    }

    /**
     * Anonymize IP address (replace last octet with 0)
     */
    private function anonymizeIpAddress(string $ip): string
    {
        // IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.0', $ip) ?? $ip;
        }

        // IPv6 - replace last segment
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return preg_replace('/:[^:]+$/', ':0', $ip) ?? $ip;
        }

        return $ip;
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
