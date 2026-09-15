<?php
/** One item, with its stock movements. */

use App\Core\Lang;
?>
<div class="page-head">
    <div class="page-head__text">
        <div class="flex flex-wrap">
            <h1 class="mb-0"><?= e(Lang::pick($item, 'name')) ?></h1>
            <span class="badge badge--<?= $item['kind'] === 'service' ? 'info' : 'muted' ?>">
                <?= e($item['kind']) ?>
            </span>
        </div>
        <p class="page-head__sub">
            <?= e($item['sku']) ?> · <?= e($item['uom']) ?>
            <?php if ($item['barcode'] !== ''): ?> · barcode <?= e($item['barcode']) ?><?php endif; ?>
        </p>
    </div>
    <div class="page-head__actions">
        <?php if ((int) $item['track_stock'] === 1 && can('inventory.adjust')): ?>
            <a class="btn" href="<?= url('/stock/adjust', ['item_id' => $item['id']]) ?>">Adjust stock</a>
        <?php endif; ?>
        <?php if (can('items.edit')): ?>
            <a class="btn btn--primary" href="<?= url('/items/' . $item['id'] . '/edit') ?>"><?= t('action.edit') ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="stats">
    <div class="stat">
        <div class="stat__label">Sale price</div>
        <div class="stat__value"><?= e(money($item['sale_price'])) ?></div>
    </div>
    <?php if ((int) $item['track_stock'] === 1): ?>
        <div class="stat <?= (float) $item['reorder_level'] > 0 && $onHand <= (float) $item['reorder_level'] ? 'stat--warn' : '' ?>">
            <div class="stat__label">On hand</div>
            <div class="stat__value">
                <?= e(rtrim(rtrim(number_format($onHand, 3, '.', ''), '0'), '.')) ?>
                <span class="small muted"><?= e($item['uom']) ?></span>
            </div>
            <?php if ((float) $item['reorder_level'] > 0): ?>
                <div class="stat__meta">reorder at <?= e((string) (float) $item['reorder_level']) ?></div>
            <?php endif; ?>
        </div>
        <div class="stat">
            <div class="stat__label">Average cost</div>
            <div class="stat__value"><?= e(money($averageCost)) ?></div>
            <div class="stat__meta">weighted average</div>
        </div>
        <div class="stat">
            <div class="stat__label">Stock value</div>
            <div class="stat__value"><?= e(money($stockValue)) ?></div>
        </div>
    <?php endif; ?>
    <div class="stat">
        <div class="stat__label">Margin</div>
        <div class="stat__value">
            <?php
            $cost = $averageCost > 0 ? $averageCost : (int) $item['cost_price'];
            $margin = (int) $item['sale_price'] > 0
                ? round(((int) $item['sale_price'] - $cost) / (int) $item['sale_price'] * 100, 1)
                : 0;
            ?>
            <?= e((string) $margin) ?>%
        </div>
        <div class="stat__meta">at <?= e(money($cost)) ?> cost</div>
    </div>
</div>

<?php if ((int) $item['track_stock'] === 1): ?>
    <div class="card">
        <div class="card__head"><h2 class="card__title">Stock movements</h2></div>
        <?php if ($movements === []): ?>
            <?= App\Core\View::partial('partials/empty', ['message' => 'No movements yet.']) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                    <tr>
                        <th><?= t('field.date') ?></th>
                        <th>Warehouse</th>
                        <th>Source</th>
                        <th>Note</th>
                        <th class="num">In</th>
                        <th class="num">Out</th>
                        <th class="num">Unit cost</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($movements as $move): ?>
                        <?php $qty = (float) $move['quantity']; ?>
                        <tr>
                            <td class="nowrap tiny"><?= e(fdate($move['move_date'])) ?></td>
                            <td class="tiny muted"><?= e($move['warehouse_code']) ?></td>
                            <td class="tiny">
                                <?php
                                $sourceUrls = [
                                    'sales_invoice' => '/invoices/',
                                    'purchase_bill' => '/bills/',
                                ];
                                ?>
                                <?php if (isset($sourceUrls[$move['source_type']]) && $move['source_id']): ?>
                                    <a href="<?= url($sourceUrls[$move['source_type']] . $move['source_id']) ?>">
                                        <?= e(str_replace('_', ' ', $move['source_type'])) ?></a>
                                <?php else: ?>
                                    <?= e(str_replace('_', ' ', $move['source_type'])) ?>
                                <?php endif; ?>
                            </td>
                            <td class="tiny muted"><?= e($move['note']) ?></td>
                            <td class="num text-ok">
                                <?= $qty > 0 ? e(rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.')) : '' ?>
                            </td>
                            <td class="num text-bad">
                                <?= $qty < 0 ? e(rtrim(rtrim(number_format(abs($qty), 3, '.', ''), '0'), '.')) : '' ?>
                            </td>
                            <td class="num muted"><?= e(money($move['unit_cost'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
