<?php
/** Printable bilingual receipt or payment voucher. */

use App\Services\Payments;
use App\Support\Money;

$isIn = $payment['direction'] === Payments::IN;
?>
<div class="doc">
    <?= App\Core\View::partial('print/_header', [
        'settings' => $settings,
        'docTitleEn' => $isIn ? 'Receipt Voucher' : 'Payment Voucher',
        'docTitleAr' => $isIn ? 'سند قبض' : 'سند صرف',
    ]) ?>

    <?php if ($payment['status'] === 'void'): ?>
        <div style="text-align:center;color:#b3261e;font-weight:700;font-size:18px;letter-spacing:.2em;margin-bottom:8px">
            VOID / ملغى
        </div>
    <?php endif; ?>

    <table style="margin-bottom:12px">
        <tr>
            <td style="width:33%;color:#666;border-bottom:none">Voucher No. / رقم السند</td>
            <td style="font-weight:700;border-bottom:none"><?= e($payment['number']) ?></td>
            <td style="width:20%;color:#666;border-bottom:none">Date / التاريخ</td>
            <td style="border-bottom:none"><?= e(date('d/m/Y', strtotime((string) $payment['payment_date']))) ?></td>
        </tr>
    </table>

    <table>
        <tbody>
        <tr>
            <td style="width:33%;color:#666"><?= $isIn ? 'Received from / استلمنا من' : 'Paid to / صرفنا إلى' ?></td>
            <td colspan="3" style="font-weight:700">
                <?= e($payment['contact_name_en'] ?? '—') ?>
                <?php if (($payment['contact_name_ar'] ?? '') !== ''): ?>
                    <div class="doc__ar"><?= e($payment['contact_name_ar']) ?></div>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td style="color:#666">Amount / المبلغ</td>
            <td colspan="3" style="font-weight:700;font-size:14px">
                QAR <?= e(money($payment['amount'])) ?>
            </td>
        </tr>
        <tr>
            <td style="color:#666">Method / طريقة الدفع</td>
            <td><?= e(Payments::METHODS[$payment['method']]['name_en'] ?? $payment['method']) ?>
                / <?= e(Payments::METHODS[$payment['method']]['name_ar'] ?? '') ?></td>
            <td style="color:#666">Account / الحساب</td>
            <td><?= e($payment['account_name_en']) ?></td>
        </tr>
        <?php if ($payment['cheque_number'] !== ''): ?>
            <tr>
                <td style="color:#666">Cheque No. / رقم الشيك</td>
                <td><?= e($payment['cheque_number']) ?></td>
                <td style="color:#666">Cheque date / تاريخ الشيك</td>
                <td><?= $payment['cheque_date']
                        ? e(date('d/m/Y', strtotime((string) $payment['cheque_date']))) : '—' ?></td>
            </tr>
        <?php endif; ?>
        <?php if ($payment['bank_name'] !== ''): ?>
            <tr>
                <td style="color:#666">Bank / البنك</td>
                <td colspan="3"><?= e($payment['bank_name']) ?></td>
            </tr>
        <?php endif; ?>
        <?php if ($payment['reference'] !== ''): ?>
            <tr>
                <td style="color:#666">Reference / المرجع</td>
                <td colspan="3"><?= e($payment['reference']) ?></td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($payment['allocations'] !== []): ?>
        <div style="margin-top:12px">
            <span style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#666;font-weight:700">
                Applied to / مقابل
            </span>
            <table style="margin-top:4px">
                <thead>
                <tr>
                    <th>Document<span class="ar">المستند</span></th>
                    <th style="width:90px">Date<span class="ar">التاريخ</span></th>
                    <th style="width:100px;text-align:end">Document total<span class="ar">إجمالي المستند</span></th>
                    <th style="width:100px;text-align:end">Applied<span class="ar">المخصص</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($payment['allocations'] as $allocation): ?>
                    <tr>
                        <td><?= e($allocation['doc_number']) ?></td>
                        <td><?= e(date('d/m/Y', strtotime((string) $allocation['doc_date']))) ?></td>
                        <td style="text-align:end"><?= e(money($allocation['doc_total'])) ?></td>
                        <td style="text-align:end;font-weight:600"><?= e(money($allocation['amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if ((int) $payment['unallocated'] > 0): ?>
                    <tfoot>
                    <tr>
                        <td colspan="3" style="text-align:end;color:#555">On account / على الحساب</td>
                        <td style="text-align:end"><?= e(money($payment['unallocated'])) ?></td>
                    </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    <?php endif; ?>

    <div class="doc__words">
        <div><strong>Amount in words:</strong> <?= e(Money::inWords((int) $payment['amount'], 'en')) ?></div>
        <div class="ar"><strong>المبلغ كتابةً:</strong> <?= e(Money::inWords((int) $payment['amount'], 'ar')) ?></div>
    </div>

    <?php if ($payment['notes']): ?>
        <div class="doc__footer"><?= nl2br(e($payment['notes'])) ?></div>
    <?php endif; ?>

    <div class="doc__signatures">
        <div class="doc__sign-line">
            <?= $isIn ? 'Received by / المستلم' : 'Paid by / الدافع' ?><br>
            <span style="color:#888">Name &amp; signature</span>
        </div>
        <div class="doc__sign-line">
            <?= $isIn ? 'Payer / الدافع' : 'Recipient / المستلم' ?><br>
            <span style="color:#888">Name, signature &amp; stamp</span>
        </div>
    </div>
</div>
