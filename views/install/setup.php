<?php
/**
 * First-run installer.
 *
 * @var array  $checks
 * @var string $driver
 */
$blocked = false;
foreach ($checks as $check) {
    if (!$check['ok'] && $check['required']) {
        $blocked = true;
    }
}
?>
<h2 class="mb-1">Set up SmallERP</h2>
<p class="muted small">
    Create the administrator account and SmallERP will build its database.
    Database driver: <strong><?= e($driver) ?></strong>.
</p>

<div class="card mb-2">
    <div class="card__body" style="padding:.6rem .8rem">
        <?php foreach ($checks as $check): ?>
            <div class="flex small" style="padding:.18rem 0">
                <span class="<?= $check['ok'] ? 'text-ok' : ($check['required'] ? 'text-bad' : 'text-warn') ?>">
                    <?= $check['ok'] ? '✓' : ($check['required'] ? '✕' : '!') ?>
                </span>
                <span><?= e($check['label']) ?></span>
            </div>
            <?php if (!$check['ok']): ?>
                <div class="tiny faint" style="margin-inline-start:1.2rem"><?= e($check['detail']) ?></div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($blocked): ?>
    <div class="alert alert--error">
        <span>Fix the items marked above, then reload this page.</span>
    </div>
<?php endif; ?>

<form method="post" action="<?= url('/install') ?>">
    <?= csrf_field() ?>

    <div class="field mb-1">
        <label for="name" class="required">Your full name</label>
        <input type="text" id="name" name="name" value="<?= old('name') ?>" required>
    </div>

    <div class="field mb-1">
        <label for="username" class="required">Username</label>
        <input type="text" id="username" name="username" value="<?= old('username', 'admin') ?>"
               autocomplete="username" required>
    </div>

    <div class="field mb-1">
        <label for="email">Email (optional)</label>
        <input type="email" id="email" name="email" value="<?= old('email') ?>">
    </div>

    <div class="field mb-1">
        <label for="password" class="required">Password</label>
        <input type="password" id="password" name="password" autocomplete="new-password" required>
        <span class="field__hint">At least 10 characters, with letters and numbers.</span>
    </div>

    <div class="field mb-2">
        <label for="password_confirmation" class="required">Confirm password</label>
        <input type="password" id="password_confirmation" name="password_confirmation"
               autocomplete="new-password" required>
    </div>

    <label class="check mb-2">
        <input type="checkbox" name="demo_data" value="1">
        <span>Load demo data — a sample Doha trading company with a quarter of
              trading, six employees and a completed payroll run, so you can see
              how everything fits together. Leave this off for a real company.</span>
    </label>

    <button class="btn btn--primary btn--block" type="submit" <?= $blocked ? 'disabled' : '' ?>>
        Install SmallERP
    </button>
</form>
