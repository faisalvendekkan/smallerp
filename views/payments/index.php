<?php
/** Receipt / payment list. */

use App\Core\Lang;
use App\Services\Payments;

$isIn = $direction === Payments::IN;
$listUrl = $isIn ? '/receipts' : '/payments';
?>
<div class="page-head">
    <div class="page-head__text">
        <p class="page-head__sub">
            <?= number_format((int) $pagination['total']) ?> ·
            <?= e(money($total, true)) ?> <?= $isIn ? 'received' : 'paid out' ?>
        </p>
    </div>
    <div class="page-head__actions">
        <?php if (can('payments.create')): ?>
            <a class="btn btn--primary" href="<?= url($isIn ? '/receipts/new' : '/payments/new') ?>">
                + <?= t('action.new') ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <form class="filters" method="get" action="<?= url($listUrl) ?>" data-auto-submit>
            <div class="field field--search">
                <label for="search"><?= t('action.search') ?></label>
                <input type="search" id="search" name="search" value="<?= e($filters['search']) ?>"
                       placeholder="Number, reference or cheque number">
            </div>
            <div class="field">
                <label for="method">Method</label>
                <select id="method" name="method">
                    <option value="">All</option>
                    <?php foreach (Payments::METHODS as $key => $method): ?>
                        <option value="<?= e($key) ?>" <?= $filters['method'] === $key ? 'selected' : '' ?>>
                            <?= e(Lang::isRtl() ? $method['name_ar'] : $method['name_en']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="contact_id"><?= $isIn ? t('field.customer') : t('field.supplier') ?></label>
                <select id="contact_id" name="contact_id">
                    <option value="">All</option>
                    <?php foreach ($contacts as $contact): ?>
                        <option value="<?= (int) $contact['id'] ?>"
                            <?= (int) $filters['contact_id'] === (int) $contact['id'] ? 'selected' : '' ?>>
                            <?= e(Lang::pick($contact, 'name')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="from">From</label>
                <input type="date" id="from" name="from" value="<?= e($filters['from'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="to">To</label>
                <input type="date" id="to" name="to" value="<?= e($filters['to'] ?? '') ?>">
            </div>
            <button class="btn" type="submit"><?= t('action.filter') ?></button>
            <a class="btn btn--ghost" href="<?= url($listUrl) ?>"><?= t('action.clear') ?></a>
        </form>
    </div>

    <?php if ($rows === []): ?>
        <?= App\Core\View::partial('partials/empty', [
            'message' => $isIn ? 'No receipts recorded yet.' : 'No payments recorded yet.',
            'actionUrl' => can('payments.create') ? url($isIn ? '/receipts/new' : '/payments/new') : null,
            'actionLabel' => __('action.new'),
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th><?= t('field.number') ?></th>
                    <th><?= t('field.date') ?></th>
                    <th><?= $isIn ? t('field.customer') : t('field.supplier') ?></th>
                    <th>Method</th>
                    <th><?= t('field.reference') ?></th>
                    <th>Account</th>
                    <th class="num"><?= t('field.amount') ?></th>
                    <th class="num">Unapplied</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $unallocated = (int) $row['amount'] - (int) $row['allocated']; ?>
                    <tr>
                        <td class="nowrap">
                            <a href="<?= url('/payments/' . $row['id']) ?>"><?= e($row['number']) ?></a>
                            <?php if ($row['status'] === 'void'): ?>
                                <span class="badge badge--bad"><?= t('status.void') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap"><?= e(fdate($row['payment_date'])) ?></td>
                        <td><?= e(Lang::pick($row, 'contact_name')) ?: '<span class="muted">—</span>' ?></td>
                        <td class="small">
                            <?= e(Lang::isRtl()
                                ? (Payments::METHODS[$row['method']]['name_ar'] ?? $row['method'])
                                : (Payments::METHODS[$row['method']]['name_en'] ?? $row['method'])) ?>
                            <?php if ($row['cheque_number'] !== ''): ?>
                                <div class="tiny muted">#<?= e($row['cheque_number']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="tiny muted"><?= e($row['reference']) ?></td>
                        <td class="tiny muted"><?= e($row['account_name_en']) ?></td>
                        <td class="num strong"><?= e(money($row['amount'])) ?></td>
                        <td class="num <?= $unallocated > 0 ? 'text-warn' : 'muted' ?>">
                            <?= $unallocated > 0 ? e(money($unallocated)) : '—' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= App\Core\View::partial('partials/pagination', ['pagination' => $pagination]) ?>
    <?php endif; ?>
</div>
