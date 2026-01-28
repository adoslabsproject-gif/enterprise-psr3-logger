<?php
/**
 * Log File Viewer with Pagination
 *
 * @var string $filename File name
 * @var bool $exists File exists
 * @var array $lines Parsed log lines
 * @var int $total_lines Total lines in file
 * @var int $page Current page
 * @var int $per_page Lines per page
 * @var int $pages Total pages
 * @var int $file_size File size in bytes
 * @var int $modified File modification timestamp
 * @var string $page_title Page title
 * @var string $admin_base_path Admin base path
 * @var string $csrf_token CSRF token
 */
$formatBytes = function ($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }

    return round($bytes, 2) . ' ' . $units[$i];
};

$getLevelClass = fn ($level) => match ($level) {
    'emergency', 'alert', 'critical', 'error' => 'danger',
    'warning' => 'warning',
    'notice', 'info' => 'info',
    'debug' => 'secondary',
    default => 'secondary',
};

// Build query string for pagination links
$buildUrl = function ($newPage, $newPerPage = null) use ($filename, $page, $per_page, $admin_base_path) {
    $params = [
        'file' => $filename,
        'page' => $newPage,
        'per_page' => $newPerPage ?? $per_page,
    ];

    return $admin_base_path . '/logger/view?' . http_build_query($params);
};
?>

<!-- Page Header -->
<div class="eap-page-header">
    <div class="eap-page-header__content">
        <h1 class="eap-page-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
            </svg>
            <?= esc($filename) ?>
        </h1>
        <?php if ($exists): ?>
        <p class="eap-page-subtitle">
            <?= $formatBytes($file_size) ?> |
            <?= number_format($total_lines) ?> lines |
            Modified: <?= date('Y-m-d H:i:s', $modified) ?>
        </p>
        <?php endif; ?>
    </div>
    <div class="eap-page-header__actions">
        <?php if ($exists): ?>
        <a href="<?= esc($admin_base_path) ?>/logger/file/download?file=<?= urlencode($filename) ?>"
           class="eap-btn eap-btn--secondary">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            Download
        </a>
        <?php endif; ?>
        <a href="<?= esc($admin_base_path) ?>/logger" class="eap-btn eap-btn--ghost">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="19" y1="12" x2="5" y2="12"/>
                <polyline points="12 19 5 12 12 5"/>
            </svg>
            Back to Dashboard
        </a>
    </div>
</div>

<?php if (!$exists): ?>
<!-- File Not Found -->
<div class="eap-card">
    <div class="eap-card__body">
        <div class="eap-logger-empty-state">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <p>File not found</p>
            <p class="eap-logger-empty-state__hint">The log file "<?= esc($filename) ?>" does not exist.</p>
        </div>
    </div>
</div>
<?php else: ?>

<!-- Controls Bar -->
<div class="eap-logger-viewer-controls">
    <div class="eap-logger-viewer-controls__left">
        <span class="eap-logger-viewer-controls__info">
            Showing <?= number_format(($page - 1) * $per_page + 1) ?> - <?= number_format(min($page * $per_page, $total_lines)) ?> of <?= number_format($total_lines) ?> lines
        </span>
    </div>
    <div class="eap-logger-viewer-controls__right">
        <label class="eap-logger-viewer-controls__label">Lines per page:</label>
        <select class="eap-logger-viewer-controls__select" id="per-page-select">
            <option value="50" <?= $per_page === 50 ? 'selected' : '' ?>>50</option>
            <option value="100" <?= $per_page === 100 ? 'selected' : '' ?>>100</option>
            <option value="200" <?= $per_page === 200 ? 'selected' : '' ?>>200</option>
        </select>
    </div>
</div>

