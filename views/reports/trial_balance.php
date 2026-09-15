<?php
/** Trial balance. */

use App\Core\Lang;
use App\Services\ChartOfAccounts;
?>
<div class="page-head">
    <div class="page-head__text">
        <p class="page-head__sub"><?= e(fdate($from)) ?> to <?= e(fdate($to)) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= url('/reports/trial-balance', ['from' => $from, 'to' => $to, 'format' => 'csv']) ?>">
            <?= t('action.export') ?>
        </a>
        <button class="btn" type="button" data-print><?= t('action.print') ?></button>
    </div>
</div>

<div class="card">
    <div class="card__head no-print">
        <form class="filters" method="get" action="<?= url('/reports/trial-balance') ?>" data-auto-submit>
            <div class="field"><label for="from">From</label>
                <input type="date" id="from" name="from" value="<?= e($from) ?>"></div>
            <div class="field"><label for="to">To</label>
                <input type="date" id="to" name="to" value="<?= e($to) ?>"></div>
        </form>
    </div>

    <?php if ($rows === []): ?>
        <?= App\Core\View::partial('partials/empty', ['message' => 'No postings in this period.']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Code</th>
                    <th>Account</th>
                    <th>Type</th>
                    <th class="num">Total debit</th>
                    <th class="num">Total credit</th>
                    <th class="num">Balance debit</th>
                    <th class="num">Balance credit</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="mono tiny"><?= e($row['code']) ?></td>
                        <td><a href="<?= url('/accounts/' . $row['id']) ?>"><?= e(Lang::pick($row, 'name')) ?></a></td>
                        <td class="tiny muted"><?= e($row['type']) ?></td>
                        <td class="num muted"><?= e(money($row['total_debit'])) ?></td>
                        <td class="num muted"><?= e(money($row['total_credit'])) ?></td>
                        <td class="num strong"><?= (int) $row['balance_debit'] > 0 ? e(money($row['balance_debit'])) : '' ?></td>
                        <td class="num strong"><?= (int) $row['balance_credit'] > 0 ? e(money($row['balance_credit'])) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr>
                    <td colspan="5" class="text-end">Totals</td>
                    <td class="num"><?= e(money($totalDebit)) ?></td>
                    <td class="num"><?= e(money($totalCredit)) ?></td>
                </tr>
                </tfoot>
            </table>
        </div>
        <div class="card__foot">
            <?php if ($totalDebit === $totalCredit): ?>
                <span class="text-ok">✓ The trial balance agrees.</span>
            <?php else: ?>
                <span class="text-bad">
                    ✕ Out by <?= e(money(abs($totalDebit - $totalCredit), true)) ?>.
                </span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
