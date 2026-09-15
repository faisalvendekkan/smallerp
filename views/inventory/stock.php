<?php
/** Stock position. */

use App\Core\Lang;
?>
<div class="page-head">
    <div class="page-head__text">
        <p class="page-head__sub">
            <?= count($rows) ?> stocked items ·
            <strong><?= e(money($totalValue, true)) ?></strong> at weighted average cost
            <?php if ($lowCount > 0): ?>
                · <span class="text-warn"><?= $lowCount ?> below reorder level</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= url('/stock/export', ['as_of' => $asOf]) ?>"><?= t('action.export') ?></a>
        <?php if (can('inventory.adjust')): ?>
            <a class="btn btn--primary" href="<?= url('/stock/adjust') ?>">Adjust stock</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <form class="filters" method="get" action="<?= url('/stock') ?>" data-auto-submit>
            <div class="field">
                <label for="as_of">As at</label>
                <input type="date" id="as_of" name="as_of" value="<?= e($asOf) ?>">
            </div>
            <?php if (count($warehouses) > 1): ?>
                <div class="field">
                    <label for="warehouse_id">Warehouse</label>
                    <select id="warehouse_id" name="warehouse_id">
                        <option value="">All</option>
                        <?php foreach ($warehouses as $warehouse): ?>
                            <option value="<?= (int) $warehouse['id'] ?>"
                                <?= $warehouseId === (int) $warehouse['id'] ? 'selected' : '' ?>>
                                <?= e(Lang::pick($warehouse, 'name')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <span class="topbar__spacer"></span>
            <input type="search" placeholder="Filter…" data-table-filter="#stock-table" style="width:180px">
        </form>
    </div>

    <?php if ($rows === []): ?>
        <?= App\Core\View::partial('partials/empty', ['message' => 'Nothing in stock.']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data" id="stock-table">
                <thead>
                <tr>
                    <th>Code</th>
                    <th>Item</th>
                    <th>Unit</th>
                    <th class="num">On hand</th>
                    <th class="num">Reorder at</th>
                    <th class="num">Avg cost</th>
                    <th class="num">Value</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="mono tiny"><?= e($row['sku']) ?></td>
                        <td>
                            <a href="<?= url('/items/' . $row['id']) ?>"><?= e(Lang::pick($row, 'name')) ?></a>
                            <?php if ($row['below_reorder']): ?>
                                <span class="badge badge--warn">reorder</span>
                            <?php endif; ?>
                        </td>
                        <td class="tiny muted"><?= e($row['uom']) ?></td>
                        <td class="num <?= $row['below_reorder'] ? 'text-warn strong' : '' ?>">
                            <?= e(rtrim(rtrim(number_format((float) $row['qty_on_hand'], 3, '.', ''), '0'), '.')) ?>
                        </td>
                        <td class="num muted tiny">
                            <?= (float) $row['reorder_level'] > 0
                                ? e(rtrim(rtrim(number_format((float) $row['reorder_level'], 2, '.', ''), '0'), '.'))
                                : '—' ?>
                        </td>
                        <td class="num"><?= e(money($row['average_cost'])) ?></td>
                        <td class="num strong"><?= e(money($row['stock_value'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr>
                    <td colspan="6" class="text-end">Total stock value</td>
                    <td class="num"><?= e(money($totalValue)) ?></td>
                </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>
