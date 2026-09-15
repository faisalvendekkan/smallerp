<?php
/** Report index. */
$reports = [
    [
        'group' => 'Financial statements',
        'items' => [
            ['/reports/trial-balance', 'Trial balance', 'Every account with a movement, proving the ledger balances.'],
            ['/reports/profit-loss', 'Profit and loss', 'Income less cost of sales and expenses, with gross and net margin.'],
            ['/reports/balance-sheet', 'Balance sheet', 'What the company owns and owes at a date, including the gratuity provision.'],
        ],
    ],
    [
        'group' => 'Money owed',
        'items' => [
            ['/reports/ageing?direction=receivable', 'Receivables ageing', 'Who owes you, aged 30 / 60 / 90 days — the report your bank asks for.'],
            ['/reports/ageing?direction=payable', 'Payables ageing', 'What you owe suppliers, on the same buckets.'],
        ],
    ],
    [
        'group' => 'Trading',
        'items' => [
            ['/reports/sales', 'Sales analysis', 'Revenue by customer and by item, with gross margin per line.'],
            ['/stock', 'Stock position', 'Quantity and value on hand at weighted average cost.'],
            ['/reports/tax', 'Tax summary', 'Output and input tax by rate, ready for the day VAT commences.'],
        ],
    ],
    [
        'group' => 'People and compliance',
        'items' => [
            ['/reports/expiries', 'Document expiries', 'QIDs, visas, passports and health cards about to lapse.'],
            ['/reports/gratuity-liability', 'End of service liability', 'What you would owe if every employee left today.'],
        ],
    ],
];
?>
<?php if (!$integrity['balanced']): ?>
    <div class="alert alert--error">
        <span class="alert__icon">✕</span>
        <span><strong>The ledger does not balance</strong> — the figures in these reports
            cannot be relied on until that is resolved.</span>
    </div>
<?php endif; ?>

<?php foreach ($reports as $section): ?>
    <div class="card">
        <div class="card__head"><h2 class="card__title"><?= e($section['group']) ?></h2></div>
        <div class="card__body">
            <div class="form-grid form-grid--2">
                <?php foreach ($section['items'] as [$href, $title, $description]): ?>
                    <a class="stat" href="<?= url(parse_url($href, PHP_URL_PATH), (static function () use ($href): array {
                        parse_str((string) parse_url($href, PHP_URL_QUERY), $q);
                        return $q;
                    })()) ?>">
                        <div class="stat__label"><?= e($title) ?></div>
                        <div class="small muted mt-1"><?= e($description) ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
