<?php
/**
 * Employee list, with the expiry warning that matters most in Qatar shown
 * inline rather than buried on a report.
 */

use App\Core\Lang;
use App\Services\Hr;
?>
<div class="page-head">
    <div class="page-head__text">
        <p class="page-head__sub">
            <?= number_format((int) $pagination['total']) ?> employees ·
            <?= e(money($monthlyPayroll, true)) ?> monthly wage bill on this page
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= url('/reports/expiries') ?>">Expiries</a>
        <a class="btn" href="<?= url('/employees/export', $_GET) ?>"><?= t('action.export') ?></a>
        <?php if (can('hr.create')): ?>
            <a class="btn btn--primary" href="<?= url('/employees/new') ?>">+ <?= t('action.new') ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <form class="filters" method="get" action="<?= url('/employees') ?>" data-auto-submit>
            <div class="field field--search">
                <label for="search"><?= t('action.search') ?></label>
                <input type="search" id="search" name="search" value="<?= e($filters['search']) ?>"
                       placeholder="Name, code, QID or designation">
            </div>
            <div class="field">
                <label for="status"><?= t('field.status') ?></label>
                <select id="status" name="status">
                    <option value="">All</option>
                    <?php foreach ([Hr::STATUS_ACTIVE, Hr::STATUS_ON_LEAVE, Hr::STATUS_TERMINATED] as $status): ?>
                        <option value="<?= $status ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
                            <?= e(ucfirst(str_replace('_', ' ', $status))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($departments !== []): ?>
                <div class="field">
                    <label for="department">Department</label>
                    <select id="department" name="department">
                        <option value="">All</option>
                        <?php foreach ($departments as $department): ?>
                            <option value="<?= e($department) ?>"
                                <?= $filters['department'] === $department ? 'selected' : '' ?>>
                                <?= e($department) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <button class="btn" type="submit"><?= t('action.filter') ?></button>
        </form>
    </div>

    <?php if ($rows === []): ?>
        <?= App\Core\View::partial('partials/empty', [
            'message' => 'No employees match these filters.',
            'actionUrl' => can('hr.create') ? url('/employees/new') : null,
            'actionLabel' => __('action.new'),
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th><?= t('qatar.qid') ?></th>
                    <th>Designation</th>
                    <th>Joined</th>
                    <th class="num">Basic</th>
                    <th class="num">Gross</th>
                    <th>Documents</th>
                    <th><?= t('field.status') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="mono tiny"><?= e($row['code']) ?></td>
                        <td>
                            <a href="<?= url('/employees/' . $row['id']) ?>"><?= e(Lang::pick($row, 'name')) ?></a>
                            <?php if ($row['nationality'] !== ''): ?>
                                <div class="tiny muted"><?= e($row['nationality']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="mono tiny"><?= e($row['qid']) ?: '<span class="text-warn">missing</span>' ?></td>
                        <td class="small"><?= e($row['designation']) ?></td>
                        <td class="nowrap tiny"><?= e(fdate($row['join_date'])) ?></td>
                        <td class="num"><?= e(money($row['basic_salary'])) ?></td>
                        <td class="num strong"><?= e(money($row['gross_salary'])) ?></td>
                        <td>
                            <?php $worst = $row['worst_expiry']; ?>
                            <?php if ($worst && in_array($worst['level'], ['expired', 'critical', 'warning'], true)): ?>
                                <div class="expiry expiry--<?= e($worst['level']) ?>">
                                    <span class="expiry__dot"></span>
                                    <span class="tiny">
                                        <?= e(Lang::pick($worst, 'document')) ?>
                                        <?= $worst['days'] < 0
                                            ? '<strong class="text-bad">expired</strong>'
                                            : (int) $worst['days'] . 'd' ?>
                                    </span>
                                </div>
                            <?php else: ?>
                                <span class="tiny text-ok">✓</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge--<?= e(status_class($row['status'])) ?>">
                                <?= e(ucfirst(str_replace('_', ' ', $row['status']))) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= App\Core\View::partial('partials/pagination', ['pagination' => $pagination]) ?>
    <?php endif; ?>
</div>
