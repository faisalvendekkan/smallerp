<?php
/**
 * The document line editor, shared by quotations, invoices and bills.
 *
 * Totals are recomputed in the browser as the user types (see app.js) and
 * again on the server when the form is submitted -- the server's figures are
 * the ones that are stored.
 *
 * @var array $lines            Existing lines, if editing.
 * @var array $items            Item catalogue for the dropdown.
 * @var string $itemsJson       Same catalogue as JSON for the browser.
 * @var bool  $taxEnabled
 * @var float $defaultTaxRate
 * @var bool  $pricesIncludeTax
 * @var array|null $expenseAccounts  Present on purchase bills.
 */

use App\Core\Lang;
use App\Support\Money;

$showTax = $taxEnabled ?? false;
$accounts = $expenseAccounts ?? null;
$priceField = $priceField ?? 'sale_price';
$columns = 6 + ($showTax ? 1 : 0) + ($accounts !== null ? 1 : 0);
?>
<div class="card" data-line-editor
     data-prices-include-tax="<?= !empty($pricesIncludeTax) ? '1' : '0' ?>"
     data-price-field="<?= e($priceField) ?>">
    <div class="card__head">
        <h2 class="card__title"><?= t('field.description') ?></h2>
        <span class="topbar__spacer"></span>
        <button class="btn btn--sm" type="button" data-add-line>+ <?= t('action.add_line') ?></button>
    </div>

    <div class="table-wrap">
        <table class="lines">
            <thead>
            <tr>
                <th style="width:26px">#</th>
                <th style="width:170px"><?= t('nav.items') ?></th>
                <?php if ($accounts !== null): ?>
                    <th style="width:160px"><?= t('nav.accounts') ?></th>
                <?php endif; ?>
                <th><?= t('field.description') ?></th>
                <th style="width:78px" class="num"><?= t('field.quantity') ?></th>
                <th style="width:72px">Unit</th>
                <th style="width:100px" class="num"><?= t('field.unit_price') ?></th>
                <th style="width:66px" class="num">Disc %</th>
                <?php if ($showTax): ?>
                    <th style="width:66px" class="num"><?= t('field.tax') ?> %</th>
                <?php endif; ?>
                <th style="width:104px" class="num"><?= t('field.total') ?></th>
                <th style="width:30px"></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $index => $line): ?>
                <tr>
                    <td class="muted tiny" data-line-number><?= $index + 1 ?></td>
                    <td>
                        <select name="lines[item_id][]" data-field="item_id">
                            <option value="">—</option>
                            <?php foreach ($items as $item): ?>
                                <option value="<?= (int) $item['id'] ?>"
                                    <?= (int) ($line['item_id'] ?? 0) === (int) $item['id'] ? 'selected' : '' ?>>
                                    <?= e($item['sku'] . ' · ' . Lang::pick($item, 'name')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <?php if ($accounts !== null): ?>
                        <td>
                            <select name="lines[account_id][]" data-field="account_id">
                                <option value="">—</option>
                                <?php foreach ($accounts as $account): ?>
                                    <option value="<?= (int) $account['id'] ?>"
                                        <?= (int) ($line['account_id'] ?? 0) === (int) $account['id'] ? 'selected' : '' ?>>
                                        <?= e($account['code'] . ' · ' . Lang::pick($account, 'name')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    <?php endif; ?>
                    <td><input type="text" name="lines[description][]" data-field="description"
                               value="<?= e($line['description'] ?? '') ?>"></td>
                    <td><input type="text" class="num" name="lines[quantity][]" data-field="quantity"
                               value="<?= e(rtrim(rtrim(number_format((float) ($line['quantity'] ?? 1), 3, '.', ''), '0'), '.')) ?>"
                               inputmode="decimal"></td>
                    <td><input type="text" name="lines[uom][]" data-field="uom"
                               value="<?= e($line['uom'] ?? 'PCS') ?>"></td>
                    <td><input type="text" class="num" name="lines[unit_price][]" data-field="unit_price"
                               value="<?= e(Money::toDecimalString((int) ($line['unit_price'] ?? 0))) ?>"
                               inputmode="decimal"></td>
                    <td><input type="text" class="num" name="lines[discount_pct][]" data-field="discount_pct"
                               value="<?= e((string) (float) ($line['discount_pct'] ?? 0)) ?>"
                               inputmode="decimal"></td>
                    <?php if ($showTax): ?>
                        <td><input type="text" class="num" name="lines[tax_rate][]" data-field="tax_rate"
                                   value="<?= e((string) (float) ($line['tax_rate'] ?? $defaultTaxRate)) ?>"
                                   inputmode="decimal"></td>
                    <?php else: ?>
                        <input type="hidden" name="lines[tax_rate][]" data-field="tax_rate" value="0">
                    <?php endif; ?>
                    <td class="num strong" data-line-total><?= e(Money::format((int) ($line['line_total'] ?? 0))) ?></td>
                    <td><button class="line-remove" type="button" title="<?= t('action.delete') ?>">×</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card__body">
        <div class="totals-box">
            <div class="totals-box__row">
                <span><?= t('field.subtotal') ?></span>
                <span class="num mono" data-total="subtotal">0.00</span>
            </div>
            <div class="totals-box__row hidden" data-total-row="discount">
                <span><?= t('field.discount') ?></span>
                <span class="num mono" data-total="discount">0.00</span>
            </div>
            <div class="totals-box__row hidden" data-total-row="tax">
                <span><?= t('field.tax') ?></span>
                <span class="num mono" data-total="tax">0.00</span>
            </div>
            <div class="totals-box__row totals-box__row--grand">
                <span><?= t('field.total') ?> (QAR)</span>
                <span class="num mono" data-total="grand">0.00</span>
            </div>
        </div>
    </div>
</div>

<template id="line-template">
    <tr>
        <td class="muted tiny" data-line-number></td>
        <td>
            <select name="lines[item_id][]" data-field="item_id">
                <option value="">—</option>
                <?php foreach ($items as $item): ?>
                    <option value="<?= (int) $item['id'] ?>">
                        <?= e($item['sku'] . ' · ' . Lang::pick($item, 'name')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <?php if ($accounts !== null): ?>
            <td>
                <select name="lines[account_id][]" data-field="account_id">
                    <option value="">—</option>
                    <?php foreach ($accounts as $account): ?>
                        <option value="<?= (int) $account['id'] ?>">
                            <?= e($account['code'] . ' · ' . Lang::pick($account, 'name')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        <?php endif; ?>
        <td><input type="text" name="lines[description][]" data-field="description"></td>
        <td><input type="text" class="num" name="lines[quantity][]" data-field="quantity" value="1" inputmode="decimal"></td>
        <td><input type="text" name="lines[uom][]" data-field="uom" value="PCS"></td>
        <td><input type="text" class="num" name="lines[unit_price][]" data-field="unit_price" value="0.00" inputmode="decimal"></td>
        <td><input type="text" class="num" name="lines[discount_pct][]" data-field="discount_pct" value="0" inputmode="decimal"></td>
        <?php if ($showTax): ?>
            <td><input type="text" class="num" name="lines[tax_rate][]" data-field="tax_rate"
                       value="<?= e((string) (float) $defaultTaxRate) ?>" inputmode="decimal"></td>
        <?php else: ?>
            <input type="hidden" name="lines[tax_rate][]" data-field="tax_rate" value="0">
        <?php endif; ?>
        <td class="num strong" data-line-total>0.00</td>
        <td><button class="line-remove" type="button">×</button></td>
    </tr>
</template>

<?php
/*
 * The catalogue travels as a JSON data block rather than an inline script.
 * A `type="application/json"` block is data, not code, so the Content Security
 * Policy does not have to allow inline scripts for the line editor to work.
 */
?>
<script type="application/json" id="item-catalogue"><?= $itemsJson ?></script>
