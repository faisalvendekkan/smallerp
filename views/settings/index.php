<?php
/**
 * Company settings.
 *
 * These are the details that end up on a printed invoice and inside the WPS
 * file, so the Qatari identifiers are validated here before they can cause a
 * rejection later.
 */

use App\Support\Qatar;
?>
<form method="post" action="<?= url('/settings') ?>" data-dirty-guard>
    <?= csrf_field() ?>

    <div class="card">
        <div class="card__head"><h2 class="card__title">Company</h2></div>
        <div class="card__body">
            <div class="form-grid">
                <div class="field">
                    <label for="company_name_en">Name (English)</label>
                    <input type="text" id="company_name_en" name="company_name_en"
                           value="<?= e($settings['company_name_en']) ?>">
                </div>
                <div class="field">
                    <label for="company_name_ar">Name (Arabic) — الاسم بالعربية</label>
                    <input type="text" id="company_name_ar" name="company_name_ar" dir="rtl"
                           value="<?= e($settings['company_name_ar']) ?>">
                </div>
                <div class="field">
                    <label for="cr_number"><?= t('qatar.cr_number') ?></label>
                    <input type="text" id="cr_number" name="cr_number" inputmode="numeric"
                           value="<?= e($settings['cr_number']) ?>">
                    <span class="field__hint">Commercial Registration from the MoCI. Printed on every invoice.</span>
                </div>
                <div class="field">
                    <label for="establishment_id"><?= t('qatar.establishment_id') ?></label>
                    <input type="text" id="establishment_id" name="establishment_id" inputmode="numeric"
                           value="<?= e($settings['establishment_id']) ?>">
                    <span class="field__hint">Ministry of Labour number, used as the WPS employer ID.</span>
                </div>
                <div class="field">
                    <label for="tax_card_number"><?= t('qatar.tax_card') ?></label>
                    <input type="text" id="tax_card_number" name="tax_card_number"
                           value="<?= e($settings['tax_card_number']) ?>">
                </div>
                <div class="field">
                    <label for="municipality_licence">Municipality licence</label>
                    <input type="text" id="municipality_licence" name="municipality_licence"
                           value="<?= e($settings['municipality_licence']) ?>">
                </div>
                <div class="field">
                    <label for="chamber_membership">Chamber of Commerce membership</label>
                    <input type="text" id="chamber_membership" name="chamber_membership"
                           value="<?= e($settings['chamber_membership']) ?>">
                </div>
                <div class="field field--full">
                    <label for="address">Address</label>
                    <input type="text" id="address" name="address" value="<?= e($settings['address']) ?>"
                           placeholder="Building, street, zone">
                </div>
                <div class="field">
                    <label for="po_box">P.O. Box</label>
                    <input type="text" id="po_box" name="po_box" value="<?= e($settings['po_box']) ?>">
                </div>
                <div class="field">
                    <label for="city">City</label>
                    <input type="text" id="city" name="city" value="<?= e($settings['city']) ?>">
                </div>
                <div class="field">
                    <label for="phone">Telephone</label>
                    <input type="tel" id="phone" name="phone" value="<?= e($settings['phone']) ?>">
                </div>
                <div class="field">
                    <label for="mobile">Mobile</label>
                    <input type="tel" id="mobile" name="mobile" value="<?= e($settings['mobile']) ?>">
                </div>
                <div class="field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= e($settings['email']) ?>">
                </div>
                <div class="field">
                    <label for="website">Website</label>
                    <input type="text" id="website" name="website" value="<?= e($settings['website']) ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card__head">
            <h2 class="card__title"><?= t('qatar.wps') ?></h2>
            <span class="topbar__spacer"></span>
            <span class="badge badge--<?= $wpsConfigured ? 'ok' : 'warn' ?>">
                <?= $wpsConfigured ? 'configured' : 'incomplete' ?>
            </span>
        </div>
        <div class="card__body">
            <p class="small muted">
                Since Law No. 1 of 2015 amended Art. 66 of the Labour Law, wages must
                be paid through the Wage Protection System. These details go into the
                SCR control line of every SIF file you send your bank.
            </p>
            <div class="form-grid">
                <div class="field">
                    <label for="wps_iban">Company salary account (IBAN)</label>
                    <input type="text" id="wps_iban" name="wps_iban" class="mono" maxlength="34"
                           value="<?= e($settings['wps_iban']) ?>" placeholder="QA00 XXXX 0000 …">
                    <span class="field__hint">29 characters, verified with the mod-97 check.</span>
                </div>
                <div class="field">
                    <label for="wps_bank_short_name">Bank short name</label>
                    <input type="text" id="wps_bank_short_name" name="wps_bank_short_name"
                           value="<?= e($settings['wps_bank_short_name']) ?>" list="bank-shorts">
                    <datalist id="bank-shorts">
                        <?php foreach ($banks as $bank): ?>
                            <option value="<?= e($bank['short']) ?>"><?= e($bank['name_en']) ?></option>
                        <?php endforeach; ?>
                    </datalist>
                    <span class="field__hint">Left blank, derived from the IBAN.</span>
                </div>
                <div class="field">
                    <label for="wps_payer_qid">Payer QID</label>
                    <input type="text" id="wps_payer_qid" name="wps_payer_qid" inputmode="numeric" maxlength="11"
                           value="<?= e($settings['wps_payer_qid']) ?>">
                    <span class="field__hint">The QID of the person authorised to release salaries.</span>
                </div>
                <div class="field">
                    <label for="wps_payer_eid">Payer establishment ID</label>
                    <input type="text" id="wps_payer_eid" name="wps_payer_eid" inputmode="numeric"
                           value="<?= e($settings['wps_payer_eid']) ?>">
                    <span class="field__hint">Only if it differs from the company EID above.</span>
                </div>
                <div class="field">
                    <label for="wps_sif_version">SIF version</label>
                    <input type="text" id="wps_sif_version" name="wps_sif_version"
                           value="<?= e($settings['wps_sif_version'] ?: $sifVersion) ?>">
                    <span class="field__hint">Change only if your bank asks for a different version.</span>
                </div>
                <div class="field">
                    <label for="payroll_working_days">Working days per month</label>
                    <input type="number" id="payroll_working_days" name="payroll_working_days" min="1" max="31"
                           value="<?= e($settings['payroll_working_days']) ?>">
                    <span class="field__hint">30 is the conventional divisor for a daily wage in Qatar.</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card__head"><h2 class="card__title"><?= t('qatar.gratuity') ?></h2></div>
        <div class="card__body">
            <p class="small muted">
                Art. 54 sets a floor of three weeks' basic wage per year of service.
                Many Qatari SMEs improve on it for longer service — set your own
                tiers here. You cannot go below the statutory three weeks.
            </p>
            <div class="form-grid">
                <div class="field">
                    <label for="gratuity_weeks_tier1">Weeks per year — first 5 years</label>
                    <input type="text" class="num" id="gratuity_weeks_tier1" name="gratuity_weeks_tier1"
                           inputmode="decimal" value="<?= e($settings['gratuity_weeks_tier1']) ?>">
                </div>
                <div class="field">
                    <label for="gratuity_weeks_tier2">Weeks per year — after 5 years</label>
                    <input type="text" class="num" id="gratuity_weeks_tier2" name="gratuity_weeks_tier2"
                           inputmode="decimal" value="<?= e($settings['gratuity_weeks_tier2']) ?>">
                </div>
                <div class="field">
                    <label for="gratuity_weeks_tier3">Weeks per year — after 10 years</label>
                    <input type="text" class="num" id="gratuity_weeks_tier3" name="gratuity_weeks_tier3"
                           inputmode="decimal" value="<?= e($settings['gratuity_weeks_tier3']) ?>">
                </div>
                <div class="field">
                    <label for="expiry_alert_days">Warn about expiring documents (days)</label>
                    <input type="number" id="expiry_alert_days" name="expiry_alert_days" min="7" max="365"
                           value="<?= e($settings['expiry_alert_days']) ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card__head"><h2 class="card__title">Tax</h2></div>
        <div class="card__body">
            <div class="alert alert--info">
                <span class="alert__icon">i</span>
                <span><?= e($taxNotice) ?></span>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label class="check">
                        <input type="checkbox" name="tax_enabled" value="1"
                            <?= $settings['tax_enabled'] === '1' ? 'checked' : '' ?>>
                        <span>Charge tax on invoices</span>
                    </label>
                    <span class="field__hint">
                        Switch this on when VAT commences. The GCC standard rate is
                        <?= e((string) $gccRate) ?>%.
                    </span>
                </div>
                <div class="field">
                    <label for="default_tax_rate">Default rate (%)</label>
                    <input type="text" class="num" id="default_tax_rate" name="default_tax_rate"
                           inputmode="decimal" value="<?= e($settings['default_tax_rate']) ?>">
                </div>
                <div class="field">
                    <label for="tax_label_en">Tax label (English)</label>
                    <input type="text" id="tax_label_en" name="tax_label_en" value="<?= e($settings['tax_label_en']) ?>">
                </div>
                <div class="field">
                    <label for="tax_label_ar">Tax label (Arabic)</label>
                    <input type="text" id="tax_label_ar" name="tax_label_ar" dir="rtl"
                           value="<?= e($settings['tax_label_ar']) ?>">
                </div>
                <div class="field">
                    <label class="check">
                        <input type="checkbox" name="prices_include_tax" value="1"
                            <?= $settings['prices_include_tax'] === '1' ? 'checked' : '' ?>>
                        <span>Prices are entered inclusive of tax</span>
                    </label>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card__head"><h2 class="card__title">Documents</h2></div>
        <div class="card__body">
            <div class="form-grid form-grid--2">
                <div class="field">
                    <label for="invoice_terms_en">Default invoice terms (English)</label>
                    <textarea id="invoice_terms_en" name="invoice_terms_en" rows="2"><?= e($settings['invoice_terms_en']) ?></textarea>
                </div>
                <div class="field">
                    <label for="invoice_terms_ar">Default invoice terms (Arabic)</label>
                    <textarea id="invoice_terms_ar" name="invoice_terms_ar" rows="2" dir="rtl"><?= e($settings['invoice_terms_ar']) ?></textarea>
                </div>
                <div class="field field--full">
                    <label for="bank_details">Bank details printed on invoices</label>
                    <textarea id="bank_details" name="bank_details" rows="3"><?= e($settings['bank_details']) ?></textarea>
                </div>
                <div class="field">
                    <label for="invoice_footer_en">Invoice footer (English)</label>
                    <input type="text" id="invoice_footer_en" name="invoice_footer_en"
                           value="<?= e($settings['invoice_footer_en']) ?>">
                </div>
                <div class="field">
                    <label for="invoice_footer_ar">Invoice footer (Arabic)</label>
                    <input type="text" id="invoice_footer_ar" name="invoice_footer_ar" dir="rtl"
                           value="<?= e($settings['invoice_footer_ar']) ?>">
                </div>
            </div>

            <fieldset class="mt-2">
                <legend>Document number prefixes</legend>
                <div class="form-grid">
                    <?php foreach ($numbering as $docType => $config): ?>
                        <div class="field">
                            <label for="numbering_<?= e($docType) ?>">
                                <?= e(ucfirst(str_replace('_', ' ', $docType))) ?>
                            </label>
                            <input type="text" id="numbering_<?= e($docType) ?>"
                                   name="numbering_<?= e($docType) ?>_prefix"
                                   value="<?= e($settings['numbering_' . $docType . '_prefix'] ?? $config['prefix']) ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="tiny faint mb-0">
                    Changing a prefix affects new documents only; numbers already
                    issued are never renumbered.
                </p>
            </fieldset>
        </div>
        <div class="card__foot">
            <button class="btn btn--primary" type="submit"><?= t('action.save') ?></button>
            <span class="topbar__spacer"></span>
            <a class="btn btn--ghost" href="<?= url('/holidays') ?>">Public holidays</a>
        </div>
    </div>
</form>
