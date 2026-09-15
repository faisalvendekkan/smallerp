<?php
/** Payroll runs and the WPS status. */

use App\Core\Lang;
use App\Support\Wps;
?>
<div class="page-head">
    <div class="page-head__text">
        <p class="page-head__sub">
            Since Law No. 1 of 2015, wages in Qatar must be paid through the Wage
            Protection System within <?= Wps::PAYMENT_DEADLINE_DAYS ?> days of the
            due date. SmallERP produces the SIF file your bank needs.
        </p>
    </div>
</div>

<?php if (!$wpsConfigured): ?>
    <div class="alert alert--warning">
        <span class="alert__icon">!</span>
        <span>
            WPS is not fully configured. A SIF file cannot be generated until the
            company <strong>Establishment ID</strong> and <strong>salary account
            IBAN</strong> are recorded.
            <a href="<?= url('/settings') ?>">Open Settings</a>.
        </span>
    </div>
<?php else: ?>
    <div class="card mb-2">
        <div class="card__body" style="padding:.7rem 1rem">
            <div class="flex flex-wrap" style="gap:1.75rem">
                <div>
                    <div class="detail__label">Establishment ID</div>
                    <div class="detail__value mono"><?= e($employer['establishment_id']) ?></div>
                </div>
                <div>
                    <div class="detail__label">Salary account</div>
                    <div class="detail__value mono small">
                        <?= e(App\Support\Qatar::formatIban($employer['iban'])) ?>
                    </div>
                </div>
                <div>
                    <div class="detail__label">Bank</div>
                    <div class="detail__value"><?= e($employer['bank_short_name']) ?></div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="split split--sidebar">
    <div>
        <div class="card">
            <div class="card__head"><h2 class="card__title">Payroll runs</h2></div>
            <?php if ($runs === []): ?>
                <?= App\Core\View::partial('partials/empty', [
                    'message' => 'No payroll has been run yet.',
                ]) ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                        <tr>
                            <th>Period</th>
                            <th>Pay date</th>
                            <th>WPS deadline</th>
                            <th class="num">Staff</th>
                            <th class="num">Gross</th>
                            <th class="num">Net</th>
                            <th><?= t('field.status') ?></th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($runs as $run): ?>
                            <tr>
                                <td class="nowrap strong">
                                    <a href="<?= url('/payroll/' . $run['id']) ?>"><?= e($run['period_label']) ?></a>
                                </td>
                                <td class="nowrap tiny"><?= e(fdate($run['pay_date'])) ?></td>
                                <td class="nowrap tiny <?= $run['is_late'] ? 'text-bad strong' : '' ?>">
                                    <?= e(fdate($run['wps_due_date'])) ?>
                                    <?php if ($run['is_late']): ?>
                                        <div class="tiny">overdue</div>
                                    <?php endif; ?>
                                </td>
                                <td class="num"><?= (int) $run['employee_count'] ?></td>
                                <td class="num"><?= e(money($run['total_gross'])) ?></td>
                                <td class="num strong"><?= e(money($run['total_net'])) ?></td>
                                <td>
                                    <span class="badge badge--<?= e(status_class($run['status'])) ?>">
                                        <?= e(ucfirst($run['status'])) ?>
                                    </span>
                                    <?php if ($run['sif_generated_at']): ?>
                                        <div class="tiny text-ok">SIF sent</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($run['status'] !== 'draft'): ?>
                                        <a class="btn btn--sm" href="<?= url('/payroll/' . $run['id'] . '/sif') ?>">
                                            SIF
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (can('payroll.create')): ?>
        <div>
            <div class="card">
                <div class="card__head"><h2 class="card__title">Open a payroll run</h2></div>
                <form method="post" action="<?= url('/payroll/open') ?>">
                    <?= csrf_field() ?>
                    <div class="card__body">
                        <p class="small muted">
                            The run is pre-filled from each employee's contract, pro-rated
                            for anyone who joined or left mid-month. You then adjust
                            overtime and deductions before approving it.
                        </p>
                        <div class="field mb-1">
                            <label for="period_month">Month</label>
                            <select id="period_month" name="period_month">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?= $m ?>" <?= $m === $suggestedMonth ? 'selected' : '' ?>>
                                        <?= e(App\Core\Lang::monthName($m)) ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="field mb-1">
                            <label for="period_year">Year</label>
                            <select id="period_year" name="period_year">
                                <?php for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 3; $y--): ?>
                                    <option value="<?= $y ?>" <?= $y === $suggestedYear ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="pay_date">Pay date</label>
                            <input type="date" id="pay_date" name="pay_date">
                            <span class="field__hint">Left blank, the WPS deadline is used.</span>
                        </div>
                    </div>
                    <div class="card__foot">
                        <button class="btn btn--primary btn--block" type="submit">Open run</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
