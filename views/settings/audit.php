<?php
/**
 * The audit trail.
 *
 * Not optional in a system that produces statutory books: when an auditor asks
 * who voided an invoice, the answer has to be in the database.
 */
?>
<div class="page-head">
    <div class="page-head__text">
        <p class="page-head__sub">
            The last <?= count($rows) ?> recorded actions. The log is append-only.
        </p>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <form class="filters" method="get" action="<?= url('/audit') ?>" data-auto-submit>
            <div class="field">
                <label for="entity_type">Entity</label>
                <select id="entity_type" name="entity_type">
                    <option value="">All</option>
                    <?php foreach ($entityTypes as $value): ?>
                        <option value="<?= e($value) ?>" <?= $entityType === $value ? 'selected' : '' ?>>
                            <?= e(ucfirst(str_replace('_', ' ', $value))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="user_id">User</label>
                <select id="user_id" name="user_id">
                    <option value="">All</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int) $user['id'] ?>" <?= $userId === (int) $user['id'] ? 'selected' : '' ?>>
                            <?= e($user['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <span class="topbar__spacer"></span>
            <input type="search" placeholder="Filter…" data-table-filter="#audit-table" style="width:180px">
        </form>
    </div>

    <?php if ($rows === []): ?>
        <?= App\Core\View::partial('partials/empty', ['message' => 'Nothing recorded yet.']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data" id="audit-table">
                <thead>
                <tr>
                    <th>When</th>
                    <th>Who</th>
                    <th>Action</th>
                    <th>Entity</th>
                    <th>Details</th>
                    <th>IP</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $details = $row['details'] ? json_decode((string) $row['details'], true) : null;
                    $urls = [
                        'sales_invoice' => '/invoices/',
                        'purchase_bill' => '/bills/',
                        'payment' => '/payments/',
                        'contact' => '/contacts/',
                        'item' => '/items/',
                        'employee' => '/employees/',
                        'journal' => '/journals/',
                        'payroll_run' => '/payroll/',
                    ];
                    ?>
                    <tr>
                        <td class="nowrap tiny"><?= e(fdate($row['created_at'], true)) ?></td>
                        <td class="small"><?= e($row['user_name'] ?? 'system') ?></td>
                        <td>
                            <span class="badge badge--<?= str_contains($row['action'], 'void')
                                || str_contains($row['action'], 'failed') ? 'bad' : 'muted' ?>">
                                <?= e($row['action']) ?>
                            </span>
                        </td>
                        <td class="tiny muted">
                            <?php if (isset($urls[$row['entity_type']]) && $row['entity_id']): ?>
                                <a href="<?= url($urls[$row['entity_type']] . $row['entity_id']) ?>">
                                    <?= e($row['entity_type']) ?> #<?= (int) $row['entity_id'] ?></a>
                            <?php else: ?>
                                <?= e($row['entity_type']) ?>
                                <?= $row['entity_id'] ? '#' . (int) $row['entity_id'] : '' ?>
                            <?php endif; ?>
                        </td>
                        <td class="tiny muted">
                            <?php if (is_array($details)): ?>
                                <?php foreach ($details as $key => $value): ?>
                                    <?php if (!is_array($value)): ?>
                                        <?= e($key) ?>=<?= e((string) $value) ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td class="tiny faint mono"><?= e($row['ip_address'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
