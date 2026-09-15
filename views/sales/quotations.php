<?php
/** Quotation list. */

use App\Core\Lang;
?>
<div class="page-head">
    <div class="page-head__text">
        <p class="page-head__sub"><?= number_format((int) $pagination['total']) ?> quotations</p>
    </div>
    <div class="page-head__actions">
        <?php if (can('sales.create')): ?>
            <a class="btn btn--primary" href="<?= url('/quotations/new') ?>">+ <?= t('action.new') ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <form class="filters" method="get" action="<?= url('/quotations') ?>" data-auto-submit>
            <div class="field field--search">
                <label for="search"><?= t('action.search') ?></label>
                <input type="search" id="search" name="search" value="<?= e($search) ?>"
                       placeholder="Number, subject or customer">
            </div>
            <div class="field">
                <label for="status"><?= t('field.status') ?></label>
                <select id="status" name="status">
                    <option value="">All</option>
                    <?php foreach (['draft', 'sent', 'accepted', 'rejected', 'invoiced'] as $value): ?>
                        <option value="<?= $value ?>" <?= $status === $value ? 'selected' : '' ?>>
                            <?= e(ucfirst($value)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn" type="submit"><?= t('action.filter') ?></button>
        </form>
    </div>

    <?php if ($rows === []): ?>
        <?= App\Core\View::partial('partials/empty', [
            'message' => 'No quotations yet.',
            'actionUrl' => can('sales.create') ? url('/quotations/new') : null,
            'actionLabel' => __('action.new'),
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th><?= t('field.number') ?></th>
                    <th><?= t('field.customer') ?></th>
                    <th>Subject</th>
                    <th><?= t('field.date') ?></th>
                    <th>Valid until</th>
                    <th class="num"><?= t('field.total') ?></th>
                    <th><?= t('field.status') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="nowrap">
                            <a href="<?= url('/quotations/' . $row['id']) ?>"><?= e($row['number']) ?></a>
                        </td>
                        <td><?= e(Lang::pick($row, 'contact_name')) ?></td>
                        <td class="small muted"><?= e($row['subject']) ?></td>
                        <td class="nowrap tiny"><?= e(fdate($row['issue_date'])) ?></td>
                        <td class="nowrap tiny <?= $row['is_expired'] ? 'text-warn' : '' ?>">
                            <?= e(fdate($row['valid_until'])) ?>
                        </td>
                        <td class="num strong"><?= e(money($row['total'])) ?></td>
                        <td>
                            <?php if ($row['is_expired']): ?>
                                <span class="badge badge--warn">expired</span>
                            <?php else: ?>
                                <span class="badge badge--<?= e(status_class($row['status'])) ?>">
                                    <?= e(ucfirst($row['status'])) ?>
                                </span>
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
