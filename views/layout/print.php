<?php
/**
 * Layout for printable documents: invoices, payslips, statements.
 *
 * Bilingual by design -- a Qatari SME's customers, its bank and the ministries
 * all expect Arabic alongside English on a formal document.
 */

use App\Core\Lang;
?>
<!doctype html>
<html lang="<?= e(Lang::locale()) ?>" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Document') ?></title>
    <link rel="stylesheet" href="<?= url('/assets/app.css') ?>">
</head>
<body>
<div class="print-bar no-print">
    <a class="btn" href="<?= e($backUrl ?? url('/')) ?>">← <?= t('action.back') ?></a>
    <button class="btn btn--primary" type="button" onclick="window.print()"><?= t('action.print') ?></button>
</div>
<?= $content ?>
</body>
</html>
