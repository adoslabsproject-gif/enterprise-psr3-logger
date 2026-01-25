<?php
/**
 * PHP Errors Log Viewer
 *
 * CSP Compliant: No inline styles or scripts
 * Requires: /css/logger.css, /js/logger.js
 *
 * @var bool $exists Whether the file exists
 * @var string $content File content
 * @var int $file_size File size in bytes
 * @var int|null $modified Last modified timestamp
 * @var string $filepath Path to php errors file
 * @var string $admin_base_path Admin base path
 * @var string $csrf_input CSRF hidden input
 */

// Helper function for formatting bytes
if (!function_exists('formatBytes')) {
    function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
?>

<!-- File Info -->
<div class="eap-card">
    <div class="eap-card__header">
        <span class="eap-card__title">$ cat <?= htmlspecialchars($filepath) ?></span>
        <div class="eap-card__actions">
            <?php if ($exists && !empty($content)): ?>
                <form method="POST" action="<?= $admin_base_path ?>/logger/php-errors/clear" class="eap-inline-form">
                    <?= $csrf_input ?>
                    <button type="submit" class="eap-btn eap-btn--danger eap-btn--sm" data-confirm="Clear all PHP errors? This cannot be undone.">
                        Clear Log
                    </button>
                </form>
            <?php endif; ?>
            <a href="<?= $admin_base_path ?>/logger" class="eap-btn eap-btn--ghost eap-btn--sm">Back to Dashboard</a>
        </div>
    </div>
</div>

<?php if (!$exists): ?>
    <!-- File Not Found -->
    <div class="eap-card">
        <div class="eap-card__body">
            <div class="eap-empty-state">
                <div class="eap-empty-state__icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="48" height="48">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <h3 class="eap-text--success">No PHP Errors Log File</h3>
                <p class="eap-text--muted">The PHP errors log file does not exist yet.</p>
                <p class="eap-text--muted">File path: <code class="eap-code"><?= htmlspecialchars($filepath) ?></code></p>
            </div>
        </div>
    </div>
<?php elseif (empty(trim($content))): ?>
    <!-- File Empty -->
    <div class="eap-card">
        <div class="eap-card__body">
            <div class="eap-empty-state">
                <div class="eap-empty-state__icon eap-empty-state__icon--success">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="48" height="48">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <h3 class="eap-text--success">No PHP Errors!</h3>
                <p class="eap-text--muted">The PHP errors log is empty. Your application is running cleanly.</p>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- File Stats -->
    <div class="eap-stats-row">
        <div class="eap-stat-card">
            <div class="eap-stat-card__icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <div class="eap-stat-card__content">
                <div class="eap-stat-card__value"><?= formatBytes($file_size) ?></div>
                <div class="eap-stat-card__label">File Size</div>
            </div>
        </div>
        <div class="eap-stat-card">
            <div class="eap-stat-card__icon eap-stat-card__icon--info">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div class="eap-stat-card__content">
                <div class="eap-stat-card__value"><?= $modified ? date('H:i:s', $modified) : 'N/A' ?></div>
                <div class="eap-stat-card__label">Last Modified</div>
            </div>
        </div>
        <div class="eap-stat-card eap-stat-card--danger">
            <div class="eap-stat-card__icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <div class="eap-stat-card__content">
                <div class="eap-stat-card__value"><?= substr_count($content, '[') ?></div>
                <div class="eap-stat-card__label">~Error Entries</div>
            </div>
        </div>
    </div>

    <!-- Log Content -->
    <div class="eap-card">
        <div class="eap-card__header">
            <span class="eap-card__title">$ tail -1000 <?= htmlspecialchars($filepath) ?></span>
            <div class="eap-card__actions">
                <button type="button" class="eap-btn eap-btn--ghost eap-btn--sm" id="scroll-bottom">
                    Scroll to Bottom
                </button>
            </div>
        </div>
        <div class="eap-card__body eap-card__body--flush">
            <div class="eap-php-errors-viewer" id="errors-viewer">
                <?php
                // Parse and colorize the errors
                $lines = explode("\n", $content);
                foreach ($lines as $line):
                    $line = trim($line);
                    if (empty($line)) continue;

                    // Detect error type for coloring
                    $class = 'eap-error-line';
                    if (stripos($line, 'fatal') !== false) {
                        $class .= ' eap-error-line--fatal';
                    } elseif (stripos($line, 'error') !== false) {
                        $class .= ' eap-error-line--error';
                    } elseif (stripos($line, 'warning') !== false) {
                        $class .= ' eap-error-line--warning';
                    } elseif (stripos($line, 'notice') !== false) {
                        $class .= ' eap-error-line--notice';
                    } elseif (stripos($line, 'deprecated') !== false) {
                        $class .= ' eap-error-line--deprecated';
                    } elseif (stripos($line, 'stack trace') !== false || preg_match('/^#\d+/', $line)) {
                        $class .= ' eap-error-line--trace';
                    }
                ?>
                    <div class="<?= $class ?>"><?= htmlspecialchars($line) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Link external CSS and JS (published to /public/modules/psr3-logger/) -->
<link rel="stylesheet" href="/modules/psr3-logger/css/logger.css">
<script src="/modules/psr3-logger/js/logger.js" defer></script>
