<?php
/**
 * @var int    $status
 * @var string $message
 */
?>
<div class="card">
    <div class="card__body text-center" style="padding:2.5rem 1.5rem">
        <div style="font-size:2.6rem;font-weight:700;color:var(--text-faint);line-height:1">
            <?= (int) $status ?>
        </div>
        <p class="mt-1 mb-2"><?= e($message) ?></p>
        <a class="btn btn--primary" href="<?= url('/') ?>"><?= t('nav.dashboard') ?></a>
    </div>
</div>
