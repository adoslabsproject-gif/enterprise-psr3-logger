<?php

declare(strict_types=1);

namespace Senza1dio\EnterprisePSR3Logger\Handlers;

use Psr\Log\LogLevel;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Monolog\Level;

/**
 * Telegram Handler
 *
 * Sends log messages to a Telegram chat/channel via the Bot API.
 *
 * Features:
 * - Separate minimum level (independent from channel level)
 * - Rate limiting to prevent spam
 * - Message formatting with emojis
 * - HTML parsing for structured messages
 * - Silent mode option
 *
 * @version 1.0.0
 */
final class TelegramHandler extends AbstractProcessingHandler
{
    private string $botToken;
    private string $chatId;
    private bool $enabled;
    private bool $silent;
    private int $rateLimitPerMinute;

    /** @var array<string, int> Rate limit tracking */
    private static array $messageCount = [];
    private static int $lastCleanup = 0;

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
        int $rateLimitPerMinute = 30
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
            sprintf("%s <b>[%s]</b> %s", $emoji, strtoupper($levelName), htmlspecialchars($record->channel)),
            sprintf("<code>%s</code>", $record->datetime->format('Y-m-d H:i:s')),
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
        curl_close($ch);

        return $response !== false && $httpCode === 200;
    }

    /**
     * Check if we're within rate limit
     */
    private function isWithinRateLimit(): bool
    {
        if ($this->rateLimitPerMinute <= 0) {
            return true;
        }

        $this->cleanupOldCounts();

        $currentMinute = date('YmdHi');
        $currentCount = self::$messageCount[$currentMinute] ?? 0;

        return $currentCount < $this->rateLimitPerMinute;
    }

    /**
     * Increment message count for rate limiting
     */
    private function incrementMessageCount(): void
    {
        if ($this->rateLimitPerMinute <= 0) {
            return;
        }

        $currentMinute = date('YmdHi');
        self::$messageCount[$currentMinute] = (self::$messageCount[$currentMinute] ?? 0) + 1;
    }

    /**
     * Clean up old rate limit counts
     */
    private function cleanupOldCounts(): void
    {
        $now = time();

        // Only cleanup every minute
        if ($now - self::$lastCleanup < 60) {
            return;
        }

        self::$lastCleanup = $now;
        $currentMinute = date('YmdHi');

        // Keep only current minute
        foreach (array_keys(self::$messageCount) as $minute) {
            if ($minute !== $currentMinute) {
                unset(self::$messageCount[$minute]);
            }
        }
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
            "<code>%s</code>",
            "\xF0\x9F\x94\x94", // 🔔
            date('Y-m-d H:i:s')
        );

        return $this->sendToTelegram($message);
    }

    /**
     * Get handler configuration
     */
    public function getConfig(): array
    {
        return [
            'bot_token' => substr($this->botToken, 0, 10) . '...',
            'chat_id' => $this->chatId,
            'enabled' => $this->enabled,
            'silent' => $this->silent,
            'rate_limit' => $this->rateLimitPerMinute,
            'min_level' => $this->level->name,
        ];
    }
}
