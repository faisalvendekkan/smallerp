<?php
/**
 * One invoice, with its lines, payments and the journal it posted.
 *
 * @var array      $invoice
 * @var array      $taxBreakdown
 * @var array|null $journal
 */

use App\Core\Lang;
use App\Services\Sales;
use App\Support\Money;

$isDraft = $invoice['status'] === Sales::STATUS_DRAFT;
$isVoid = $invoice['status'] === Sales::STATUS_VOID;
?>
<div class="page-head">
    <div class="page-head__text">
        <div class="flex flex-wrap">
            <h1 class="mb-0"><?= e($invoice['number']) ?></h1>
            <?php if ($invoice['is_overdue']): ?>
                <span class="badge badge--bad"><?= t('status.overdue') ?></span>
            <?php else: ?>
                <span class="badge badge--<?= e(status_class($invoice['status'])) ?>">
                    <?= t('status.' . $invoice['status']) ?>
                </span>
            <?php endif; ?>
        </div>
        <p class="page-head__sub">
            <a href="<?= url('/contacts/' . $invoice['contact_id']) ?>">
                <?= e(Lang::pick($invoice, 'contact_name')) ?></a>
            · <?= e(fdate($invoice['issue_date'])) ?>
            <?php if ($invoice['lpo_number'] !== ''): ?>
                · LPO <?= e($invoice['lpo_number']) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= url('/invoices/' . $invoice['id'] . '/print') ?>" target="_blank">
            <?= t('action.print') ?>
        </a>
        <?php if ($isDraft && can('sales.edit')): ?>
            <a class="btn" href="<?= url('/invoices/' . $invoice['id'] . '/edit') ?>"><?= t('action.edit') ?></a>
        <?php endif; ?>
        <?php if ($isDraft && can('sales.post')): ?>
            <form method="post" action="<?= url('/invoices/' . $invoice['id'] . '/post') ?>"
                  data-confirm="Post this invoice? It will book the revenue and move the stock, and can no longer be edited.">
                <?= csrf_field() ?>
                <button class="btn btn--primary" type="submit"><?= t('action.post') ?></button>
            </form>
        <?php endif; ?>
        <?php if (!$isDraft && !$isVoid && (int) $invoice['balance_due'] > 0 && can('payments.create')): ?>
            <a class="btn btn--primary"
               href="<?= url('/receipts/new', ['contact_id' => $invoice['contact_id'], 'doc_id' => $invoice['id']]) ?>">
                <?= t('action.record_payment') ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($isVoid): ?>
    <div class="alert alert--error">
        <span class="alert__icon">✕</span>
        <span>This invoice was voided on <?= e(fdate($invoice['voided_at'], true)) ?>.
            <?= $invoice['void_reason'] !== '' ? 'Reason: ' . e($invoice['void_reason']) : '' ?></span>
    </div>
