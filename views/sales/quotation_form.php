<?php
/** Quotation form. */

use App\Core\Lang;

$isEdit = $quotation !== null;
$action = $isEdit ? url('/quotations/' . $quotation['id']) : url('/quotations');
?>
<form method="post" action="<?= e($action) ?>" data-dirty-guard>
    <?= csrf_field() ?>

    <div class="card">
        <div class="card__head">
            <h2 class="card__title"><?= $isEdit ? e($quotation['number']) : e($nextNumber) ?></h2>
        </div>
        <div class="card__body">
            <div class="form-grid">
                <div class="field">
                    <label for="contact_id" class="required"><?= t('field.customer') ?></label>
                    <select id="contact_id" name="contact_id" required>
                        <option value="">— choose —</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= (int) $customer['id'] ?>"
                                <?= (int) ($quotation['contact_id'] ?? 0) === (int) $customer['id'] ? 'selected' : '' ?>>
                                <?= e($customer['code'] . ' · ' . Lang::pick($customer, 'name')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="issue_date" class="required"><?= t('field.date') ?></label>
                    <input type="date" id="issue_date" name="issue_date"
                           value="<?= e($quotation['issue_date'] ?? date('Y-m-d')) ?>" required>
                </div>
                <div class="field">
                    <label for="valid_until">Valid until</label>
                    <input type="date" id="valid_until" name="valid_until"
                           value="<?= e($quotation['valid_until'] ?? '') ?>">
                    <span class="field__hint">Left blank, 30 days from the quotation date.</span>
                </div>
                <div class="field field--full">
                    <label for="subject">Subject</label>
                    <input type="text" id="subject" name="subject" value="<?= e($quotation['subject'] ?? '') ?>"
                           placeholder="e.g. supply and installation of air conditioning, Zone 55">
                </div>
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
                    <textarea id="notes" name="notes" rows="3"><?= e($quotation['notes'] ?? '') ?></textarea>
                </div>
                <div class="field">
                    <label for="terms">Terms</label>
                    <textarea id="terms" name="terms" rows="3"><?= e($quotation['terms'] ?? $defaultTerms) ?></textarea>
                </div>
            </div>
        </div>
        <div class="card__foot">
            <button class="btn btn--primary" type="submit"><?= t('action.save') ?></button>
            <a class="btn btn--ghost" href="<?= url($isEdit ? '/quotations/' . $quotation['id'] : '/quotations') ?>">
                <?= t('action.cancel') ?>
            </a>
        </div>
    </div>
</form>
