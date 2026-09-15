<?php
/**
 * Manual journal entry.
 *
 * Deliberately plain: the posting engine refuses anything that does not
 * balance, so the form only has to collect the lines.
 */

use App\Core\Lang;
?>
<form method="post" action="<?= url('/journals') ?>" data-dirty-guard>
    <?= csrf_field() ?>

    <div class="card">
        <div class="card__body">
            <div class="form-grid">
                <div class="field">
                    <label for="entry_date" class="required"><?= t('field.date') ?></label>
                    <input type="date" id="entry_date" name="entry_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="field field--full">
                    <label for="memo" class="required"><?= t('field.description') ?></label>
                    <input type="text" id="memo" name="memo" required
                           placeholder="e.g. depreciation for the quarter">
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card__head">
            <h2 class="card__title">Lines</h2>
            <span class="topbar__spacer"></span>
            <span class="small muted">Debits must equal credits.</span>
        </div>
        <div class="table-wrap">
            <table class="lines">
                <thead>
                <tr>
                    <th style="width:26px">#</th>
                    <th>Account</th>
                    <th><?= t('field.description') ?></th>
                    <th style="width:130px" class="num">Debit</th>
                    <th style="width:130px" class="num">Credit</th>
                </tr>
                </thead>
                <tbody>
                <?php for ($i = 0; $i < 4; $i++): ?>
                    <tr>
                        <td class="muted tiny"><?= $i + 1 ?></td>
                        <td>
                            <select name="lines[account_id][]">
                                <option value="">—</option>
                                <?php foreach ($accounts as $account): ?>
                                    <option value="<?= (int) $account['id'] ?>">
                                        <?= e($account['code'] . ' · ' . Lang::pick($account, 'name')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="text" name="lines[memo][]"></td>
                        <td><input type="text" class="num" name="lines[debit][]" inputmode="decimal"></td>
                        <td><input type="text" class="num" name="lines[credit][]" inputmode="decimal"></td>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
        </div>
        <div class="card__foot">
            <button class="btn btn--primary" type="submit"><?= t('action.post') ?></button>
            <a class="btn btn--ghost" href="<?= url('/journals') ?>"><?= t('action.cancel') ?></a>
        </div>
    </div>
</form>
