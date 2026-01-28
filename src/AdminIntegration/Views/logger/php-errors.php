<?php
/**
 * PHP Errors View
 * CSP-compliant - no inline styles
 */
?>

<div class="eap-page">
    <div class="eap-page__header">
        <h1 class="eap-page__title">PHP Errors Log</h1>
        <p class="eap-page__subtitle">View PHP error log: <?= esc($filepath ?? 'N/A') ?></p>
    </div>

    <div class="eap-card">
        <div class="eap-card__header">
            <h2 class="eap-card__title">Error Log</h2>
            <div class="eap-logger-php-errors-badges">
                <?php if ($exists ?? false): ?>
                <span class="eap-badge eap-badge--info"><?= number_format($file_size ?? 0) ?> bytes</span>
                <span class="eap-badge eap-badge--secondary">Modified: <?= $modified ? date('Y-m-d H:i', $modified) : 'N/A' ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="eap-card__body">
            <?php if ($exists ?? false): ?>
            <div class="eap-logger-php-errors-content">
<?= esc($content ?? '') ?>
            </div>

            <form method="POST" action="<?= esc($admin_base_path) ?>/logger/php-errors/clear"
                  class="eap-logger-php-errors-form"
                  id="php-errors-clear-form">
                <?= $csrf_input ?>
                <button type="submit" class="eap-btn eap-btn--danger">Clear Error Log</button>
            </form>
            <?php else: ?>
            <div class="eap-logger-empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <p>No PHP errors log file found</p>
                <p class="eap-logger-empty-state__hint">Configure <code>error_log</code> in php.ini to enable PHP error logging</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
