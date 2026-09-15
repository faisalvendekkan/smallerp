<?php
/**
 * Employee form.
 *
 * Grouped the way an HR file is: who they are, their residence documents,
 * their wage, and how they get paid through the WPS.
 */

use App\Services\Hr;
use App\Support\Money;

$isEdit = $employee !== null;
$action = $isEdit ? url('/employees/' . $employee['id']) : url('/employees');
$money = static fn ($v): string => Money::toDecimalString((int) ($v ?? 0));
?>
<form method="post" action="<?= e($action) ?>" data-dirty-guard>
    <?= csrf_field() ?>

    <div class="card">
        <div class="card__body">
            <fieldset>
                <legend>Personal</legend>
                <div class="form-grid">
                    <div class="field">
                        <label for="name_en" class="required">Name (English)</label>
                        <input type="text" id="name_en" name="name_en"
                               value="<?= e($employee['name_en'] ?? old('name_en')) ?>" required>
                        <span class="field__hint">Must match the passport — the bank checks it against the WPS file.</span>
                    </div>
                    <div class="field">
                        <label for="name_ar">Name (Arabic) — الاسم بالعربية</label>
                        <input type="text" id="name_ar" name="name_ar" dir="rtl"
                               value="<?= e($employee['name_ar'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="nationality">Nationality</label>
                        <input type="text" id="nationality" name="nationality"
                               value="<?= e($employee['nationality'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="date_of_birth">Date of birth</label>
                        <input type="date" id="date_of_birth" name="date_of_birth"
                               value="<?= e($employee['date_of_birth'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="gender">Gender</label>
                        <select id="gender" name="gender">
                            <option value="">—</option>
                            <?php foreach (['male' => 'Male', 'female' => 'Female'] as $value => $label): ?>
                                <option value="<?= $value ?>"
                                    <?= ($employee['gender'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="phone">Mobile</label>
                        <input type="tel" id="phone" name="phone" value="<?= e($employee['phone'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?= e($employee['email'] ?? '') ?>">
                    </div>
                    <div class="field field--full">
                        <label for="address">Address in Qatar</label>
                        <input type="text" id="address" name="address" value="<?= e($employee['address'] ?? '') ?>">
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <legend>Residence documents</legend>
                <p class="tiny faint mb-2">
                    SmallERP warns you before each of these lapses. A QID or visa that
                    expires stops the employee working and exposes the company to a fine.
                </p>
                <div class="form-grid">
                    <div class="field">
                        <label for="qid"><?= t('qatar.qid') ?></label>
                        <input type="text" id="qid" name="qid" inputmode="numeric" maxlength="11"
                               value="<?= e($employee['qid'] ?? '') ?>" placeholder="11 digits">
                        <span class="field__hint">Required before this employee can be paid through the WPS.</span>
                    </div>
                    <div class="field">
                        <label for="qid_expiry"><?= t('qatar.qid_expiry') ?></label>
                        <input type="date" id="qid_expiry" name="qid_expiry" value="<?= e($employee['qid_expiry'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="visa_number">Visa number</label>
                        <input type="text" id="visa_number" name="visa_number"
                               value="<?= e($employee['visa_number'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="visa_expiry"><?= t('qatar.visa_expiry') ?></label>
                        <input type="date" id="visa_expiry" name="visa_expiry"
                               value="<?= e($employee['visa_expiry'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="passport_number">Passport number</label>
                        <input type="text" id="passport_number" name="passport_number"
                               value="<?= e($employee['passport_number'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="passport_expiry">Passport expiry</label>
                        <input type="date" id="passport_expiry" name="passport_expiry"
                               value="<?= e($employee['passport_expiry'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="health_card_expiry"><?= t('qatar.health_card') ?></label>
                        <input type="date" id="health_card_expiry" name="health_card_expiry"
                               value="<?= e($employee['health_card_expiry'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="contract_expiry">Contract expiry</label>
                        <input type="date" id="contract_expiry" name="contract_expiry"
                               value="<?= e($employee['contract_expiry'] ?? '') ?>">
                    </div>
                    <div class="field field--full">
                        <label for="sponsor">Sponsor (كفيل)</label>
                        <input type="text" id="sponsor" name="sponsor" value="<?= e($employee['sponsor'] ?? '') ?>">
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <legend>Employment</legend>
                <div class="form-grid">
                    <div class="field">
                        <label for="designation">Designation</label>
                        <input type="text" id="designation" name="designation"
                               value="<?= e($employee['designation'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="department">Department</label>
                        <input type="text" id="department" name="department"
                               value="<?= e($employee['department'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="join_date" class="required">Joining date</label>
                        <input type="date" id="join_date" name="join_date"
                               value="<?= e($employee['join_date'] ?? '') ?>" required>
                        <span class="field__hint">Drives gratuity and leave entitlement.</span>
                    </div>
                    <div class="field">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <?php foreach ([
                                Hr::STATUS_ACTIVE => 'Active',
                                Hr::STATUS_ON_LEAVE => 'On leave',
                                Hr::STATUS_TERMINATED => 'Terminated',
                            ] as $value => $label): ?>
                                <option value="<?= $value ?>"
                                    <?= ($employee['status'] ?? Hr::STATUS_ACTIVE) === $value ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="end_date">Last working day</label>
                        <input type="date" id="end_date" name="end_date" value="<?= e($employee['end_date'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="contract_hours_month">Contract hours per month</label>
                        <input type="number" id="contract_hours_month" name="contract_hours_month" min="1" max="400"
                               value="<?= (int) ($employee['contract_hours_month'] ?? 208) ?>">
                        <span class="field__hint">Used to work out the overtime rate.</span>
                    </div>
                    <div class="field">
                        <label for="leave_carried_forward">Leave brought forward (days)</label>
                        <input type="text" class="num" id="leave_carried_forward" name="leave_carried_forward"
                               inputmode="decimal" value="<?= e((string) (float) ($employee['leave_carried_forward'] ?? 0)) ?>">
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <legend>Wage</legend>
                <p class="tiny faint mb-2">
                    Gratuity is calculated on the <strong>basic</strong> salary only,
                    as Art. 54 of the Labour Law requires. Allowances count towards
                    leave pay and the net wage but not towards gratuity.
                </p>
                <div class="form-grid">
                    <div class="field">
                        <label for="basic_salary" class="required">Basic salary (QAR)</label>
                        <input type="text" class="num" id="basic_salary" name="basic_salary" inputmode="decimal"
                               value="<?= e($money($employee['basic_salary'] ?? 0)) ?>" required>
                    </div>
                    <div class="field">
                        <label for="housing_allowance">Housing allowance</label>
                        <input type="text" class="num" id="housing_allowance" name="housing_allowance"
                               inputmode="decimal" value="<?= e($money($employee['housing_allowance'] ?? 0)) ?>">
                    </div>
                    <div class="field">
                        <label for="transport_allowance">Transport allowance</label>
                        <input type="text" class="num" id="transport_allowance" name="transport_allowance"
                               inputmode="decimal" value="<?= e($money($employee['transport_allowance'] ?? 0)) ?>">
                    </div>
                    <div class="field">
                        <label for="food_allowance">Food allowance</label>
                        <input type="text" class="num" id="food_allowance" name="food_allowance"
                               inputmode="decimal" value="<?= e($money($employee['food_allowance'] ?? 0)) ?>">
                    </div>
                    <div class="field">
                        <label for="other_allowance">Other allowance</label>
                        <input type="text" class="num" id="other_allowance" name="other_allowance"
                               inputmode="decimal" value="<?= e($money($employee['other_allowance'] ?? 0)) ?>">
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <legend>Salary payment (WPS)</legend>
                <div class="form-grid">
                    <div class="field">
                        <label for="iban"><?= t('qatar.iban') ?></label>
                        <input type="text" id="iban" name="iban" class="mono" maxlength="34"
                               value="<?= e($employee['iban'] ?? '') ?>" placeholder="QA00 XXXX 0000 …">
                        <span class="field__hint">29 characters. Checked with the mod-97 algorithm before it is saved.</span>
                    </div>
                    <div class="field">
                        <label for="bank_name">Bank</label>
                        <input type="text" id="bank_name" name="bank_name" list="bank-list"
                               value="<?= e($employee['bank_name'] ?? '') ?>">
                        <datalist id="bank-list">
                            <?php foreach ($banks as $bank): ?>
                                <option value="<?= e($bank['name_en']) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="field">
                        <label for="bank_short_name">Bank short name</label>
                        <input type="text" id="bank_short_name" name="bank_short_name"
                               value="<?= e($employee['bank_short_name'] ?? '') ?>">
                        <span class="field__hint">Left blank, it is derived from the IBAN.</span>
                    </div>
                    <div class="field">
                        <label for="salary_frequency">Salary frequency</label>
                        <select id="salary_frequency" name="salary_frequency">
                            <?php foreach (['M' => 'Monthly', 'B' => 'Fortnightly', 'W' => 'Weekly'] as $value => $label): ?>
                                <option value="<?= $value ?>"
                                    <?= ($employee['salary_frequency'] ?? 'M') === $value ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </fieldset>

            <div class="field">
                <label for="notes"><?= t('field.notes') ?></label>
                <textarea id="notes" name="notes" rows="2"><?= e($employee['notes'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="card__foot">
            <button class="btn btn--primary" type="submit"><?= t('action.save') ?></button>
            <a class="btn btn--ghost" href="<?= url($isEdit ? '/employees/' . $employee['id'] : '/employees') ?>">
                <?= t('action.cancel') ?>
            </a>
        </div>
    </div>
</form>
