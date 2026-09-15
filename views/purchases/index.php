<?php
/** Supplier bill list. */

use App\Core\Lang;
?>
<div class="page-head">
    <div class="page-head__text">
        <p class="page-head__sub">
            <?= number_format((int) $summary['count']) ?> bills ·
            <?= e(money($summary['total'], true)) ?> billed ·
            <strong class="<?= $summary['outstanding'] > 0 ? 'text-warn' : 'text-ok' ?>">
                <?= e(money($summary['outstanding'], true)) ?> outstanding
            </strong>
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= url('/bills/export', $_GET) ?>"><?= t('action.export') ?></a>
        <?php if (can('purchases.create')): ?>
            <a class="btn btn--primary" href="<?= url('/bills/new') ?>">+ <?= t('action.new') ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <form class="filters" method="get" action="<?= url('/bills') ?>" data-auto-submit>
            <div class="field field--search">
                <label for="search"><?= t('action.search') ?></label>
                <input type="search" id="search" name="search" value="<?= e($filters['search']) ?>"
                       placeholder="Number, supplier invoice or supplier">
            </div>
            <div class="field">
                <label for="status"><?= t('field.status') ?></label>
                <select id="status" name="status">
                    <option value="">All</option>
                    <?php foreach (['draft', 'posted', 'partial', 'paid', 'void'] as $status): ?>
                        <option value="<?= $status ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
                            <?= t('status.' . $status) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="contact_id"><?= t('field.supplier') ?></label>
                <select id="contact_id" name="contact_id">
                    <option value="">All</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?= (int) $supplier['id'] ?>"
                            <?= (int) $filters['contact_id'] === (int) $supplier['id'] ? 'selected' : '' ?>>
                            <?= e(Lang::pick($supplier, 'name')) ?>
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
            <a class="btn btn--ghost" href="<?= url('/bills') ?>"><?= t('action.clear') ?></a>
        </form>
    </div>

    <?php if ($rows === []): ?>
        <?= App\Core\View::partial('partials/empty', [
            'message' => 'No bills match these filters.',
            'actionUrl' => can('purchases.create') ? url('/bills/new') : null,
            'actionLabel' => __('action.new') . ' ' . __('nav.bills'),
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th><?= t('field.number') ?></th>
                    <th>Supplier invoice</th>
                    <th><?= t('field.supplier') ?></th>
                    <th><?= t('field.date') ?></th>
                    <th><?= t('field.due_date') ?></th>
                    <th class="num"><?= t('field.total') ?></th>
                    <th class="num"><?= t('field.balance') ?></th>
                    <th><?= t('field.status') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="nowrap"><a href="<?= url('/bills/' . $row['id']) ?>"><?= e($row['number']) ?></a></td>
                        <td class="mono tiny"><?= e($row['supplier_invoice_no']) ?></td>
                        <td><?= e(Lang::pick($row, 'contact_name')) ?></td>
                        <td class="nowrap"><?= e(fdate($row['issue_date'])) ?></td>
                        <td class="nowrap <?= $row['is_overdue'] ? 'text-bad strong' : '' ?>">
                            <?= e(fdate($row['due_date'])) ?>
                        </td>
                        <td class="num"><?= e(money($row['total'])) ?></td>
                        <td class="num strong"><?= e(money($row['balance_due'])) ?></td>
                        <td>
                            <?php if ($row['is_overdue']): ?>
                                <span class="badge badge--bad"><?= t('status.overdue') ?></span>
                            <?php else: ?>
                                <span class="badge badge--<?= e(status_class($row['status'])) ?>">
                                    <?= t('status.' . $row['status']) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= App\Core\View::partial('partials/pagination', ['pagination' => $pagination]) ?>
    <?php endif; ?>
</div>
