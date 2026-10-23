<?php
/** Shared compact navigation. A null page count supports lists with only a next-page signal. */
return static function (int $current, ?int $pages, callable $pageUrl, string $label, bool $hasNext = false, string $class = 'pagination compact-pagination'): void {
    if ($pages !== null && $pages <= 1) { return; }
    $numbers = $pages === null ? [$current] : array_values(array_unique(array_merge([1], range(max(1, $current - 2), min($pages, $current + 2)), [$pages])));
    sort($numbers);
    ?>
    <nav class="<?= e($class) ?>" aria-label="<?= e($label) ?>">
        <?php if ($current > 1): ?><a href="<?= e($pageUrl($current - 1)) ?>" rel="prev">Anterior</a><?php endif; ?>
        <?php $previous = 0; foreach ($numbers as $number): ?>
            <?php if ($previous !== 0 && $number > $previous + 1): ?><span aria-hidden="true">…</span><?php endif; ?>
            <a href="<?= e($pageUrl($number)) ?>" aria-label="Página <?= (int) $number ?>"<?= $number === $current ? ' aria-current="page" class="active"' : '' ?>><?= (int) $number ?></a>
        <?php $previous = $number; endforeach; ?>
        <?php if ($pages !== null ? $current < $pages : $hasNext): ?><a href="<?= e($pageUrl($current + 1)) ?>" rel="next">Próxima</a><?php endif; ?>
    </nav>
    <?php
};
