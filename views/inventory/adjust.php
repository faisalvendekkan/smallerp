<?php
/**
 * Stock adjustment.
 *
 * A count that finds less on the shelf than the system says is a loss, and it
 * has to reach the P&L rather than quietly disappear — so this posts a journal
 * as well as moving the stock.
 */

use App\Core\Lang;
?>
<form method="post" action="<?= url('/stock/adjust') ?>">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card__head"><h2 class="card__title">Adjust stock</h2></div>
        <div class="card__body">
            <p class="small muted">
                Use a positive quantity to bring stock in and a negative one to write
                it off. The value is posted against Inventory Adjustments, so the
                books stay in step with the store.
            </p>

            <div class="form-grid">
                <div class="field">
                    <label for="item_id" class="required">Item</label>
                    <select id="item_id" name="item_id" required>
                        <option value="">— choose —</option>
                        <?php foreach ($items as $item): ?>
                            <option value="<?= (int) $item['id'] ?>"
                                <?= $itemId === (int) $item['id'] ? 'selected' : '' ?>>
                                <?= e($item['sku'] . ' · ' . Lang::pick($item, 'name')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (count($warehouses) > 1): ?>
                    <div class="field">
                        <label for="warehouse_id">Warehouse</label>
                        <select id="warehouse_id" name="warehouse_id">
                            <?php foreach ($warehouses as $warehouse): ?>
                                <option value="<?= (int) $warehouse['id'] ?>">
                                    <?= e(Lang::pick($warehouse, 'name')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="field">
                    <label for="quantity" class="required">Quantity</label>
                    <input type="text" class="num" id="quantity" name="quantity" inputmode="decimal" required
                           placeholder="e.g. 5 or -2">
                </div>
                <div class="field">
                    <label for="unit_cost">Unit cost (QAR)</label>
                    <input type="text" class="num" id="unit_cost" name="unit_cost" inputmode="decimal">
                    <span class="field__hint">For stock coming in. Write-offs use the current average cost.</span>
                </div>
                <div class="field">
                    <label for="move_date"><?= t('field.date') ?></label>
                    <input type="date" id="move_date" name="move_date" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="field field--full">
                    <label for="reason" class="required">Reason</label>
                    <input type="text" id="reason" name="reason" required
                           placeholder="e.g. annual stock count, damaged in the warehouse">
                </div>
            </div>
        </div>
        <div class="card__foot">
            <button class="btn btn--primary" type="submit">Post adjustment</button>
            <a class="btn btn--ghost" href="<?= url('/stock') ?>"><?= t('action.cancel') ?></a>
        </div>
    </div>
</form>
