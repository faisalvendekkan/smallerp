<?php
/**
 * Customer / supplier form.
 *
 * @var array|null $contact
 * @var string     $kind
 */
$isEdit = $contact !== null;
$action = $isEdit ? url('/contacts/' . $contact['id']) : url('/contacts');
?>
<form method="post" action="<?= e($action) ?>" data-dirty-guard>
    <?= csrf_field() ?>

    <div class="card">
        <div class="card__body">
            <fieldset>
                <legend>Identity</legend>
                <div class="form-grid">
                    <div class="field">
                        <label for="kind" class="required">Type</label>
                        <select id="kind" name="kind" required>
                            <?php foreach (['customer' => 'Customer', 'supplier' => 'Supplier', 'both' => 'Both'] as $value => $label): ?>
                                <option value="<?= $value ?>"
                                    <?= ($contact['kind'] ?? $kind) === $value ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="name_en" class="required">Name (English)</label>
                        <input type="text" id="name_en" name="name_en"
                               value="<?= e($contact['name_en'] ?? old('name_en')) ?>" required>
                    </div>
                    <div class="field">
                        <label for="name_ar">Name (Arabic) — الاسم بالعربية</label>
                        <input type="text" id="name_ar" name="name_ar" dir="rtl"
                               value="<?= e($contact['name_ar'] ?? '') ?>">
                        <span class="field__hint">Printed on invoices alongside the English name.</span>
                    </div>
                    <div class="field">
                        <label for="cr_number"><?= t('qatar.cr_number') ?></label>
                        <input type="text" id="cr_number" name="cr_number" inputmode="numeric"
                               value="<?= e($contact['cr_number'] ?? '') ?>">
                        <span class="field__hint">Commercial Registration, 4–12 digits.</span>
                    </div>
                    <div class="field">
                        <label for="tax_id">Tax card number</label>
                        <input type="text" id="tax_id" name="tax_id" value="<?= e($contact['tax_id'] ?? '') ?>">
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <legend>Contact</legend>
                <div class="form-grid">
                    <div class="field">
                        <label for="contact_person">Contact person</label>
                        <input type="text" id="contact_person" name="contact_person"
                               value="<?= e($contact['contact_person'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="phone">Telephone</label>
                        <input type="tel" id="phone" name="phone" value="<?= e($contact['phone'] ?? '') ?>"
                               placeholder="+974 4431 8820">
                    </div>
                    <div class="field">
                        <label for="mobile">Mobile</label>
                        <input type="tel" id="mobile" name="mobile" value="<?= e($contact['mobile'] ?? '') ?>"
                               placeholder="+974 5511 2233">
                    </div>
                    <div class="field">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?= e($contact['email'] ?? '') ?>">
                    </div>
                    <div class="field field--full">
                        <label for="address">Address</label>
                        <input type="text" id="address" name="address" value="<?= e($contact['address'] ?? '') ?>"
                               placeholder="Building, street, zone">
                    </div>
                    <div class="field">
                        <label for="po_box">P.O. Box</label>
                        <input type="text" id="po_box" name="po_box" value="<?= e($contact['po_box'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="city">City</label>
                        <input type="text" id="city" name="city" value="<?= e($contact['city'] ?? 'Doha') ?>">
                    </div>
                    <div class="field">
                        <label for="country">Country</label>
                        <input type="text" id="country" name="country" value="<?= e($contact['country'] ?? 'Qatar') ?>">
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <legend>Trading terms</legend>
                <div class="form-grid">
                    <div class="field">
                        <label for="payment_terms_days">Payment terms (days)</label>
                        <input type="number" id="payment_terms_days" name="payment_terms_days" min="0" max="365"
                               value="<?= (int) ($contact['payment_terms_days'] ?? 30) ?>">
                        <span class="field__hint">Used to work out the due date on new documents.</span>
                    </div>
                    <div class="field">
                        <label for="credit_limit">Credit limit (QAR)</label>
                        <input type="text" class="num" id="credit_limit" name="credit_limit" inputmode="decimal"
                               value="<?= e(App\Support\Money::toDecimalString((int) ($contact['credit_limit'] ?? 0))) ?>">
                    </div>
                    <?php if (!$isEdit): ?>
                        <div class="field">
                            <label for="opening_balance">Opening balance (QAR)</label>
                            <input type="text" class="num" id="opening_balance" name="opening_balance"
                                   inputmode="decimal" value="0.00">
                            <span class="field__hint">What they already owed when you started using SmallERP.</span>
                        </div>
                    <?php endif; ?>
                    <div class="field field--full">
                        <label for="notes"><?= t('field.notes') ?></label>
                        <textarea id="notes" name="notes" rows="2"><?= e($contact['notes'] ?? '') ?></textarea>
                    </div>
                    <div class="field">
                        <label class="check">
                            <input type="checkbox" name="is_active" value="1"
                                <?= ($contact === null || (int) $contact['is_active'] === 1) ? 'checked' : '' ?>>
                            <span>Active</span>
                        </label>
                    </div>
                </div>
            </fieldset>
        </div>
        <div class="card__foot">
            <button class="btn btn--primary" type="submit"><?= t('action.save') ?></button>
            <a class="btn btn--ghost"
               href="<?= url($isEdit ? '/contacts/' . $contact['id'] : ($kind === 'supplier' ? '/suppliers' : '/customers')) ?>">
                <?= t('action.cancel') ?>
            </a>
        </div>
    </div>
</form>
