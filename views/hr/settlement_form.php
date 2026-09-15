<?php
/**
 * End-of-service settlement: calculate, review, then commit.
 *
 * Deliberately two steps — the figures are shown before anything is posted,
 * because this is usually the largest single payment an SME makes to a leaver.
 */

use App\Core\Lang;
use App\Support\Money;
?>
<form method="post" action="<?= url('/gratuity/calculate') ?>">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card__head"><h2 class="card__title">Settlement details</h2></div>
        <div class="card__body">
            <div class="form-grid">
                <div class="field">
                    <label for="employee_id" class="required">Employee</label>
                    <select id="employee_id" name="employee_id" required>
                        <option value="">— choose —</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= (int) $employee['id'] ?>"
                                <?= (int) ($input['employee_id'] ?? $_GET['employee_id'] ?? 0) === (int) $employee['id'] ? 'selected' : '' ?>>
                                <?= e($employee['code'] . ' · ' . Lang::pick($employee, 'name')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="last_working_day" class="required">Last working day</label>
                    <input type="date" id="last_working_day" name="last_working_day" required
                           value="<?= e($input['last_working_day'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="field">
                    <label for="reason">Reason for leaving</label>
                    <select id="reason" name="reason">
                        <?php foreach ([
                            'resignation' => 'Resignation',
                            'termination' => 'Termination by employer',
                            'contract_end' => 'End of contract',
                            'retirement' => 'Retirement',
                        ] as $value => $label): ?>
                            <option value="<?= $value ?>"
                                <?= ($input['reason'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="other_dues">Other dues (QAR)</label>
                    <input type="text" class="num" id="other_dues" name="other_dues" inputmode="decimal"
                           value="<?= e(Money::toDecimalString((int) ($input['other_dues'] ?? 0))) ?>">
                    <span class="field__hint">Air ticket, outstanding expenses, final commission.</span>
                </div>
                <div class="field">
                    <label for="deductions">Deductions (QAR)</label>
                    <input type="text" class="num" id="deductions" name="deductions" inputmode="decimal"
                           value="<?= e(Money::toDecimalString((int) ($input['deductions'] ?? 0))) ?>">
                    <span class="field__hint">Unrecovered advances or loans, which Art. 54 permits.</span>
                </div>
                <div class="field">
                    <label class="check" style="margin-top:1.4rem">
                        <input type="checkbox" name="pay_in_lieu_of_notice" value="1"
                            <?= !empty($input['pay_in_lieu_of_notice']) ? 'checked' : '' ?>>
                        <span>Pay in lieu of notice</span>
                    </label>
                    <span class="field__hint">Art. 49: one month up to five years of service, two months after.</span>
                </div>
            </div>
        </div>
        <div class="card__foot">
            <button class="btn btn--primary" type="submit">Calculate</button>
            <a class="btn btn--ghost" href="<?= url('/gratuity') ?>"><?= t('action.cancel') ?></a>
        </div>
    </div>
</form>

<?php if ($preview !== null): ?>
    <?php $gratuity = $preview['gratuity']; ?>
    <div class="card">
        <div class="card__head">
            <h2 class="card__title">
                Calculation — <?= e(Lang::pick($preview['employee'], 'name')) ?>
            </h2>
        </div>
        <div class="card__body">
            <div class="split">
                <div>
                    <h3>How the gratuity is worked out</h3>
                    <table class="data">
                        <tbody>
                        <tr>
                            <td>Joined</td>
                            <td class="num"><?= e(fdate($preview['employee']['join_date'])) ?></td>
                        </tr>
                        <tr>
                            <td>Last working day</td>
                            <td class="num"><?= e(fdate($preview['last_working_day'])) ?></td>
                        </tr>
                        <tr>
                            <td>Service</td>
                            <td class="num"><?= (int) $gratuity['service_days'] ?> days
                                (<?= e(number_format((float) $gratuity['service_years'], 2)) ?> years)</td>
                        </tr>
                        <tr>
                            <td>Monthly basic wage</td>
                            <td class="num"><?= e(money($preview['employee']['basic_salary'])) ?></td>
                        </tr>
                        <tr>
                            <td>Daily basic wage (÷ 30)</td>
                            <td class="num"><?= e(money($gratuity['daily_basic_wage_dirhams'])) ?></td>
                        </tr>
                        <tr>
                            <td>Rate</td>
                            <td class="num"><?= e((string) $gratuity['weeks_per_year']) ?> weeks per year</td>
                        </tr>
                        <tr>
                            <td>Entitlement</td>
                            <td class="num"><?= e((string) $gratuity['entitlement_days']) ?> days</td>
                        </tr>
                        </tbody>
                    </table>
                    <?php foreach ($gratuity['notes'] as $note): ?>
                        <p class="tiny faint mt-1 mb-0"><?= e($note) ?></p>
                    <?php endforeach; ?>
                </div>

                <div>
                    <h3>Final settlement</h3>
                    <div class="totals-box" style="max-width:none">
                        <div class="totals-box__row">
                            <span>Gratuity (Art. 54)</span>
                            <span class="num mono"><?= e(money($gratuity['gross_dirhams'])) ?></span>
                        </div>
                        <div class="totals-box__row">
                            <span>Leave encashment
                                <span class="tiny muted">(<?= e((string) $preview['leave_days']) ?> days)</span></span>
                            <span class="num mono"><?= e(money($preview['leave_encashment'])) ?></span>
                        </div>
                        <?php if ((int) $preview['notice_pay'] > 0): ?>
                            <div class="totals-box__row">
                                <span>Pay in lieu of notice
                                    <span class="tiny muted">(<?= (int) $preview['notice_days'] ?> days)</span></span>
                                <span class="num mono"><?= e(money($preview['notice_pay'])) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ((int) $preview['other_dues'] > 0): ?>
                            <div class="totals-box__row">
                                <span>Other dues</span>
                                <span class="num mono"><?= e(money($preview['other_dues'])) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ((int) $preview['deductions'] > 0): ?>
                            <div class="totals-box__row">
                                <span class="text-bad">Deductions</span>
                                <span class="num mono text-bad">−<?= e(money($preview['deductions'])) ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="totals-box__row totals-box__row--grand">
                            <span>Net payable (QAR)</span>
                            <span class="num mono"><?= e(money($preview['net_payable'])) ?></span>
                        </div>
                    </div>
                    <p class="tiny faint mt-1"><?= e(Money::inWords((int) $preview['net_payable'], 'en')) ?></p>
                </div>
            </div>
        </div>

        <?php if (can('hr.edit')): ?>
            <div class="card__foot">
                <form method="post" action="<?= url('/gratuity/save') ?>"
                      data-confirm="Record this settlement? It posts the cost to the ledger and marks the employee as terminated.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="employee_id" value="<?= (int) $preview['employee']['id'] ?>">
                    <input type="hidden" name="last_working_day" value="<?= e($preview['last_working_day']) ?>">
                    <input type="hidden" name="reason" value="<?= e($preview['reason']) ?>">
                    <input type="hidden" name="other_dues"
                           value="<?= e(Money::toDecimalString((int) $preview['other_dues'])) ?>">
                    <input type="hidden" name="deductions"
                           value="<?= e(Money::toDecimalString((int) $preview['deductions'])) ?>">
                    <?php if ((int) $preview['notice_pay'] > 0): ?>
                        <input type="hidden" name="pay_in_lieu_of_notice" value="1">
                    <?php endif; ?>
                    <button class="btn btn--primary" type="submit">Record and post this settlement</button>
                </form>
                <span class="tiny faint">
                    The employee will be marked as terminated with this last working day.
                </span>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
