<?php
/**
 * A layout with no navigation, used for login, the installer and error pages
 * shown to users who are not signed in.
 */

use App\Core\Lang;
use App\Core\View;

$flash = View::takeFlash();
?>
<!doctype html>
<html lang="<?= e(Lang::locale()) ?>" dir="<?= e(Lang::dir()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e(($title ?? '') . ' · ' . __('app.name')) ?></title>
    <link rel="stylesheet" href="<?= url('/assets/app.css') ?>">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%230f5c57'/><text x='16' y='22' font-family='sans-serif' font-size='15' font-weight='bold' fill='white' text-anchor='middle'>Q</text></svg>">
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="auth-card__brand">
        <div class="auth-card__mark">Q</div>
        <h1 class="mb-0"><?= t('app.name') ?></h1>
        <p class="muted small mb-0"><?= t('app.tagline') ?></p>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert--<?= e($flash['type']) ?>"><span><?= e($flash['message']) ?></span></div>
    <?php endif; ?>

    <?= $content ?>
</div>
</body>
</html>
