<?php
/**
 * Printable tax invoice, bilingual English/Arabic.
 *
 * Carries the details a Qatari customer, bank or ministry will look for: the
 * company CR number, the customer's LPO reference, the amount in words in both
 * languages, and space for the stamp and signature that a payment department
 * here will still ask for.
 *
 * @var array $invoice
 * @var array $settings
 * @var array $taxBreakdown
 */

use App\Support\Money;

$hasTax = (int) $invoice['tax_total'] > 0;
$hasDiscount = (int) $invoice['discount_total'] > 0;
$isVoid = $invoice['status'] === 'void';
?>
<div class="doc">
    <?php if ($isVoid): ?>
        <div style="text-align:center;color:#b3261e;font-weight:700;font-size:20px;letter-spacing:.2em;margin-bottom:8px">
            VOID / ملغاة
        </div>
    <?php endif; ?>

    <header class="doc__header">
        <div>
            <div class="doc__company-en"><?= e($settings['company_name_en'] ?: 'Your Company') ?></div>
            <?php if ($settings['company_name_ar'] !== ''): ?>
                <div class="doc__company-ar"><?= e($settings['company_name_ar']) ?></div>
            <?php endif; ?>
            <div class="doc__meta" style="margin-top:5px">
                <?php if ($settings['address'] !== ''): ?><?= e($settings['address']) ?><br><?php endif; ?>
                <?php if ($settings['po_box'] !== ''): ?>P.O. Box <?= e($settings['po_box']) ?>, <?php endif; ?>
                <?= e($settings['city'] ?: 'Doha') ?>, <?= e($settings['country'] ?: 'Qatar') ?><br>
                <?php if ($settings['phone'] !== ''): ?>Tel: <?= e($settings['phone']) ?><?php endif; ?>
                <?php if ($settings['email'] !== ''): ?> · <?= e($settings['email']) ?><?php endif; ?>
            </div>
        </div>
        <div class="doc__meta" style="text-align:end;min-width:190px">
            <?php if ($settings['cr_number'] !== ''): ?>
                <div><strong>CR No. / س.ت:</strong> <?= e($settings['cr_number']) ?></div>
            <?php endif; ?>
            <?php if ($settings['tax_card_number'] !== ''): ?>
                <div><strong>Tax Card:</strong> <?= e($settings['tax_card_number']) ?></div>
            <?php endif; ?>
            <?php if ($settings['establishment_id'] !== ''): ?>
                <div><strong>Est. ID:</strong> <?= e($settings['establishment_id']) ?></div>
            <?php endif; ?>
            <?php if ($settings['municipality_licence'] !== ''): ?>
                <div><strong>Licence:</strong> <?= e($settings['municipality_licence']) ?></div>
            <?php endif; ?>
        </div>
    </header>

    <div class="doc__title">
        <?= $hasTax ? 'Tax Invoice' : 'Invoice' ?>
        <small><?= $hasTax ? 'فاتورة ضريبية' : 'فاتورة' ?></small>
    </div>

    <div class="doc__parties">
        <div>
            <div class="doc__party-label">Bill to / العميل</div>
            <div style="font-weight:700"><?= e($invoice['contact_name_en']) ?></div>
            <?php if (($invoice['contact_name_ar'] ?? '') !== ''): ?>
                <div class="doc__ar" style="font-weight:700"><?= e($invoice['contact_name_ar']) ?></div>
            <?php endif; ?>
            <div class="doc__meta">
                <?php if (($invoice['contact_address'] ?? '') !== ''): ?>
                    <?= e($invoice['contact_address']) ?><br>
                <?php endif; ?>
                <?php if (($invoice['contact_po_box'] ?? '') !== ''): ?>
                    P.O. Box <?= e($invoice['contact_po_box']) ?>,
                <?php endif; ?>
                <?= e($invoice['contact_city'] ?? 'Doha') ?><br>
                <?php if (($invoice['contact_phone'] ?? '') !== ''): ?>
                    Tel: <?= e($invoice['contact_phone']) ?><br>
                <?php endif; ?>
                <?php if (($invoice['contact_cr_number'] ?? '') !== ''): ?>
                    CR No: <?= e($invoice['contact_cr_number']) ?>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <table style="font-size:11.5px">
                <tr>
                    <td style="border:none;padding:2px 0;color:#666">Invoice No. / رقم الفاتورة</td>
                    <td style="border:none;padding:2px 0;text-align:end;font-weight:700"><?= e($invoice['number']) ?></td>
                </tr>
                <tr>
                    <td style="border:none;padding:2px 0;color:#666">Date / التاريخ</td>
                    <td style="border:none;padding:2px 0;text-align:end"><?= e(date('d/m/Y', strtotime((string) $invoice['issue_date']))) ?></td>
                </tr>
                <tr>
                    <td style="border:none;padding:2px 0;color:#666">Due date / تاريخ الاستحقاق</td>
                    <td style="border:none;padding:2px 0;text-align:end"><?= e(date('d/m/Y', strtotime((string) $invoice['due_date']))) ?></td>
                </tr>
                <?php if ($invoice['lpo_number'] !== ''): ?>
                    <tr>
                        <td style="border:none;padding:2px 0;color:#666">Your LPO / أمر الشراء</td>
                        <td style="border:none;padding:2px 0;text-align:end"><?= e($invoice['lpo_number']) ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ($invoice['project'] !== ''): ?>
                    <tr>
                        <td style="border:none;padding:2px 0;color:#666">Project / المشروع</td>
                        <td style="border:none;padding:2px 0;text-align:end"><?= e($invoice['project']) ?></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td style="border:none;padding:2px 0;color:#666">Currency / العملة</td>
                    <td style="border:none;padding:2px 0;text-align:end">QAR — ريال قطري</td>
                </tr>
            </table>
        </div>
    </div>

    <table>
        <thead>
        <tr>
            <th style="width:26px">#</th>
            <th>Description<span class="ar">البيان</span></th>
            <th style="width:64px;text-align:end">Qty<span class="ar">الكمية</span></th>
            <th style="width:48px">Unit<span class="ar">الوحدة</span></th>
            <th style="width:78px;text-align:end">Rate<span class="ar">السعر</span></th>
            <?php if ($hasDiscount): ?>
                <th style="width:54px;text-align:end">Disc.<span class="ar">خصم</span></th>
            <?php endif; ?>
            <?php if ($hasTax): ?>
                <th style="width:66px;text-align:end">Tax<span class="ar">الضريبة</span></th>
            <?php endif; ?>
            <th style="width:88px;text-align:end">Amount<span class="ar">المبلغ</span></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($invoice['lines'] as $line): ?>
            <tr>
                <td><?= (int) $line['line_no'] ?></td>
                <td>
                    <?= e($line['description']) ?>
                    <?php if (($line['item_name_ar'] ?? '') !== ''): ?>
                        <div class="doc__ar" style="color:#555;font-size:10.5px"><?= e($line['item_name_ar']) ?></div>
                    <?php endif; ?>
                </td>
                <td style="text-align:end">
                    <?= e(rtrim(rtrim(number_format((float) $line['quantity'], 3, '.', ''), '0'), '.')) ?>
                </td>
                <td><?= e($line['uom']) ?></td>
                <td style="text-align:end"><?= e(money($line['unit_price'])) ?></td>
                <?php if ($hasDiscount): ?>
                    <td style="text-align:end"><?= e((string) (float) $line['discount_pct']) ?>%</td>
                <?php endif; ?>
                <?php if ($hasTax): ?>
                    <td style="text-align:end"><?= e(money($line['line_tax'])) ?></td>
                <?php endif; ?>
                <td style="text-align:end;font-weight:600"><?= e(money($line['line_total'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <?php $span = 4 + ($hasDiscount ? 1 : 0) + ($hasTax ? 1 : 0); ?>
        <tr>
            <td colspan="<?= $span ?>" style="text-align:end;color:#555">Subtotal / المجموع</td>
            <td style="text-align:end"><?= e(money($invoice['subtotal'])) ?></td>
        </tr>
        <?php if ($hasDiscount): ?>
            <tr>
                <td colspan="<?= $span ?>" style="text-align:end;color:#555">Discount / الخصم</td>
                <td style="text-align:end">−<?= e(money($invoice['discount_total'])) ?></td>
            </tr>
        <?php endif; ?>
        <?php foreach ($taxBreakdown as $group): ?>
            <?php if ((int) $group['tax'] > 0): ?>
                <tr>
                    <td colspan="<?= $span ?>" style="text-align:end;color:#555">
                        <?= e($settings['tax_label_en'] ?: 'VAT') ?> @ <?= e((string) $group['rate']) ?>%
                        / <?= e($settings['tax_label_ar'] ?: 'الضريبة') ?>
                    </td>
                    <td style="text-align:end"><?= e(money($group['tax'])) ?></td>
                </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        <tr>
            <td colspan="<?= $span ?>"
                style="text-align:end;font-weight:700;font-size:13px;border-top:2px solid #0f5c57;padding-top:6px">
                Total / الإجمالي (QAR)
            </td>
            <td style="text-align:end;font-weight:700;font-size:13px;border-top:2px solid #0f5c57;padding-top:6px">
                <?= e(money($invoice['total'])) ?>
            </td>
        </tr>
        <?php if ((int) $invoice['amount_paid'] > 0): ?>
            <tr>
                <td colspan="<?= $span ?>" style="text-align:end;color:#555">Paid / المدفوع</td>
                <td style="text-align:end">−<?= e(money($invoice['amount_paid'])) ?></td>
            </tr>
            <tr>
                <td colspan="<?= $span ?>" style="text-align:end;font-weight:700">Balance due / الرصيد المستحق</td>
                <td style="text-align:end;font-weight:700"><?= e(money($invoice['balance_due'])) ?></td>
            </tr>
        <?php endif; ?>
        </tfoot>
    </table>

    <div class="doc__words">
        <div><strong>Amount in words:</strong> <?= e(Money::inWords((int) $invoice['total'], 'en')) ?></div>
        <div class="ar"><strong>المبلغ كتابةً:</strong> <?= e(Money::inWords((int) $invoice['total'], 'ar')) ?></div>
    </div>

    <?php if (($invoice['terms'] ?? '') !== '' || ($settings['bank_details'] ?? '') !== ''): ?>
        <div class="doc__footer">
            <?php if (($invoice['terms'] ?? '') !== ''): ?>
                <div style="margin-bottom:6px">
                    <strong>Terms / الشروط:</strong> <?= nl2br(e($invoice['terms'])) ?>
                </div>
            <?php endif; ?>
            <?php if (($settings['bank_details'] ?? '') !== ''): ?>
                <div><strong>Bank details / التفاصيل البنكية:</strong><br><?= nl2br(e($settings['bank_details'])) ?></div>
            <?php endif; ?>
            <?php if (($invoice['notes'] ?? '') !== ''): ?>
                <div style="margin-top:6px"><?= nl2br(e($invoice['notes'])) ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="doc__signatures">
        <div class="doc__sign-line">Received in good order / استلمت بحالة جيدة<br>
            <span style="color:#888">Name, signature &amp; date</span></div>
        <div class="doc__sign-line">For <?= e($settings['company_name_en'] ?: 'the company') ?><br>
            <span style="color:#888">Authorised signatory &amp; stamp</span></div>
    </div>

    <?php if (($settings['invoice_footer_en'] ?? '') !== ''): ?>
        <div class="doc__stamp"><?= e($settings['invoice_footer_en']) ?></div>
    <?php endif; ?>
    <div class="doc__stamp">
        This is a computer-generated invoice. هذه فاتورة صادرة عن نظام محاسبي.
    </div>
</div>
