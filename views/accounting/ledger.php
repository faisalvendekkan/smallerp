<?php
/** One account's ledger, with a running balance. */

use App\Core\Lang;
?>
<div class="page-head">
    <div class="page-head__text">
        <div class="flex flex-wrap">
            <h1 class="mb-0"><?= e($account['code']) ?> — <?= e(Lang::pick($account, 'name')) ?></h1>
            <span class="badge badge--muted"><?= e($account['type']) ?></span>
        </div>
        <p class="page-head__sub">
            <?= count($ledger['lines']) ?> entries between
            <?= e(fdate($from)) ?> and <?= e(fdate($to)) ?>
        </p>
    </div>
    <div class="page-head__actions">
        <?php if (can('accounting.edit')): ?>
            <a class="btn" href="<?= url('/accounts/' . $account['id'] . '/edit') ?>"><?= t('action.edit') ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="stats">
    <div class="stat">
        <div class="stat__label">Opening balance</div>
        <div class="stat__value"><?= e(money($ledger['opening'])) ?></div>
        <div class="stat__meta">at <?= e(fdate($from)) ?></div>
    </div>
    <div class="stat <?= $ledger['closing'] < 0 ? 'stat--bad' : '' ?>">
        <div class="stat__label">Closing balance</div>
        <div class="stat__value"><?= e(money($ledger['closing'])) ?></div>
        <div class="stat__meta">at <?= e(fdate($to)) ?></div>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <form class="filters" method="get" action="<?= url('/accounts/' . $account['id']) ?>" data-auto-submit>
            <div class="field">
                <label for="from">From</label>
                <input type="date" id="from" name="from" value="<?= e($from) ?>">
            </div>
            <div class="field">
                <label for="to">To</label>
                <input type="date" id="to" name="to" value="<?= e($to) ?>">
            </div>
        </form>
    </div>

    <?php if ($ledger['lines'] === []): ?>
        <?= App\Core\View::partial('partials/empty', ['message' => 'No entries in this period.']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th><?= t('field.date') ?></th>
                    <th>Entry</th>
                    <th><?= t('field.description') ?></th>
                    <th class="num">Debit</th>
                    <th class="num">Credit</th>
                    <th class="num"><?= t('field.balance') ?></th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td colspan="5" class="muted">Opening balance</td>
                    <td class="num strong"><?= e(money($ledger['opening'])) ?></td>
                </tr>
                <?php foreach ($ledger['lines'] as $line): ?>
                    <tr>
                        <td class="nowrap tiny"><?= e(fdate($line['entry_date'])) ?></td>
                        <td class="nowrap">
                            <a href="<?= url('/journals/' . $line['journal_id']) ?>"><?= e($line['number']) ?></a>
                        </td>
                        <td class="small">
                            <?= e($line['memo'] ?: $line['journal_memo']) ?>
                            <div class="tiny muted"><?= e(str_replace('_', ' ', $line['source_type'])) ?></div>
                        </td>
                        <td class="num"><?= (int) $line['debit'] > 0 ? e(money($line['debit'])) : '' ?></td>
                        <td class="num"><?= (int) $line['credit'] > 0 ? e(money($line['credit'])) : '' ?></td>
                        <td class="num strong"><?= e(money($line['running_balance'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr>
                    <td colspan="5" class="text-end">Closing balance</td>
                    <td class="num"><?= e(money($ledger['closing'])) ?></td>
                </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>
