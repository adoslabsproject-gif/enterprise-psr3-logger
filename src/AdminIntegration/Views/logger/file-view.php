<?php
/**
 * Log File View
 */
$formatBytes = function ($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
};
?>

<div class="eap-page">
    <div class="eap-page__header">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1 class="eap-page__title"><?= htmlspecialchars($filename ?? 'Log File') ?></h1>
                <p class="eap-page__subtitle">
                    <?= $formatBytes($file_size ?? 0) ?> | 
                    <?= number_format($total_lines ?? 0) ?> lines |
                    Modified: <?= date('Y-m-d H:i:s', $modified ?? time()) ?>
                </p>
            </div>
            <div style="display: flex; gap: 8px;">
                <a href="<?= htmlspecialchars($admin_base_path) ?>/logger/file/download?file=<?= urlencode($filename ?? '') ?>" 
                   class="eap-btn eap-btn--secondary">Download</a>
                <a href="<?= htmlspecialchars($admin_base_path) ?>/logger" class="eap-btn eap-btn--secondary">Back</a>
            </div>
        </div>
    </div>

    <div class="eap-card">
        <div class="eap-card__body">
            <div style="background: #1e1e1e; color: #d4d4d4; padding: 16px; border-radius: 8px; overflow: auto; max-height: 700px; font-family: monospace; font-size: 12px; white-space: pre-wrap; line-height: 1.5;">
<?= htmlspecialchars($content ?? '') ?>
            </div>
        </div>
    </div>
</div>