<!-- Log Content -->
<div class="eap-card eap-logger-viewer">
    <div class="eap-card__body eap-logger-viewer__body">
        <?php if (empty($lines)): ?>
        <div class="eap-logger-empty-state">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
            </svg>
            <p>Log file is empty</p>
        </div>
        <?php else: ?>
        <div class="eap-logger-entries">
            <?php foreach ($lines as $index => $line): ?>
            <div class="eap-logger-entry eap-logger-entry--<?= $getLevelClass($line['level']) ?>">
                <?php
                // Separate metadata (pid, mem, req) from actual context data
                $metadata = [];
                $contextData = [];
                if (!empty($line['details'])) {
                    foreach ($line['details'] as $detail) {
                        // Check if it's metadata (pid:xxx, mem:xxx, req:xxx)
                        if (preg_match('/^(pid|mem|req|duration):/', $detail)) {
                            $metadata[] = $detail;
                        } else {
                            $contextData[] = $detail;
                        }
                    }
                }
                ?>
                <!-- Header -->
                <div class="eap-logger-entry__header">
                    <span class="eap-logger-entry__number">#<?= number_format(($page - 1) * $per_page + $index + 1) ?></span>
                    <?php if ($line['timestamp']): ?>
                    <span class="eap-logger-entry__time"><?= esc($line['timestamp']) ?></span>
                    <?php endif; ?>
                    <?php if (isset($line['channel'])): ?>
                    <span class="eap-logger-entry__channel"><?= esc($line['channel']) ?></span>
                    <?php endif; ?>
                    <span class="eap-badge eap-badge--<?= $getLevelClass($line['level']) ?> eap-logger-entry__level">
                        <?= strtoupper($line['level']) ?>
                    </span>
                    <?php foreach ($metadata as $meta): ?>
                    <span class="eap-logger-entry__meta"><?= esc($meta) ?></span>
                    <?php endforeach; ?>
                </div>

                <!-- Body -->
                <div class="eap-logger-entry__body">
                    <?php if (!empty($line['message'])): ?>
                    <div class="eap-logger-entry__message"><?= esc($line['message']) ?></div>
                    <?php endif; ?>

                    <?php
                    // Combine context data for inline display (metadata already shown in header)
                    $allContext = $contextData; // Already filtered above

                // Add context if it's not already in details
                if ($line['context'] && empty($contextData)) {
                    // Try to decode JSON context
                    $jsonContextData = json_decode($line['context'], true);
                    if ($jsonContextData !== null && is_array($jsonContextData)) {
                        // Format as key=value pairs
                        foreach ($jsonContextData as $key => $value) {
                            if (is_scalar($value)) {
                                $allContext[] = $key . '=' . $value;
                            } elseif (is_array($value)) {
                                $allContext[] = $key . '=' . json_encode($value, JSON_UNESCAPED_SLASHES);
                            } else {
                                $allContext[] = $key . '=' . (string) $value;
                            }
                        }
                    } else {
                        // Plain text context - split by newlines or show as-is
                        $contextLines = preg_split('/\r?\n/', $line['context']);
                        foreach ($contextLines as $ctxLine) {
                            if (trim($ctxLine) !== '') {
                                $allContext[] = trim($ctxLine);
                            }
                        }
                    }
                }
                ?>

                    <?php if (!empty($allContext)): ?>
                    <div class="eap-logger-entry__context-data">
                        <?php foreach ($allContext as $ctxItem):
                            // Check if it's a stack trace line
                            $isStack = str_starts_with(trim($ctxItem), '#') || str_contains($ctxItem, '->') || str_contains($ctxItem, '::');
                            // Check if it's an exception line
                            $isException = str_contains($ctxItem, 'Exception') || str_contains($ctxItem, 'Error in ');
                            $class = 'eap-logger-entry__context-line';
                            if ($isStack) {
                                $class .= ' eap-logger-entry__context-line--stack';
                            }
                            if ($isException) {
                                $class .= ' eap-logger-entry__context-line--exception';
                            }
                            ?>
                        <div class="<?= $class ?>"><?= esc($ctxItem) ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<div class="eap-logger-pagination">
    <div class="eap-logger-pagination__info">
        Page <?= $page ?> of <?= $pages ?>
    </div>
    <div class="eap-logger-pagination__links">
        <!-- First -->
        <?php if ($page > 1): ?>
        <a href="<?= $buildUrl(1) ?>" class="eap-logger-pagination__link" title="First page">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="11 17 6 12 11 7"/>
                <polyline points="18 17 13 12 18 7"/>
            </svg>
        </a>
        <!-- Prev -->
        <a href="<?= $buildUrl($page - 1) ?>" class="eap-logger-pagination__link" title="Previous page">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </a>
        <?php endif; ?>

        <!-- Page Numbers -->
        <?php
        $start = max(1, $page - 2);
    $end = min($pages, $page + 2);

    if ($start > 1): ?>
        <span class="eap-logger-pagination__ellipsis">...</span>
        <?php endif;

    for ($i = $start; $i <= $end; $i++): ?>
        <a href="<?= $buildUrl($i) ?>"
           class="eap-logger-pagination__link <?= $i === $page ? 'eap-logger-pagination__link--active' : '' ?>">
            <?= $i ?>
        </a>
        <?php endfor;

    if ($end < $pages): ?>
        <span class="eap-logger-pagination__ellipsis">...</span>
        <?php endif; ?>

        <!-- Next -->
        <?php if ($page < $pages): ?>
        <a href="<?= $buildUrl($page + 1) ?>" class="eap-logger-pagination__link" title="Next page">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="9 18 15 12 9 6"/>
            </svg>
        </a>
        <!-- Last -->
        <a href="<?= $buildUrl($pages) ?>" class="eap-logger-pagination__link" title="Last page">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="13 17 18 12 13 7"/>
                <polyline points="6 17 11 12 6 7"/>
            </svg>
        </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- JS handlers in /module-assets/enterprise-psr3-logger/js/logger.js -->
<?php endif; ?>
