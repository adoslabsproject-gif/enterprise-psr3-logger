<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Handlers;

use AdosLabs\EnterprisePSR3Logger\Security\RateLimiter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Telegram Handler
 *
 * Sends log messages to a Telegram chat/channel via the Bot API.
 *
 * Features:
 * - Separate minimum level (independent from channel level)
 * - TRUE sliding window rate limiting (uses RateLimiter with Redis support)
 * - Message formatting with emojis
 * - HTML parsing for structured messages
 * - Silent mode option
 *
 * Rate Limiting:
 * - Uses proper sliding window algorithm (not fixed minute buckets)
 * - Supports Redis for distributed rate limiting
 * - Falls back to local memory if Redis unavailable
 *
 * @version 2.0.0
 */
final class TelegramHandler extends AbstractProcessingHandler
{
    private string $botToken;
    private string $chatId;
    private bool $enabled;
    private bool $silent;
    private int $rateLimitPerMinute;

    /** @var RateLimiter|null Rate limiter instance (lazy initialized) */
    private static ?RateLimiter $rateLimiter = null;

    private const API_URL = 'https://api.telegram.org/bot%s/sendMessage';

    /**
     * Level emojis for visual distinction
     */
    private const LEVEL_EMOJIS = [
        'emergency' => "\xF0\x9F\x9A\xA8", // 🚨
        'alert' => "\xF0\x9F\x94\xB4",     // 🔴
        'critical' => "\xE2\x9D\x8C",      // ❌
        'error' => "\xE2\x9A\xA0\xEF\xB8\x8F", // ⚠️
        'warning' => "\xE2\x9A\xA1",       // ⚡
        'notice' => "\xF0\x9F\x93\xA2",    // 📢
        'info' => "\xE2\x84\xB9\xEF\xB8\x8F", // ℹ️
        'debug' => "\xF0\x9F\x94\xA7",     // 🔧
    ];

    /**
     * Constructor
     *
     * @param string $botToken Telegram Bot API token
     * @param string $chatId Chat ID or @channel_name
     * @param int|string|Level $level Minimum logging level (independent from channel)
     * @param bool $bubble Whether messages should bubble up to other handlers
     * @param bool $enabled Whether this handler is enabled
     * @param bool $silent Send notifications silently (no sound)
     * @param int $rateLimitPerMinute Max messages per minute (0 = unlimited)
     */
    public function __construct(
        string $botToken,
        string $chatId,
        int|string|Level $level = Level::Error,
        bool $bubble = true,
        bool $enabled = true,
        bool $silent = false,
        int $rateLimitPerMinute = 30,
    ) {
        parent::__construct($level, $bubble);

        $this->botToken = $botToken;
        $this->chatId = $chatId;
        $this->enabled = $enabled;
        $this->silent = $silent;
        $this->rateLimitPerMinute = $rateLimitPerMinute;
    }

