<?php
/** One journal entry. */

use App\Core\Lang;

$totalDebit = array_sum(array_map(static fn ($l) => (int) $l['debit'], $journal['lines']));
$totalCredit = array_sum(array_map(static fn ($l) => (int) $l['credit'], $journal['lines']));

// Where the entry came from, so the user can jump back to the document.
$sourceUrls = [
    'sales_invoice' => '/invoices/',
    'purchase_bill' => '/bills/',
    'receipt' => '/payments/',
    'payment' => '/payments/',
    'payroll_run' => '/payroll/',
    'payroll_payment' => '/payroll/',
];
?>
<div class="page-head">
    <div class="page-head__text">
        <div class="flex flex-wrap">
            <h1 class="mb-0"><?= e($journal['number']) ?></h1>
            <?php if ((int) $journal['is_reversal'] === 1): ?>
                <span class="badge badge--warn">reversal</span>
            <?php endif; ?>
            <?php if ($reversal): ?>
                <span class="badge badge--bad">reversed</span>
            <?php endif; ?>
        </div>
        <p class="page-head__sub">
            <?= e(fdate($journal['entry_date'])) ?> · <?= e($journal['memo']) ?>
        </p>
    </div>
    <div class="page-head__actions">
        <?php if (isset($sourceUrls[$journal['source_type']]) && $journal['source_id']): ?>
            <a class="btn" href="<?= url($sourceUrls[$journal['source_type']] . $journal['source_id']) ?>">
                View <?= e(str_replace('_', ' ', $journal['source_type'])) ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($reverses): ?>
    <div class="alert alert--info">
        <span class="alert__icon">i</span>
        <span>This entry reverses
            <a href="<?= url('/journals/' . $reverses['id']) ?>"><?= e($reverses['number']) ?></a>.</span>
    </div>
<?php endif; ?>
<?php if ($reversal): ?>
    <div class="alert alert--warning">
        <span class="alert__icon">!</span>
        <span>This entry was reversed by
            <a href="<?= url('/journals/' . $reversal['id']) ?>"><?= e($reversal['number']) ?></a>.</span>
    </div>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th style="width:26px">#</th>
                <th>Account</th>
                <th><?= t('field.description') ?></th>
                <th class="num">Debit</th>
                <th class="num">Credit</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($journal['lines'] as $line): ?>
                <tr>
                    <td class="muted tiny"><?= (int) $line['line_no'] ?></td>
                    <td>
                        <a href="<?= url('/accounts/' . $line['account_id']) ?>">
                            <span class="mono tiny"><?= e($line['code']) ?></span>
                            <?= e(Lang::pick($line, 'name')) ?>
                        </a>
                    </td>
                    <td class="small muted"><?= e($line['memo']) ?></td>
                    <td class="num"><?= (int) $line['debit'] > 0 ? e(money($line['debit'])) : '' ?></td>
                    <td class="num"><?= (int) $line['credit'] > 0 ? e(money($line['credit'])) : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <td colspan="3" class="text-end">Totals</td>
                <td class="num"><?= e(money($totalDebit)) ?></td>
                <td class="num"><?= e(money($totalCredit)) ?></td>
            </tr>
            </tfoot>
        </table>
    </div>

    <?php if ($journal['source_type'] === 'manual' && !$reversal && can('accounting.post')): ?>
        <div class="card__foot">
            <form method="post" action="<?= url('/journals/' . $journal['id'] . '/reverse') ?>" class="flex flex-wrap"
                  data-confirm="Reverse this entry? A mirror-image entry will be posted; both stay in the books.">
                <?= csrf_field() ?>
                <div class="field">
                    <label class="tiny">Reversal date</label>
                    <input type="date" name="entry_date" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="field" style="min-width:220px">
                    <label class="tiny">Reason</label>
                    <input type="text" name="memo" placeholder="Reversal of <?= e($journal['number']) ?>">
                </div>
                <button class="btn btn--danger" type="submit" style="align-self:flex-end">Reverse</button>
            </form>
        </div>
    <?php elseif ($journal['source_type'] !== 'manual'): ?>
        <div class="card__foot">
            <span class="small muted">
                This entry was posted by a <?= e(str_replace('_', ' ', $journal['source_type'])) ?>.
                Void that document to reverse it.
            </span>
        </div>
    <?php endif; ?>
</div>
