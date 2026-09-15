<?php
/** Item form. */

use App\Core\Lang;
use App\Support\Money;

$isEdit = $item !== null;
$action = $isEdit ? url('/items/' . $item['id']) : url('/items');
?>
<form method="post" action="<?= e($action) ?>" data-dirty-guard>
    <?= csrf_field() ?>

    <div class="card">
        <div class="card__body">
            <div class="form-grid">
                <div class="field">
                    <label for="sku">Item code</label>
                    <input type="text" id="sku" name="sku" value="<?= e($item['sku'] ?? '') ?>"
                           placeholder="<?= e($nextSku) ?>">
                    <span class="field__hint">Left blank, one is generated.</span>
                </div>
                <div class="field">
                    <label for="kind">Type</label>
                    <select id="kind" name="kind">
                        <option value="goods" <?= ($item['kind'] ?? 'goods') === 'goods' ? 'selected' : '' ?>>
                            Goods — held in stock
                        </option>
                        <option value="service" <?= ($item['kind'] ?? '') === 'service' ? 'selected' : '' ?>>
                            Service — no stock
                        </option>
                    </select>
                </div>
                <div class="field">
                    <label for="name_en" class="required">Name (English)</label>
                    <input type="text" id="name_en" name="name_en"
                           value="<?= e($item['name_en'] ?? old('name_en')) ?>" required>
                </div>
                <div class="field">
                    <label for="name_ar">Name (Arabic) — الاسم بالعربية</label>
                    <input type="text" id="name_ar" name="name_ar" dir="rtl" value="<?= e($item['name_ar'] ?? '') ?>">
                    <span class="field__hint">Printed under the English name on invoices.</span>
                </div>
                <div class="field">
                    <label for="uom">Unit of measure</label>
                    <input type="text" id="uom" name="uom" value="<?= e($item['uom'] ?? 'PCS') ?>"
                           list="uom-list">
                    <datalist id="uom-list">
                        <?php foreach (['PCS', 'BOX', 'SET', 'ROLL', 'MTR', 'KG', 'LTR', 'HOUR', 'DAY', 'JOB', 'CONTRACT', 'MONTH'] as $uom): ?>
                            <option value="<?= $uom ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="field">
                    <label for="barcode">Barcode</label>
                    <input type="text" id="barcode" name="barcode" value="<?= e($item['barcode'] ?? '') ?>">
                </div>
                <div class="field field--full">
                    <label for="description"><?= t('field.description') ?></label>
                    <input type="text" id="description" name="description" value="<?= e($item['description'] ?? '') ?>">
                </div>
            </div>

            <fieldset class="mt-2">
                <legend>Pricing</legend>
                <div class="form-grid">
                    <div class="field">
                        <label for="sale_price">Sale price (QAR)</label>
                        <input type="text" class="num" id="sale_price" name="sale_price" inputmode="decimal"
                               value="<?= e(Money::toDecimalString((int) ($item['sale_price'] ?? 0))) ?>">
                    </div>
                    <div class="field">
                        <label for="cost_price">Cost price (QAR)</label>
                        <input type="text" class="num" id="cost_price" name="cost_price" inputmode="decimal"
                               value="<?= e(Money::toDecimalString((int) ($item['cost_price'] ?? 0))) ?>">
                        <span class="field__hint">A starting figure; stock is valued at weighted average cost once received.</span>
                    </div>
                    <div class="field">
                        <label for="tax_rate">Tax rate (%)</label>
                        <input type="text" class="num" id="tax_rate" name="tax_rate" inputmode="decimal"
                               value="<?= e((string) (float) ($item['tax_rate'] ?? $defaultTaxRate)) ?>">
                        <?php if (!$taxEnabled): ?>
                            <span class="field__hint">Tax is switched off in Settings — VAT is not yet in force in Qatar.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <legend>Stock</legend>
                <div class="form-grid">
                    <div class="field">
                        <label class="check">
                            <input type="checkbox" name="track_stock" value="1"
                                <?= ($item === null || (int) $item['track_stock'] === 1) ? 'checked' : '' ?>>
                            <span>Track stock for this item</span>
                        </label>
                        <span class="field__hint">Ignored for services.</span>
                    </div>
                    <div class="field">
                        <label for="reorder_level">Reorder level</label>
                        <input type="text" class="num" id="reorder_level" name="reorder_level" inputmode="decimal"
                               value="<?= e((string) (float) ($item['reorder_level'] ?? 0)) ?>">
                        <span class="field__hint">Flagged on the dashboard when stock falls to this.</span>
                    </div>
                    <?php if (!$isEdit): ?>
                        <div class="field">
                            <label for="opening_qty">Opening quantity</label>
                            <input type="text" class="num" id="opening_qty" name="opening_qty" inputmode="decimal"
                                   value="0">
                            <span class="field__hint">Posted to Inventory against Opening Balance Equity.</span>
                        </div>
                    <?php endif; ?>
                </div>
            </fieldset>

            <fieldset>
                <legend>Accounts</legend>
                <p class="tiny faint mb-2">
                    Leave these blank to use the defaults — Sales, Purchases and Inventory.
                    Set them to break revenue down by product line on the P&amp;L.
                </p>
                <div class="form-grid">
                    <div class="field">
                        <label for="income_account_id">Revenue account</label>
                        <select id="income_account_id" name="income_account_id">
                            <option value="">Default</option>
                            <?php foreach ($incomeAccounts as $account): ?>
                                <option value="<?= (int) $account['id'] ?>"
                                    <?= (int) ($item['income_account_id'] ?? 0) === (int) $account['id'] ? 'selected' : '' ?>>
                                    <?= e($account['code'] . ' · ' . Lang::pick($account, 'name')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="expense_account_id">Expense account</label>
                        <select id="expense_account_id" name="expense_account_id">
                            <option value="">Default</option>
                            <?php foreach ($expenseAccounts as $account): ?>
                                <option value="<?= (int) $account['id'] ?>"
                                    <?= (int) ($item['expense_account_id'] ?? 0) === (int) $account['id'] ? 'selected' : '' ?>>
                                    <?= e($account['code'] . ' · ' . Lang::pick($account, 'name')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="inventory_account_id">Inventory account</label>
                        <select id="inventory_account_id" name="inventory_account_id">
                            <option value="">Default</option>
                            <?php foreach ($inventoryAccounts as $account): ?>
                                <option value="<?= (int) $account['id'] ?>"
                                    <?= (int) ($item['inventory_account_id'] ?? 0) === (int) $account['id'] ? 'selected' : '' ?>>
                                    <?= e($account['code'] . ' · ' . Lang::pick($account, 'name')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </fieldset>

            <label class="check">
                <input type="checkbox" name="is_active" value="1"
                    <?= ($item === null || (int) $item['is_active'] === 1) ? 'checked' : '' ?>>
                <span>Active</span>
            </label>
        </div>
        <div class="card__foot">
            <button class="btn btn--primary" type="submit"><?= t('action.save') ?></button>
            <a class="btn btn--ghost" href="<?= url($isEdit ? '/items/' . $item['id'] : '/items') ?>">
                <?= t('action.cancel') ?>
            </a>
        </div>
    </div>
</form>
