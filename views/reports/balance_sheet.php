<?php
/** Balance sheet. */

use App\Core\Lang;
?>
<div class="page-head">
    <div class="page-head__text">
        <p class="page-head__sub">as at <?= e(fdate($asOf)) ?></p>
    </div>
    <div class="page-head__actions">
        <button class="btn" type="button" data-print><?= t('action.print') ?></button>
    </div>
</div>

<div class="card no-print">
    <div class="card__head">
        <form class="filters" method="get" action="<?= url('/reports/balance-sheet') ?>" data-auto-submit>
            <div class="field"><label for="as_of">As at</label>
                <input type="date" id="as_of" name="as_of" value="<?= e($asOf) ?>"></div>
        </form>
    </div>
</div>

<?php if (!$report['balanced']): ?>
    <div class="alert alert--error">
        <span class="alert__icon">✕</span>
        <span>The balance sheet does not balance — assets
            <?= e(money($report['assets_total'])) ?> against
            <?= e(money($report['total_liabilities_and_equity'])) ?>.</span>
    </div>
<?php endif; ?>

<div class="split">
    <div class="card">
        <div class="card__head"><h2 class="card__title">Assets</h2></div>
        <div class="table-wrap">
            <table class="data">
                <tbody>
                <?php foreach ($report['assets'] as $row): ?>
                    <tr>
                        <td><a href="<?= url('/accounts/' . $row['id']) ?>">
                            <span class="mono tiny"><?= e($row['code']) ?></span>
                            <?= e(Lang::pick($row, 'name')) ?></a></td>
                        <td class="num"><?= e(money($row['amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr><td>Total assets</td><td class="num"><?= e(money($report['assets_total'])) ?></td></tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card__head"><h2 class="card__title">Liabilities</h2></div>
            <div class="table-wrap">
                <table class="data">
                    <tbody>
                    <?php foreach ($report['liabilities'] as $row): ?>
                        <tr>
                            <td><a href="<?= url('/accounts/' . $row['id']) ?>">
                                <span class="mono tiny"><?= e($row['code']) ?></span>
                                <?= e(Lang::pick($row, 'name')) ?></a></td>
                            <td class="num"><?= e(money($row['amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                    <tr><td>Total liabilities</td>
                        <td class="num"><?= e(money($report['liabilities_total'])) ?></td></tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card__head"><h2 class="card__title">Equity</h2></div>
            <div class="table-wrap">
                <table class="data">
                    <tbody>
                    <?php foreach ($report['equity'] as $row): ?>
                        <tr>
                            <td><a href="<?= url('/accounts/' . $row['id']) ?>">
                                <span class="mono tiny"><?= e($row['code']) ?></span>
                                <?= e(Lang::pick($row, 'name')) ?></a></td>
                            <td class="num"><?= e(money($row['amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td>Retained earnings
                            <div class="tiny muted">cumulative income less expenses</div></td>
                        <td class="num <?= $report['retained_earnings'] < 0 ? 'text-bad' : '' ?>">
                            <?= e(money($report['retained_earnings'])) ?>
                        </td>
                    </tr>
                    </tbody>
                    <tfoot>
                    <tr><td>Total equity</td><td class="num"><?= e(money($report['total_equity'])) ?></td></tr>
                    <tr><td>Liabilities and equity</td>
                        <td class="num"><?= e(money($report['total_liabilities_and_equity'])) ?></td></tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card__body">
        <h3>End of service gratuity</h3>
        <p class="small muted mb-1">
            Gratuity accrues from the first day of service under Art. 54 of the
            Labour Law, even though it only becomes payable after a completed year.
            For an established SME it is often the largest single liability — and
            the one most often left off the balance sheet.
        </p>
        <div class="flex flex-wrap" style="gap:2rem">
            <div>
                <div class="detail__label">Accrued to date</div>
                <div class="detail__value strong" style="font-size:1.2rem">
                    <?= e(money($gratuityLiability, true)) ?>
                </div>
            </div>
            <div>
                <div class="detail__label">Provision booked</div>
                <?php
                $booked = 0;
                foreach ($report['liabilities'] as $row) {
                    if (($row['subtype'] ?? '') === 'payroll' && str_contains(strtolower($row['name_en']), 'gratuity')) {
                        $booked = (int) $row['amount'];
                    }
                }
                ?>
                <div class="detail__value strong" style="font-size:1.2rem"><?= e(money($booked, true)) ?></div>
            </div>
            <div>
                <div class="detail__label">Shortfall</div>
                <div class="detail__value strong <?= $gratuityLiability - $booked > 0 ? 'text-warn' : 'text-ok' ?>"
                     style="font-size:1.2rem">
                    <?= e(money(max(0, $gratuityLiability - $booked), true)) ?>
                </div>
            </div>
        </div>
        <p class="tiny faint mt-2 mb-0">
            <a href="<?= url('/reports/gratuity-liability') ?>">See the breakdown by employee</a>.
            To book the provision, post a journal debiting End of Service Gratuity
            and crediting the End of Service Gratuity Provision.
        </p>
    </div>
</div>
