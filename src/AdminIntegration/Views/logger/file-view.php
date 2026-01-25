<?php
/**
 * Log File Viewer
 *
 * CSP Compliant: No inline styles or scripts
 * Requires: /css/logger.css, /js/logger.js
 *
 * @var string $filename Filename
 * @var string $content File content (last 500 lines)
 * @var int $total_lines Total lines in file
 * @var int $file_size File size in bytes
 * @var int $modified Last modified timestamp
 * @var string $admin_base_path Admin base path
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
        <span class="eap-card__title">$ cat <?= htmlspecialchars($filename) ?></span>
        <div class="eap-card__actions">
            <a href="<?= $admin_base_path ?>/logger/file/download?file=<?= urlencode($filename) ?>"
               class="eap-btn eap-btn--primary eap-btn--sm">
                Download
            </a>
            <a href="<?= $admin_base_path ?>/logger" class="eap-btn eap-btn--ghost eap-btn--sm">
                Back to Dashboard
            </a>
        </div>
    </div>
</div>

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
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7"/>
            </svg>
        </div>
        <div class="eap-stat-card__content">
            <div class="eap-stat-card__value"><?= number_format($total_lines) ?></div>
            <div class="eap-stat-card__label">Total Lines</div>
        </div>
    </div>
    <div class="eap-stat-card">
        <div class="eap-stat-card__icon eap-stat-card__icon--success">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </div>
        <div class="eap-stat-card__content">
            <div class="eap-stat-card__value"><?= date('H:i:s', $modified) ?></div>
            <div class="eap-stat-card__label">Last Modified</div>
        </div>
    </div>
</div>

<!-- Search Bar -->
<div class="eap-card eap-card--compact">
    <div class="eap-card__body">
        <div class="eap-search-bar">
            <input type="text" id="log-search" class="eap-form__input" placeholder="Search in log...">
            <span id="search-results" class="eap-search-results"></span>
        </div>
    </div>
</div>

<!-- Log Content -->
<div class="eap-card">
    <div class="eap-card__header">
        <span class="eap-card__title">$ tail -500 <?= htmlspecialchars($filename) ?></span>
        <div class="eap-card__actions">
            <button type="button" class="eap-btn eap-btn--ghost eap-btn--sm" id="scroll-top">Top</button>
            <button type="button" class="eap-btn eap-btn--ghost eap-btn--sm" id="scroll-bottom">Bottom</button>
            <button type="button" class="eap-btn eap-btn--ghost eap-btn--sm" id="toggle-wrap">Toggle Wrap</button>
        </div>
    </div>
    <div class="eap-card__body eap-card__body--flush">
        <div class="eap-log-viewer" id="log-viewer">
            <pre class="eap-log-content" id="log-content"><?= htmlspecialchars($content) ?></pre>
        </div>
    </div>
</div>

<?php if ($total_lines > 500): ?>
    <div class="eap-notice eap-notice--info">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <span>Showing last 500 of <?= number_format($total_lines) ?> lines. Download the file to view all content.</span>
    </div>
<?php endif; ?>

<!-- Link external CSS and JS (published to /public/modules/psr3-logger/) -->
<link rel="stylesheet" href="/modules/psr3-logger/css/logger.css">
<script src="/modules/psr3-logger/js/logger.js" defer></script>
