<?php
/**
 * Public holidays.
 *
 * Eid al-Fitr and Eid al-Adha follow the Hijri calendar and are announced by
 * the Amiri Diwan each year, so they are entered here rather than computed.
 */

use App\Core\Lang;

$existing = array_column($rows, 'holiday_date');
?>
<div class="page-head">
    <div class="page-head__text">
        <p class="page-head__sub">
            Holidays for <?= (int) $year ?>. National Sports Day and National Day have
            fixed Gregorian dates and are seeded automatically; the two Eids move with
            the Hijri calendar and are announced each year, so add them by hand.
        </p>
    </div>
    <div class="page-head__actions">
        <?php foreach ([$year - 1, $year, $year + 1] as $y): ?>
            <a class="btn btn--sm <?= $y === $year ? 'btn--primary' : '' ?>"
               href="<?= url('/holidays', ['year' => $y]) ?>"><?= $y ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="split split--sidebar">
    <div>
        <div class="card">
            <div class="card__head"><h2 class="card__title"><?= (int) $year ?></h2></div>
            <?php if ($rows === []): ?>
                <?= App\Core\View::partial('partials/empty', ['message' => 'No holidays recorded for this year.']) ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                        <tr><th><?= t('field.date') ?></th><th>Holiday</th><th>Paid</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td class="nowrap">
                                    <?= e(fdate($row['holiday_date'])) ?>
                                    <div class="tiny muted"><?= e(date('l', strtotime((string) $row['holiday_date']))) ?></div>
                                </td>
                                <td>
                                    <?= e($row['name_en']) ?>
                                    <?php if ($row['name_ar'] !== ''): ?>
                                        <div class="tiny muted" dir="rtl"><?= e($row['name_ar']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int) $row['is_paid'] === 1): ?>
                                        <span class="badge badge--ok">paid</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (can('settings.edit')): ?>
                                        <form method="post" action="<?= url('/holidays/' . $row['id'] . '/delete') ?>"
                                              data-confirm="Remove this holiday?">
                                            <?= csrf_field() ?>
                                            <button class="btn btn--sm btn--ghost" type="submit">×</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (can('settings.edit')): ?>
        <div>
            <div class="card">
                <div class="card__head"><h2 class="card__title">Add a holiday</h2></div>
                <form method="post" action="<?= url('/holidays') ?>">
                    <?= csrf_field() ?>
                    <div class="card__body">
                        <div class="field mb-1">
                            <label for="holiday_date" class="required"><?= t('field.date') ?></label>
                            <input type="date" id="holiday_date" name="holiday_date" required
                                   value="<?= e($year . '-01-01') ?>">
                        </div>
                        <div class="field mb-1">
                            <label for="name_en" class="required">Name (English)</label>
                            <input type="text" id="name_en" name="name_en" required list="holiday-names">
                            <datalist id="holiday-names">
                                <option value="Eid Al Fitr"></option>
                                <option value="Eid Al Adha"></option>
                                <option value="National Sports Day"></option>
                                <option value="Qatar National Day"></option>
                            </datalist>
                        </div>
                        <div class="field mb-1">
                            <label for="name_ar">Name (Arabic)</label>
                            <input type="text" id="name_ar" name="name_ar" dir="rtl">
                        </div>
                        <label class="check">
                            <input type="checkbox" name="is_paid" value="1" checked>
                            <span>Paid holiday</span>
                        </label>
                    </div>
                    <div class="card__foot">
                        <button class="btn btn--primary btn--block" type="submit"><?= t('action.save') ?></button>
                    </div>
                </form>
            </div>

            <?php
            $missing = array_filter(
                $suggested,
                static fn (array $h): bool => !in_array($h['date'], $existing, true)
            );
            ?>
            <?php if ($missing !== []): ?>
                <div class="card">
                    <div class="card__head"><h2 class="card__title">Suggested</h2></div>
                    <div class="card__body">
                        <?php foreach ($missing as $holiday): ?>
                            <form method="post" action="<?= url('/holidays') ?>" class="flex mb-1">
                                <?= csrf_field() ?>
                                <input type="hidden" name="holiday_date" value="<?= e($holiday['date']) ?>">
                                <input type="hidden" name="name_en" value="<?= e($holiday['name_en']) ?>">
                                <input type="hidden" name="name_ar" value="<?= e($holiday['name_ar']) ?>">
                                <input type="hidden" name="is_paid" value="1">
                                <span class="small" style="flex:1">
                                    <?= e($holiday['name_en']) ?>
                                    <div class="tiny muted"><?= e(fdate($holiday['date'])) ?></div>
                                </span>
                                <button class="btn btn--sm" type="submit">Add</button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
