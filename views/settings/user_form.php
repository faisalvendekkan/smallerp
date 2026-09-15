<?php
/** User account form. */

use App\Core\Auth;

$isEdit = $user !== null;
$action = $isEdit ? url('/users/' . $user['id']) : url('/users');
?>
<form method="post" action="<?= e($action) ?>">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card__body">
            <div class="form-grid">
                <div class="field">
                    <label for="name" class="required">Full name</label>
                    <input type="text" id="name" name="name" value="<?= e($user['name'] ?? old('name')) ?>" required>
                </div>
                <div class="field">
                    <label for="username" class="required">Username</label>
                    <input type="text" id="username" name="username"
                           value="<?= e($user['username'] ?? old('username')) ?>"
                           <?= $isEdit ? 'readonly' : 'required' ?>>
                    <?php if ($isEdit): ?>
                        <span class="field__hint">A username cannot be changed once the account exists.</span>
                    <?php endif; ?>
                </div>
                <div class="field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= e($user['email'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="role" class="required">Role</label>
                    <select id="role" name="role" required>
                        <?php foreach ($roles as $key => $meta): ?>
                            <option value="<?= e($key) ?>"
                                <?= ($user['role'] ?? Auth::ROLE_VIEWER) === $key ? 'selected' : '' ?>>
                                <?= e($meta['name_en']) ?> — <?= e($meta['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="locale">Interface language</label>
                    <select id="locale" name="locale">
                        <option value="en" <?= ($user['locale'] ?? 'en') === 'en' ? 'selected' : '' ?>>English</option>
                        <option value="ar" <?= ($user['locale'] ?? '') === 'ar' ? 'selected' : '' ?>>العربية</option>
                    </select>
                </div>
                <div class="field">
                    <label for="password" <?= $isEdit ? '' : 'class="required"' ?>>Password</label>
                    <input type="password" id="password" name="password" autocomplete="new-password"
                           <?= $isEdit ? '' : 'required' ?>>
                    <span class="field__hint">
                        <?= $isEdit ? 'Leave blank to keep the current password. ' : '' ?>
                        At least <?= Auth::MIN_PASSWORD_LENGTH ?> characters, with letters and numbers.
                    </span>
                </div>
            </div>

            <label class="check mt-2">
                <input type="checkbox" name="is_active" value="1"
                    <?= ($user === null || (int) $user['is_active'] === 1) ? 'checked' : '' ?>>
                <span>Active — can sign in</span>
            </label>
        </div>
        <div class="card__foot">
            <button class="btn btn--primary" type="submit"><?= t('action.save') ?></button>
            <a class="btn btn--ghost" href="<?= url('/users') ?>"><?= t('action.cancel') ?></a>
        </div>
    </div>
</form>

<?php if ($isEdit && $grants !== []): ?>
    <div class="card">
        <div class="card__head"><h2 class="card__title">What this role can do</h2></div>
        <div class="card__body">
            <div class="flex flex-wrap">
                <?php foreach ($grants as $grant): ?>
                    <span class="badge badge--muted mono"><?= e($grant) ?></span>
                <?php endforeach; ?>
            </div>
            <p class="tiny faint mt-2 mb-0">
                A grant ending in <code>.*</code> covers every action in that module.
                <code>*</code> means everything.
            </p>
        </div>
    </div>
<?php endif; ?>
