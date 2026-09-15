<?php
/** Sales analysis by customer and by item. */

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
        <form class="filters" method="get" action="<?= url('/reports/sales') ?>" data-auto-submit>
            <div class="field"><label for="from">From</label>
                <input type="date" id="from" name="from" value="<?= e($from) ?>"></div>
            <div class="field"><label for="to">To</label>
                <input type="date" id="to" name="to" value="<?= e($to) ?>"></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card__head"><h2 class="card__title">By customer</h2></div>
    <?php if ($byCustomer === []): ?>
        <?= App\Core\View::partial('partials/empty', ['message' => 'No sales in this period.']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th><?= t('field.customer') ?></th>
                    <th class="num">Invoices</th>
                    <th class="num">Net sales</th>
                    <th class="num">Total billed</th>
                    <th class="num"><?= t('field.outstanding') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($byCustomer as $row): ?>
                    <tr>
                        <td><a href="<?= url('/contacts/' . $row['id']) ?>"><?= e(Lang::pick($row, 'name')) ?></a></td>
                        <td class="num"><?= (int) $row['invoice_count'] ?></td>
                        <td class="num"><?= e(money($row['net_sales'])) ?></td>
                        <td class="num strong"><?= e(money($row['total_sales'])) ?></td>
                        <td class="num <?= (int) $row['outstanding'] > 0 ? 'text-warn' : 'muted' ?>">
                            <?= e(money($row['outstanding'])) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr>
                    <td colspan="3" class="text-end">Total</td>
                    <td class="num"><?= e(money(array_sum(array_map(static fn ($r) => (int) $r['total_sales'], $byCustomer)))) ?></td>
                    <td class="num"><?= e(money(array_sum(array_map(static fn ($r) => (int) $r['outstanding'], $byCustomer)))) ?></td>
                </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card__head">
        <h2 class="card__title">By item</h2>
        <span class="topbar__spacer"></span>
        <span class="small muted">Cost at weighted average</span>
    </div>
    <?php if ($byItem === []): ?>
        <?= App\Core\View::partial('partials/empty', ['message' => 'No item sales in this period.']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Code</th>
                    <th>Item</th>
                    <th class="num">Qty sold</th>
                    <th class="num">Net sales</th>
                    <th class="num">Cost of sales</th>
                    <th class="num">Gross profit</th>
                    <th class="num">Margin</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($byItem as $row): ?>
                    <tr>
                        <td class="mono tiny"><?= e($row['sku']) ?></td>
                        <td><a href="<?= url('/items/' . $row['id']) ?>"><?= e(Lang::pick($row, 'name')) ?></a></td>
                        <td class="num">
                            <?= e(rtrim(rtrim(number_format((float) $row['quantity_sold'], 3, '.', ''), '0'), '.')) ?>
                            <span class="tiny muted"><?= e($row['uom']) ?></span>
                        </td>
                        <td class="num"><?= e(money($row['net_sales'])) ?></td>
                        <td class="num muted"><?= e(money($row['cost_of_sales'])) ?></td>
                        <td class="num strong <?= (int) $row['gross_profit'] < 0 ? 'text-bad' : '' ?>">
                            <?= e(money($row['gross_profit'])) ?>
                        </td>
                        <td class="num <?= (float) $row['margin_pct'] < 15 ? 'text-warn' : '' ?>">
                            <?= e((string) $row['margin_pct']) ?>%
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
