<?php
/** Printable bilingual quotation. */

use App\Support\Money;

$hasTax = (int) $quotation['tax_total'] > 0;
?>
<div class="doc">
    <?= App\Core\View::partial('print/_header', [
        'settings' => $settings,
        'docTitleEn' => 'Quotation',
        'docTitleAr' => 'عرض سعر',
    ]) ?>

    <div class="doc__parties">
        <div>
            <div class="doc__party-label">To / إلى</div>
            <div style="font-weight:700"><?= e($quotation['contact_name_en']) ?></div>
            <?php if (($quotation['contact_name_ar'] ?? '') !== ''): ?>
                <div class="doc__ar" style="font-weight:700"><?= e($quotation['contact_name_ar']) ?></div>
            <?php endif; ?>
            <div class="doc__meta">
                <?php if (($quotation['contact_address'] ?? '') !== ''): ?>
                    <?= e($quotation['contact_address']) ?><br>
                <?php endif; ?>
                <?= e($quotation['contact_city'] ?? 'Doha') ?><br>
                <?php if (($quotation['contact_phone'] ?? '') !== ''): ?>
                    Tel: <?= e($quotation['contact_phone']) ?>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <table style="font-size:11.5px">
                <tr>
                    <td style="border:none;padding:2px 0;color:#666">Quotation No. / رقم العرض</td>
                    <td style="border:none;padding:2px 0;text-align:end;font-weight:700"><?= e($quotation['number']) ?></td>
                </tr>
                <tr>
                    <td style="border:none;padding:2px 0;color:#666">Date / التاريخ</td>
                    <td style="border:none;padding:2px 0;text-align:end">
                        <?= e(date('d/m/Y', strtotime((string) $quotation['issue_date']))) ?></td>
                </tr>
                <?php if ($quotation['valid_until']): ?>
                    <tr>
                        <td style="border:none;padding:2px 0;color:#666">Valid until / صالح حتى</td>
                        <td style="border:none;padding:2px 0;text-align:end">
                            <?= e(date('d/m/Y', strtotime((string) $quotation['valid_until']))) ?></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td style="border:none;padding:2px 0;color:#666">Currency / العملة</td>
                    <td style="border:none;padding:2px 0;text-align:end">QAR — ريال قطري</td>
                </tr>
            </table>
        </div>
    </div>

    <?php if ($quotation['subject'] !== ''): ?>
        <div style="margin-bottom:10px">
            <span style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#666;font-weight:700">
                Subject / الموضوع
            </span>
            <div style="font-weight:600"><?= e($quotation['subject']) ?></div>
        </div>
    <?php endif; ?>

    <table>
        <thead>
        <tr>
            <th style="width:26px">#</th>
            <th>Description<span class="ar">البيان</span></th>
            <th style="width:64px;text-align:end">Qty<span class="ar">الكمية</span></th>
            <th style="width:48px">Unit<span class="ar">الوحدة</span></th>
            <th style="width:84px;text-align:end">Rate<span class="ar">السعر</span></th>
            <?php if ($hasTax): ?>
                <th style="width:66px;text-align:end">Tax<span class="ar">الضريبة</span></th>
            <?php endif; ?>
            <th style="width:92px;text-align:end">Amount<span class="ar">المبلغ</span></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($quotation['lines'] as $line): ?>
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
                <?php if ($hasTax): ?>
                    <td style="text-align:end"><?= e(money($line['line_tax'])) ?></td>
                <?php endif; ?>
                <td style="text-align:end;font-weight:600"><?= e(money($line['line_total'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <?php $span = 4 + ($hasTax ? 1 : 0); ?>
        <tr>
            <td colspan="<?= $span ?>" style="text-align:end;color:#555">Subtotal / المجموع</td>
            <td style="text-align:end"><?= e(money($quotation['subtotal'])) ?></td>
        </tr>
        <?php if ($hasTax): ?>
            <tr>
                <td colspan="<?= $span ?>" style="text-align:end;color:#555">
                    <?= e($settings['tax_label_en'] ?: 'VAT') ?> / <?= e($settings['tax_label_ar'] ?: 'الضريبة') ?>
                </td>
                <td style="text-align:end"><?= e(money($quotation['tax_total'])) ?></td>
            </tr>
        <?php endif; ?>
        <tr>
            <td colspan="<?= $span ?>"
                style="text-align:end;font-weight:700;font-size:13px;border-top:2px solid #0f5c57;padding-top:6px">
                Total / الإجمالي (QAR)
            </td>
            <td style="text-align:end;font-weight:700;font-size:13px;border-top:2px solid #0f5c57;padding-top:6px">
                <?= e(money($quotation['total'])) ?>
            </td>
        </tr>
        </tfoot>
    </table>

    <div class="doc__words">
        <div><strong>Amount in words:</strong> <?= e(Money::inWords((int) $quotation['total'], 'en')) ?></div>
        <div class="ar"><strong>المبلغ كتابةً:</strong> <?= e(Money::inWords((int) $quotation['total'], 'ar')) ?></div>
    </div>

    <?php if ($quotation['terms'] || $quotation['notes']): ?>
        <div class="doc__footer">
            <?php if ($quotation['terms']): ?>
                <div style="margin-bottom:6px"><strong>Terms / الشروط:</strong>
                    <?= nl2br(e($quotation['terms'])) ?></div>
            <?php endif; ?>
            <?php if ($quotation['notes']): ?>
                <div><?= nl2br(e($quotation['notes'])) ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="doc__signatures">
        <div class="doc__sign-line">Accepted by / قبول العميل<br>
            <span style="color:#888">Name, signature, stamp &amp; date</span></div>
        <div class="doc__sign-line">For <?= e($settings['company_name_en'] ?: 'the company') ?><br>
            <span style="color:#888">Authorised signatory &amp; stamp</span></div>
    </div>
</div>
