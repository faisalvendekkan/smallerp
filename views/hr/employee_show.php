<?php
/** One employee: documents, wage, leave and what they would be owed today. */

use App\Core\Lang;
use App\Services\Hr;
?>
<div class="page-head">
    <div class="page-head__text">
        <div class="flex flex-wrap">
            <h1 class="mb-0"><?= e(Lang::pick($employee, 'name')) ?></h1>
            <span class="badge badge--<?= e(status_class($employee['status'])) ?>">
                <?= e(ucfirst(str_replace('_', ' ', $employee['status']))) ?>
            </span>
        </div>
        <p class="page-head__sub">
            <?= e($employee['code']) ?>
            <?php if ($employee['designation'] !== ''): ?> · <?= e($employee['designation']) ?><?php endif; ?>
            <?php if ($employee['department'] !== ''): ?> · <?= e($employee['department']) ?><?php endif; ?>
            · joined <?= e(fdate($employee['join_date'])) ?>
            (<?= e((string) $employee['service_years']) ?> years)
        </p>
    </div>
    <div class="page-head__actions">
        <?php if (can('hr.edit') && $employee['status'] !== Hr::STATUS_TERMINATED): ?>
            <a class="btn" href="<?= url('/gratuity/calculate', ['employee_id' => $employee['id']]) ?>">
                End of service
            </a>
        <?php endif; ?>
        <?php if (can('hr.edit')): ?>
            <a class="btn btn--primary" href="<?= url('/employees/' . $employee['id'] . '/edit') ?>">
                <?= t('action.edit') ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="stats">
    <div class="stat">
        <div class="stat__label">Monthly wage</div>
        <div class="stat__value"><?= e(money($employee['gross_salary'])) ?></div>
        <div class="stat__meta">basic <?= e(money($employee['basic_salary'])) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label"><?= t('qatar.gratuity') ?> accrued</div>
        <div class="stat__value"><?= e(money($gratuity['gross_dirhams'])) ?></div>
        <div class="stat__meta">
            <?php if ($gratuity['eligible']): ?>
                <?= e((string) $gratuity['weeks_per_year']) ?> weeks/year ·
                <?= e((string) $gratuity['entitlement_days']) ?> days
            <?php else: ?>
                under one year of service
            <?php endif; ?>
        </div>
    </div>
    <div class="stat <?= $employee['leave_balance'] < 0 ? 'stat--bad' : '' ?>">
        <div class="stat__label">Leave balance</div>
        <div class="stat__value"><?= e((string) $employee['leave_balance']) ?><span class="small muted"> days</span></div>
        <div class="stat__meta">
            <?= (int) $employee['leave_entitlement'] ?>/year ·
            <?= e((string) $employee['leave_taken']) ?> taken
        </div>
    </div>
</div>

