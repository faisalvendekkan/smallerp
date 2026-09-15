<?php
/**
 * Tax summary.
 *
 * Dormant while Qatar has no VAT in force, but it means an SME already has the
 * numbers the day the law commences rather than a software migration.
 */
?>
<div class="page-head">
    <div class="page-head__text">
        <p class="page-head__sub"><?= e(fdate($from)) ?> to <?= e(fdate($to)) ?></p>
    </div>
    <div class="page-head__actions">
        <button class="btn" type="button" onclick="window.print()"><?= t('action.print') ?></button>
    </div>
</div>

<?php if (!$report['vat_in_force']): ?>
    <div class="alert alert--info">
        <span class="alert__icon">i</span>
        <span><?= e($report['notice']) ?>
            <?php if (!$taxEnabled): ?>
                Tax is currently switched off, so these figures will be zero.
                <a href="<?= url('/settings') ?>">Settings</a>.
            <?php endif; ?>
        </span>
    </div>
<?php endif; ?>

<div class="card no-print">
    <div class="card__head">
        <form class="filters" method="get" action="<?= url('/reports/tax') ?>" data-auto-submit>
            <div class="field"><label for="from">From</label>
                <input type="date" id="from" name="from" value="<?= e($from) ?>"></div>
            <div class="field"><label for="to">To</label>
                <input type="date" id="to" name="to" value="<?= e($to) ?>"></div>
        </form>
    </div>
</div>

<div class="stats">
    <div class="stat">
        <div class="stat__label">Sales (net)</div>
        <div class="stat__value"><?= e(money($report['total_sales'])) ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Output tax</div>
        <div class="stat__value"><?= e(money($report['output_tax'])) ?></div>
        <div class="stat__meta">charged on sales</div>
    </div>
    <div class="stat">
        <div class="stat__label">Input tax</div>
        <div class="stat__value"><?= e(money($report['input_tax'])) ?></div>
        <div class="stat__meta">paid on purchases</div>
    </div>
    <div class="stat <?= $report['net_payable'] > 0 ? 'stat--warn' : 'stat--ok' ?>">
        <div class="stat__label">Net position</div>
        <div class="stat__value"><?= e(money(abs($report['net_payable']))) ?></div>
        <div class="stat__meta"><?= $report['net_payable'] >= 0 ? 'payable' : 'reclaimable' ?></div>
    </div>
</div>

<div class="split">
    <div class="card">
        <div class="card__head"><h2 class="card__title">Sales by tax rate</h2></div>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Rate</th><th class="num">Taxable</th><th class="num">Tax</th></tr></thead>
                <tbody>
                <?php foreach ($report['sales'] as $row): ?>
                    <tr>
                        <td><?= e((string) (float) $row['tax_rate']) ?>%</td>
                        <td class="num"><?= e(money($row['taxable'])) ?></td>
                        <td class="num"><?= e(money($row['tax'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($report['sales'] === []): ?>
                    <tr><td colspan="3" class="muted text-center">No sales in this period.</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot>
                <tr><td>Total</td>
                    <td class="num"><?= e(money($report['total_sales'])) ?></td>
                    <td class="num"><?= e(money($report['output_tax'])) ?></td></tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card__head"><h2 class="card__title">Purchases by tax rate</h2></div>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Rate</th><th class="num">Taxable</th><th class="num">Tax</th></tr></thead>
                <tbody>
                <?php foreach ($report['purchases'] as $row): ?>
                    <tr>
                        <td><?= e((string) (float) $row['tax_rate']) ?>%</td>
                        <td class="num"><?= e(money($row['taxable'])) ?></td>
                        <td class="num"><?= e(money($row['tax'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($report['purchases'] === []): ?>
                    <tr><td colspan="3" class="muted text-center">No purchases in this period.</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot>
                <tr><td>Total</td>
                    <td class="num"><?= e(money($report['total_purchases'])) ?></td>
                    <td class="num"><?= e(money($report['input_tax'])) ?></td></tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
