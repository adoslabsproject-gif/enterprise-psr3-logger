<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Handlers;

use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Webhook Handler
 *
 * Sends logs to webhooks (Slack, Discord, Microsoft Teams, custom endpoints).
 *
 * SLACK INTEGRATION:
 * ```php
 * $handler = WebhookHandler::slack(
 *     webhookUrl: 'https://hooks.slack.com/services/xxx/yyy/zzz',
 *     channel: '#alerts',
 *     username: 'Logger Bot',
 *     minLevel: Level::Error
 * );
 * ```
 *
 * DISCORD INTEGRATION:
 * ```php
 * $handler = WebhookHandler::discord(
 *     webhookUrl: 'https://discord.com/api/webhooks/xxx/yyy',
 *     username: 'Logger Bot',
 *     minLevel: Level::Warning
 * );
 * ```
 *
 * CUSTOM WEBHOOK:
 * ```php
 * $handler = new WebhookHandler(
 *     url: 'https://api.example.com/logs',
 *     headers: ['Authorization' => 'Bearer xxx']
 * );
 * ```
 */
class WebhookHandler extends AbstractProcessingHandler implements HandlerInterface
{
    private string $url;

    /** @var array<string, string> */
    private array $headers;

    private int $timeout;
    private bool $verifySSL;

    /** @var callable|null */
    private $payloadTransformer;

    /**
     * @param string $url Webhook URL
     * @param array<string, string> $headers HTTP headers
     * @param Level $level Minimum log level
     * @param bool $bubble Whether to bubble
     * @param int $timeout Request timeout in seconds
     * @param bool $verifySSL Verify SSL certificates
     * @param callable|null $payloadTransformer Custom payload transformer
     */
    public function __construct(
        string $url,
        array $headers = [],
        Level $level = Level::Error,
        bool $bubble = true,
        int $timeout = 5,
        bool $verifySSL = true,
        ?callable $payloadTransformer = null,
    ) {
        parent::__construct($level, $bubble);

        // SSRF Protection: Validate URL before accepting
        $this->validateWebhookUrl($url);

        $this->url = $url;
        $this->headers = $headers;
        $this->timeout = $timeout;
        $this->verifySSL = $verifySSL;
        $this->payloadTransformer = $payloadTransformer;
    }

    /**
     * Validate webhook URL to prevent SSRF attacks
     *
     * Blocks:
     * - Internal IP ranges (10.x.x.x, 172.16-31.x.x, 192.168.x.x, 127.x.x.x)
     * - Cloud metadata endpoints (169.254.169.254)
     * - Non-HTTPS schemes (except for localhost in development)
     * - File, FTP, gopher and other dangerous schemes
     *
     * @throws \InvalidArgumentException If URL is invalid or potentially dangerous
     */
    private function validateWebhookUrl(string $url): void
    {
        $parsed = parse_url($url);

        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            throw new \InvalidArgumentException('Invalid webhook URL format');
        }

        $scheme = strtolower($parsed['scheme']);
        $host = strtolower($parsed['host']);
        $isLocalhost = in_array($host, ['localhost', '127.0.0.1'], true);

        // SECURITY: Localhost only allowed in non-production environments
        // Check APP_ENV, APP_DEBUG, or explicit WEBHOOK_ALLOW_LOCALHOST=true
        $isProduction = $this->isProductionEnvironment();
        if ($isLocalhost && $isProduction) {
            throw new \InvalidArgumentException(
                'Webhook URL cannot use localhost in production environment. ' .
                'Set APP_ENV=local or WEBHOOK_ALLOW_LOCALHOST=true to allow localhost webhooks.',
            );
        }

        // Only allow HTTPS (HTTP only for localhost in non-production)
        if ($scheme !== 'https' && !($isLocalhost && !$isProduction)) {
            throw new \InvalidArgumentException(
                'Webhook URL must use HTTPS scheme for security (HTTP allowed only for localhost in development)',
            );
        }

