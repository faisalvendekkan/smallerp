<?php
/**
 * End-of-service liability across the workforce.
 *
 * Gratuity accrues from the first day of service under Art. 54 even though it
 * only becomes payable after a year, so for an established SME this is often
 * the largest liability on the balance sheet — and the one most often absent
 * from it.
 */

use App\Core\Lang;
?>
<div class="page-head">
    <div class="page-head__text">
        <p class="page-head__sub">
            What the company would owe if every employee left today, under
            Art. 54 of the Labour Law.
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= url('/reports/gratuity-liability', ['format' => 'csv']) ?>">
            <?= t('action.export') ?>
        </a>
        <?php if (can('hr.edit')): ?>
            <a class="btn btn--primary" href="<?= url('/gratuity/calculate') ?>">Calculate a settlement</a>
        <?php endif; ?>
    </div>
</div>

<div class="stats">
    <div class="stat stat--warn">
        <div class="stat__label">Total accrued liability</div>
        <div class="stat__value"><?= e(money($liability['total'])) ?></div>
        <div class="stat__meta"><?= count($liability['rows']) ?> employees</div>
    </div>
    <div class="stat">
        <div class="stat__label">Weeks per year of service</div>
        <div class="stat__value" style="font-size:1.05rem">
            <?= e((string) $tiers[0]) ?> → <?= e((string) $tiers[5]) ?> → <?= e((string) $tiers[10]) ?>
        </div>
        <div class="stat__meta">0–5 yrs · 5–10 yrs · 10+ yrs</div>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <h2 class="card__title">By employee</h2>
        <span class="topbar__spacer"></span>
        <input type="search" class="tiny" style="width:180px" placeholder="Filter…"
               data-table-filter="#gratuity-table">
    </div>
    <div class="table-wrap">
        <table class="data" id="gratuity-table">
            <thead>
            <tr>
                <th>Code</th>
                <th>Employee</th>
                <th>Joined</th>
                <th class="num">Service</th>
                <th class="num">Weeks/yr</th>
                <th class="num">Basic salary</th>
                <th class="num">Accrued</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($liability['rows'] as $row): ?>
                <tr>
                    <td class="mono tiny"><?= e($row['employee']['code']) ?></td>
                    <td>
                        <a href="<?= url('/employees/' . $row['employee']['id']) ?>">
                            <?= e(Lang::pick($row['employee'], 'name')) ?></a>
                        <?php if (!$row['eligible']): ?>
                            <span class="badge badge--muted">under 1 year</span>
                        <?php endif; ?>
                    </td>
                    <td class="nowrap tiny"><?= e(fdate($row['employee']['join_date'])) ?></td>
                    <td class="num"><?= e(number_format((float) $row['service_years'], 2)) ?> yrs</td>
                    <td class="num muted"><?= e((string) $row['weeks_per_year']) ?></td>
                    <td class="num"><?= e(money($row['employee']['basic_salary'])) ?></td>
                    <td class="num strong"><?= e(money($row['amount'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <td colspan="6" class="text-end">Total liability</td>
                <td class="num"><?= e(money($liability['total'])) ?></td>
            </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php if ($settlements !== []): ?>
    <div class="card">
        <div class="card__head"><h2 class="card__title">Settlements paid</h2></div>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Employee</th>
                    <th>Last working day</th>
                    <th>Reason</th>
                    <th class="num">Service</th>
                    <th class="num">Gratuity</th>
                    <th class="num">Leave</th>
                    <th class="num">Net paid</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($settlements as $settlement): ?>
                    <tr>
                        <td><a href="<?= url('/employees/' . $settlement['employee_id']) ?>">
                            <?= e(Lang::pick($settlement, 'name')) ?></a></td>
                        <td class="nowrap tiny"><?= e(fdate($settlement['last_working_day'])) ?></td>
                        <td class="small"><?= e(ucfirst(str_replace('_', ' ', $settlement['reason']))) ?></td>
                        <td class="num"><?= e(number_format((float) $settlement['service_years'], 2)) ?> yrs</td>
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
