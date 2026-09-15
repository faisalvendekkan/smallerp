<?php
/**
 * One contact, with its trading history.
 *
 * @var array $contact
 */

use App\Core\Lang;

$isCustomer = in_array($contact['kind'], ['customer', 'both'], true);
$isSupplier = in_array($contact['kind'], ['supplier', 'both'], true);
?>
<div class="page-head">
    <div class="page-head__text">
        <div class="flex flex-wrap">
            <h1 class="mb-0"><?= e(Lang::pick($contact, 'name')) ?></h1>
            <span class="badge badge--<?= (int) $contact['is_active'] === 1 ? 'ok' : 'muted' ?>">
                <?= (int) $contact['is_active'] === 1 ? t('status.active') : 'archived' ?>
            </span>
        </div>
        <p class="page-head__sub">
            <?= e($contact['code']) ?>
            <?php if ($contact['cr_number'] !== ''): ?> · CR <?= e($contact['cr_number']) ?><?php endif; ?>
            <?php if ($contact['phone'] !== ''): ?> · <?= e($contact['phone']) ?><?php endif; ?>
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= url('/contacts/' . $contact['id'] . '/statement', ['from' => date('Y-01-01'), 'to' => date('Y-m-d')]) ?>"
           target="_blank">Statement</a>
        <?php if ($isCustomer && can('sales.create')): ?>
            <a class="btn" href="<?= url('/invoices/new', ['contact_id' => $contact['id']]) ?>">New invoice</a>
        <?php endif; ?>
        <?php if (can('contacts.edit')): ?>
            <a class="btn btn--primary" href="<?= url('/contacts/' . $contact['id'] . '/edit') ?>">
                <?= t('action.edit') ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="stats">
    <?php if ($isCustomer): ?>
        <div class="stat <?= $receivable > 0 ? 'stat--warn' : '' ?>">
            <div class="stat__label"><?= t('dash.receivable') ?></div>
            <div class="stat__value"><?= e(money($receivable)) ?></div>
            <?php if ((int) $contact['credit_limit'] > 0): ?>
                <div class="stat__meta">
                    limit <?= e(money($contact['credit_limit'])) ?>
                    <?php if ($receivable > (int) $contact['credit_limit']): ?>
                        <span class="text-bad strong">— over limit</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($isSupplier): ?>
        <div class="stat">
            <div class="stat__label"><?= t('dash.payable') ?></div>
            <div class="stat__value"><?= e(money($payable)) ?></div>
        </div>
    <?php endif; ?>
    <div class="stat">
        <div class="stat__label">Payment terms</div>
        <div class="stat__value"><?= (int) $contact['payment_terms_days'] ?><span class="small muted"> days</span></div>
    </div>
</div>

<div class="split">
    <div>
        <?php if ($isCustomer): ?>
            <div class="card">
                <div class="card__head">
                    <h2 class="card__title"><?= t('nav.invoices') ?></h2>
                    <span class="topbar__spacer"></span>
                    <a class="btn btn--sm" href="<?= url('/invoices', ['contact_id' => $contact['id']]) ?>">All</a>
                </div>
                <?php if ($invoices === []): ?>
                    <?= App\Core\View::partial('partials/empty', ['message' => 'No invoices yet.']) ?>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data">
                            <thead>
                            <tr>
                                <th><?= t('field.number') ?></th>
                                <th><?= t('field.date') ?></th>
                                <th class="num"><?= t('field.total') ?></th>
                                <th class="num"><?= t('field.balance') ?></th>
                                <th><?= t('field.status') ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($invoices as $invoice): ?>
                                <tr>
                                    <td><a href="<?= url('/invoices/' . $invoice['id']) ?>"><?= e($invoice['number']) ?></a></td>
                                    <td class="nowrap tiny"><?= e(fdate($invoice['issue_date'])) ?></td>
                                    <td class="num"><?= e(money($invoice['total'])) ?></td>
                                    <td class="num"><?= e(money($invoice['balance_due'])) ?></td>
                                    <td><span class="badge badge--<?= e(status_class($invoice['status'])) ?>">
                                        <?= t('status.' . $invoice['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($isSupplier): ?>
            <div class="card">
                <div class="card__head">
                    <h2 class="card__title"><?= t('nav.bills') ?></h2>
                    <span class="topbar__spacer"></span>
                    <a class="btn btn--sm" href="<?= url('/bills', ['contact_id' => $contact['id']]) ?>">All</a>
                </div>
                <?php if ($bills === []): ?>
                    <?= App\Core\View::partial('partials/empty', ['message' => 'No bills yet.']) ?>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data">
                            <thead>
                            <tr>
                                <th><?= t('field.number') ?></th>
                                <th><?= t('field.date') ?></th>
                                <th class="num"><?= t('field.total') ?></th>
                                <th class="num"><?= t('field.balance') ?></th>
                                <th><?= t('field.status') ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($bills as $bill): ?>
                                <tr>
                                    <td><a href="<?= url('/bills/' . $bill['id']) ?>"><?= e($bill['number']) ?></a></td>
                                    <td class="nowrap tiny"><?= e(fdate($bill['issue_date'])) ?></td>
                                    <td class="num"><?= e(money($bill['total'])) ?></td>
                                    <td class="num"><?= e(money($bill['balance_due'])) ?></td>
                                    <td><span class="badge badge--<?= e(status_class($bill['status'])) ?>">
                                        <?= t('status.' . $bill['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div>
        <div class="card">
            <div class="card__head"><h2 class="card__title">Details</h2></div>
            <div class="card__body">
                <div class="detail-grid">
                    <?php
                    $details = [
                        'Contact person' => $contact['contact_person'],
                        'Telephone' => $contact['phone'],
                        'Mobile' => $contact['mobile'],
                        'Email' => $contact['email'],
                        'Address' => $contact['address'],
                        'P.O. Box' => $contact['po_box'],
                        'City' => $contact['city'],
                        'Tax card' => $contact['tax_id'],
                    ];
                    ?>
                    <?php foreach ($details as $label => $value): ?>
                        <?php if ((string) $value !== ''): ?>
                            <div>
                                <div class="detail__label"><?= e($label) ?></div>
                                <div class="detail__value"><?= e($value) ?></div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php if ($contact['notes']): ?>
                    <div class="mt-2">
                        <div class="detail__label"><?= t('field.notes') ?></div>
                        <div class="small"><?= nl2br(e($contact['notes'])) ?></div>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (can('contacts.edit')): ?>
                <div class="card__foot">
                    <form method="post" action="<?= url('/contacts/' . $contact['id'] . '/archive') ?>"
                          data-confirm="<?= (int) $contact['is_active'] === 1 ? 'Archive this contact? Its documents stay in the books.' : 'Restore this contact?' ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn--sm" type="submit">
                            <?= (int) $contact['is_active'] === 1 ? 'Archive' : 'Restore' ?>
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($payments !== []): ?>
            <div class="card">
                <div class="card__head"><h2 class="card__title">Recent payments</h2></div>
                <div class="table-wrap">
                    <table class="data">
                        <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td>
                                    <a href="<?= url('/payments/' . $payment['id']) ?>"><?= e($payment['number']) ?></a>
                                    <div class="tiny muted"><?= e($payment['method']) ?></div>
                                </td>
                                <td class="nowrap tiny"><?= e(fdate($payment['payment_date'])) ?></td>
                                <td class="num"><?= e(money($payment['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
