<?php
/** Journal entries. */
?>
<div class="page-head">
    <div class="page-head__text">
        <p class="page-head__sub">
            <?= number_format((int) $pagination['total']) ?> entries.
            Entries raised by an invoice, bill or payroll run are posted
            automatically — correct those by voiding the document, not here.
        </p>
    </div>
    <div class="page-head__actions">
        <?php if (can('accounting.post')): ?>
            <a class="btn btn--primary" href="<?= url('/journals/new') ?>">+ Manual entry</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <form class="filters" method="get" action="<?= url('/journals') ?>" data-auto-submit>
            <div class="field field--search">
                <label for="search"><?= t('action.search') ?></label>
                <input type="search" id="search" name="search" value="<?= e($search) ?>"
                       placeholder="Number or description">
            </div>
            <div class="field">
                <label for="source">Source</label>
                <select id="source" name="source">
                    <option value="">All</option>
                    <?php foreach ($sources as $value): ?>
                        <option value="<?= e($value) ?>" <?= $source === $value ? 'selected' : '' ?>>
                            <?= e(ucfirst(str_replace('_', ' ', $value))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="from">From</label>
                <input type="date" id="from" name="from" value="<?= e($from) ?>">
            </div>
            <div class="field">
                <label for="to">To</label>
                <input type="date" id="to" name="to" value="<?= e($to) ?>">
            </div>
            <button class="btn" type="submit"><?= t('action.filter') ?></button>
        </form>
    </div>

    <?php if ($rows === []): ?>
        <?= App\Core\View::partial('partials/empty', ['message' => 'No journal entries in this period.']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th><?= t('field.number') ?></th>
                    <th><?= t('field.date') ?></th>
                    <th><?= t('field.description') ?></th>
                    <th>Source</th>
                    <th>By</th>
                    <th class="num"><?= t('field.amount') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="nowrap">
                            <a href="<?= url('/journals/' . $row['id']) ?>"><?= e($row['number']) ?></a>
                            <?php if ((int) $row['is_reversal'] === 1): ?>
                                <span class="badge badge--warn">reversal</span>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap tiny"><?= e(fdate($row['entry_date'])) ?></td>
                        <td class="small"><?= e($row['memo']) ?></td>
                        <td class="tiny muted"><?= e(str_replace('_', ' ', $row['source_type'])) ?></td>
                        <td class="tiny muted"><?= e($row['created_by_name'] ?? '') ?></td>
                        <td class="num"><?= e(money($row['total_debit'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= App\Core\View::partial('partials/pagination', ['pagination' => $pagination]) ?>
    <?php endif; ?>
</div>
