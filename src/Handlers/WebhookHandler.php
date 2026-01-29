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

        // Only allow HTTPS (HTTP only for localhost in development)
        $allowedSchemes = ['https'];
        if (in_array($host, ['localhost', '127.0.0.1'], true)) {
            $allowedSchemes[] = 'http';
        }

        if (!in_array($scheme, $allowedSchemes, true)) {
            throw new \InvalidArgumentException(
                'Webhook URL must use HTTPS scheme for security (HTTP allowed only for localhost)',
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

        // Block internal/private IP ranges (SSRF protection)
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $blockedRanges = [
                '10.0.0.0/8',       // Private Class A
                '172.16.0.0/12',    // Private Class B
                '192.168.0.0/16',   // Private Class C
                '127.0.0.0/8',      // Loopback (except explicit localhost)
                '169.254.0.0/16',   // Link-local (includes AWS metadata)
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

            // Allow localhost explicitly
            if (in_array($host, ['localhost', '127.0.0.1'], true)) {
                return;
            }

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
     * @param array<string, mixed> $payload
     */
    private function sendRequest(array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            error_log('WebhookHandler: Failed to encode payload');

            return;
        }

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
