<?php
/**
 * The dashboard.
 *
 * Money at the top, then the things that will cost the company real money if
 * they are missed: overdue invoices, expiring residence documents, unpaid
 * payroll and stock that has run down.
 *
 * @var array $data
 * @var bool  $setup_needed
 * @var bool  $wps_needed
 */

use App\Core\Lang;
use App\Services\Payroll;

$maxTrend = 1;
foreach ($data['trend'] as $point) {
    $maxTrend = max($maxTrend, (int) $point['income'], (int) $point['expense']);
}
?>

<?php if ($setup_needed): ?>
    <div class="alert alert--info">
        <span class="alert__icon">i</span>
        <span>
            <?= t('dash.setup_prompt') ?>
            <a href="<?= url('/settings') ?>"><?= t('dash.complete_settings') ?></a>.
        </span>
    </div>
<?php endif; ?>

<div class="stats">
    <div class="stat">
        <div class="stat__label"><?= t('dash.cash') ?></div>
        <div class="stat__value"><?= e(money($data['cash'])) ?></div>
        <div class="stat__meta"><?= t('dash.cash_meta') ?></div>
    </div>

    <a class="stat <?= $data['overdue_receivable'] > 0 ? 'stat--warn' : '' ?>" href="<?= url('/invoices') ?>">
        <div class="stat__label"><?= t('dash.receivable') ?></div>
        <div class="stat__value"><?= e(money($data['receivable'])) ?></div>
        <div class="stat__meta">
            <?php if ($data['overdue_receivable'] > 0): ?>
                <span class="text-bad"><?= e(money($data['overdue_receivable'])) ?> <?= t('dash.overdue') ?></span>
            <?php else: ?>
                <?= t('dash.nothing_overdue') ?>
            <?php endif; ?>
        </div>
    </a>

    <a class="stat" href="<?= url('/bills') ?>">
        <div class="stat__label"><?= t('dash.payable') ?></div>
        <div class="stat__value"><?= e(money($data['payable'])) ?></div>
        <div class="stat__meta"><?= t('dash.to_suppliers') ?></div>
    </a>

    <div class="stat <?= $data['month_profit'] >= 0 ? 'stat--ok' : 'stat--bad' ?>">
        <div class="stat__label"><?= t('dash.month_profit') ?></div>
        <div class="stat__value"><?= e(money($data['month_profit'])) ?></div>
        <div class="stat__meta">
            <?= t('dash.income') ?> <?= e(money($data['month_income'])) ?> ·
            <?= t('dash.costs') ?> <?= e(money($data['month_expense'])) ?>
        </div>
    </div>

    <a class="stat" href="<?= url('/stock') ?>">
        <div class="stat__label"><?= t('dash.stock_value') ?></div>
        <div class="stat__value"><?= e(money($data['stock_value'])) ?></div>
        <div class="stat__meta"><?= t('dash.at_average_cost') ?></div>
    </a>
</div>

