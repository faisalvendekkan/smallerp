<?php
/** Chart of accounts, grouped by type with balances. */

use App\Core\Lang;
use App\Services\ChartOfAccounts;

$grouped = [];
foreach ($accounts as $account) {
    $grouped[$account['type']][] = $account;
}
?>
<div class="page-head">
    <div class="page-head__text">
        <p class="page-head__sub">
            <?= count($accounts) ?> accounts.
            Accounts marked <span class="badge badge--info">system</span> are wired into
            the posting engine — they can be renamed but not removed.
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= url('/reports/trial-balance') ?>">Trial balance</a>
        <?php if (can('accounting.edit')): ?>
            <a class="btn btn--primary" href="<?= url('/accounts/new') ?>">+ <?= t('action.new') ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$integrity['balanced']): ?>
    <div class="alert alert--error">
        <span class="alert__icon">✕</span>
        <span>
            <strong>The ledger does not balance.</strong>
            Debits <?= e(money($integrity['total_debit'])) ?>,
            credits <?= e(money($integrity['total_credit'])) ?>.
            <?= count($integrity['unbalanced_entries']) ?> entries are affected.
        </span>
    </div>
<?php else: ?>
    <div class="alert alert--success">
        <span class="alert__icon">✓</span>
        <span>The ledger balances: <?= e(money($integrity['total_debit'], true)) ?> on both sides.</span>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card__head">
        <form class="filters" method="get" action="<?= url('/accounts') ?>" data-auto-submit>
            <div class="field field--search">
                <label for="search"><?= t('action.search') ?></label>
                <input type="search" id="search" name="search" value="<?= e($search) ?>"
                       placeholder="Code or name">
            </div>
            <div class="field">
                <label for="type">Type</label>
                <select id="type" name="type">
                    <option value="">All</option>
                    <?php foreach ($types as $key => $meta): ?>
                        <option value="<?= e($key) ?>" <?= $type === $key ? 'selected' : '' ?>>
                            <?= e(Lang::isRtl() ? $meta['name_ar'] : $meta['name_en']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn" type="submit"><?= t('action.filter') ?></button>
        </form>
    </div>

    <?php foreach ($types as $typeKey => $typeMeta): ?>
        <?php if (empty($grouped[$typeKey])) { continue; } ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th colspan="4" style="background:var(--brand-light);color:var(--brand);font-size:.8rem">
                        <?= e(Lang::isRtl() ? $typeMeta['name_ar'] : $typeMeta['name_en']) ?>
                        <span class="tiny muted">
                            (increases with a <?= e($typeMeta['normal']) ?>)
                        </span>
                    </th>
                </tr>
                <tr>
                    <th style="width:80px">Code</th>
                    <th>Account</th>
                    <th>Category</th>
                    <th class="num">Balance</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($grouped[$typeKey] as $account): ?>
                    <tr>
                        <td class="mono tiny"><?= e($account['code']) ?></td>
                        <td>
                            <a href="<?= url('/accounts/' . $account['id']) ?>">
                                <?= e(Lang::pick($account, 'name')) ?></a>
                            <?php if ($account['system_key']): ?>
                                <span class="badge badge--info">system</span>
                            <?php endif; ?>
                            <?php if ((int) $account['is_active'] !== 1): ?>
                                <span class="badge badge--muted">archived</span>
                            <?php endif; ?>
                        </td>
                        <td class="tiny muted"><?= e(str_replace('_', ' ', $account['subtype'])) ?></td>
                        <td class="num <?= (int) $account['balance'] < 0 ? 'text-bad' : '' ?>">
                            <?= (int) $account['balance'] !== 0 ? e(money($account['balance'])) : '<span class="muted">—</span>' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
</div>
