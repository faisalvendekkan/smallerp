<?php
/**
 * Customer / supplier list.
 *
 * @var array  $rows
 * @var string $kind
 */

use App\Core\Lang;

$isSupplier = $kind === 'supplier';
$listUrl = $isSupplier ? '/suppliers' : '/customers';
$total = array_sum(array_map(static fn ($r) => (int) $r['balance'], $rows));
?>
<div class="page-head">
    <div class="page-head__text">
        <p class="page-head__sub">
            <?= number_format((int) $pagination['total']) ?> on file ·
            <?= e(money($total, true)) ?> <?= $isSupplier ? 'owed to them' : 'owed to us' ?> on this page
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= url('/contacts/export', ['kind' => $kind]) ?>"><?= t('action.export') ?></a>
        <?php if (can('contacts.create')): ?>
            <a class="btn btn--primary" href="<?= url('/contacts/new', ['kind' => $kind]) ?>">
                + <?= t('action.new') ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card__head">
        <form class="filters" method="get" action="<?= url($listUrl) ?>">
            <div class="field field--search">
                <label for="search"><?= t('action.search') ?></label>
                <input type="search" id="search" name="search" value="<?= e($search) ?>"
                       placeholder="Name, code, CR number or phone">
            </div>
            <label class="check" style="padding-bottom:.4rem">
                <input type="checkbox" name="archived" value="1" <?= $archived ? 'checked' : '' ?>>
                <span>Show archived</span>
            </label>
            <button class="btn" type="submit"><?= t('action.search') ?></button>
        </form>
    </div>

    <?php if ($rows === []): ?>
        <?= App\Core\View::partial('partials/empty', [
            'message' => $isSupplier ? 'No suppliers yet.' : 'No customers yet.',
            'actionUrl' => can('contacts.create') ? url('/contacts/new', ['kind' => $kind]) : null,
            'actionLabel' => __('action.new'),
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th><?= t('qatar.cr_number') ?></th>
                    <th>Contact</th>
                    <th class="num">Terms</th>
                    <th class="num"><?= t('field.outstanding') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="mono tiny"><?= e($row['code']) ?></td>
                        <td>
                            <a href="<?= url('/contacts/' . $row['id']) ?>"><?= e(Lang::pick($row, 'name')) ?></a>
                            <?php if ((int) $row['is_active'] !== 1): ?>
                                <span class="badge badge--muted">archived</span>
                            <?php endif; ?>
                            <?php if ($row['kind'] === 'both'): ?>
                                <span class="badge badge--info">customer &amp; supplier</span>
                            <?php endif; ?>
                        </td>
                        <td class="mono tiny"><?= e($row['cr_number']) ?></td>
                        <td class="small">
                            <?= e($row['contact_person']) ?>
                            <?php if ($row['phone'] !== ''): ?>
                                <div class="tiny muted"><?= e($row['phone']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="num tiny muted"><?= (int) $row['payment_terms_days'] ?>d</td>
                        <td class="num <?= (int) $row['balance'] > 0 ? 'strong' : 'muted' ?>">
                            <?= e(money($row['balance'])) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= App\Core\View::partial('partials/pagination', ['pagination' => $pagination]) ?>
    <?php endif; ?>
</div>
