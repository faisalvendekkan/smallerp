<?php
/** Profit and loss. */

use App\Core\Lang;
?>
<div class="page-head">
    <div class="page-head__text">
        <p class="page-head__sub"><?= e(fdate($from)) ?> to <?= e(fdate($to)) ?></p>
    </div>
    <div class="page-head__actions">
        <button class="btn" type="button" onclick="window.print()"><?= t('action.print') ?></button>
    </div>
</div>

<div class="card no-print">
    <div class="card__head">
        <form class="filters" method="get" action="<?= url('/reports/profit-loss') ?>" data-auto-submit>
            <div class="field"><label for="from">From</label>
                <input type="date" id="from" name="from" value="<?= e($from) ?>"></div>
            <div class="field"><label for="to">To</label>
                <input type="date" id="to" name="to" value="<?= e($to) ?>"></div>
            <span class="topbar__spacer"></span>
            <a class="btn btn--sm" href="<?= url('/reports/profit-loss', ['from' => date('Y-01-01'), 'to' => date('Y-m-d')]) ?>">
                Year to date
            </a>
            <a class="btn btn--sm" href="<?= url('/reports/profit-loss', ['from' => date('Y-m-01'), 'to' => date('Y-m-d')]) ?>">
                This month
            </a>
        </form>
    </div>
</div>

<div class="stats">
    <div class="stat">
        <div class="stat__label">Revenue</div>
        <div class="stat__value"><?= e(money($report['income_total'])) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Gross profit</div>
        <div class="stat__value"><?= e(money($report['gross_profit'])) ?></div>
        <div class="stat__meta"><?= e((string) $report['gross_margin_pct']) ?>% margin</div>
    </div>
    <div class="stat <?= $report['net_profit'] >= 0 ? 'stat--ok' : 'stat--bad' ?>">
        <div class="stat__label">Net profit</div>
        <div class="stat__value"><?= e(money($report['net_profit'])) ?></div>
        <div class="stat__meta"><?= e((string) $report['net_margin_pct']) ?>% margin</div>
    </div>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="data">
            <tbody>
            <tr><th colspan="2" style="background:var(--brand-light);color:var(--brand)">Revenue</th></tr>
            <?php foreach ($report['income'] as $row): ?>
                <tr>
                    <td><a href="<?= url('/accounts/' . $row['id']) ?>">
                        <span class="mono tiny"><?= e($row['code']) ?></span>
                        <?= e(Lang::pick($row, 'name')) ?></a></td>
                    <td class="num"><?= e(money($row['amount'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="strong">
                <td>Total revenue</td>
                <td class="num"><?= e(money($report['income_total'])) ?></td>
            </tr>

            <?php if ($report['cost_of_sales'] !== []): ?>
                <tr><th colspan="2" style="background:var(--brand-light);color:var(--brand)">Cost of sales</th></tr>
                <?php foreach ($report['cost_of_sales'] as $row): ?>
                    <tr>
                        <td><a href="<?= url('/accounts/' . $row['id']) ?>">
                            <span class="mono tiny"><?= e($row['code']) ?></span>
                            <?= e(Lang::pick($row, 'name')) ?></a></td>
                        <td class="num"><?= e(money($row['amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="strong">
                    <td>Total cost of sales</td>
                    <td class="num"><?= e(money($report['cost_of_sales_total'])) ?></td>
                </tr>
                <tr class="strong" style="background:var(--surface-alt)">
                    <td>Gross profit
                        <span class="tiny muted">(<?= e((string) $report['gross_margin_pct']) ?>%)</span></td>
                    <td class="num"><?= e(money($report['gross_profit'])) ?></td>
                </tr>
            <?php endif; ?>

            <tr><th colspan="2" style="background:var(--brand-light);color:var(--brand)">Expenses</th></tr>
            <?php foreach ($report['expenses'] as $row): ?>
                <tr>
                    <td><a href="<?= url('/accounts/' . $row['id']) ?>">
                        <span class="mono tiny"><?= e($row['code']) ?></span>
                        <?= e(Lang::pick($row, 'name')) ?></a></td>
                    <td class="num"><?= e(money($row['amount'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="strong">
                <td>Total expenses</td>
                <td class="num"><?= e(money($report['expenses_total'])) ?></td>
            </tr>
            </tbody>
            <tfoot>
            <tr>
                <td>Net profit <?= $report['net_profit'] < 0 ? '(loss)' : '' ?>
                    <span class="tiny muted">(<?= e((string) $report['net_margin_pct']) ?>%)</span></td>
                <td class="num <?= $report['net_profit'] >= 0 ? 'text-ok' : 'text-bad' ?>">
                    <?= e(money($report['net_profit'])) ?>
                </td>
            </tr>
            </tfoot>
        </table>
    </div>

    <?php if ($report['indicative_income_tax'] > 0): ?>
        <div class="card__foot">
            <span class="small muted">
                Indicative corporate income tax at <?= e((string) $taxRate) ?>%:
                <strong><?= e(money($report['indicative_income_tax'], true)) ?></strong>.
                Wholly Qatari and GCC-owned entities are generally exempt — the 10%
                rate applies to the foreign share of profit. Your return is filed
                with the General Tax Authority separately.
            </span>
        </div>
    <?php endif; ?>
</div>
