<?php
/**
 * PHP Errors View
 */
?>

<div class="eap-page">
    <div class="eap-page__header">
        <h1 class="eap-page__title">PHP Errors Log</h1>
        <p class="eap-page__subtitle">View PHP error log: <?= htmlspecialchars($filepath ?? 'N/A') ?></p>
    </div>

    <div class="eap-card">
        <div class="eap-card__header">
            <h2 class="eap-card__title">Error Log</h2>
            <div style="display: flex; gap: 8px;">
                <?php if ($exists ?? false): ?>
                <span class="eap-badge eap-badge--info"><?= number_format($file_size ?? 0) ?> bytes</span>
                <span class="eap-badge eap-badge--secondary">Modified: <?= $modified ? date('Y-m-d H:i', $modified) : 'N/A' ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="eap-card__body">
            <?php if ($exists ?? false): ?>
            <div style="background: #1e1e1e; color: #d4d4d4; padding: 16px; border-radius: 8px; overflow: auto; max-height: 600px; font-family: monospace; font-size: 12px; white-space: pre-wrap;">
<?= htmlspecialchars($content ?? '') ?>
            </div>
            
            <form method="POST" action="<?= htmlspecialchars($admin_base_path) ?>/logger/php-errors/clear" 
                  style="margin-top: 16px;"
                  onsubmit="return confirm('Are you sure you want to clear the PHP errors log?')">
                <?= $csrf_input ?>
                <button type="submit" class="eap-btn eap-btn--danger">Clear Error Log</button>
            </form>
            <?php else: ?>
            <div style="text-align: center; padding: 48px; color: var(--eap-text-muted);">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-bottom: 16px; opacity: 0.5;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <p>No PHP errors log file found</p>
                <p style="font-size: 0.85em;">Configure <code>error_log</code> in php.ini to enable PHP error logging</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
