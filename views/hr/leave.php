<?php
/** Leave requests. */

use App\Core\Lang;
?>
<div class="page-head">
    <div class="page-head__text">
        <p class="page-head__sub">
            Annual leave under Art. 79 of the Labour Law: three weeks a year,
            four after five years of service. Unpaid leave is excluded from service
            when gratuity is calculated.
        </p>
    </div>
</div>

<div class="split split--sidebar">
    <div>
        <div class="card">
            <div class="card__head">
                <h2 class="card__title">Requests</h2>
                <span class="topbar__spacer"></span>
                <form method="get" action="<?= url('/leave') ?>" data-auto-submit>
                    <select name="status">
                        <option value="">All</option>
                        <?php foreach (['pending', 'approved', 'rejected', 'cancelled'] as $value): ?>
                            <option value="<?= $value ?>" <?= $status === $value ? 'selected' : '' ?>>
                                <?= e(ucfirst($value)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <?php if ($rows === []): ?>
                <?= App\Core\View::partial('partials/empty', ['message' => 'No leave requests.']) ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Type</th>
                            <th>From</th>
                            <th>To</th>
                            <th class="num">Days</th>
                            <th><?= t('field.status') ?></th>
                            <th><?= t('field.actions') ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>
                                    <a href="<?= url('/employees/' . $row['employee_id']) ?>">
                                        <?= e(Lang::pick($row, 'name')) ?></a>
                                    <div class="tiny muted"><?= e($row['department']) ?></div>
                                </td>
                                <td class="small">
                                    <?= e(Lang::isRtl()
                                        ? ($leaveTypes[$row['leave_type']]['name_ar'] ?? $row['leave_type'])
                                        : ($leaveTypes[$row['leave_type']]['name_en'] ?? $row['leave_type'])) ?>
                                    <?php if (!($leaveTypes[$row['leave_type']]['paid'] ?? true)): ?>
                                        <span class="badge badge--warn">unpaid</span>
                                    <?php endif; ?>
                                </td>
                                <td class="nowrap tiny"><?= e(fdate($row['start_date'])) ?></td>
                                <td class="nowrap tiny"><?= e(fdate($row['end_date'])) ?></td>
                                <td class="num"><?= e((string) (float) $row['days']) ?></td>
                                <td><span class="badge badge--<?= e(status_class($row['status'])) ?>">
                                    <?= t('status.' . $row['status']) ?></span></td>
                                <td>
                                    <?php if ($row['status'] === 'pending' && can('hr.edit')): ?>
                                        <div class="btn-group">
                                            <?php foreach (['approved' => 'Approve', 'rejected' => 'Reject'] as $decision => $label): ?>
                                                <form method="post" action="<?= url('/leave/' . $row['id'] . '/decide') ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="decision" value="<?= $decision ?>">
                                                    <button class="btn btn--sm <?= $decision === 'approved' ? 'btn--primary' : '' ?>"
                                                            type="submit"><?= $label ?></button>
                                                </form>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (can('hr.edit')): ?>
        <div>
            <div class="card">
                <div class="card__head"><h2 class="card__title">Record leave</h2></div>
                <form method="post" action="<?= url('/leave') ?>">
                    <?= csrf_field() ?>
                    <div class="card__body">
                        <div class="field mb-1">
                            <label for="employee_id" class="required">Employee</label>
                            <select id="employee_id" name="employee_id" required>
                                <option value="">— choose —</option>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?= (int) $employee['id'] ?>">
                                        <?= e($employee['code'] . ' · ' . Lang::pick($employee, 'name')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field mb-1">
                            <label for="leave_type">Type</label>
                            <select id="leave_type" name="leave_type">
                                <?php foreach ($leaveTypes as $key => $type): ?>
                                    <option value="<?= e($key) ?>">
                                        <?= e(Lang::isRtl() ? $type['name_ar'] : $type['name_en']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field mb-1">
                            <label for="start_date" class="required">From</label>
                            <input type="date" id="start_date" name="start_date" required>
                        </div>
                        <div class="field mb-1">
                            <label for="end_date" class="required">To</label>
                            <input type="date" id="end_date" name="end_date" required>
                        </div>
                        <div class="field mb-1">
                            <label for="days">Days</label>
                            <input type="text" class="num" id="days" name="days" inputmode="decimal">
                            <span class="field__hint">Left blank, counted as calendar days inclusive.</span>
                        </div>
                        <div class="field">
                            <label for="reason">Reason</label>
                            <input type="text" id="reason" name="reason">
                        </div>
                    </div>
                    <div class="card__foot">
                        <button class="btn btn--primary btn--block" type="submit"><?= t('action.save') ?></button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
