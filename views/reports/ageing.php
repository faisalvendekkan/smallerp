<?php
/** Receivables / payables ageing. */

use App\Core\Lang;

$isReceivable = $direction === 'receivable';
$buckets = [
    'current' => 'Not yet due',
    'd30' => '1–30 days',
    'd60' => '31–60 days',
    'd90' => '61–90 days',
    'd90plus' => 'Over 90 days',
];
?>
<div class="page-head">
    <div class="page-head__text">
        <p class="page-head__sub">
            as at <?= e(fdate($asOf)) ?> ·
            <strong><?= e(money($report['total'], true)) ?></strong>
            <?= $isReceivable ? 'owed to the company' : 'owed by the company' ?>
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= url('/reports/ageing', ['direction' => $direction, 'as_of' => $asOf, 'format' => 'csv']) ?>">
            <?= t('action.export') ?>
        </a>
        <button class="btn" type="button" onclick="window.print()"><?= t('action.print') ?></button>
    </div>
</div>

<div class="card no-print">
    <div class="card__head">
        <form class="filters" method="get" action="<?= url('/reports/ageing') ?>" data-auto-submit>
            <div class="field">
                <label for="direction">Show</label>
                <select id="direction" name="direction">
                    <option value="receivable" <?= $isReceivable ? 'selected' : '' ?>>Receivables</option>
                    <option value="payable" <?= !$isReceivable ? 'selected' : '' ?>>Payables</option>
                </select>
            </div>
            <div class="field">
                <label for="as_of">As at</label>
                <input type="date" id="as_of" name="as_of" value="<?= e($asOf) ?>">
            </div>
        </form>
    </div>
</div>

<div class="stats">
    <?php foreach ($buckets as $key => $label): ?>
        <div class="stat <?= in_array($key, ['d90', 'd90plus'], true) && $report['buckets'][$key] > 0 ? 'stat--bad' : '' ?>">
            <div class="stat__label"><?= e($label) ?></div>
            <div class="stat__value" style="font-size:1.15rem"><?= e(money($report['buckets'][$key])) ?></div>
            <div class="stat__meta">
                <?= $report['total'] > 0
                    ? round($report['buckets'][$key] / $report['total'] * 100) . '% of total'
                    : '—' ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card__head">
        <h2 class="card__title">By <?= $isReceivable ? 'customer' : 'supplier' ?></h2>
        <span class="topbar__spacer"></span>
        <input type="search" class="no-print" placeholder="Filter…" data-table-filter="#ageing-table"
               style="width:180px">
    </div>
    <?php if ($report['contacts'] === []): ?>
        <?= App\Core\View::partial('partials/empty', [
            'message' => $isReceivable ? 'Nothing outstanding — every invoice is paid.' : 'Nothing owed to suppliers.',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data" id="ageing-table">
                <thead>
                <tr>
                    <th><?= $isReceivable ? t('field.customer') : t('field.supplier') ?></th>
                    <?php foreach ($buckets as $label): ?>
                        <th class="num"><?= e($label) ?></th>
                    <?php endforeach; ?>
                    <th class="num"><?= t('field.total') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($report['contacts'] as $contact): ?>
                    <tr>
                        <td>
                            <a href="<?= url('/contacts/' . $contact['contact_id']) ?>">
                                <?= e(Lang::pick($contact, 'name')) ?></a>
                            <?php if ($contact['phone'] !== ''): ?>
                                <div class="tiny muted"><?= e($contact['phone']) ?></div>
                            <?php endif; ?>
                            <div class="tiny muted">
                                <?php foreach (array_slice($contact['documents'], 0, 4) as $document): ?>
                                    <?= e($document['number']) ?><?= $document['days_overdue'] > 0
                                        ? ' <span class="text-bad">+' . (int) $document['days_overdue'] . 'd</span>' : '' ?>
                                    <?= $document !== end($contact['documents']) ? '· ' : '' ?>
                                <?php endforeach; ?>
                                <?php if (count($contact['documents']) > 4): ?>
                                    and <?= count($contact['documents']) - 4 ?> more
                                <?php endif; ?>
                            </div>
                        </td>
                        <?php foreach (array_keys($buckets) as $key): ?>
                            <td class="num <?= $contact[$key] > 0 && in_array($key, ['d90', 'd90plus'], true) ? 'text-bad' : '' ?>">
                                <?= $contact[$key] > 0 ? e(money($contact[$key])) : '<span class="muted">—</span>' ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="num strong"><?= e(money($contact['total'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr>
                    <td>Total</td>
                    <?php foreach (array_keys($buckets) as $key): ?>
                        <td class="num"><?= e(money($report['buckets'][$key])) ?></td>
                    <?php endforeach; ?>
                    <td class="num"><?= e(money($report['total'])) ?></td>
                </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>
