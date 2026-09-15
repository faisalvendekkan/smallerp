<?php
/**
 * Printable bilingual payslip.
 *
 * Art. 66 entitles the worker to a record of their wage; this is the document
 * an employee here will take to a bank or a landlord as proof of income.
 */

use App\Core\Lang;
use App\Support\Money;
use App\Support\Qatar;

$allowances = (int) $payslip['housing'] + (int) $payslip['transport']
    + (int) $payslip['food'] + (int) $payslip['other_allowance'];
?>
<div class="doc">
    <?= App\Core\View::partial('print/_header', [
        'settings' => $settings,
        'docTitleEn' => 'Payslip — ' . $payslip['period_label'],
        'docTitleAr' => 'قسيمة راتب',
    ]) ?>

    <table style="margin-bottom:12px">
        <tbody>
        <tr>
            <td style="width:22%;color:#666">Employee / الموظف</td>
            <td style="font-weight:700">
                <?= e($payslip['name_en']) ?>
                <?php if (($payslip['name_ar'] ?? '') !== ''): ?>
                    <div class="doc__ar"><?= e($payslip['name_ar']) ?></div>
                <?php endif; ?>
            </td>
            <td style="width:20%;color:#666">Staff No. / الرقم الوظيفي</td>
            <td><?= e($payslip['code']) ?></td>
        </tr>
        <tr>
            <td style="color:#666">QID / الرقم الشخصي</td>
            <td><?= e($payslip['qid']) ?></td>
            <td style="color:#666">Designation / الوظيفة</td>
            <td><?= e($payslip['designation']) ?></td>
        </tr>
        <tr>
            <td style="color:#666">Period / الفترة</td>
            <td><?= e($payslip['period_label']) ?></td>
            <td style="color:#666">Pay date / تاريخ الصرف</td>
            <td><?= e(date('d/m/Y', strtotime((string) $payslip['pay_date']))) ?></td>
        </tr>
        <tr>
            <td style="color:#666">Days paid / أيام العمل</td>
            <td><?= (int) $payslip['working_days'] ?></td>
            <td style="color:#666">Joined / تاريخ الالتحاق</td>
            <td><?= e(date('d/m/Y', strtotime((string) $payslip['join_date']))) ?></td>
        </tr>
        </tbody>
    </table>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div>
            <table>
                <thead>
                <tr><th colspan="2">Earnings<span class="ar">الاستحقاقات</span></th></tr>
                </thead>
                <tbody>
                <tr>
                    <td>Basic salary / الراتب الأساسي</td>
                    <td style="text-align:end"><?= e(money($payslip['basic'])) ?></td>
                </tr>
                <?php foreach ([
                    'housing' => ['Housing allowance', 'بدل السكن'],
                    'transport' => ['Transport allowance', 'بدل المواصلات'],
                    'food' => ['Food allowance', 'بدل الطعام'],
                    'other_allowance' => ['Other allowance', 'بدلات أخرى'],
                ] as $field => [$labelEn, $labelAr]): ?>
                    <?php if ((int) $payslip[$field] > 0): ?>
                        <tr>
                            <td><?= e($labelEn) ?> / <?= e($labelAr) ?></td>
                            <td style="text-align:end"><?= e(money($payslip[$field])) ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if ((int) $payslip['overtime_amount'] > 0): ?>
                    <tr>
                        <td>Overtime / العمل الإضافي
                            <span style="color:#888">(<?= e((string) (float) $payslip['overtime_hours']) ?> hrs)</span>
                        </td>
                        <td style="text-align:end"><?= e(money($payslip['overtime_amount'])) ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ((int) $payslip['bonus'] > 0): ?>
                    <tr>
                        <td>Bonus / مكافأة</td>
                        <td style="text-align:end"><?= e(money($payslip['bonus'])) ?></td>
                    </tr>
                <?php endif; ?>
                </tbody>
                <tfoot>
                <tr style="border-top:2px solid #0f5c57">
                    <td style="font-weight:700;padding-top:5px">Gross / إجمالي الاستحقاق</td>
                    <td style="text-align:end;font-weight:700;padding-top:5px"><?= e(money($payslip['gross'])) ?></td>
                </tr>
                </tfoot>
            </table>
        </div>

        <div>
            <table>
                <thead>
                <tr><th colspan="2">Deductions<span class="ar">الاستقطاعات</span></th></tr>
                </thead>
                <tbody>
                <?php
                $deductions = [
                    'absence_deduction' => ['Absence', 'الغياب'],
                    'loan_deduction' => ['Loan repayment', 'سداد قرض'],
                    'other_deduction' => ['Other deductions', 'استقطاعات أخرى'],
                ];
                $any = false;
                ?>
                <?php foreach ($deductions as $field => [$labelEn, $labelAr]): ?>
                    <?php if ((int) $payslip[$field] > 0): ?>
                        <?php $any = true; ?>
                        <tr>
                            <td><?= e($labelEn) ?> / <?= e($labelAr) ?>
                                <?php if ($field === 'absence_deduction' && (float) $payslip['absence_days'] > 0): ?>
                                    <span style="color:#888">(<?= e((string) (float) $payslip['absence_days']) ?> days)</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:end"><?= e(money($payslip[$field])) ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if (!$any): ?>
                    <tr><td colspan="2" style="color:#888">None / لا يوجد</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot>
                <tr style="border-top:2px solid #0f5c57">
                    <td style="font-weight:700;padding-top:5px">Total / إجمالي الاستقطاع</td>
                    <td style="text-align:end;font-weight:700;padding-top:5px">
                        <?= e(money($payslip['total_deductions'])) ?>
                    </td>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <table style="margin-top:12px">
        <tr style="background:#0f5c57;color:#fff">
            <td style="font-weight:700;font-size:14px;padding:8px;border:none">
                Net pay / صافي الراتب (QAR)
            </td>
            <td style="text-align:end;font-weight:700;font-size:16px;padding:8px;border:none">
                <?= e(money($payslip['net'])) ?>
            </td>
        </tr>
    </table>

    <div class="doc__words">
        <div><strong>Amount in words:</strong> <?= e(Money::inWords((int) $payslip['net'], 'en')) ?></div>
        <div class="ar"><strong>المبلغ كتابةً:</strong> <?= e(Money::inWords((int) $payslip['net'], 'ar')) ?></div>
    </div>

    <div class="doc__footer">
        <div>
            <strong>Paid to / حُوّل إلى:</strong>
            <?= e($payslip['bank_name']) ?>
            <span class="mono"><?= e(Qatar::formatIban($payslip['iban'])) ?></span>
        </div>
        <div style="margin-top:4px">
            Paid through the Wage Protection System in accordance with Art. 66 of
            Labour Law No. 14 of 2004.
            <div class="doc__ar">
                تم صرف الراتب عبر نظام حماية الأجور وفقاً للمادة 66 من قانون العمل رقم 14 لسنة 2004.
            </div>
        </div>
    </div>

    <div class="doc__signatures">
        <div class="doc__sign-line">Employee / الموظف<br>
            <span style="color:#888">Signature &amp; date</span></div>
        <div class="doc__sign-line">For <?= e($settings['company_name_en'] ?: 'the company') ?><br>
            <span style="color:#888">Authorised signatory</span></div>
    </div>

    <div class="doc__stamp">
        This is a computer-generated payslip. هذه قسيمة صادرة عن نظام محاسبي.
    </div>
</div>