<div class="split split--sidebar">
    <div>
        <!-- Residence documents -->
        <div class="card">
            <div class="card__head"><h2 class="card__title">Residence documents</h2></div>
            <?php if ($employee['expiries'] === []): ?>
                <?= App\Core\View::partial('partials/empty', [
                    'message' => 'No document expiry dates recorded yet.',
                    'actionUrl' => can('hr.edit') ? url('/employees/' . $employee['id'] . '/edit') : null,
                    'actionLabel' => 'Add them',
                ]) ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                        <tr>
                            <th>Document</th>
                            <th>Number</th>
                            <th>Expires</th>
                            <th class="num">Days left</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($employee['expiries'] as $expiry): ?>
                            <?php
                            $numbers = [
                                'qid_expiry' => $employee['qid'],
                                'visa_expiry' => $employee['visa_number'],
                                'passport_expiry' => $employee['passport_number'],
                            ];
                            ?>
                            <tr>
                                <td><?= e(Lang::pick($expiry, 'document')) ?></td>
                                <td class="mono tiny"><?= e($numbers[$expiry['field']] ?? '') ?></td>
                                <td class="nowrap"><?= e(fdate($expiry['date'])) ?></td>
                                <td class="num <?= $expiry['days'] < 0 ? 'text-bad strong' : ($expiry['days'] <= 60 ? 'text-warn' : '') ?>">
                                    <?= $expiry['days'] < 0
                                        ? 'expired ' . abs((int) $expiry['days']) . 'd ago'
                                        : (int) $expiry['days'] ?>
                                </td>
                                <td>
                                    <div class="expiry expiry--<?= e($expiry['level']) ?>">
                                        <span class="expiry__dot"></span>
                                        <span class="tiny"><?= e(ucfirst($expiry['level'])) ?></span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Payslips -->
        <?php if ($payslips !== []): ?>
            <div class="card">
                <div class="card__head"><h2 class="card__title">Payslips</h2></div>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                        <tr>
                            <th>Period</th>
                            <th class="num">Days</th>
                            <th class="num">Gross</th>
                            <th class="num">Deductions</th>
                            <th class="num">Net</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($payslips as $payslip): ?>
                            <tr>
                                <td class="nowrap">
                                    <?= sprintf('%02d/%04d', (int) $payslip['period_month'], (int) $payslip['period_year']) ?>
                                    <span class="badge badge--<?= e(status_class($payslip['run_status'])) ?>">
                                        <?= e($payslip['run_status']) ?></span>
                                </td>
                                <td class="num"><?= (int) $payslip['working_days'] ?></td>
                                <td class="num"><?= e(money($payslip['gross'])) ?></td>
                                <td class="num muted"><?= e(money($payslip['total_deductions'])) ?></td>
                                <td class="num strong"><?= e(money($payslip['net'])) ?></td>
                                <td>
                                    <a class="btn btn--sm btn--ghost"
                                       href="<?= url('/payroll/payslip/' . $payslip['id']) ?>" target="_blank">
                                        <?= t('action.print') ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Leave -->
        <div class="card">
            <div class="card__head">
                <h2 class="card__title"><?= t('nav.leave') ?></h2>
                <span class="topbar__spacer"></span>
                <a class="btn btn--sm" href="<?= url('/leave') ?>">All leave</a>
            </div>
            <?php if ($leave === []): ?>
                <?= App\Core\View::partial('partials/empty', ['message' => 'No leave recorded.']) ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                        <tr><th>Type</th><th>From</th><th>To</th><th class="num">Days</th><th><?= t('field.status') ?></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($leave as $row): ?>
                            <tr>
                                <td><?= e(Lang::isRtl()
                                        ? ($leaveTypes[$row['leave_type']]['name_ar'] ?? $row['leave_type'])
                                        : ($leaveTypes[$row['leave_type']]['name_en'] ?? $row['leave_type'])) ?></td>
                                <td class="nowrap tiny"><?= e(fdate($row['start_date'])) ?></td>
                                <td class="nowrap tiny"><?= e(fdate($row['end_date'])) ?></td>
                                <td class="num"><?= e((string) (float) $row['days']) ?></td>
                                <td><span class="badge badge--<?= e(status_class($row['status'])) ?>">
                                    <?= t('status.' . $row['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($settlements !== []): ?>
            <div class="card">
                <div class="card__head"><h2 class="card__title">End of service settlement</h2></div>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                        <tr><th>Last day</th><th>Reason</th><th class="num">Gratuity</th>
                            <th class="num">Leave</th><th class="num">Net paid</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($settlements as $settlement): ?>
                            <tr>
                                <td class="nowrap"><?= e(fdate($settlement['last_working_day'])) ?></td>
                                <td><?= e(ucfirst($settlement['reason'])) ?></td>
                                <td class="num"><?= e(money($settlement['gratuity_amount'])) ?></td>
                                <td class="num"><?= e(money($settlement['leave_encashment'])) ?></td>
                                <td class="num strong"><?= e(money($settlement['net_payable'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div>
        <div class="card">
            <div class="card__head"><h2 class="card__title">Wage breakdown</h2></div>
            <div class="card__body">
                <div class="totals-box" style="max-width:none">
                    <?php foreach ([
                        'Basic' => 'basic_salary',
                        'Housing' => 'housing_allowance',
                        'Transport' => 'transport_allowance',
                        'Food' => 'food_allowance',
                        'Other' => 'other_allowance',
                    ] as $label => $field): ?>
                        <?php if ((int) $employee[$field] > 0): ?>
                            <div class="totals-box__row">
                                <span class="muted"><?= e($label) ?></span>
                                <span class="num mono"><?= e(money($employee[$field])) ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <div class="totals-box__row totals-box__row--grand">
                        <span>Monthly</span>
                        <span class="num mono"><?= e(money($employee['gross_salary'])) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card__head"><h2 class="card__title">Salary payment</h2></div>
            <div class="card__body">
                <div class="detail__label"><?= t('qatar.iban') ?></div>
                <div class="detail__value mono small">
                    <?= e(App\Support\Qatar::formatIban($employee['iban'])) ?: '<span class="text-warn">not set</span>' ?>
                </div>
                <?php if ($employee['bank_name'] !== ''): ?>
                    <div class="detail__label mt-1">Bank</div>
                    <div class="detail__value small"><?= e($employee['bank_name']) ?>
                        <?php if ($employee['bank_short_name'] !== ''): ?>
                            <span class="badge badge--muted"><?= e($employee['bank_short_name']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($employee['iban'] === '' || $employee['qid'] === ''): ?>
                    <div class="alert alert--warning mt-2 mb-0">
                        <span>This employee cannot be included in a WPS file until the
                            QID and IBAN are both recorded.</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card__head"><h2 class="card__title">If they left today</h2></div>
            <div class="card__body">
                <div class="totals-box" style="max-width:none">
                    <div class="totals-box__row">
                        <span class="muted">Service</span>
                        <span class="num mono"><?= (int) $gratuity['service_days'] ?> days</span>
                    </div>
                    <div class="totals-box__row">
                        <span class="muted">Daily basic wage</span>
                        <span class="num mono"><?= e(money($gratuity['daily_basic_wage_dirhams'])) ?></span>
                    </div>
                    <div class="totals-box__row">
                        <span class="muted">Entitlement</span>
                        <span class="num mono"><?= e((string) $gratuity['entitlement_days']) ?> days</span>
                    </div>
                    <div class="totals-box__row totals-box__row--grand">
                        <span>Gratuity</span>
                        <span class="num mono"><?= e(money($gratuity['gross_dirhams'])) ?></span>
                    </div>
                </div>
                <?php foreach ($gratuity['notes'] as $note): ?>
                    <p class="tiny faint mt-1 mb-0"><?= e($note) ?></p>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
