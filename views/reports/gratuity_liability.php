<?php
/** End-of-service liability — same data as the HR screen, framed for finance. */

use App\Core\Lang;
?>
<div class="page-head">
    <div class="page-head__text">
        <p class="page-head__sub">
            Accrued under Art. 54 of the Labour Law as at <?= e(fdate(date('Y-m-d'))) ?>
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= url('/reports/gratuity-liability', ['format' => 'csv']) ?>">
            <?= t('action.export') ?>
        </a>
        <button class="btn" type="button" data-print><?= t('action.print') ?></button>
    </div>
</div>

<div class="stats">
    <div class="stat stat--warn">
        <div class="stat__label">Accrued gratuity liability</div>
        <div class="stat__value"><?= e(money($liability['total'])) ?></div>
        <div class="stat__meta"><?= count($liability['rows']) ?> employees</div>
    </div>
    <div class="stat">
        <div class="stat__label">For comparison: stock on hand</div>
        <div class="stat__value"><?= e(money($stockValue)) ?></div>
        <div class="stat__meta">
            <?php if ($stockValue > 0): ?>
                gratuity is <?= e((string) round($liability['total'] / $stockValue, 1)) ?>× stock value
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <h2 class="card__title">By employee</h2>
        <span class="topbar__spacer"></span>
        <span class="small muted">
            <?= e((string) $tiers[0]) ?> weeks/year, rising to <?= e((string) $tiers[5]) ?> after 5 years
            and <?= e((string) $tiers[10]) ?> after 10
        </span>
    </div>
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
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
                    <td>
                        <a href="<?= url('/employees/' . $row['employee']['id']) ?>">
                            <?= e(Lang::pick($row['employee'], 'name')) ?></a>
                        <div class="tiny muted"><?= e($row['employee']['designation']) ?></div>
                    </td>
                    <td class="nowrap tiny"><?= e(fdate($row['employee']['join_date'])) ?></td>
                    <td class="num"><?= e(number_format((float) $row['service_years'], 2)) ?> yrs</td>
                    <td class="num muted"><?= e((string) $row['weeks_per_year']) ?></td>
                    <td class="num"><?= e(money($row['employee']['basic_salary'])) ?></td>
                    <td class="num strong <?= $row['eligible'] ? '' : 'muted' ?>">
                        <?= e(money($row['amount'])) ?>
                        <?php if (!$row['eligible']): ?>
                            <div class="tiny">under 1 year</div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr><td colspan="5" class="text-end">Total</td>
                <td class="num"><?= e(money($liability['total'])) ?></td></tr>
            </tfoot>
        </table>
    </div>
    <div class="card__foot">
        <span class="small muted">
            Gratuity is calculated on the basic wage only, excluding allowances.
            Employees with under a year of service accrue but are not yet entitled,
            so their figure is shown for provisioning and excluded from the total due.
        </span>
    </div>
</div>