<?php elseif ($isDraft): ?>
    <div class="alert alert--warning">
        <span class="alert__icon">!</span>
        <span>This invoice is a draft. It is not in your books and has not moved any stock.</span>
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
                        <th class="num"><?= t('field.quantity') ?></th>
                        <th class="num"><?= t('field.unit_price') ?></th>
                        <?php if ((int) $invoice['discount_total'] > 0): ?>
                            <th class="num">Disc</th>
                        <?php endif; ?>
                        <?php if ((int) $invoice['tax_total'] > 0): ?>
                            <th class="num"><?= t('field.tax') ?></th>
                        <?php endif; ?>
                        <th class="num"><?= t('field.total') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($invoice['lines'] as $line): ?>
                        <tr>
                            <td class="muted tiny"><?= (int) $line['line_no'] ?></td>
                            <td>
                                <?= e($line['description']) ?>
                                <?php if ($line['sku']): ?>
                                    <div class="tiny muted">
                                        <a href="<?= url('/items/' . $line['item_id']) ?>"><?= e($line['sku']) ?></a>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="num">
                                <?= e(rtrim(rtrim(number_format((float) $line['quantity'], 3, '.', ''), '0'), '.')) ?>
                                <span class="tiny muted"><?= e($line['uom']) ?></span>
                            </td>
                            <td class="num"><?= e(money($line['unit_price'])) ?></td>
                            <?php if ((int) $invoice['discount_total'] > 0): ?>
                                <td class="num muted"><?= e((string) (float) $line['discount_pct']) ?>%</td>
                            <?php endif; ?>
                            <?php if ((int) $invoice['tax_total'] > 0): ?>
                                <td class="num muted"><?= e(money($line['line_tax'])) ?></td>
                            <?php endif; ?>
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
                        <span class="num mono"><?= e(money($invoice['subtotal'])) ?></span>
                    </div>
                    <?php if ((int) $invoice['discount_total'] > 0): ?>
                        <div class="totals-box__row">
                            <span><?= t('field.discount') ?></span>
                            <span class="num mono">−<?= e(money($invoice['discount_total'])) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($taxBreakdown as $group): ?>
                        <?php if ((int) $group['tax'] > 0): ?>
                            <div class="totals-box__row">
                                <span><?= t('field.tax') ?> @ <?= e((string) $group['rate']) ?>%</span>
                                <span class="num mono"><?= e(money($group['tax'])) ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <div class="totals-box__row totals-box__row--grand">
                        <span><?= t('field.total') ?> (QAR)</span>
                        <span class="num mono"><?= e(money($invoice['total'])) ?></span>
                    </div>
                    <?php if ((int) $invoice['amount_paid'] > 0): ?>
                        <div class="totals-box__row">
                            <span class="text-ok"><?= t('field.paid') ?></span>
                            <span class="num mono text-ok">−<?= e(money($invoice['amount_paid'])) ?></span>
                        </div>
                        <div class="totals-box__row strong">
                            <span><?= t('field.balance') ?></span>
                            <span class="num mono"><?= e(money($invoice['balance_due'])) ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <p class="tiny faint mt-2 mb-0"><?= e(Money::inWords((int) $invoice['total'], 'en')) ?></p>
            </div>
        </div>

        <?php if ($invoice['payments'] !== []): ?>
            <div class="card">
                <div class="card__head"><h2 class="card__title">Payments received</h2></div>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                        <tr>
                            <th><?= t('field.number') ?></th>
                            <th><?= t('field.date') ?></th>
                            <th>Method</th>
                            <th><?= t('field.reference') ?></th>
                            <th class="num"><?= t('field.amount') ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($invoice['payments'] as $payment): ?>
                            <tr>
                                <td><?= e($payment['number']) ?></td>
                                <td class="nowrap"><?= e(fdate($payment['payment_date'])) ?></td>
                                <td><?= e($payment['method']) ?></td>
                                <td class="tiny muted"><?= e($payment['reference']) ?></td>
                                <td class="num"><?= e(money($payment['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($journal): ?>
            <div class="card">
                <div class="card__head">
                    <h2 class="card__title">Journal entry</h2>
                    <span class="topbar__spacer"></span>
                    <a class="btn btn--sm" href="<?= url('/journals/' . $journal['id']) ?>">
                        <?= e($journal['number']) ?>
                    </a>
                </div>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                        <tr>
                            <th>Account</th>
                            <th class="num">Debit</th>
                            <th class="num">Credit</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($journal['lines'] as $line): ?>
                            <tr>
                                <td>
                                    <span class="mono tiny"><?= e($line['code']) ?></span>
                                    <?= e(Lang::pick($line, 'name')) ?>
                                </td>
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
        <div class="card">
            <div class="card__head"><h2 class="card__title">Details</h2></div>
            <div class="card__body">
                <div class="detail-grid">
                    <div>
                        <div class="detail__label"><?= t('field.date') ?></div>
                        <div class="detail__value"><?= e(fdate($invoice['issue_date'])) ?></div>
                    </div>
                    <div>
                        <div class="detail__label"><?= t('field.due_date') ?></div>
                        <div class="detail__value <?= $invoice['is_overdue'] ? 'text-bad' : '' ?>">
                            <?= e(fdate($invoice['due_date'])) ?>
                        </div>
                    </div>
                    <?php if ($invoice['project'] !== ''): ?>
                        <div>
                            <div class="detail__label">Project</div>
                            <div class="detail__value"><?= e($invoice['project']) ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($invoice['posted_at']): ?>
                        <div>
                            <div class="detail__label">Posted</div>
                            <div class="detail__value tiny"><?= e(fdate($invoice['posted_at'], true)) ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($invoice['notes']): ?>
                    <div class="mt-2">
                        <div class="detail__label"><?= t('field.notes') ?></div>
                        <div class="small"><?= nl2br(e($invoice['notes'])) ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$isVoid && can('sales.void')): ?>
            <div class="card">
                <div class="card__head"><h2 class="card__title">Void this invoice</h2></div>
                <div class="card__body">
                    <p class="small muted">
                        Voiding reverses the journal entry and returns the stock. Both
                        the invoice and the reversal stay in the books, which is what
                        an auditor expects to find.
                    </p>
                    <?php if ((int) $invoice['amount_paid'] > 0): ?>
                        <p class="small text-warn">
                            This invoice has payments against it. Void the receipts first.
                        </p>
                    <?php endif; ?>
                    <form method="post" action="<?= url('/invoices/' . $invoice['id'] . '/void') ?>"
                          data-confirm="Void <?= e($invoice['number']) ?>? This cannot be undone.">
                        <?= csrf_field() ?>
                        <div class="field mb-1">
                            <label for="void_reason" class="required">Reason</label>
                            <input type="text" id="void_reason" name="void_reason" required
                                   placeholder="e.g. raised against the wrong customer">
                        </div>
                        <button class="btn btn--danger btn--sm" type="submit"
                                <?= (int) $invoice['amount_paid'] > 0 ? 'disabled' : '' ?>>
                            <?= t('action.void') ?>
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