    /**
     * Check if handler is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enable/disable handler
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Write the log record to Telegram
     */
    protected function write(LogRecord $record): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!$this->isWithinRateLimit()) {
            return;
        }

        $message = $this->formatMessage($record);
        $this->sendToTelegram($message);
        $this->incrementMessageCount();
    }

    /**
     * Format the log record for Telegram
     */
    private function formatMessage(LogRecord $record): string
    {
        $levelName = strtolower($record->level->name);
        $emoji = self::LEVEL_EMOJIS[$levelName] ?? "\xF0\x9F\x93\x9D"; // 📝

        $lines = [
            sprintf('%s <b>[%s]</b> %s', $emoji, strtoupper($levelName), htmlspecialchars($record->channel)),
            sprintf('<code>%s</code>', $record->datetime->format('Y-m-d H:i:s')),
            '',
            htmlspecialchars($record->message),
        ];

        // Add context if present
        if (!empty($record->context)) {
            $lines[] = '';
            $lines[] = '<b>Context:</b>';
            $contextJson = json_encode($record->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($contextJson !== false && strlen($contextJson) <= 500) {
                $lines[] = '<pre>' . htmlspecialchars($contextJson) . '</pre>';
            } else {
                $lines[] = '<i>(truncated)</i>';
            }
        }

        // Add extra data summary
        if (!empty($record->extra)) {
            $extraKeys = array_keys($record->extra);
            if (count($extraKeys) > 0) {
                $lines[] = '';
                $lines[] = '<b>Extra:</b> ' . htmlspecialchars(implode(', ', array_slice($extraKeys, 0, 5)));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Send message to Telegram via Bot API
     */
    private function sendToTelegram(string $message): bool
    {
        $url = sprintf(self::API_URL, $this->botToken);

        $payload = [
            'chat_id' => $this->chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_notification' => $this->silent,
            'disable_web_page_preview' => true,
        ];

        $ch = curl_init($url);

        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // CURL error
        if ($response === false) {
            error_log('TelegramHandler: CURL failed - ' . $curlError);

            return false;
        }

        // HTTP error
        if ($httpCode !== 200) {
            // Parse Telegram API error response for better debugging
            // PHPStan: $response is string here since we checked for false above and CURLOPT_RETURNTRANSFER is true
            $responseString = is_string($response) ? $response : '';
            $errorDescription = $this->parseTelegramError($responseString);
            error_log("TelegramHandler: HTTP {$httpCode} - {$errorDescription}");

            return false;
        }

        return true;
    }

    /**
     * Parse Telegram API error response
     *
     * Telegram API returns JSON with 'ok', 'error_code', and 'description' fields.
     * Example: {"ok":false,"error_code":400,"description":"Bad Request: chat not found"}
     */
    private function parseTelegramError(string $response): string
    {
        if ($response === '') {
            return 'Empty response';
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            // Not JSON - return truncated raw response
            return 'Non-JSON response: ' . substr($response, 0, 100);
        }

        // Standard Telegram error format
        if (isset($data['description'])) {
            $desc = (string) $data['description'];

            // Sanitize for log safety (no newlines, reasonable length)
            return preg_replace('/[\r\n]+/', ' ', substr($desc, 0, 200)) ?? 'Unknown error';
        }

        // Fallback: return ok status
        if (isset($data['ok']) && $data['ok'] === false) {
            return 'API returned ok=false (no description)';
        }

        return 'Unknown error format';
    }

    /**
     * Get rate limiter instance (lazy initialization)
     */
    private function getRateLimiter(): RateLimiter
    {
        if (self::$rateLimiter === null) {
            self::$rateLimiter = new RateLimiter();
        }

        return self::$rateLimiter;
    }

    /**
     * Check if we're within rate limit AND increment counter atomically
     *
     * Uses RateLimiter::attemptWithLimit() for custom rate limits.
     * This is atomic - no race condition between check and increment.
     *
     * Returns true if message can be sent, false if rate limited.
     */
    private function isWithinRateLimit(): bool
    {
        if ($this->rateLimitPerMinute <= 0) {
            return true;
        }

        // Use atomic attemptWithLimit with our EXACT custom rate
        // Key is unique per chat to allow independent rate limiting per destination
        $key = 'telegram:' . md5($this->chatId);
        $result = $this->getRateLimiter()->attemptWithLimit(
            $key,
            $this->rateLimitPerMinute,
            60, // 1 minute window
        );

        return $result['allowed'];
    }

    /**
     * Increment message count for rate limiting
     *
     * NOTE: This is now a no-op because isWithinRateLimit() uses atomic
     * attemptWithLimit() which already increments on success.
     * Kept for backwards compatibility in case subclasses override write().
     *
     * @deprecated Will be removed in v3.0. Rate limiting is now atomic.
     */
    private function incrementMessageCount(): void
    {
        // No-op: attemptWithLimit() already increments atomically
    }

    /**
     * Send a test message
     */
    public function sendTestMessage(): bool
    {
        if (empty($this->botToken) || empty($this->chatId)) {
            return false;
        }

        $message = sprintf(
            "%s <b>Test Message</b>\n\n" .
            "Enterprise PSR-3 Logger Telegram integration is working!\n\n" .
            '<code>%s</code>',
            "\xF0\x9F\x94\x94", // 🔔
            date('Y-m-d H:i:s'),
        );

        return $this->sendToTelegram($message);
    }

    /**
     * Get handler configuration (secrets redacted for security)
     */
    public function getConfig(): array
    {
        return [
            'bot_token' => '[REDACTED]',
            'chat_id' => str_repeat('*', max(0, strlen($this->chatId) - 4)) . substr($this->chatId, -4),
            'enabled' => $this->enabled,
            'silent' => $this->silent,
            'rate_limit' => $this->rateLimitPerMinute,
            'min_level' => $this->level->name,
        ];
    }

    /**
     * Reset rate limit state (for long-running processes)
     *
     * Call this method between requests when using PHP in long-running mode
     * (Swoole, RoadRunner, ReactPHP) to prevent rate limit state from
     * bleeding between unrelated requests.
     *
     * Note: If using Redis-backed rate limiting, this only clears local state.
     * Redis state persists across requests (which is usually desired).
     */
    public static function resetRateLimitState(): void
    {
        self::$rateLimiter = null;
    }
}
