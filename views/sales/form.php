<?php
/**
 * Invoice form (create and edit a draft).
 *
 * @var array|null $invoice
 * @var array      $lines
 * @var array      $customers
 * @var array      $warehouses
 * @var string     $nextNumber
 */

use App\Core\Lang;

$isEdit = $invoice !== null && isset($invoice['id']);
$action = $isEdit ? url('/invoices/' . $invoice['id']) : url('/invoices');
?>
<form method="post" action="<?= e($action) ?>" data-dirty-guard>
    <?= csrf_field() ?>
    <?php if (!empty($invoice['quotation_id'])): ?>
        <input type="hidden" name="quotation_id" value="<?= (int) $invoice['quotation_id'] ?>">
    <?php endif; ?>

    <div class="card">
        <div class="card__head">
            <h2 class="card__title">
                <?= $isEdit ? e($invoice['number']) : e($nextNumber) ?>
            </h2>
            <?php if (!$isEdit): ?>
                <span class="badge badge--muted">draft</span>
            <?php endif; ?>
        </div>
        <div class="card__body">
            <div class="form-grid">
                <div class="field">
                    <label for="contact_id" class="required"><?= t('field.customer') ?></label>
                    <select id="contact_id" name="contact_id" required>
                        <option value="">— choose —</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= (int) $customer['id'] ?>"
                                    data-terms="<?= (int) $customer['payment_terms_days'] ?>"
                                <?= (int) ($invoice['contact_id'] ?? 0) === (int) $customer['id'] ? 'selected' : '' ?>>
                                <?= e($customer['code'] . ' · ' . Lang::pick($customer, 'name')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field__hint">
                        <a href="<?= url('/contacts/new', ['kind' => 'customer']) ?>">Add a new customer</a>
                    </span>
                </div>

                <div class="field">
                    <label for="issue_date" class="required"><?= t('field.date') ?></label>
                    <input type="date" id="issue_date" name="issue_date"
                           value="<?= e($invoice['issue_date'] ?? date('Y-m-d')) ?>" required>
                </div>

                <div class="field">
                    <label for="due_date"><?= t('field.due_date') ?></label>
                    <input type="date" id="due_date" name="due_date" value="<?= e($invoice['due_date'] ?? '') ?>">
                    <span class="field__hint">Left blank, the customer's payment terms decide it.</span>
                </div>

                <div class="field">
                    <label for="lpo_number"><?= t('qatar.lpo') ?></label>
                    <input type="text" id="lpo_number" name="lpo_number" value="<?= e($invoice['lpo_number'] ?? '') ?>">
                    <span class="field__hint">Most government and corporate customers will not pay without it.</span>
                </div>

                <div class="field">
                    <label for="project">Project / site</label>
                    <input type="text" id="project" name="project" value="<?= e($invoice['project'] ?? '') ?>">
                </div>

                <?php if (count($warehouses) > 1): ?>
                    <div class="field">
                        <label for="warehouse_id">Ship from</label>
                        <select id="warehouse_id" name="warehouse_id">
                            <?php foreach ($warehouses as $warehouse): ?>
                                <option value="<?= (int) $warehouse['id'] ?>"
                                    <?= (int) ($invoice['warehouse_id'] ?? 0) === (int) $warehouse['id'] ? 'selected' : '' ?>>
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
        'taxEnabled' => $taxEnabled,
        'defaultTaxRate' => $defaultTaxRate,
        'pricesIncludeTax' => $pricesIncludeTax,
        'priceField' => 'sale_price',
    ]) ?>

    <div class="card">
        <div class="card__body">
            <div class="form-grid form-grid--2">
                <div class="field">
                    <label for="notes"><?= t('field.notes') ?></label>
                    <textarea id="notes" name="notes" rows="3"><?= e($invoice['notes'] ?? '') ?></textarea>
                </div>
                <div class="field">
                    <label for="terms">Terms shown on the invoice</label>
                    <textarea id="terms" name="terms" rows="3"><?= e($invoice['terms'] ?? $defaultTerms) ?></textarea>
                </div>
            </div>
        </div>
        <div class="card__foot">
            <button class="btn" type="submit" name="post_now" value="0"><?= t('action.save_draft') ?></button>
            <?php if (can('sales.post')): ?>
                <button class="btn btn--primary" type="submit" name="post_now" value="1">
                    <?= t('action.save') ?> &amp; <?= t('action.post') ?>
                </button>
            <?php endif; ?>
            <span class="topbar__spacer"></span>
            <a class="btn btn--ghost" href="<?= url($isEdit ? '/invoices/' . $invoice['id'] : '/invoices') ?>">
                <?= t('action.cancel') ?>
            </a>
        </div>
    </div>

    <p class="tiny faint">
        Posting books the revenue and moves the stock, and the invoice can no
        longer be edited — only voided. Save it as a draft while you are still
        working on it.
    </p>
</form>
