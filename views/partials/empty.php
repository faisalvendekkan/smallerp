<?php
/**
 * The "nothing here" state for a table.
 *
 * @var string      $message
 * @var string|null $actionUrl
 * @var string|null $actionLabel
 */
?>
<div class="table-empty">
    <span class="table-empty__icon" aria-hidden="true">◯</span>
    <p class="mb-1"><?= e($message ?? __('empty.none')) ?></p>
    <?php if (!empty($actionUrl) && !empty($actionLabel)): ?>
        <a class="btn btn--primary btn--sm" href="<?= e($actionUrl) ?>"><?= e($actionLabel) ?></a>
    <?php endif; ?>
</div>
