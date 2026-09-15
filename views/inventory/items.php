<?php
/** Item catalogue. */

use App\Core\Lang;
?>
<div class="page-head">
    <div class="page-head__text">
        <p class="page-head__sub"><?= number_format((int) $pagination['total']) ?> items</p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= url('/stock') ?>"><?= t('nav.stock') ?></a>
        <?php if (can('items.create')): ?>
            <a class="btn btn--primary" href="<?= url('/items/new') ?>">+ <?= t('action.new') ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <form class="filters" method="get" action="<?= url('/items') ?>" data-auto-submit>
            <div class="field field--search">
                <label for="search"><?= t('action.search') ?></label>
                <input type="search" id="search" name="search" value="<?= e($search) ?>"
                       placeholder="Code, name or barcode">
            </div>
            <div class="field">
                <label for="kind">Type</label>
                <select id="kind" name="kind">
                    <option value="">All</option>
                    <option value="goods" <?= $kind === 'goods' ? 'selected' : '' ?>>Goods</option>
                    <option value="service" <?= $kind === 'service' ? 'selected' : '' ?>>Services</option>
                </select>
            </div>
            <label class="check" style="padding-bottom:.4rem">
                <input type="checkbox" name="archived" value="1" <?= $archived ? 'checked' : '' ?>>
                <span>Show archived</span>
            </label>
            <button class="btn" type="submit"><?= t('action.search') ?></button>
        </form>
    </div>

    <?php if ($rows === []): ?>
        <?= App\Core\View::partial('partials/empty', [
            'message' => 'No items yet.',
            'actionUrl' => can('items.create') ? url('/items/new') : null,
            'actionLabel' => __('action.new'),
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Unit</th>
                    <th class="num">Cost</th>
                    <th class="num">Sale price</th>
                    <th class="num">Margin</th>
                    <th class="num">On hand</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $margin = (int) $row['sale_price'] > 0
                        ? round(((int) $row['sale_price'] - (int) $row['cost_price']) / (int) $row['sale_price'] * 100, 1)
                        : null;
                    $low = $row['qty_on_hand'] !== null
                        && (float) $row['reorder_level'] > 0
                        && (float) $row['qty_on_hand'] <= (float) $row['reorder_level'];
                    ?>
                    <tr>
                        <td class="mono tiny"><?= e($row['sku']) ?></td>
                        <td>
                            <a href="<?= url('/items/' . $row['id']) ?>"><?= e(Lang::pick($row, 'name')) ?></a>
                            <?php if ((int) $row['is_active'] !== 1): ?>
                                <span class="badge badge--muted">archived</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge--<?= $row['kind'] === 'service' ? 'info' : 'muted' ?>">
                                <?= e($row['kind']) ?>
                            </span>
                        </td>
                        <td class="tiny muted"><?= e($row['uom']) ?></td>
                        <td class="num muted"><?= e(money($row['cost_price'])) ?></td>
                        <td class="num"><?= e(money($row['sale_price'])) ?></td>
                        <td class="num tiny <?= $margin !== null && $margin < 15 ? 'text-warn' : 'muted' ?>">
                            <?= $margin !== null ? e((string) $margin) . '%' : '—' ?>
                        </td>
                        <td class="num <?= $low ? 'text-warn strong' : '' ?>">
                            <?php if ($row['qty_on_hand'] === null): ?>
                                <span class="muted">—</span>
                            <?php else: ?>
                                <?= e(rtrim(rtrim(number_format((float) $row['qty_on_hand'], 3, '.', ''), '0'), '.')) ?>
                                <?php if ($low): ?><div class="tiny">reorder</div><?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= App\Core\View::partial('partials/pagination', ['pagination' => $pagination]) ?>
    <?php endif; ?>
</div>