        // Resolve hostname to IP to check for internal addresses
        $ip = gethostbyname($host);

        // If gethostbyname returns the hostname, DNS resolution failed
        // SECURITY: FAIL CLOSED - Do NOT allow unresolved hostnames
        // This prevents SSRF via DNS poisoning or timing attacks
        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException(
                'Webhook URL hostname could not be resolved (DNS failure). ' .
                'For security, unresolved hostnames are blocked to prevent SSRF attacks.',
            );
        }

        // Skip IP validation for allowed localhost
        if ($isLocalhost && !$isProduction) {
            return;
        }

        // Block internal/private IP ranges (SSRF protection)
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            // Use static const for zero-allocation on repeated calls
            static $blockedRanges = [
                '10.0.0.0/8',       // Private Class A
                '172.16.0.0/12',    // Private Class B
                '192.168.0.0/16',   // Private Class C
                '127.0.0.0/8',      // Loopback
                '169.254.0.0/16',   // Link-local (includes AWS/GCP/Azure metadata)
                '0.0.0.0/8',        // Current network
                '100.64.0.0/10',    // Carrier-grade NAT
                '192.0.0.0/24',     // IETF Protocol Assignments
                '192.0.2.0/24',     // TEST-NET-1
                '198.51.100.0/24',  // TEST-NET-2
                '203.0.113.0/24',   // TEST-NET-3
                '224.0.0.0/4',      // Multicast
                '240.0.0.0/4',      // Reserved
                '255.255.255.255/32', // Broadcast
            ];

            foreach ($blockedRanges as $range) {
                if ($this->ipInRange($ip, $range)) {
                    throw new \InvalidArgumentException(
                        'Webhook URL resolves to internal/private IP address (SSRF protection)',
                    );
                }
            }
        }
    }

    /**
     * Check if an IP is within a CIDR range
     */
    private function ipInRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $mask = -1 << (32 - (int) $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /**
     * Check if we're running in a production environment
     *
     * Uses multiple signals to determine production status:
     * 1. WEBHOOK_ALLOW_LOCALHOST=true explicitly allows localhost
     * 2. APP_ENV=production or APP_ENV=prod indicates production
     * 3. APP_DEBUG=false without APP_ENV suggests production
     * 4. Default: assume production for security (fail-safe)
     */
    private function isProductionEnvironment(): bool
    {
        // Explicit override to allow localhost
        $allowLocalhost = $_ENV['WEBHOOK_ALLOW_LOCALHOST'] ?? getenv('WEBHOOK_ALLOW_LOCALHOST');
        if ($allowLocalhost === 'true' || $allowLocalhost === '1') {
            return false; // Not production (localhost allowed)
        }

        // Check APP_ENV
        $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: null;
        if ($appEnv !== null) {
            $appEnv = strtolower($appEnv);
            // Non-production environments
            if (in_array($appEnv, ['local', 'dev', 'development', 'testing', 'test', 'staging'], true)) {
                return false;
            }
            // Production environments
            if (in_array($appEnv, ['production', 'prod', 'live'], true)) {
                return true;
            }
        }

        // Check APP_DEBUG (debug=true suggests non-production)
        $appDebug = $_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG');
        if ($appDebug === 'true' || $appDebug === '1') {
            return false;
        }

        // Default: assume production for security
        return true;
    }

    /**
     * Create a Slack webhook handler
     */
    public static function slack(
        string $webhookUrl,
        ?string $channel = null,
        ?string $username = null,
        ?string $iconEmoji = null,
        Level $level = Level::Error,
    ): self {
        $handler = new self($webhookUrl, [], $level);

        $handler->payloadTransformer = function (LogRecord $record) use ($channel, $username, $iconEmoji): array {
            $color = match ($record->level) {
                Level::Emergency, Level::Alert, Level::Critical => '#dc3545', // Red
                Level::Error => '#fd7e14', // Orange
                Level::Warning => '#ffc107', // Yellow
                Level::Notice, Level::Info => '#0dcaf0', // Cyan
                Level::Debug => '#6c757d', // Gray
            };

            $fields = [];
            if (!empty($record->context)) {
                foreach ($record->context as $key => $value) {
                    if ($key === 'exception') {
                        continue;
                    }
                    $fields[] = [
                        'title' => $key,
                        'value' => is_string($value) ? $value : (json_encode($value) ?: ''),
                        'short' => strlen((string) json_encode($value)) < 30,
                    ];
                }
            }

            $attachment = [
                'color' => $color,
                'title' => "[{$record->level->name}] {$record->channel}",
                'text' => $record->message,
                'fields' => $fields,
                'ts' => $record->datetime->getTimestamp(),
                'footer' => 'Logger',
            ];

            // Add exception info
            if (isset($record->context['exception']) && $record->context['exception'] instanceof \Throwable) {
                $e = $record->context['exception'];
                $attachment['text'] .= "\n\n*Exception:* " . get_class($e) . "\n" . $e->getMessage();
                $attachment['text'] .= "\n`" . basename($e->getFile()) . ':' . $e->getLine() . '`';
            }

            $payload = [
                'attachments' => [$attachment],
            ];

            if ($channel !== null) {
                $payload['channel'] = $channel;
            }
            if ($username !== null) {
                $payload['username'] = $username;
            }
            if ($iconEmoji !== null) {
                $payload['icon_emoji'] = $iconEmoji;
            }

            return $payload;
        };

        return $handler;
    }

    /**
     * Create a Discord webhook handler
     */
    public static function discord(
        string $webhookUrl,
        ?string $username = null,
        ?string $avatarUrl = null,
        Level $level = Level::Error,
    ): self {
        $handler = new self($webhookUrl, [], $level);

        $handler->payloadTransformer = function (LogRecord $record) use ($username, $avatarUrl): array {
            $color = match ($record->level) {
                Level::Emergency, Level::Alert, Level::Critical => 0xdc3545, // Red
                Level::Error => 0xfd7e14, // Orange
                Level::Warning => 0xffc107, // Yellow
                Level::Notice, Level::Info => 0x0dcaf0, // Cyan
                Level::Debug => 0x6c757d, // Gray
            };

            $fields = [];
            foreach ($record->context as $key => $value) {
                if ($key === 'exception') {
                    continue;
                }
                $fields[] = [
                    'name' => $key,
                    'value' => is_string($value) ? substr($value, 0, 1024) : substr((string) json_encode($value), 0, 1024),
                    'inline' => true,
                ];
            }

            $embed = [
                'title' => "[{$record->level->name}] {$record->channel}",
                'description' => substr($record->message, 0, 4096),
                'color' => $color,
                'fields' => array_slice($fields, 0, 25), // Discord limit
                'timestamp' => $record->datetime->format(\DateTimeInterface::ISO8601),
            ];

            $payload = [
                'embeds' => [$embed],
            ];

            if ($username !== null) {
                $payload['username'] = $username;
            }
            if ($avatarUrl !== null) {
                $payload['avatar_url'] = $avatarUrl;
            }

            return $payload;
        };

        return $handler;
    }

    /**
     * Create a Microsoft Teams webhook handler
     */
    public static function teams(
        string $webhookUrl,
        ?string $title = null,
        Level $level = Level::Error,
    ): self {
        $handler = new self($webhookUrl, [], $level);

        $handler->payloadTransformer = function (LogRecord $record) use ($title): array {
            $color = match ($record->level) {
                Level::Emergency, Level::Alert, Level::Critical => 'dc3545',
                Level::Error => 'fd7e14',
                Level::Warning => 'ffc107',
                Level::Notice, Level::Info => '0dcaf0',
                Level::Debug => '6c757d',
            };

            $facts = [];
            foreach ($record->context as $key => $value) {
                if ($key === 'exception') {
                    continue;
                }
                $facts[] = [
                    'name' => $key,
                    'value' => is_string($value) ? $value : json_encode($value),
                ];
            }

            return [
                '@type' => 'MessageCard',
                '@context' => 'https://schema.org/extensions',
                'summary' => $record->message,
                'themeColor' => $color,
                'title' => $title ?? "[{$record->level->name}] {$record->channel}",
                'sections' => [
                    [
                        'text' => $record->message,
                        'facts' => $facts,
                    ],
                ],
            ];
        };

        return $handler;
    }

    /**
     * {@inheritdoc}
     */
    protected function write(LogRecord $record): void
    {
        $payload = $this->buildPayload($record);

        $this->sendRequest($payload);
    }

    /**
     * Build the webhook payload
     *
     * @return array<string, mixed>
     */
    private function buildPayload(LogRecord $record): array
    {
        if ($this->payloadTransformer !== null) {
            return ($this->payloadTransformer)($record);
        }

        // Default JSON payload
        return [
            'timestamp' => $record->datetime->format(\DateTimeInterface::RFC3339_EXTENDED),
            'level' => $record->level->name,
            'channel' => $record->channel,
            'message' => $record->message,
            'context' => $record->context,
            'extra' => $record->extra,
        ];
    }

    /**
     * Send HTTP request to webhook
     *
     * IMPLEMENTATION: Uses CURL when available for reliable timeouts.
     * Falls back to file_get_contents for environments without CURL.
     *
     * WHY CURL:
     * - file_get_contents timeout is unreliable (may not honor connect timeout)
     * - CURL has separate connect_timeout and timeout options
     * - Better error reporting
     * - Industry standard for HTTP in PHP
     *
     * @param array<string, mixed> $payload
     */
    private function sendRequest(array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            error_log('WebhookHandler: Failed to encode payload');

            return;
        }

        // Use CURL if available (preferred for reliable timeouts)
        if (function_exists('curl_init')) {
            $this->sendWithCurl($json);

            return;
        }

        // Fallback to file_get_contents
        $this->sendWithFileGetContents($json);
    }

    /**
     * Send request using CURL (preferred method)
     *
     * @param string $json JSON payload
     */
    private function sendWithCurl(string $json): void
    {
        $ch = curl_init($this->url);

        if ($ch === false) {
            error_log('WebhookHandler: Failed to initialize CURL');

            return;
        }

        $headers = ['Content-Type: application/json'];
        foreach ($this->headers as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min($this->timeout, 5), // Connect timeout (max 5s)
            CURLOPT_TIMEOUT => $this->timeout,                // Total timeout
            CURLOPT_SSL_VERIFYPEER => $this->verifySSL,
            CURLOPT_SSL_VERIFYHOST => $this->verifySSL ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false, // Security: don't follow redirects
            CURLOPT_MAXREDIRS => 0,
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            error_log('WebhookHandler: CURL request failed - ' . $error);

            return;
        }

        // Log non-2xx responses (but don't throw - logging should be fire-and-forget)
        if ($httpCode < 200 || $httpCode >= 300) {
            error_log("WebhookHandler: HTTP {$httpCode} response from webhook");
        }
    }

    /**
     * Send request using file_get_contents (fallback)
     *
     * @param string $json JSON payload
     */
    private function sendWithFileGetContents(string $json): void
    {
        $headers = array_merge(
            ['Content-Type' => 'application/json'],
            $this->headers,
        );

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $json,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => $this->verifySSL,
                'verify_peer_name' => $this->verifySSL,
            ],
        ]);

        $result = @file_get_contents($this->url, false, $context);

        if ($result === false) {
            $error = error_get_last();
            error_log('WebhookHandler: Request failed - ' . ($error['message'] ?? 'Unknown error'));
        }
    }

    /**
     * Get the default formatter
     */
    protected function getDefaultFormatter(): FormatterInterface
    {
        return new \AdosLabs\EnterprisePSR3Logger\Formatters\JsonFormatter(appendNewline: false);
    }
}
