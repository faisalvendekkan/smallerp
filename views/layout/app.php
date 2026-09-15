<?php
/**
 * The signed-in application shell: sidebar, top bar and flash messages.
 *
 * @var string $content Rendered page body.
 * @var string $title   Page title.
 */

use App\Core\Auth;
use App\Core\Lang;
use App\Core\View;
use App\Services\Settings;

$user = Auth::user();
$flash = View::takeFlash();
$company = Settings::companyName(Lang::locale());
$path = $currentPath ?? '';
$alerts = $navAlerts ?? [];
?>
<!doctype html>
<html lang="<?= e(Lang::locale()) ?>" dir="<?= e(Lang::dir()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e(($title ?? __('nav.dashboard')) . ' · ' . $company) ?></title>
    <link rel="stylesheet" href="<?= url('/assets/app.css') ?>">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%230f5c57'/><text x='16' y='22' font-family='sans-serif' font-size='15' font-weight='bold' fill='white' text-anchor='middle'>Q</text></svg>">
</head>
<body>
<div class="shell">
    <?php
    /**
     * The navigation is declared as data so that permissions, the active
     * highlight and the alert badges are all handled in one place.
     */
    $nav = [
        'nav.main' => [
            ['/', 'nav.dashboard', '▦', 'dashboard.view'],
        ],
        'nav.sales' => [
            ['/quotations', 'nav.quotations', '◳', 'sales.view'],
            ['/invoices', 'nav.invoices', '▤', 'sales.view'],
            ['/receipts', 'nav.receipts', '↓', 'payments.view'],
            ['/customers', 'nav.customers', '◍', 'contacts.view'],
        ],
        'nav.purchases' => [
            ['/bills', 'nav.bills', '▥', 'purchases.view'],
            ['/payments', 'nav.payments', '↑', 'payments.view'],
            ['/suppliers', 'nav.suppliers', '◎', 'contacts.view'],
        ],
        'nav.inventory' => [
            ['/items', 'nav.items', '◰', 'items.view'],
            ['/stock', 'nav.stock', '▧', 'inventory.view'],
        ],
        'nav.people' => [
            ['/employees', 'nav.employees', '☗', 'hr.view'],
            ['/payroll', 'nav.payroll', '▦', 'payroll.view'],
            ['/leave', 'nav.leave', '◷', 'hr.view'],
            ['/gratuity', 'nav.gratuity', '◈', 'hr.view'],
        ],
        'nav.accounting' => [
            ['/accounts', 'nav.accounts', '☰', 'accounting.view'],
            ['/journals', 'nav.journals', '▨', 'accounting.view'],
        ],
        'nav.reports' => [
            ['/reports', 'nav.reports', '◱', 'reports.view'],
        ],
        'nav.admin' => [
            ['/settings', 'nav.settings', '⚙', 'settings.view'],
            ['/users', 'nav.users', '◑', 'users.view'],
            ['/audit', 'nav.audit', '◴', 'audit.view'],
        ],
    ];
    ?>
    <aside class="sidebar">
        <a class="sidebar__brand" href="<?= url('/') ?>">
            <span class="sidebar__mark">Q</span>
            <span>
                <?= t('app.name') ?>
                <span class="sidebar__company"><?= e(\App\Support\Text::truncate($company, 26)) ?></span>
            </span>
        </a>

        <nav class="nav">
            <?php foreach ($nav as $section => $links): ?>
                <?php
                $visible = array_filter($links, static fn (array $l): bool => can($l[3]));
                if ($visible === []) {
                    continue;
                }
                ?>
                <div class="nav__section"><?= t($section) ?></div>
                <?php foreach ($visible as [$href, $label, $icon, $permission]): ?>
                    <?php
                    // Highlight the deepest matching link, so /invoices/12 still
                    // lights up Invoices.
                    $active = $href === '/'
                        ? $path === '/'
                        : str_starts_with($path, $href);
                    $badge = $alerts[$href] ?? 0;
                    ?>
                    <a class="nav__link<?= $active ? ' nav__link--active' : '' ?>" href="<?= url($href) ?>">
                        <span class="nav__icon" aria-hidden="true"><?= $icon ?></span>
                        <span><?= t($label) ?></span>
                        <?php if ($badge > 0): ?>
                            <span class="nav__badge"><?= (int) $badge ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>
    </aside>

    <div class="scrim"></div>

    <div class="main">
        <header class="topbar">
            <button class="menu-toggle" data-menu-toggle type="button" aria-label="Menu">☰</button>
            <h1 class="topbar__title"><?= e($title ?? __('nav.dashboard')) ?></h1>
            <span class="topbar__spacer"></span>

            <form method="post" action="<?= url('/locale') ?>" class="no-print">
                <?= csrf_field() ?>
                <input type="hidden" name="locale" value="<?= Lang::isRtl() ? 'en' : 'ar' ?>">
                <button class="btn btn--sm btn--ghost" type="submit">
                    <?= Lang::isRtl() ? 'English' : 'العربية' ?>
                </button>
            </form>

            <span class="small muted nowrap" title="<?= e(Auth::ROLES[$user['role']]['name_en'] ?? '') ?>">
                <?= e($user['name']) ?>
            </span>

            <form method="post" action="<?= url('/logout') ?>" class="no-print">
                <?= csrf_field() ?>
                <button class="btn btn--sm" type="submit"><?= t('action.sign_out') ?></button>
            </form>
        </header>

        <main class="content">
            <?php if ($flash): ?>
                <div class="alert alert--<?= e($flash['type']) ?>">
                    <span class="alert__icon" aria-hidden="true"><?php
                        echo match ($flash['type']) {
                            'error' => '✕',
                            'warning' => '!',
                            'info' => 'i',
                            default => '✓',
                        };
                    ?></span>
                    <span><?= e($flash['message']) ?></span>
                </div>
            <?php endif; ?>

            <?= $content ?>
        </main>
    </div>
</div>
<script src="<?= url('/assets/app.js') ?>"></script>
</body>
</html>
