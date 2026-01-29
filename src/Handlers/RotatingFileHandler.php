<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Handlers;

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
 * - Path validation (prevents directory traversal)
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
 */
class RotatingFileHandler extends AbstractProcessingHandler implements HandlerInterface
{
    public const ROTATION_DAILY = 'daily';
    public const ROTATION_HOURLY = 'hourly';
    public const ROTATION_NONE = 'none';

    private string $filename;
    private string $baseDir;
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
     *
     * @throws \InvalidArgumentException If filename contains path traversal sequences
     */
    public function __construct(
        string $filename,
        Level $level = Level::Debug,
        string $rotationType = self::ROTATION_DAILY,
        int $maxFiles = 14,
        int $maxFileSize = 0,
        bool $compress = false,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);

        // Validate and sanitize path
        $this->filename = $this->validatePath($filename);
        $this->baseDir = dirname($this->filename);
        $this->rotationType = $rotationType;
        $this->maxFiles = max(0, $maxFiles);
        $this->maxFileSize = max(0, $maxFileSize);
        $this->compress = $compress;
    }

    /**
     * Validate path to prevent directory traversal attacks
     *
     * @throws \InvalidArgumentException If path is invalid or contains traversal
     */
    private function validatePath(string $path): string
    {
        // Check for null bytes (PHP path injection)
        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Path contains null bytes');
        }

        // Check for obvious traversal attempts
        if (preg_match('#(?:^|[\\\\/])\.\.(?:[\\\\/]|$)#', $path)) {
            throw new \InvalidArgumentException('Path contains directory traversal sequences');
        }

        // Normalize path separators
        $path = str_replace('\\', '/', $path);

        // For php:// streams, allow them through
        if (str_starts_with($path, 'php://')) {
            return $path;
        }

        // Get directory and ensure it can be resolved
        $dir = dirname($path);

        // If directory exists, validate with realpath
        if (is_dir($dir)) {
            $realDir = realpath($dir);
            if ($realDir === false) {
                throw new \InvalidArgumentException("Cannot resolve directory: {$dir}");
            }

            return $realDir . '/' . basename($path);
        }

        // Directory doesn't exist yet - validate parent exists
        $parentDir = dirname($dir);
        if ($parentDir !== '.' && $parentDir !== '/' && !is_dir($parentDir)) {
            // Allow creation of nested directories, but validate no traversal
            $normalized = $this->normalizePath($path);
            if ($normalized === null) {
                throw new \InvalidArgumentException("Invalid path: {$path}");
            }

            return $normalized;
        }

        return $path;
    }

    /**
     * Normalize path without requiring it to exist
     */
    private function normalizePath(string $path): ?string
    {
        $parts = explode('/', $path);
        $normalized = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if (empty($normalized)) {
                    return null; // Trying to go above root
                }
                array_pop($normalized);
            } else {
                $normalized[] = $part;
            }
        }

        $result = implode('/', $normalized);

        // Preserve absolute path
        if (str_starts_with($path, '/')) {
            $result = '/' . $result;
        }

        return $result;
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

        if ($this->stream === null || $this->formatter === null) {
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
     *
     * Note: For size-based rotation in multi-process environments,
     * we check actual file size, not just tracked size.
     *
     * TOCTOU Warning: There's an inherent race condition between checking
     * file size and performing rotation. In high-concurrency scenarios,
     * multiple processes may rotate simultaneously. This is mitigated by:
     * 1. Using file locking during writes
     * 2. Using unique timestamp-based rotated filenames
     * 3. Accepting that occasional over-rotation is better than data loss
     */
    private function needsRotation(string $targetFile): bool
    {
        // Date-based rotation (file changed)
        if ($this->currentFilename !== null && $this->currentFilename !== $targetFile) {
            return true;
        }

        // Size-based rotation - check actual file size for multi-process safety
        if ($this->maxFileSize > 0) {
            // Use actual file size if file exists
            if ($this->currentFilename !== null && file_exists($this->currentFilename)) {
                clearstatcache(true, $this->currentFilename);
                $actualSize = @filesize($this->currentFilename);
                if ($actualSize !== false && $actualSize >= $this->maxFileSize) {
                    return true;
                }
            }
            // Also check tracked size as fallback
            if ($this->currentFileSize >= $this->maxFileSize) {
                return true;
            }
        }

        return false;
    }

    /**
     * Perform rotation with file locking to prevent race conditions
     *
     * MULTI-PROCESS SAFETY:
     * 1. Uses dedicated lock file for coordination
     * 2. Non-blocking lock attempt - if another process has lock, we wait
     * 3. Double-check pattern after acquiring lock
     * 4. Atomic rename for size-based rotation to prevent data loss
     *
     * TOCTOU MITIGATION:
     * - File locking ensures only one process rotates at a time
     * - Double-check after lock acquisition catches races
     * - Atomic rename() prevents partial writes during rotation
     */
    private function rotate(): void
    {
        // Close current stream
        if ($this->stream !== null) {
            fclose($this->stream);
            $this->stream = null;
        }

        // Use lock file for multi-process coordination
        $lockFile = $this->baseDir . '/.rotation.lock';
        $lockHandle = @fopen($lockFile, 'c');

        if ($lockHandle !== false) {
            // Try to get exclusive lock (non-blocking first)
            if (flock($lockHandle, LOCK_EX | LOCK_NB)) {
                try {
                    $this->performRotationUnderLock();
                } finally {
                    flock($lockHandle, LOCK_UN);
                    fclose($lockHandle);
                }
            } else {
                // Another process is rotating - wait for lock (blocking)
                // This ensures we don't proceed until rotation is complete
                if (flock($lockHandle, LOCK_EX)) {
                    // Rotation done by other process, just release lock
                    flock($lockHandle, LOCK_UN);
                }
                fclose($lockHandle);
            }
        }

        $this->currentFilename = null;
        $this->currentFileSize = 0;
    }

    /**
     * Perform actual rotation while holding exclusive lock
     */
    private function performRotationUnderLock(): void
    {
        if ($this->currentFilename === null || !file_exists($this->currentFilename)) {
            return;
        }

        clearstatcache(true, $this->currentFilename);
        $size = @filesize($this->currentFilename);

        // For size-based rotation, use atomic rename to timestamped file
        if ($this->maxFileSize > 0 && $size !== false && $size >= $this->maxFileSize) {
            $rotatedName = $this->generateRotatedFilename($this->currentFilename);

            // Atomic rename - prevents data loss during rotation
            if (@rename($this->currentFilename, $rotatedName)) {
                // Compress the rotated file (not the current one)
                if ($this->compress) {
                    $this->compressFile($rotatedName);
                }
            }
        } elseif ($this->compress && $size !== false && $size > 0) {
            // Date-based rotation - compress in place
            $this->compressFile($this->currentFilename);
        }

        // Clean up old files
        if ($this->maxFiles > 0) {
            $this->cleanOldFiles();
        }
    }

    /**
     * Generate unique rotated filename with microsecond precision
     *
     * Format: app-2024-01-15-143052-123456.log
     * This prevents collisions when multiple processes rotate simultaneously.
     */
    private function generateRotatedFilename(string $currentFilename): string
    {
        $info = pathinfo($currentFilename);
        $dir = $info['dirname'] ?? '.';
        $basename = $info['filename'] ?? 'log';
        $extension = isset($info['extension']) ? '.' . $info['extension'] : '';

        // Use microtime for uniqueness
        $timestamp = date('Y-m-d-His') . '-' . substr((string) hrtime(true), -6);

        return "{$dir}/{$basename}-{$timestamp}{$extension}";
    }

    /**
     * Open a stream to the target file
     */
    private function openStream(string $filename): void
    {
        // Ensure directory exists
        $dir = dirname($filename);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $opened = fopen($filename, 'a');
        $this->stream = $opened !== false ? $opened : null;

        if ($this->stream === null) {
            error_log("RotatingFileHandler: Failed to open {$filename}");

            return;
        }

        $this->currentFilename = $filename;
        $this->currentFileSize = filesize($filename) ?: 0;
    }

    /**
     * Compress a file using gzip
     *
     * Uses try-finally to guarantee resource cleanup even on failure.
     */
    private function compressFile(string $filename): void
    {
        // Validate file is within base directory
        $realPath = realpath($filename);
        if ($realPath === false) {
            error_log("RotatingFileHandler: Cannot compress non-existent file: {$filename}");

            return;
        }

        $realBaseDir = realpath($this->baseDir);
        if ($realBaseDir !== false && !str_starts_with($realPath, $realBaseDir)) {
            error_log("RotatingFileHandler: Refusing to compress file outside base directory: {$filename}");

            return;
        }

        $gzFile = $filename . '.gz';
        $source = null;
        $dest = null;
        $success = false;

        try {
            $source = @fopen($filename, 'rb');
            if ($source === false) {
                error_log("RotatingFileHandler: Failed to open source file for compression: {$filename}");

                return;
            }

            $dest = @gzopen($gzFile, 'wb9');
            if ($dest === false) {
                error_log("RotatingFileHandler: Failed to create gzip file: {$gzFile}");

                return;
            }

            while (!feof($source)) {
                $chunk = fread($source, 65536);
                if ($chunk === false) {
                    error_log("RotatingFileHandler: Failed to read chunk during compression: {$filename}");

                    return;
                }
                if (gzwrite($dest, $chunk) === false) {
                    error_log("RotatingFileHandler: Failed to write gzip chunk: {$gzFile}");

                    return;
                }
            }

            $success = true;
        } finally {
            // Always close resources
            if ($source !== null && is_resource($source)) {
                fclose($source);
            }
            if ($dest !== null && is_resource($dest)) {
                gzclose($dest);
            }

            // Only delete original if compression was successful
            if ($success) {
                @unlink($filename);
            } else {
                // Remove partial gzip file on failure
                if (file_exists($gzFile)) {
                    @unlink($gzFile);
                }
            }
        }
    }

    /**
     * Clean up old log files with file locking
     *
     * MULTI-PROCESS SAFETY:
     * Uses a dedicated cleanup lock file to prevent race conditions when
     * multiple processes try to clean up simultaneously. This prevents:
     * 1. Multiple processes deleting the same file
     * 2. Race between glob() and unlink()
     * 3. File count miscalculation due to concurrent deletions
     *
     * Note: This matches both .log and .log.gz files. If compression is enabled,
     * maxFiles applies to the total number of files (logs + compressed).
     * For example, with maxFiles=14 and compression enabled, you might have
     * a mix of recent .log files and older .log.gz files totaling 14.
     */
    private function cleanOldFiles(): void
    {
        $info = pathinfo($this->filename);
        $dir = $info['dirname'] ?? '.';
        $basename = $info['filename'] ?? 'app';

        // Use dedicated cleanup lock file (separate from rotation lock)
        $cleanupLockFile = $this->baseDir . '/.cleanup.lock';
        $lockHandle = @fopen($cleanupLockFile, 'c');

        if ($lockHandle === false) {
            // Cannot acquire lock file - skip cleanup this time
            return;
        }

        // Try non-blocking lock first - if another process is cleaning, skip
        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            return;
        }

        try {
            // Re-check file count AFTER acquiring lock (double-check pattern)
            $pattern = "{$dir}/{$basename}-*";
            $files = glob($pattern);

            if ($files === false || count($files) <= $this->maxFiles) {
                return;
            }

            // Sort by modification time (oldest first)
            // Use @ to suppress warnings if file was deleted between glob and filemtime
            usort($files, function ($a, $b) {
                $timeA = @filemtime($a);
                $timeB = @filemtime($b);
                // Deleted files (false) sort first (will be skipped in deletion)
                if ($timeA === false) return -1;
                if ($timeB === false) return 1;
                return $timeA <=> $timeB;
            });

            // Delete oldest files
            $toDelete = count($files) - $this->maxFiles;
            $deleted = 0;
            for ($i = 0; $i < count($files) && $deleted < $toDelete; $i++) {
                // Check file still exists before unlinking (TOCTOU mitigation)
                if (file_exists($files[$i]) && @unlink($files[$i])) {
                    $deleted++;
                }
            }
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /**
     * Get the default formatter
     */
    protected function getDefaultFormatter(): FormatterInterface
    {
        return new \AdosLabs\EnterprisePSR3Logger\Formatters\LineFormatter();
    }
}
