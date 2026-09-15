<?php
/** User accounts. */
?>
<div class="page-head">
    <div class="page-head__text">
        <p class="page-head__sub">
            <?= count($users) ?> accounts. Payroll is deliberately walled off from the
            sales role — salaries are the one thing an owner does not want on the
            sales desk's screen.
        </p>
    </div>
    <div class="page-head__actions">
        <?php if (can('users.create')): ?>
            <a class="btn btn--primary" href="<?= url('/users/new') ?>">+ <?= t('action.new') ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="data">
            <thead>
            <tr>
                <th>Name</th>
                <th>Username</th>
                <th>Role</th>
                <th>Language</th>
                <th>Last signed in</th>
                <th><?= t('field.status') ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td>
                        <?= e($user['name']) ?>
                        <?php if ($user['email'] !== ''): ?>
                            <div class="tiny muted"><?= e($user['email']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="mono tiny"><?= e($user['username']) ?></td>
                    <td>
                        <span class="badge badge--<?= $user['role'] === 'admin' ? 'info' : 'muted' ?>">
                            <?= e($roles[$user['role']]['name_en'] ?? $user['role']) ?>
                        </span>
                    </td>
                    <td class="tiny muted"><?= $user['locale'] === 'ar' ? 'العربية' : 'English' ?></td>
                    <td class="tiny muted"><?= e(fdate($user['last_login_at'], true)) ?></td>
                    <td>
                        <?php if ((int) $user['is_active'] === 1): ?>
                            <span class="badge badge--ok"><?= t('status.active') ?></span>
                        <?php else: ?>
                            <span class="badge badge--bad">disabled</span>
                        <?php endif; ?>
                        <?php if ($user['locked_until'] && strtotime((string) $user['locked_until']) > time()): ?>
                            <span class="badge badge--warn">locked out</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (can('users.edit')): ?>
                            <a class="btn btn--sm" href="<?= url('/users/' . $user['id'] . '/edit') ?>">
                                <?= t('action.edit') ?>
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
