<?php
/**
 * One payroll run: the payslips, the WPS validation and the approval workflow.
 *
 * Problems with the WPS file are shown here, while there is still time to fix
 * them, rather than when the bank rejects the submission.
 */

use App\Core\Lang;
use App\Services\Payroll;
use App\Support\Money;

$isDraft = $run['status'] === Payroll::STATUS_DRAFT;
$isApproved = $run['status'] === Payroll::STATUS_APPROVED;
$isPaid = $run['status'] === Payroll::STATUS_PAID;
?>
<div class="page-head">
    <div class="page-head__text">
        <div class="flex flex-wrap">
            <h1 class="mb-0">Payroll <?= e($run['period_label']) ?></h1>
            <span class="badge badge--<?= e(status_class($run['status'])) ?>">
                <?= e(ucfirst($run['status'])) ?>
            </span>
            <?php if ($run['is_late']): ?>
                <span class="badge badge--bad">past the WPS deadline</span>
            <?php endif; ?>
        </div>
        <p class="page-head__sub">
            Pay date <?= e(fdate($run['pay_date'])) ?> ·
            WPS deadline <?= e(fdate($dueDate)) ?> ·
            <?= (int) $run['employee_count'] ?> employees
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= url('/payroll/' . $run['id'] . '/export') ?>"><?= t('action.export') ?></a>
        <?php if (!$isDraft): ?>
            <a class="btn btn--primary" href="<?= url('/payroll/' . $run['id'] . '/sif') ?>">
                Download WPS file
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="stats">
    <div class="stat">
        <div class="stat__label">Gross</div>
        <div class="stat__value"><?= e(money($run['total_gross'])) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Deductions</div>
        <div class="stat__value"><?= e(money($run['total_deductions'])) ?></div>
    </div>
    <div class="stat stat--ok">
        <div class="stat__label">Net payable</div>
        <div class="stat__value"><?= e(money($run['total_net'])) ?></div>
        <div class="stat__meta"><?= e(Money::inWords((int) $run['total_net'], 'en')) ?></div>
    </div>
</div>

<?php if ($wpsIssues !== []): ?>
    <div class="alert alert--error">
        <span class="alert__icon">✕</span>
        <span><strong>The WPS file cannot be generated yet:</strong><br>
            • <?= implode('<br>• ', array_map('e', $wpsIssues)) ?></span>
    </div>
<?php elseif (!$isDraft): ?>
    <div class="alert alert--success">
        <span class="alert__icon">✓</span>
        <span>Every record passes WPS validation. The SIF file is ready to upload
            to your bank.</span>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card__head">
        <h2 class="card__title">Payslips</h2>
        <span class="topbar__spacer"></span>
        <?php if ($isDraft): ?>
            <span class="small muted">Edit overtime and deductions below, then approve the run.</span>
        <?php endif; ?>
    </div>
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th>Employee</th>
                <th class="num">Days</th>
                <th class="num">Basic</th>
                <th class="num">Allowances</th>
                <th class="num">OT hrs</th>
                <th class="num">Overtime</th>
                <th class="num">Bonus</th>
                <th class="num">Deductions</th>
                <th class="num">Net</th>
                <th><?= t('field.actions') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($run['payslips'] as $payslip): ?>
                <?php $allowances = (int) $payslip['housing'] + (int) $payslip['transport']
                    + (int) $payslip['food'] + (int) $payslip['other_allowance']; ?>
                <tr>
                    <td>
                        <a href="<?= url('/employees/' . $payslip['employee_id']) ?>">
                            <?= e(Lang::pick($payslip, 'name')) ?></a>
                        <div class="tiny muted">
                            <?= e($payslip['code']) ?>
                            <?php if ($payslip['qid'] === '' || $payslip['iban'] === ''): ?>
                                <span class="text-bad">— QID or IBAN missing</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="num"><?= (int) $payslip['working_days'] ?></td>
                    <td class="num"><?= e(money($payslip['basic'])) ?></td>
                    <td class="num muted"><?= e(money($allowances)) ?></td>
                    <td class="num"><?= e((string) (float) $payslip['overtime_hours']) ?: '—' ?></td>
                    <td class="num"><?= (int) $payslip['overtime_amount'] > 0
                            ? e(money($payslip['overtime_amount'])) : '—' ?></td>
                    <td class="num"><?= (int) $payslip['bonus'] > 0 ? e(money($payslip['bonus'])) : '—' ?></td>
                    <td class="num <?= (int) $payslip['total_deductions'] > 0 ? 'text-bad' : 'muted' ?>">
                        <?= (int) $payslip['total_deductions'] > 0
                            ? e(money($payslip['total_deductions'])) : '—' ?>
                    </td>
                    <td class="num strong"><?= e(money($payslip['net'])) ?></td>
                    <td class="nowrap">
                        <a class="btn btn--sm btn--ghost"
                           href="<?= url('/payroll/payslip/' . $payslip['id']) ?>" target="_blank">Slip</a>
                    </td>
                </tr>

                <?php if ($isDraft && can('payroll.edit')): ?>
                    <tr>
                        <td colspan="10" style="background:var(--surface-alt);padding:.4rem .75rem">
                            <form method="post"
                                  action="<?= url('/payroll/' . $run['id'] . '/payslip/' . $payslip['id']) ?>"
                                  class="flex flex-wrap" style="gap:.5rem;align-items:flex-end">
                                <?= csrf_field() ?>
                                <div class="field" style="width:78px">
                                    <label class="tiny">Days</label>
                                    <input type="number" name="working_days" min="0" max="31"
                                           value="<?= (int) $payslip['working_days'] ?>">
                                </div>
                                <div class="field" style="width:88px">
                                    <label class="tiny">OT hours</label>
                                    <input type="text" class="num" name="overtime_hours" inputmode="decimal"
                                           value="<?= e((string) (float) $payslip['overtime_hours']) ?>">
                                </div>
                                <div class="field" style="width:104px">
                                    <label class="tiny">OT rate</label>
                                    <select name="overtime_multiplier">
                                        <option value="1.25">125% day</option>
                                        <option value="1.5">150% night</option>
                                        <option value="1.5">150% rest day</option>
                                    </select>
                                </div>
                                <div class="field" style="width:100px">
                                    <label class="tiny">Bonus</label>
                                    <input type="text" class="num" name="bonus" inputmode="decimal"
                                           value="<?= e(Money::toDecimalString((int) $payslip['bonus'])) ?>">
                                </div>
                                <div class="field" style="width:88px">
                                    <label class="tiny">Absent days</label>
                                    <input type="text" class="num" name="absence_days" inputmode="decimal"
                                           value="<?= e((string) (float) $payslip['absence_days']) ?>">
                                </div>
                                <div class="field" style="width:100px">
                                    <label class="tiny">Loan repay</label>
                                    <input type="text" class="num" name="loan_deduction" inputmode="decimal"
                                           value="<?= e(Money::toDecimalString((int) $payslip['loan_deduction'])) ?>">
                                </div>
                                <div class="field" style="width:100px">
                                    <label class="tiny">Other deduct.</label>
                                    <input type="text" class="num" name="other_deduction" inputmode="decimal"
                                           value="<?= e(Money::toDecimalString((int) $payslip['other_deduction'])) ?>">
                                </div>
                                <div class="field" style="flex:1;min-width:130px">
                                    <label class="tiny">Note</label>
                                    <input type="text" name="notes" value="<?= e($payslip['notes']) ?>">
                                </div>
                                <button class="btn btn--sm btn--primary" type="submit">Update</button>
                            </form>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <td colspan="8" class="text-end">Total net payable</td>
                <td class="num"><?= e(money($run['total_net'])) ?></td>
                <td></td>
            </tr>
            </tfoot>
        </table>
    </div>
