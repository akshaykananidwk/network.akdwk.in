<?php
/**
 * @var array{total:int,page:int,per_page:int,pages:int} $result
 */
declare(strict_types=1);

if (($result['pages'] ?? 1) <= 1) {
    return;
}

$request = \App\Core\Request::current();
$query = $_GET ?? [];

$pageUrl = static function (int $page) use ($query, $request): string {
    $query['page'] = $page;

    return ($request?->path() ?? '') . '?' . http_build_query($query);
};

$current = (int) $result['page'];
$last = (int) $result['pages'];
$window = 2;
$from = max(1, $current - $window);
$to = min($last, $current + $window);
?>
<nav class="pagination" aria-label="Pagination">
    <span class="pagination-summary">
        <?= e(number_format((($current - 1) * (int) $result['per_page']) + 1)) ?>–<?= e(number_format(min($current * (int) $result['per_page'], (int) $result['total']))) ?>
        of <?= e(number_format((int) $result['total'])) ?>
    </span>

    <div class="pagination-links">
        <?php if ($current > 1): ?>
            <a class="btn btn-sm" href="<?= e($pageUrl($current - 1)) ?>" rel="prev">Previous</a>
        <?php endif; ?>

        <?php if ($from > 1): ?>
            <a class="btn btn-sm" href="<?= e($pageUrl(1)) ?>">1</a>
            <?php if ($from > 2): ?><span class="pagination-gap">…</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($page = $from; $page <= $to; $page++): ?>
            <a class="btn btn-sm<?= $page === $current ? ' btn-primary' : '' ?>"
               href="<?= e($pageUrl($page)) ?>"
               <?= $page === $current ? 'aria-current="page"' : '' ?>><?= e($page) ?></a>
        <?php endfor; ?>

        <?php if ($to < $last): ?>
            <?php if ($to < $last - 1): ?><span class="pagination-gap">…</span><?php endif; ?>
            <a class="btn btn-sm" href="<?= e($pageUrl($last)) ?>"><?= e($last) ?></a>
        <?php endif; ?>

        <?php if ($current < $last): ?>
            <a class="btn btn-sm" href="<?= e($pageUrl($current + 1)) ?>" rel="next">Next</a>
        <?php endif; ?>
    </div>
</nav>
