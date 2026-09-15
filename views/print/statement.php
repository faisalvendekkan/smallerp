<?php
/**
 * Statement of account.
 *
 * The document an SME emails a customer when chasing payment, so it leads with
 * the closing balance and the ageing of what is overdue.
 */

use App\Core\Lang;
use App\Services\Settings;
use App\Support\Money;

$settings = Settings::all();
$contact = $statement['contact'];
$isSupplier = $contact['kind'] === 'supplier';
?>
<div class="doc">
    <?= App\Core\View::partial('print/_header', [
        'settings' => $settings,
        'docTitleEn' => 'Statement of Account',
        'docTitleAr' => 'كشف حساب',
    ]) ?>

    <div class="doc__parties">
        <div>
            <div class="doc__party-label"><?= $isSupplier ? 'Supplier / المورد' : 'Customer / العميل' ?></div>
            <div style="font-weight:700"><?= e($contact['name_en']) ?></div>
            <?php if ($contact['name_ar'] !== ''): ?>
                <div class="doc__ar" style="font-weight:700"><?= e($contact['name_ar']) ?></div>
            <?php endif; ?>
            <div class="doc__meta">
                <?php if ($contact['address'] !== ''): ?><?= e($contact['address']) ?><br><?php endif; ?>
                <?= e($contact['city']) ?><br>
                <?php if ($contact['phone'] !== ''): ?>Tel: <?= e($contact['phone']) ?><br><?php endif; ?>
                <?php if ($contact['cr_number'] !== ''): ?>CR No: <?= e($contact['cr_number']) ?><?php endif; ?>
            </div>
        </div>
        <div>
            <table style="font-size:11.5px">
                <tr>
                    <td style="border:none;padding:2px 0;color:#666">Account / رقم الحساب</td>
                    <td style="border:none;padding:2px 0;text-align:end;font-weight:700"><?= e($contact['code']) ?></td>
                </tr>
                <tr>
                    <td style="border:none;padding:2px 0;color:#666">Period / الفترة</td>
                    <td style="border:none;padding:2px 0;text-align:end">
                        <?= e(date('d/m/Y', strtotime($statement['from']))) ?> —
                        <?= e(date('d/m/Y', strtotime($statement['to']))) ?>
                    </td>
                </tr>
                <tr>
                    <td style="border:none;padding:2px 0;color:#666">Printed / تاريخ الطباعة</td>
                    <td style="border:none;padding:2px 0;text-align:end"><?= e(date('d/m/Y')) ?></td>
                </tr>
                <tr>
                    <td style="border:none;padding:2px 0;color:#666">Terms / الشروط</td>
                    <td style="border:none;padding:2px 0;text-align:end">
                        <?= (int) $contact['payment_terms_days'] ?> days
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <table>
        <thead>
        <tr>
            <th style="width:80px">Date<span class="ar">التاريخ</span></th>
            <th style="width:110px">Reference<span class="ar">المرجع</span></th>
            <th>Description<span class="ar">البيان</span></th>
            <th style="width:88px;text-align:end">Debit<span class="ar">مدين</span></th>
            <th style="width:88px;text-align:end">Credit<span class="ar">دائن</span></th>
            <th style="width:96px;text-align:end">Balance<span class="ar">الرصيد</span></th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td colspan="5" style="color:#555">Opening balance / الرصيد الافتتاحي</td>
            <td style="text-align:end;font-weight:600"><?= e(money($statement['opening_balance'])) ?></td>
        </tr>
        <?php foreach ($statement['entries'] as $entry): ?>
            <tr>
                <td><?= e(date('d/m/Y', strtotime((string) $entry['entry_date']))) ?></td>
                <td><?= e($entry['number']) ?></td>
                <td>
                    <?= $entry['entry_type'] === 'invoice'
                        ? ($isSupplier ? 'Supplier bill / فاتورة مورد' : 'Invoice / فاتورة')
                        : ($isSupplier ? 'Payment / سند صرف' : 'Receipt / سند قبض') ?>
                    <?php if ($entry['due_date']): ?>
                        <span style="color:#888">— due <?= e(date('d/m/Y', strtotime((string) $entry['due_date']))) ?></span>
                    <?php endif; ?>
                </td>
                <td style="text-align:end"><?= $entry['debit'] > 0 ? e(money($entry['debit'])) : '' ?></td>
                <td style="text-align:end"><?= $entry['credit'] > 0 ? e(money($entry['credit'])) : '' ?></td>
                <td style="text-align:end;font-weight:600"><?= e(money($entry['balance'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($statement['entries'] === []): ?>
            <tr><td colspan="6" style="text-align:center;color:#888">No transactions in this period.</td></tr>
        <?php endif; ?>
        </tbody>
        <tfoot>
        <tr>
            <td colspan="5"
                style="text-align:end;font-weight:700;font-size:13px;border-top:2px solid #0f5c57;padding-top:6px">
                Closing balance / الرصيد الختامي (QAR)
            </td>
            <td style="text-align:end;font-weight:700;font-size:13px;border-top:2px solid #0f5c57;padding-top:6px">
                <?= e(money($statement['closing_balance'])) ?>
            </td>
        </tr>
        </tfoot>
    </table>

    <div class="doc__words">
        <div><strong>Balance in words:</strong>
            <?= e(Money::inWords((int) $statement['closing_balance'], 'en')) ?></div>
        <div class="ar"><strong>الرصيد كتابةً:</strong>
            <?= e(Money::inWords((int) $statement['closing_balance'], 'ar')) ?></div>
    </div>

    <?php if (!$isSupplier && (int) $statement['closing_balance'] > 0): ?>
        <div class="doc__footer">
            <strong>Please settle the balance above.</strong>
            <?php if (($settings['bank_details'] ?? '') !== ''): ?>
                <div style="margin-top:4px"><?= nl2br(e($settings['bank_details'])) ?></div>
            <?php endif; ?>
            <div class="doc__ar" style="margin-top:4px">نرجو تسوية الرصيد المستحق أعلاه.</div>
        </div>
    <?php endif; ?>

    <div class="doc__stamp">
        If you believe any entry is incorrect, please contact us within seven days.
        <div class="doc__ar">في حال وجود أي اعتراض على هذا الكشف، نرجو إبلاغنا خلال سبعة أيام.</div>
    </div>
</div>
