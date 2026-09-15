<?php
/** Supplier bill form. */

use App\Core\Lang;

$isEdit = $bill !== null;
$action = $isEdit ? url('/bills/' . $bill['id']) : url('/bills');
?>
<form method="post" action="<?= e($action) ?>" data-dirty-guard>
    <?= csrf_field() ?>

    <div class="card">
        <div class="card__head">
            <h2 class="card__title"><?= $isEdit ? e($bill['number']) : e($nextNumber) ?></h2>
            <?php if (!$isEdit): ?><span class="badge badge--muted">draft</span><?php endif; ?>
        </div>
        <div class="card__body">
            <div class="form-grid">
                <div class="field">
                    <label for="contact_id" class="required"><?= t('field.supplier') ?></label>
                    <select id="contact_id" name="contact_id" required>
                        <option value="">— choose —</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?= (int) $supplier['id'] ?>"
                                <?= (int) ($bill['contact_id'] ?? 0) === (int) $supplier['id'] ? 'selected' : '' ?>>
                                <?= e($supplier['code'] . ' · ' . Lang::pick($supplier, 'name')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field__hint">
                        <a href="<?= url('/contacts/new', ['kind' => 'supplier']) ?>">Add a new supplier</a>
                    </span>
                </div>

                <div class="field">
                    <label for="supplier_invoice_no">Supplier's invoice number</label>
                    <input type="text" id="supplier_invoice_no" name="supplier_invoice_no"
                           value="<?= e($bill['supplier_invoice_no'] ?? '') ?>">
                    <span class="field__hint">Checked for duplicates, so the same bill is not paid twice.</span>
                </div>

                <div class="field">
                    <label for="issue_date" class="required"><?= t('field.date') ?></label>
                    <input type="date" id="issue_date" name="issue_date"
                           value="<?= e($bill['issue_date'] ?? date('Y-m-d')) ?>" required>
                </div>

                <div class="field">
                    <label for="due_date"><?= t('field.due_date') ?></label>
                    <input type="date" id="due_date" name="due_date" value="<?= e($bill['due_date'] ?? '') ?>">
                </div>

                <?php if (count($warehouses) > 1): ?>
                    <div class="field">
                        <label for="warehouse_id">Receive into</label>
                        <select id="warehouse_id" name="warehouse_id">
                            <?php foreach ($warehouses as $warehouse): ?>
                                <option value="<?= (int) $warehouse['id'] ?>"
                                    <?= (int) ($bill['warehouse_id'] ?? 0) === (int) $warehouse['id'] ? 'selected' : '' ?>>
                                    <?= e($warehouse['code'] . ' · ' . Lang::pick($warehouse, 'name')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?= App\Core\View::partial('partials/lines_editor', [
        'lines' => $lines,
        'items' => $items,
        'itemsJson' => $itemsJson,
        'expenseAccounts' => $expenseAccounts,
        'taxEnabled' => $taxEnabled,
        'defaultTaxRate' => $defaultTaxRate,
        'pricesIncludeTax' => $pricesIncludeTax,
        'priceField' => 'cost_price',
    ]) ?>

    <p class="tiny faint">
        Pick an <strong>item</strong> for goods you stock, or an
        <strong>account</strong> for an expense such as rent, Kahramaa or fuel.
    </p>

    <div class="card">
        <div class="card__body">
            <div class="field">
                <label for="notes"><?= t('field.notes') ?></label>
                <textarea id="notes" name="notes" rows="2"><?= e($bill['notes'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="card__foot">
            <button class="btn" type="submit" name="post_now" value="0"><?= t('action.save_draft') ?></button>
            <?php if (can('purchases.post')): ?>
                <button class="btn btn--primary" type="submit" name="post_now" value="1">
                    <?= t('action.save') ?> &amp; <?= t('action.post') ?>
                </button>
            <?php endif; ?>
            <span class="topbar__spacer"></span>
            <a class="btn btn--ghost" href="<?= url($isEdit ? '/bills/' . $bill['id'] : '/bills') ?>">
                <?= t('action.cancel') ?>
            </a>
        </div>
    </div>
</form>
