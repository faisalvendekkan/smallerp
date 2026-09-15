<?php
/** One receipt or payment. */

use App\Core\Lang;
use App\Services\Payments;

$isIn = $payment['direction'] === Payments::IN;
$isVoid = $payment['status'] === 'void';
?>
<div class="page-head">
    <div class="page-head__text">
        <div class="flex flex-wrap">
            <h1 class="mb-0"><?= e($payment['number']) ?></h1>
            <span class="badge badge--<?= $isVoid ? 'bad' : 'ok' ?>">
                <?= $isVoid ? t('status.void') : t('status.posted') ?>
            </span>
            <span class="badge badge--info"><?= $isIn ? 'Money in' : 'Money out' ?></span>
        </div>
        <p class="page-head__sub">
            <?php if ($payment['contact_id']): ?>
                <a href="<?= url('/contacts/' . $payment['contact_id']) ?>">
                    <?= e(Lang::pick($payment, 'contact_name')) ?></a> ·
            <?php endif; ?>
            <?= e(fdate($payment['payment_date'])) ?>
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= url('/payments/' . $payment['id'] . '/print') ?>" target="_blank">
            <?= t('action.print') ?>
        </a>
    </div>
</div>

<?php if ($isVoid): ?>
    <div class="alert alert--error">
        <span class="alert__icon">✕</span>
        <span>This payment was voided on <?= e(fdate($payment['voided_at'], true)) ?> and its journal reversed.</span>
    </div>
<?php endif; ?>

<div class="split split--sidebar">
    <div>
        <div class="card">
            <div class="card__head"><h2 class="card__title">Applied to</h2></div>
            <?php if ($payment['allocations'] === []): ?>
                <?= App\Core\View::partial('partials/empty', [
                    'message' => 'Nothing was applied — this is sitting on account.',
                ]) ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                        <tr>
                            <th>Document</th>
                            <th><?= t('field.date') ?></th>
                            <th class="num">Document total</th>
                            <th class="num">Applied</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($payment['allocations'] as $allocation): ?>
                            <tr>
                                <td>
                                    <a href="<?= url(($allocation['doc_type'] === 'sales_invoice' ? '/invoices/' : '/bills/') . $allocation['doc_id']) ?>">
                                        <?= e($allocation['doc_number']) ?>
                                    </a>
                                </td>
                                <td class="nowrap tiny"><?= e(fdate($allocation['doc_date'])) ?></td>
                                <td class="num muted"><?= e(money($allocation['doc_total'])) ?></td>
                                <td class="num strong"><?= e(money($allocation['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                        <tr>
                            <td colspan="3" class="text-end">Applied</td>
                            <td class="num"><?= e(money($payment['allocated'])) ?></td>
                        </tr>
                        <?php if ((int) $payment['unallocated'] > 0): ?>
                            <tr>
                                <td colspan="3" class="text-end">On account</td>
                                <td class="num text-warn"><?= e(money($payment['unallocated'])) ?></td>
                            </tr>
                        <?php endif; ?>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
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
                        <thead><tr><th>Account</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
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
        <div class="card">
            <div class="card__head"><h2 class="card__title">Details</h2></div>
            <div class="card__body">
                <div class="detail-grid">
                    <div>
                        <div class="detail__label"><?= t('field.amount') ?></div>
                        <div class="detail__value strong" style="font-size:1.15rem">
                            <?= e(money($payment['amount'], true)) ?>
                        </div>
                    </div>
                    <div>
                        <div class="detail__label">Method</div>
                        <div class="detail__value">
                            <?= e(Payments::METHODS[$payment['method']]['name_en'] ?? $payment['method']) ?>
                        </div>
                    </div>
                    <div>
                        <div class="detail__label">Account</div>
                        <div class="detail__value"><?= e($payment['account_name_en']) ?></div>
                    </div>
                    <?php if ($payment['cheque_number'] !== ''): ?>
                        <div>
                            <div class="detail__label">Cheque</div>
                            <div class="detail__value mono"><?= e($payment['cheque_number']) ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($payment['cheque_date']): ?>
                        <div>
                            <div class="detail__label">Cheque date</div>
                            <div class="detail__value">
                                <?= e(fdate($payment['cheque_date'])) ?>
                                <?php if ($payment['cheque_date'] > date('Y-m-d')): ?>
                                    <span class="badge badge--warn">post-dated</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($payment['reference'] !== ''): ?>
                        <div>
                            <div class="detail__label"><?= t('field.reference') ?></div>
                            <div class="detail__value"><?= e($payment['reference']) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!$isVoid && can('payments.void')): ?>
                <div class="card__foot">
                    <form method="post" action="<?= url('/payments/' . $payment['id'] . '/void') ?>"
                          data-confirm="Void this payment? Its journal will be reversed and the documents it settled will go back to outstanding.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="void_reason" value="Voided by user">
                        <button class="btn btn--danger btn--sm" type="submit"><?= t('action.void') ?></button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