<div class="split split--sidebar">
    <div>
        <!-- Income and expenses over six months -->
        <div class="card">
            <div class="card__head">
                <h2 class="card__title"><?= t('dash.trend') ?></h2>
                <span class="topbar__spacer"></span>
                <span class="small muted"><?= t('dash.last_months', ['count' => 6]) ?></span>
            </div>
            <div class="card__body">
                <div class="bars">
                    <?php foreach ($data['trend'] as $point): ?>
                        <div class="bars__col">
                            <div class="bars__stack">
                                <div class="bars__bar bars__bar--income"
                                     style="height:<?= max(1, (int) round((int) $point['income'] / $maxTrend * 100)) ?>%"
                                     title="<?= t('dash.income') ?> <?= e(money($point['income'])) ?>"></div>
                                <div class="bars__bar bars__bar--expense"
                                     style="height:<?= max(1, (int) round((int) $point['expense'] / $maxTrend * 100)) ?>%"
                                     title="<?= t('dash.expenses') ?> <?= e(money($point['expense'])) ?>"></div>
                            </div>
                            <div class="bars__label"><?= e($point['label']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="legend">
                    <span><i class="legend__swatch" style="background:var(--brand)"></i><?= t('dash.income') ?></span>
                    <span><i class="legend__swatch" style="background:var(--accent)"></i><?= t('dash.expenses') ?></span>
                </div>
            </div>
        </div>

        <!-- Recent invoices -->
        <div class="card">
            <div class="card__head">
                <h2 class="card__title"><?= t('dash.recent_invoices') ?></h2>
                <span class="topbar__spacer"></span>
                <a class="btn btn--sm" href="<?= url('/invoices') ?>"><?= t('action.view_all') ?></a>
            </div>
            <?php if ($data['recent_invoices'] === []): ?>
                <?= App\Core\View::partial('partials/empty', [
                    'message' => 'No invoices have been raised yet.',
                    'actionUrl' => url('/invoices/new'),
                    'actionLabel' => __('action.new') . ' ' . __('nav.invoices'),
                ]) ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                        <tr>
                            <th><?= t('field.number') ?></th>
                            <th><?= t('field.customer') ?></th>
                            <th><?= t('field.date') ?></th>
                            <th class="num"><?= t('field.total') ?></th>
                            <th><?= t('field.status') ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($data['recent_invoices'] as $invoice): ?>
                            <tr>
                                <td><a href="<?= url('/invoices/' . $invoice['id']) ?>"><?= e($invoice['number']) ?></a></td>
                                <td><?= e(Lang::pick($invoice, 'contact_name')) ?></td>
                                <td class="nowrap"><?= e(fdate($invoice['issue_date'])) ?></td>
                                <td class="num"><?= e(money($invoice['total'])) ?></td>
                                <td><span class="badge badge--<?= e(status_class($invoice['status'])) ?>">
                                    <?= t('status.' . $invoice['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <!-- Compliance: the things that cost money if missed -->
        <div class="card">
            <div class="card__head">
                <h2 class="card__title"><?= t('dash.compliance') ?></h2>
            </div>
            <div class="card__body">
                <?php
                $nothing = $data['critical_expiries'] === []
                    && $data['unpaid_payroll'] === []
                    && $data['low_stock'] === []
                    && $data['draft_invoices'] === 0
                    && !$wps_needed;
                ?>

                <?php if ($nothing): ?>
                    <p class="muted small mb-0">✓ <?= t('dash.nothing') ?></p>
                <?php endif; ?>

                <?php if ($wps_needed): ?>
                    <div class="alert alert--warning mb-1">
                        <span><?= t('dash.wps_not_set') ?>
                            <a href="<?= url('/settings') ?>"><?= t('nav.settings') ?></a>.</span>
                    </div>
                <?php endif; ?>

                <?php foreach ($data['unpaid_payroll'] as $run): ?>
                    <?php
                    $label = sprintf('%02d/%04d', (int) $run['period_month'], (int) $run['period_year']);
                    $due = App\Support\Wps::dueDate((int) $run['period_year'], (int) $run['period_month']);
                    $late = $due < date('Y-m-d');
                    ?>
                    <div class="alert alert--<?= $late ? 'error' : 'warning' ?> mb-1">
                        <span>
                            <?= t('nav.payroll') ?> <?= e($label) ?>
                            <?= $run['status'] === Payroll::STATUS_DRAFT
                                ? t('dash.payroll_draft') : t('dash.payroll_unpaid') ?>.
                            <?= $late
                                ? t('dash.wps_deadline_was', ['date' => fdate($due)])
                                : t('dash.wps_due_by', ['date' => fdate($due)]) ?>
                            <a href="<?= url('/payroll/' . $run['id']) ?>"><?= t('dash.open') ?></a>
                        </span>
                    </div>
                <?php endforeach; ?>

                <?php if ($data['critical_expiries'] !== []): ?>
                    <h3 class="mt-2 mb-1"><?= t('dash.expiring') ?></h3>
                    <?php foreach (array_slice($data['critical_expiries'], 0, 6) as $expiry): ?>
                        <div class="expiry expiry--<?= e($expiry['level']) ?>" style="padding:.2rem 0">
                            <span class="expiry__dot"></span>
                            <a href="<?= url('/employees/' . $expiry['id']) ?>"><?= e(Lang::pick($expiry, 'name')) ?></a>
                            <span class="muted">— <?= e(Lang::pick($expiry, 'document')) ?></span>
                            <span class="topbar__spacer"></span>
                            <span class="<?= $expiry['days'] < 0 ? 'text-bad strong' : 'text-warn' ?> tiny nowrap">
                                <?= $expiry['days'] < 0
                                    ? t('dash.expired_ago', ['days' => abs((int) $expiry['days'])])
                                    : t('dash.days_left', ['days' => (int) $expiry['days']]) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                    <a class="btn btn--sm mt-1" href="<?= url('/reports/expiries') ?>">
                        <?= t('dash.all_expiries', ['count' => count($data['expiries'])]) ?>
                    </a>
                <?php endif; ?>

                <?php if ($data['low_stock'] !== []): ?>
                    <h3 class="mt-2 mb-1"><?= t('dash.low_stock') ?></h3>
                    <?php foreach (array_slice($data['low_stock'], 0, 5) as $item): ?>
                        <div class="flex small" style="padding:.15rem 0">
                            <a href="<?= url('/items/' . $item['id']) ?>"><?= e($item['sku']) ?></a>
                            <span class="topbar__spacer"></span>
                            <span class="text-warn tiny nowrap">
                                <?= e(rtrim(rtrim(number_format((float) $item['qty_on_hand'], 2, '.', ''), '0'), '.')) ?>
                                / <?= e(rtrim(rtrim(number_format((float) $item['reorder_level'], 2, '.', ''), '0'), '.')) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($data['draft_invoices'] > 0): ?>
                    <div class="alert alert--info mt-2 mb-0">
                        <span><?= (int) $data['draft_invoices'] ?> <?= t('nav.invoices') ?>
                            <?= t('dash.drafts_warning') ?>.
                            <a href="<?= url('/invoices', ['status' => 'draft']) ?>"><?= t('dash.review') ?></a></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Post-dated cheques, which every Qatari SME is juggling -->
        <?php if ($data['post_dated_cheques'] !== []): ?>
            <div class="card">
                <div class="card__head"><h2 class="card__title"><?= t('dash.post_dated') ?></h2></div>
                <div class="table-wrap">
                    <table class="data">
                        <tbody>
                        <?php foreach ($data['post_dated_cheques'] as $cheque): ?>
                            <tr>
                                <td>
                                    <a href="<?= url('/payments/' . $cheque['id']) ?>"><?= e($cheque['cheque_number']) ?></a>
                                    <div class="tiny muted"><?= e($cheque['contact_name_en'] ?? '') ?></div>
                                </td>
                                <td class="nowrap tiny"><?= e(fdate($cheque['cheque_date'])) ?></td>
                                <td class="num"><?= e(money($cheque['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card__head"><h2 class="card__title"><?= t('dash.this_year') ?></h2></div>
            <div class="card__body">
                <div class="totals-box" style="max-width:none">
                    <div class="totals-box__row">
                        <span class="muted"><?= t('dash.income') ?></span>
                        <span class="num mono"><?= e(money($data['year_income'])) ?></span>
                    </div>
                    <div class="totals-box__row">
                        <span class="muted"><?= t('dash.employees') ?></span>
                        <span class="num mono"><?= (int) $data['employee_count'] ?></span>
                    </div>
                    <div class="totals-box__row totals-box__row--grand">
                        <span><?= t('dash.profit') ?></span>
                        <span class="num mono <?= $data['year_profit'] >= 0 ? 'text-ok' : 'text-bad' ?>">
                            <?= e(money($data['year_profit'])) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
