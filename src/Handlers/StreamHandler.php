<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Handlers;

use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Stream Handler
 *
 * Writes logs to any PHP stream (file, stdout, stderr, php://memory, etc.).
 * Enhanced version with better error handling and performance.
 *
 * USAGE:
 * ```php
 * // Write to file
 * $handler = new StreamHandler('/var/log/app.log');
 *
 * // Write to stderr (for containers)
 * $handler = new StreamHandler('php://stderr');
 *
 * // Write to stdout with custom formatter
 * $handler = new StreamHandler('php://stdout', Level::Info);
 * $handler->setFormatter(new PrettyFormatter());
 * ```
 *
 * FEATURES:
 * - Lazy stream opening (opens on first write)
 * - Optional file locking for concurrent writes
 * - Automatic directory creation
 * - Configurable file permissions
 * - Memory-efficient (no buffering by default)
 *
 * LIMITATIONS:
 * - Blocking I/O (use AsyncHandler for non-blocking)
 * - File locking may impact performance under high concurrency
 */
class StreamHandler extends AbstractProcessingHandler implements HandlerInterface
{
    /** @var resource|null */
    protected $stream = null;

    protected string $url;
    protected bool $useLocking;
    protected ?int $filePermission;
    protected string $streamCreationError = '';

    /**
     * @param string $stream Stream URL or file path
     * @param Level $level Minimum log level
     * @param bool $bubble Whether to bubble to next handler
     * @param int|null $filePermission File permissions (null = default)
     * @param bool $useLocking Use file locking on write
     *
     * @throws \InvalidArgumentException If path contains traversal sequences
     */
    public function __construct(
        string $stream,
        Level $level = Level::Debug,
        bool $bubble = true,
        ?int $filePermission = null,
        bool $useLocking = false,
    ) {
        parent::__construct($level, $bubble);

        $this->url = $this->validatePath($stream);
        $this->filePermission = $filePermission;
        $this->useLocking = $useLocking;
    }

    /**
     * Validate path to prevent directory traversal attacks
     *
     * @throws \InvalidArgumentException If path contains traversal
     */
    private function validatePath(string $path): string
    {
        // Check for null bytes
        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Stream path contains null bytes');
        }

        // Allow php:// streams
        if (str_starts_with($path, 'php://')) {
            return $path;
        }

        // Check for directory traversal
        if (preg_match('#(?:^|[\\\\/])\.\.(?:[\\\\/]|$)#', $path)) {
            throw new \InvalidArgumentException('Stream path contains directory traversal sequences');
        }

        return $path;
    }

    /**
     * {@inheritdoc}
     */
    protected function write(LogRecord $record): void
    {
        if ($this->stream === null) {
            if (!$this->openStream()) {
                return;
            }
        }

        if ($this->stream === null || $this->formatter === null) {
            return;
        }

        $formatted = $this->formatter->format($record);

        if ($this->useLocking) {
            flock($this->stream, LOCK_EX);
        }

        fwrite($this->stream, $formatted);

        if ($this->useLocking) {
            flock($this->stream, LOCK_UN);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        if ($this->stream !== null) {
            if (is_resource($this->stream)) {
                fclose($this->stream);
            }
            $this->stream = null;
        }

        parent::close();
    }

    /**
     * Open the stream
     */
    protected function openStream(): bool
    {
        // Create directory if needed
        if (!str_starts_with($this->url, 'php://')) {
            $dir = dirname($this->url);
            if (!is_dir($dir)) {
                $created = @mkdir($dir, 0o755, true);
                if (!$created && !is_dir($dir)) {
                    $this->streamCreationError = "Failed to create directory: {$dir}";
                    error_log("StreamHandler: {$this->streamCreationError}");

                    return false;
                }
            }
        }

        // Open stream
        set_error_handler(function (int $errno, string $errstr): bool {
            $this->streamCreationError = $errstr;

            return true;
        });

        try {
            $opened = fopen($this->url, 'a');
            $this->stream = $opened !== false ? $opened : null;
        } finally {
            restore_error_handler();
        }

        if ($this->stream === null) {
            error_log("StreamHandler: Failed to open {$this->url} - {$this->streamCreationError}");

            return false;
        }

        // Set file permissions
        if ($this->filePermission !== null && !str_starts_with($this->url, 'php://')) {
            @chmod($this->url, $this->filePermission);
        }

        // Disable buffering for real-time output
        stream_set_write_buffer($this->stream, 0);

        return true;
    }

    /**
     * Get the stream URL
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Get the default formatter
     */
    protected function getDefaultFormatter(): FormatterInterface
    {
        return new \AdosLabs\EnterprisePSR3Logger\Formatters\DetailedLineFormatter();
    }
}
