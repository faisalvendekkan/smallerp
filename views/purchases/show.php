<?php
/** One supplier bill. */

use App\Core\Lang;
use App\Services\Purchases;

$isDraft = $bill['status'] === Purchases::STATUS_DRAFT;
$isVoid = $bill['status'] === Purchases::STATUS_VOID;
?>
<div class="page-head">
    <div class="page-head__text">
        <div class="flex flex-wrap">
            <h1 class="mb-0"><?= e($bill['number']) ?></h1>
            <?php if ($bill['is_overdue']): ?>
                <span class="badge badge--bad"><?= t('status.overdue') ?></span>
            <?php else: ?>
                <span class="badge badge--<?= e(status_class($bill['status'])) ?>">
                    <?= t('status.' . $bill['status']) ?></span>
            <?php endif; ?>
        </div>
        <p class="page-head__sub">
            <a href="<?= url('/contacts/' . $bill['contact_id']) ?>"><?= e(Lang::pick($bill, 'contact_name')) ?></a>
            · <?= e(fdate($bill['issue_date'])) ?>
            <?php if ($bill['supplier_invoice_no'] !== ''): ?>
                · their ref <?= e($bill['supplier_invoice_no']) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="page-head__actions">
        <?php if ($isDraft && can('purchases.edit')): ?>
            <a class="btn" href="<?= url('/bills/' . $bill['id'] . '/edit') ?>"><?= t('action.edit') ?></a>
        <?php endif; ?>
        <?php if ($isDraft && can('purchases.post')): ?>
            <form method="post" action="<?= url('/bills/' . $bill['id'] . '/post') ?>"
                  data-confirm="Post this bill? It will book the payable and receive the stock.">
                <?= csrf_field() ?>
                <button class="btn btn--primary" type="submit"><?= t('action.post') ?></button>
            </form>
        <?php endif; ?>
        <?php if (!$isDraft && !$isVoid && (int) $bill['balance_due'] > 0 && can('payments.create')): ?>
            <a class="btn btn--primary"
               href="<?= url('/payments/new', ['contact_id' => $bill['contact_id'], 'doc_id' => $bill['id']]) ?>">
                <?= t('action.record_payment') ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($isVoid): ?>
    <div class="alert alert--error">
        <span class="alert__icon">✕</span>
        <span>This bill was voided on <?= e(fdate($bill['voided_at'], true)) ?>.
            <?= $bill['void_reason'] !== '' ? 'Reason: ' . e($bill['void_reason']) : '' ?></span>
    </div>
<?php elseif ($isDraft): ?>
    <div class="alert alert--warning">
        <span class="alert__icon">!</span>
        <span>This bill is a draft. It is not in your books and no stock has been received.</span>
    </div>
<?php endif; ?>

<div class="split split--sidebar">
    <div>
        <div class="card">
            <div class="table-wrap">
                <table class="data">
                    <thead>
                    <tr>
                        <th style="width:26px">#</th>
                        <th><?= t('field.description') ?></th>
                        <th>Coded to</th>
                        <th class="num"><?= t('field.quantity') ?></th>
                        <th class="num"><?= t('field.unit_price') ?></th>
                        <th class="num"><?= t('field.total') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($bill['lines'] as $line): ?>
                        <tr>
                            <td class="muted tiny"><?= (int) $line['line_no'] ?></td>
                            <td><?= e($line['description']) ?></td>
                            <td class="tiny muted">
                                <?php if ($line['sku']): ?>
                                    <a href="<?= url('/items/' . $line['item_id']) ?>"><?= e($line['sku']) ?></a>
                                <?php elseif ($line['account_code']): ?>
                                    <?= e($line['account_code'] . ' ' . $line['account_name_en']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="num">
                                <?= e(rtrim(rtrim(number_format((float) $line['quantity'], 3, '.', ''), '0'), '.')) ?>
                                <span class="tiny muted"><?= e($line['uom']) ?></span>
                            </td>
                            <td class="num"><?= e(money($line['unit_price'])) ?></td>
                            <td class="num strong"><?= e(money($line['line_total'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card__body">
                <div class="totals-box">
                    <div class="totals-box__row">
                        <span><?= t('field.subtotal') ?></span>
                        <span class="num mono"><?= e(money($bill['subtotal'])) ?></span>
                    </div>
                    <?php if ((int) $bill['tax_total'] > 0): ?>
                        <div class="totals-box__row">
                            <span><?= t('field.tax') ?></span>
                            <span class="num mono"><?= e(money($bill['tax_total'])) ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="totals-box__row totals-box__row--grand">
                        <span><?= t('field.total') ?> (QAR)</span>
                        <span class="num mono"><?= e(money($bill['total'])) ?></span>
                    </div>
                    <?php if ((int) $bill['amount_paid'] > 0): ?>
                        <div class="totals-box__row">
                            <span class="text-ok"><?= t('field.paid') ?></span>
                            <span class="num mono text-ok">−<?= e(money($bill['amount_paid'])) ?></span>
                        </div>
                        <div class="totals-box__row strong">
                            <span><?= t('field.balance') ?></span>
                            <span class="num mono"><?= e(money($bill['balance_due'])) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($journal): ?>
            <div class="card">
                <div class="card__head">
                    <h2 class="card__title">Journal entry</h2>
                    <span class="topbar__spacer"></span>
                    <a class="btn btn--sm" href="<?= url('/journals/' . $journal['id']) ?>"><?= e($journal['number']) ?></a>
                </div>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                        <tr><th>Account</th><th class="num">Debit</th><th class="num">Credit</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($journal['lines'] as $line): ?>
                            <tr>
                                <td><span class="mono tiny"><?= e($line['code']) ?></span>
                                    <?= e(Lang::pick($line, 'name')) ?></td>
                                <td class="num"><?= (int) $line['debit'] > 0 ? e(money($line['debit'])) : '' ?></td>
                                <td class="num"><?= (int) $line['credit'] > 0 ? e(money($line['credit'])) : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div>
        <?php if ($bill['payments'] !== []): ?>
            <div class="card">
                <div class="card__head"><h2 class="card__title">Payments made</h2></div>
                <div class="table-wrap">
                    <table class="data">
                        <tbody>
                        <?php foreach ($bill['payments'] as $payment): ?>
                            <tr>
                                <td><?= e($payment['number']) ?>
                                    <div class="tiny muted"><?= e($payment['method']) ?></div></td>
                                <td class="nowrap tiny"><?= e(fdate($payment['payment_date'])) ?></td>
                                <td class="num"><?= e(money($payment['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$isVoid && can('purchases.void')): ?>
            <div class="card">
                <div class="card__head"><h2 class="card__title">Void this bill</h2></div>
                <div class="card__body">
                    <p class="small muted">
                        Voiding reverses the journal and returns the received stock.
                        It is refused if the stock has already been sold on.
                    </p>
                    <form method="post" action="<?= url('/bills/' . $bill['id'] . '/void') ?>"
                          data-confirm="Void <?= e($bill['number']) ?>?">
                        <?= csrf_field() ?>
                        <div class="field mb-1">
                            <label for="void_reason" class="required">Reason</label>
                            <input type="text" id="void_reason" name="void_reason" required>
                        </div>
                        <button class="btn btn--danger btn--sm" type="submit"
                                <?= (int) $bill['amount_paid'] > 0 ? 'disabled' : '' ?>><?= t('action.void') ?></button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
