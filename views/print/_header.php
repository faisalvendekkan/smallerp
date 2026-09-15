<?php
/**
 * Shared bilingual letterhead for every printed document.
 *
 * @var array  $settings
 * @var string $docTitleEn
 * @var string $docTitleAr
 */
?>
<header class="doc__header">
    <div>
        <div class="doc__company-en"><?= e($settings['company_name_en'] ?: 'Your Company') ?></div>
        <?php if ($settings['company_name_ar'] !== ''): ?>
            <div class="doc__company-ar"><?= e($settings['company_name_ar']) ?></div>
        <?php endif; ?>
        <div class="doc__meta" style="margin-top:5px">
            <?php if ($settings['address'] !== ''): ?><?= e($settings['address']) ?><br><?php endif; ?>
            <?php if ($settings['po_box'] !== ''): ?>P.O. Box <?= e($settings['po_box']) ?>, <?php endif; ?>
            <?= e($settings['city'] ?: 'Doha') ?>, <?= e($settings['country'] ?: 'Qatar') ?><br>
            <?php if ($settings['phone'] !== ''): ?>Tel: <?= e($settings['phone']) ?><?php endif; ?>
            <?php if ($settings['email'] !== ''): ?> · <?= e($settings['email']) ?><?php endif; ?>
        </div>
    </div>
    <div class="doc__meta" style="text-align:end;min-width:180px">
        <?php if ($settings['cr_number'] !== ''): ?>
            <div><strong>CR No. / س.ت:</strong> <?= e($settings['cr_number']) ?></div>
        <?php endif; ?>
        <?php if ($settings['establishment_id'] !== ''): ?>
            <div><strong>Est. ID:</strong> <?= e($settings['establishment_id']) ?></div>
        <?php endif; ?>
    </div>
</header>

<div class="doc__title">
    <?= e($docTitleEn) ?>
    <small><?= e($docTitleAr) ?></small>
</div>
