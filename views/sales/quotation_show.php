<?php
/** One quotation. */

use App\Core\Lang;
?>
<div class="page-head">
    <div class="page-head__text">
        <div class="flex flex-wrap">
            <h1 class="mb-0"><?= e($quotation['number']) ?></h1>
            <span class="badge badge--<?= e(status_class($quotation['status'])) ?>">
                <?= e(ucfirst($quotation['status'])) ?>
            </span>
        </div>
        <p class="page-head__sub">
            <a href="<?= url('/contacts/' . $quotation['contact_id']) ?>">
                <?= e(Lang::pick($quotation, 'contact_name')) ?></a>
            · <?= e(fdate($quotation['issue_date'])) ?>
            <?php if ($quotation['valid_until']): ?>
                · valid until <?= e(fdate($quotation['valid_until'])) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= url('/quotations/' . $quotation['id'] . '/print') ?>" target="_blank">
            <?= t('action.print') ?>
        </a>
        <?php if ($quotation['status'] !== 'invoiced' && can('sales.edit')): ?>
            <a class="btn" href="<?= url('/quotations/' . $quotation['id'] . '/edit') ?>"><?= t('action.edit') ?></a>
        <?php endif; ?>
        <?php if ($quotation['status'] !== 'invoiced' && can('sales.create')): ?>
            <form method="post" action="<?= url('/quotations/' . $quotation['id'] . '/convert') ?>"
                  data-confirm="Create a draft invoice from this quotation?">
                <?= csrf_field() ?>
                <button class="btn btn--primary" type="submit">Convert to invoice</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($quotation['status'] === 'invoiced' && $quotation['invoice_id']): ?>
    <div class="alert alert--success">
        <span class="alert__icon">✓</span>
        <span>Converted to invoice
            <a href="<?= url('/invoices/' . $quotation['invoice_id']) ?>">view it</a>.</span>
    </div>
<?php endif; ?>

<div class="split split--sidebar">
    <div>
        <div class="card">
            <?php if ($quotation['subject'] !== ''): ?>
                <div class="card__head"><h2 class="card__title"><?= e($quotation['subject']) ?></h2></div>
            <?php endif; ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                    <tr>
                        <th style="width:26px">#</th>
                        <th><?= t('field.description') ?></th>
                        <th class="num"><?= t('field.quantity') ?></th>
                        <th class="num"><?= t('field.unit_price') ?></th>
                        <th class="num"><?= t('field.total') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($quotation['lines'] as $line): ?>
                        <tr>
                            <td class="muted tiny"><?= (int) $line['line_no'] ?></td>
                            <td>
                                <?= e($line['description']) ?>
                                <?php if ($line['sku']): ?>
                                    <div class="tiny muted"><?= e($line['sku']) ?></div>
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
                        <span class="num mono"><?= e(money($quotation['subtotal'])) ?></span>
                    </div>
                    <?php if ((int) $quotation['tax_total'] > 0): ?>
                        <div class="totals-box__row">
                            <span><?= t('field.tax') ?></span>
                            <span class="num mono"><?= e(money($quotation['tax_total'])) ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="totals-box__row totals-box__row--grand">
                        <span><?= t('field.total') ?> (QAR)</span>
                        <span class="num mono"><?= e(money($quotation['total'])) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div>
        <?php if (can('sales.edit') && $quotation['status'] !== 'invoiced'): ?>
            <div class="card">
                <div class="card__head"><h2 class="card__title">Mark as</h2></div>
                <div class="card__body">
                    <div class="btn-group">
                        <?php foreach (['sent' => 'Sent', 'accepted' => 'Accepted', 'rejected' => 'Rejected'] as $value => $label): ?>
                            <form method="post" action="<?= url('/quotations/' . $quotation['id'] . '/status') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="status" value="<?= $value ?>">
                                <button class="btn btn--sm <?= $quotation['status'] === $value ? 'btn--primary' : '' ?>"
                                        type="submit"><?= $label ?></button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($quotation['notes'] || $quotation['terms']): ?>
            <div class="card">
                <div class="card__body">
                    <?php if ($quotation['terms']): ?>
                        <div class="detail__label">Terms</div>
                        <div class="small mb-2"><?= nl2br(e($quotation['terms'])) ?></div>
                    <?php endif; ?>
                    <?php if ($quotation['notes']): ?>
                        <div class="detail__label"><?= t('field.notes') ?></div>
                        <div class="small"><?= nl2br(e($quotation['notes'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
