<?php
/** Chart of accounts entry. */

use App\Core\Lang;

$isEdit = $account !== null;
$action = $isEdit ? url('/accounts/' . $account['id']) : url('/accounts');
$hasSystemKey = $isEdit && $account['system_key'];
?>
<form method="post" action="<?= e($action) ?>">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card__body">
            <?php if ($hasSystemKey): ?>
                <div class="alert alert--info">
                    <span class="alert__icon">i</span>
                    <span>
                        This is a system account (<code><?= e($account['system_key']) ?></code>).
                        The posting engine writes to it, so it can be renamed but not
                        archived, and its type cannot be changed.
                    </span>
                </div>
            <?php endif; ?>

            <div class="form-grid">
                <div class="field">
                    <label for="code" class="required">Account code</label>
                    <input type="text" id="code" name="code" class="mono"
                           value="<?= e($account['code'] ?? '') ?>" required>
                    <span class="field__hint">
                        1xxx assets · 2xxx liabilities · 3xxx equity · 4xxx income · 5xxx expenses
                    </span>
                </div>
                <div class="field">
                    <label for="type" class="required">Type</label>
                    <select id="type" name="type" required>
                        <?php foreach ($types as $key => $meta): ?>
                            <option value="<?= e($key) ?>"
                                <?= ($account['type'] ?? '') === $key ? 'selected' : '' ?>>
                                <?= e($meta['name_en']) ?> — increases with a <?= e($meta['normal']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="name_en" class="required">Name (English)</label>
                    <input type="text" id="name_en" name="name_en"
                           value="<?= e($account['name_en'] ?? '') ?>" required>
                </div>
                <div class="field">
                    <label for="name_ar">Name (Arabic)</label>
                    <input type="text" id="name_ar" name="name_ar" dir="rtl"
                           value="<?= e($account['name_ar'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="subtype">Category</label>
                    <input type="text" id="subtype" name="subtype" value="<?= e($account['subtype'] ?? '') ?>"
                           list="subtype-list">
                    <datalist id="subtype-list">
                        <?php foreach (['cash', 'bank', 'receivable', 'payable', 'inventory', 'current_asset',
                                        'fixed_asset', 'current_liability', 'long_term_liability', 'payroll',
                                        'tax', 'capital', 'revenue', 'cogs', 'operating'] as $subtype): ?>
                            <option value="<?= $subtype ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <span class="field__hint">
                        <code>cash</code> and <code>bank</code> accounts appear on the payment forms.
                    </span>
                </div>
                <div class="field field--full">
                    <label for="description"><?= t('field.description') ?></label>
                    <input type="text" id="description" name="description"
                           value="<?= e($account['description'] ?? '') ?>">
                </div>
            </div>

            <label class="check mt-2">
                <input type="checkbox" name="is_active" value="1"
                    <?= ($account === null || (int) $account['is_active'] === 1) ? 'checked' : '' ?>
                    <?= $hasSystemKey ? 'disabled' : '' ?>>
                <span>Active</span>
            </label>
            <?php if ($hasSystemKey): ?>
                <input type="hidden" name="is_active" value="1">
            <?php endif; ?>
        </div>
        <div class="card__foot">
            <button class="btn btn--primary" type="submit"><?= t('action.save') ?></button>
            <a class="btn btn--ghost" href="<?= url('/accounts') ?>"><?= t('action.cancel') ?></a>
        </div>
    </div>
</form>
