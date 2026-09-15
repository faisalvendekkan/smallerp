<?php
/**
 * Document expiries across the workforce.
 *
 * The single most useful compliance screen for an SME in Qatar.
 */

use App\Core\Lang;

$expired = array_filter($rows, static fn ($r) => $r['level'] === 'expired');
$critical = array_filter($rows, static fn ($r) => $r['level'] === 'critical');
?>
<div class="page-head">
    <div class="page-head__text">
        <p class="page-head__sub">
            Expiring within <?= (int) $withinDays ?> days ·
            <?= count($expired) ?> already expired ·
            <?= count($critical) ?> within 30 days
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= url('/reports/expiries', ['within' => $withinDays, 'format' => 'csv']) ?>">
            <?= t('action.export') ?>
        </a>
        <button class="btn" type="button" onclick="window.print()"><?= t('action.print') ?></button>
    </div>
</div>

<?php if ($expired !== []): ?>
    <div class="alert alert--error">
        <span class="alert__icon">✕</span>
        <span>
            <strong><?= count($expired) ?> document<?= count($expired) === 1 ? ' has' : 's have' ?> already expired.</strong>
            An employee whose QID or residence visa has lapsed cannot lawfully work,
            and the company is exposed to a penalty.
        </span>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card__head">
        <form class="filters" method="get" action="<?= url('/reports/expiries') ?>" data-auto-submit>
            <div class="field">
                <label for="within">Window</label>
                <select id="within" name="within">
                    <?php foreach ([30, 60, 90, 180, 365] as $days): ?>
                        <option value="<?= $days ?>" <?= (int) $withinDays === $days ? 'selected' : '' ?>>
                            Next <?= $days ?> days
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <span class="topbar__spacer"></span>
            <input type="search" class="no-print" placeholder="Filter…" data-table-filter="#expiry-table"
                   style="width:180px">
        </form>
    </div>

    <?php if ($rows === []): ?>
        <?= App\Core\View::partial('partials/empty', [
            'message' => 'Nothing expires in this window. Everyone is up to date.',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data" id="expiry-table">
                <thead>
                <tr>
                    <th>Employee</th>
                    <th>Department</th>
                    <th>Document</th>
                    <th>Expires</th>
                    <th class="num">Days left</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <a href="<?= url('/employees/' . $row['id']) ?>"><?= e(Lang::pick($row, 'name')) ?></a>
                            <div class="tiny muted"><?= e($row['code']) ?> · <?= e($row['designation']) ?></div>
                        </td>
                        <td class="tiny muted"><?= e($row['department']) ?></td>
                        <td><?= e(Lang::pick($row, 'document')) ?></td>
                        <td class="nowrap"><?= e(fdate($row['expiry_date'])) ?></td>
                        <td class="num <?= $row['days'] < 0 ? 'text-bad strong' : ($row['days'] <= 30 ? 'text-warn strong' : '') ?>">
                            <?= $row['days'] < 0
                                ? 'expired ' . abs((int) $row['days']) . 'd ago'
                                : (int) $row['days'] ?>
                        </td>
                        <td>
                            <div class="expiry expiry--<?= e($row['level']) ?>">
                                <span class="expiry__dot"></span>
                                <span class="tiny"><?= e(ucfirst($row['level'])) ?></span>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
