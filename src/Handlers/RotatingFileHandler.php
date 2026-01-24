<?php

declare(strict_types=1);

namespace Senza1dio\EnterprisePSR3Logger\Handlers;

use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Rotating File Handler
 *
 * Writes logs to files with automatic rotation based on date or size.
 *
 * FEATURES:
 * - Date-based rotation (daily, hourly, etc.)
 * - Size-based rotation
 * - Automatic file retention (delete old files)
 * - Gzip compression of rotated files
 * - Atomic writes (via file locking)
 *
 * FILE NAMING:
 * - app.log (current)
 * - app-2024-01-15.log (rotated by date)
 * - app-2024-01-15.log.gz (compressed)
 *
 * LIMITATIONS:
 * - Rotation happens on write, not on schedule
 * - Size check is approximate (checked before write)
 * - No multi-process coordination beyond file locking
 *
 * @package Senza1dio\EnterprisePSR3Logger\Handlers
 */
class RotatingFileHandler extends AbstractProcessingHandler implements HandlerInterface
{
    public const ROTATION_DAILY = 'daily';
    public const ROTATION_HOURLY = 'hourly';
    public const ROTATION_NONE = 'none';

    private string $filename;
    private string $rotationType;
    private int $maxFiles;
    private int $maxFileSize;
    private bool $compress;

    /** @var resource|null */
    private $stream = null;

    private ?string $currentFilename = null;
    private int $currentFileSize = 0;

    /**
     * @param string $filename Base filename (e.g., /var/log/app.log)
     * @param Level $level Minimum log level
     * @param string $rotationType Rotation type (daily, hourly, none)
     * @param int $maxFiles Maximum number of files to keep (0 = unlimited)
     * @param int $maxFileSize Maximum file size in bytes before rotation (0 = size-based rotation disabled)
     * @param bool $compress Compress rotated files with gzip
     * @param bool $bubble Whether to bubble to next handler
     */
    public function __construct(
        string $filename,
        Level $level = Level::Debug,
        string $rotationType = self::ROTATION_DAILY,
        int $maxFiles = 14,
        int $maxFileSize = 0,
        bool $compress = false,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);

        $this->filename = $filename;
        $this->rotationType = $rotationType;
        $this->maxFiles = max(0, $maxFiles);
        $this->maxFileSize = max(0, $maxFileSize);
        $this->compress = $compress;
    }

    /**
     * {@inheritdoc}
     */
    protected function write(LogRecord $record): void
    {
        $targetFile = $this->getTargetFilename();

        // Check if we need to rotate
        if ($this->needsRotation($targetFile)) {
            $this->rotate();
        }

        // Open stream if needed
        if ($this->stream === null || $this->currentFilename !== $targetFile) {
            $this->openStream($targetFile);
        }

        if ($this->stream === null) {
            return;
        }

        $formatted = $this->formatter->format($record);

        // Write with file locking for atomicity
        flock($this->stream, LOCK_EX);
        fwrite($this->stream, $formatted);
        fflush($this->stream);
        flock($this->stream, LOCK_UN);

        $this->currentFileSize += strlen($formatted);
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        if ($this->stream !== null) {
            fclose($this->stream);
            $this->stream = null;
        }

        parent::close();
    }

    /**
     * Get the target filename based on rotation type
     */
    private function getTargetFilename(): string
    {
        if ($this->rotationType === self::ROTATION_NONE) {
            return $this->filename;
        }

        $info = pathinfo($this->filename);
        $dir = $info['dirname'] ?? '.';
        $basename = $info['filename'] ?? 'app';
        $extension = isset($info['extension']) ? '.' . $info['extension'] : '';

        $dateSuffix = match ($this->rotationType) {
            self::ROTATION_HOURLY => date('Y-m-d-H'),
            self::ROTATION_DAILY => date('Y-m-d'),
            default => '',
        };

        if ($dateSuffix !== '') {
            return "{$dir}/{$basename}-{$dateSuffix}{$extension}";
        }

        return $this->filename;
    }

    /**
     * Check if rotation is needed
     */
    private function needsRotation(string $targetFile): bool
    {
        // Size-based rotation
        if ($this->maxFileSize > 0 && $this->currentFileSize >= $this->maxFileSize) {
            return true;
        }

        // Date-based rotation (file changed)
        if ($this->currentFilename !== null && $this->currentFilename !== $targetFile) {
            return true;
        }

        return false;
    }

    /**
     * Perform rotation
     */
    private function rotate(): void
    {
        // Close current stream
        if ($this->stream !== null) {
            fclose($this->stream);
            $this->stream = null;
        }

        // Compress old file if enabled
        if ($this->compress && $this->currentFilename !== null && file_exists($this->currentFilename)) {
            $this->compressFile($this->currentFilename);
        }

        // Clean up old files
        if ($this->maxFiles > 0) {
            $this->cleanOldFiles();
        }

        $this->currentFilename = null;
        $this->currentFileSize = 0;
    }

    /**
     * Open a stream to the target file
     */
    private function openStream(string $filename): void
    {
        // Ensure directory exists
        $dir = dirname($filename);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->stream = fopen($filename, 'a');

        if ($this->stream === false) {
            $this->stream = null;
            error_log("RotatingFileHandler: Failed to open {$filename}");
            return;
        }

        $this->currentFilename = $filename;
        $this->currentFileSize = filesize($filename) ?: 0;
    }

    /**
     * Compress a file using gzip
     */
    private function compressFile(string $filename): void
    {
        $gzFile = $filename . '.gz';

        $source = fopen($filename, 'rb');
        if ($source === false) {
            return;
        }

        $dest = gzopen($gzFile, 'wb9');
        if ($dest === false) {
            fclose($source);
            return;
        }

        while (!feof($source)) {
            $chunk = fread($source, 65536);
            if ($chunk !== false) {
                gzwrite($dest, $chunk);
            }
        }

        fclose($source);
        gzclose($dest);

        // Remove original file after successful compression
        unlink($filename);
    }

    /**
     * Clean up old log files
     */
    private function cleanOldFiles(): void
    {
        $info = pathinfo($this->filename);
        $dir = $info['dirname'] ?? '.';
        $basename = $info['filename'] ?? 'app';
        $extension = isset($info['extension']) ? $info['extension'] : 'log';

        // Find matching files
        $pattern = "{$dir}/{$basename}-*";
        $files = glob($pattern);

        if ($files === false || count($files) <= $this->maxFiles) {
            return;
        }

        // Sort by modification time (oldest first)
        usort($files, fn($a, $b) => filemtime($a) <=> filemtime($b));

        // Delete oldest files
        $toDelete = count($files) - $this->maxFiles;
        for ($i = 0; $i < $toDelete; $i++) {
            @unlink($files[$i]);
        }
    }

    /**
     * Get the default formatter
     */
    protected function getDefaultFormatter(): FormatterInterface
    {
        return new \Senza1dio\EnterprisePSR3Logger\Formatters\LineFormatter();
    }
}
