<?php
/**
 * Record a receipt or a payment, allocating it across open documents.
 *
 * One cheque settling several invoices is the normal case here, so the form
 * lists every open document and totals the allocation as you type.
 */

use App\Core\Lang;
use App\Services\Payments;
use App\Support\Money;

$isIn = $direction === Payments::IN;
?>
<form method="post" action="<?= url('/payments') ?>" data-allocation data-dirty-guard>
    <?= csrf_field() ?>
    <input type="hidden" name="direction" value="<?= e($direction) ?>">

    <div class="card">
        <div class="card__body">
            <div class="form-grid">
                <div class="field">
                    <label for="contact_id" class="required">
                        <?= $isIn ? t('field.customer') : t('field.supplier') ?>
                    </label>
                    <select id="contact_id" name="contact_id" required
                            onchange="this.form.action='<?= url($isIn ? '/receipts/new' : '/payments/new') ?>';
                                      this.form.method='get'; this.form.submit();">
                        <option value="">— choose —</option>
                        <?php foreach ($contacts as $contact): ?>
                            <option value="<?= (int) $contact['id'] ?>"
                                <?= $contactId === (int) $contact['id'] ? 'selected' : '' ?>>
                                <?= e($contact['code'] . ' · ' . Lang::pick($contact, 'name')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field__hint">Choosing one lists their open documents below.</span>
                </div>

                <div class="field">
                    <label for="payment_date" class="required"><?= t('field.date') ?></label>
                    <input type="date" id="payment_date" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="field">
                    <label for="amount" class="required"><?= t('field.amount') ?> (QAR)</label>
                    <input type="text" class="num" id="amount" name="amount" inputmode="decimal"
                           data-payment-amount required
                           value="<?= $prefillAmount > 0 ? e(Money::toDecimalString($prefillAmount)) : '' ?>">
                </div>

                <div class="field">
                    <label for="method" class="required">Method</label>
                    <select id="method" name="method" required>
                        <?php foreach (Payments::METHODS as $key => $method): ?>
                            <option value="<?= e($key) ?>" <?= $key === 'bank' ? 'selected' : '' ?>>
                                <?= e(Lang::isRtl() ? $method['name_ar'] : $method['name_en']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="account_id" class="required">
                        <?= $isIn ? 'Received into' : 'Paid from' ?>
                    </label>
                    <select id="account_id" name="account_id" required>
                        <?php foreach ($accounts as $account): ?>
                            <option value="<?= (int) $account['id'] ?>">
                                <?= e($account['code'] . ' · ' . Lang::pick($account, 'name')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="reference"><?= t('field.reference') ?></label>
                    <input type="text" id="reference" name="reference">
                </div>

                <div class="field">
                    <label for="cheque_number">Cheque number</label>
                    <input type="text" id="cheque_number" name="cheque_number">
                    <span class="field__hint">Required when the method is cheque.</span>
                </div>

                <div class="field">
                    <label for="cheque_date">Cheque date</label>
                    <input type="date" id="cheque_date" name="cheque_date">
                    <span class="field__hint">A future date marks it as post-dated.</span>
                </div>

                <div class="field">
                    <label for="bank_name">Bank</label>
                    <input type="text" id="bank_name" name="bank_name">
                </div>
            </div>
        </div>
    </div>

    <?php if ($contactId > 0): ?>
        <div class="card">
            <div class="card__head">
                <h2 class="card__title">Apply to open <?= $isIn ? 'invoices' : 'bills' ?></h2>
                <span class="topbar__spacer"></span>
                <button class="btn btn--sm" type="button" data-pay-all>Apply oldest first</button>
            </div>

            <?php if ($documents === []): ?>
                <?= App\Core\View::partial('partials/empty', [
                    'message' => 'Nothing outstanding for this contact. The payment will sit on account.',
                ]) ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                        <tr>
                            <th><?= t('field.number') ?></th>
                            <th><?= t('field.date') ?></th>
                            <th><?= t('field.due_date') ?></th>
                            <th class="num"><?= t('field.total') ?></th>
                            <th class="num"><?= t('field.outstanding') ?></th>
                            <th class="num" style="width:130px">Apply (QAR)</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($documents as $document): ?>
                            <?php $overdue = $document['due_date'] < date('Y-m-d'); ?>
                            <tr>
                                <td>
                                    <?= e($document['number']) ?>
                                    <input type="hidden" name="allocations[doc_id][]" value="<?= (int) $document['id'] ?>">
                                </td>
                                <td class="nowrap tiny"><?= e(fdate($document['issue_date'])) ?></td>
                                <td class="nowrap tiny <?= $overdue ? 'text-bad strong' : '' ?>">
                                    <?= e(fdate($document['due_date'])) ?>
                                </td>
                                <td class="num"><?= e(money($document['total'])) ?></td>
                                <td class="num strong"><?= e(money($document['balance_due'])) ?></td>
                                <td>
                                    <input type="text" class="num" name="allocations[amount][]"
                                           data-allocation-amount inputmode="decimal"
                                           data-outstanding="<?= e(Money::toDecimalString((int) $document['balance_due'])) ?>"
                                           value="<?= $docId === (int) $document['id']
                                               ? e(Money::toDecimalString((int) $document['balance_due'])) : '' ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="card__body">
                <div class="totals-box">
                    <div class="totals-box__row">
                        <span>Applied</span>
                        <span class="num mono" data-allocated-total>0.00</span>
                    </div>
                    <div class="totals-box__row">
                        <span>Left on account</span>
                        <span class="num mono" data-unallocated-total>0.00</span>
                    </div>
                </div>
                <p class="tiny faint mt-1 mb-0">
                    Anything not applied is held as an advance on the contact's account,
                    ready to settle a future <?= $isIn ? 'invoice' : 'bill' ?>.
                </p>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card__body">
            <div class="field">
                <label for="notes"><?= t('field.notes') ?></label>
                <textarea id="notes" name="notes" rows="2"></textarea>
            </div>
        </div>
        <div class="card__foot">
            <button class="btn btn--primary" type="submit"><?= t('action.save') ?></button>
            <a class="btn btn--ghost" href="<?= url($isIn ? '/receipts' : '/payments') ?>"><?= t('action.cancel') ?></a>
        </div>
    </div>
</form>
