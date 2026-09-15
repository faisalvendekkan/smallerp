<?php
/** Warehouses. */

use App\Core\Lang;
?>
<div class="split split--sidebar">
    <div>
        <div class="card">
            <div class="card__head"><h2 class="card__title">Warehouses</h2></div>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                    <tr><th>Code</th><th>Name</th><th>Address</th><th class="num">Items</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="mono tiny"><?= e($row['code']) ?></td>
                            <td>
                                <?= e(Lang::pick($row, 'name')) ?>
                                <?php if ((int) $row['is_default'] === 1): ?>
                                    <span class="badge badge--info">default</span>
                                <?php endif; ?>
                            </td>
                            <td class="small muted"><?= e($row['address']) ?></td>
                            <td class="num"><?= (int) $row['item_count'] ?></td>
                            <td>
                                <?php if ((int) $row['is_active'] !== 1): ?>
                                    <span class="badge badge--muted">inactive</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if (can('inventory.edit')): ?>
        <div>
            <div class="card">
                <div class="card__head"><h2 class="card__title">Add a warehouse</h2></div>
                <form method="post" action="<?= url('/warehouses') ?>">
                    <?= csrf_field() ?>
                    <div class="card__body">
                        <div class="field mb-1">
                            <label for="code" class="required">Code</label>
                            <input type="text" id="code" name="code" required placeholder="MAIN">
                        </div>
                        <div class="field mb-1">
                            <label for="name_en" class="required">Name</label>
                            <input type="text" id="name_en" name="name_en" required>
                        </div>
                        <div class="field mb-1">
                            <label for="name_ar">Name (Arabic)</label>
                            <input type="text" id="name_ar" name="name_ar" dir="rtl">
                        </div>
                        <div class="field mb-1">
                            <label for="address">Address</label>
                            <input type="text" id="address" name="address">
                        </div>
                        <label class="check">
                            <input type="checkbox" name="is_default" value="1">
                            <span>Make this the default</span>
                        </label>
                    </div>
                    <div class="card__foot">
                        <button class="btn btn--primary btn--block" type="submit"><?= t('action.save') ?></button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