</div>

<div class="split">
    <?php if ($isDraft && can('payroll.post')): ?>
        <div class="card">
            <div class="card__head"><h2 class="card__title">Approve this run</h2></div>
            <div class="card__body">
                <p class="small muted">
                    Approving books the wage cost and creates the liability to your
                    staff. The money leaves the bank later, when you mark the run
                    as paid. After approval the payslips can no longer be edited.
                </p>
            </div>
            <div class="card__foot">
                <form method="post" action="<?= url('/payroll/' . $run['id'] . '/approve') ?>"
                      data-confirm="Approve payroll <?= e($run['period_label']) ?> for <?= e(money($run['total_net'], true)) ?>? The payslips can no longer be edited.">
                    <?= csrf_field() ?>
                    <button class="btn btn--primary" type="submit"><?= t('action.approve') ?></button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($isApproved && can('payroll.post')): ?>
        <div class="card">
            <div class="card__head"><h2 class="card__title">Mark as paid</h2></div>
            <form method="post" action="<?= url('/payroll/' . $run['id'] . '/paid') ?>"
                  data-confirm="Mark this payroll as paid? It will move the money out of the bank account in your books.">
                <?= csrf_field() ?>
                <div class="card__body">
                    <p class="small muted">
                        Do this once your bank confirms the WPS transfer has gone through.
                    </p>
                    <div class="field mb-1">
                        <label for="account_id">Paid from</label>
                        <select id="account_id" name="account_id">
                            <?php foreach ($bankAccounts as $account): ?>
                                <option value="<?= (int) $account['id'] ?>"
                                    <?= (int) $account['id'] === $defaultBankAccount ? 'selected' : '' ?>>
                                    <?= e($account['code'] . ' · ' . Lang::pick($account, 'name')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="paid_date">Date paid</label>
                        <input type="date" id="paid_date" name="paid_date" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="card__foot">
                    <button class="btn btn--primary" type="submit">Mark as paid</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php if (!$isDraft): ?>
        <div class="card">
            <div class="card__head"><h2 class="card__title">WPS submission</h2></div>
            <div class="card__body">
                <p class="small muted">
                    Download the SIF file and upload it to your bank's WPS portal.
                    The file is CSV: one EDR line per employee, then an SCR control
                    line with the totals your bank reconciles against.
                </p>
                <?php if ($run['sif_generated_at']): ?>
                    <p class="small text-ok">
                        ✓ Last generated <?= e(fdate($run['sif_generated_at'], true)) ?>
                    </p>
                <?php endif; ?>
                <div class="btn-group mt-1">
                    <a class="btn btn--primary" href="<?= url('/payroll/' . $run['id'] . '/sif') ?>">
                        Normal payment
                    </a>
                    <a class="btn" href="<?= url('/payroll/' . $run['id'] . '/sif', ['payment_type' => 'Final Settlement'])?>">
                        Final settlement
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($isPaid): ?>
        <div class="card">
            <div class="card__body">
                <p class="mb-0 text-ok">
                    ✓ This payroll was paid on <?= e(fdate($run['paid_at'], true)) ?>.
                </p>
            </div>
        </div>
    <?php endif; ?>
</div>
