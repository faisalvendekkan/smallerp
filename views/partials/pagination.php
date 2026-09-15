<?php
/**
 * Paging control for a list screen.
 *
 * Keeps whatever filters are in the query string, so paging through a
 * filtered list does not silently reset it.
 *
 * @var array $pagination
 */
if (($pagination['pages'] ?? 1) <= 1 && ($pagination['total'] ?? 0) <= ($pagination['per_page'] ?? 25)) {
    return;
}

$query = $_GET;
$link = static function (int $page) use ($query): string {
    $query['page'] = $page;

    return url($_SERVER['__path'] ?? App\Core\App::currentPath(), $query);
};
$current = (int) $pagination['page'];
$pages = (int) $pagination['pages'];
?>
<div class="card__foot">
    <span class="pagination__info">
        Showing <?= (int) $pagination['from'] ?>–<?= (int) $pagination['to'] ?>
        of <?= number_format((int) $pagination['total']) ?>
    </span>
    <div class="pagination">
        <a class="btn btn--sm<?= $current <= 1 ? ' btn--ghost' : '' ?>"
           href="<?= $current > 1 ? e($link($current - 1)) : '#' ?>"
           <?= $current <= 1 ? 'aria-disabled="true"' : '' ?>>‹</a>

        <?php
        // A short window around the current page, so a long list does not
        // produce a hundred links.
        $start = max(1, $current - 2);
        $end = min($pages, $start + 4);
        $start = max(1, $end - 4);
        ?>
        <?php for ($p = $start; $p <= $end; $p++): ?>
            <a class="btn btn--sm<?= $p === $current ? ' btn--primary' : '' ?>" href="<?= e($link($p)) ?>"><?= $p ?></a>
        <?php endfor; ?>

        <a class="btn btn--sm<?= $current >= $pages ? ' btn--ghost' : '' ?>"
           href="<?= $current < $pages ? e($link($current + 1)) : '#' ?>"
           <?= $current >= $pages ? 'aria-disabled="true"' : '' ?>>›</a>
    </div>
</div>
