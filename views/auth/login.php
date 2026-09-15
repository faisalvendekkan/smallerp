<?php
/** Sign-in form. */

use App\Core\Database;
use App\Core\Migrator;

// On a fresh install the demo account details are worth showing; once real
// users exist the hint disappears.
$showDemoHint = false;
try {
    $showDemoHint = Migrator::isInstalled()
        && (int) Database::value('SELECT COUNT(*) FROM users', [], 0) === 1
        && (int) Database::value('SELECT COUNT(*) FROM sales_invoices', [], 0) > 0;
} catch (\Throwable) {
    // The login page must render even if the database is unhappy.
}
?>
<form method="post" action="<?= url('/login') ?>">
    <?= csrf_field() ?>

    <div class="field mb-2">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" value="<?= old('username') ?>"
               autocomplete="username" autofocus required>
    </div>

    <div class="field mb-2">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
    </div>

    <button class="btn btn--primary btn--block" type="submit"><?= t('action.sign_in') ?></button>

    <?php if ($showDemoHint): ?>
        <p class="auth-card__hint">
            This installation has demo data loaded. Sign in with the administrator
            account you created during setup.
        </p>
    <?php endif; ?>
</form>
