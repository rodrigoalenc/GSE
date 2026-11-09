<?php
/** Shared compact navigation. Query data is submitted by POST so it never appears in the address. */
return static function (int $current, ?int $pages, callable $pageUrl, string $label, bool $hasNext = false, string $class = 'pagination compact-pagination'): void {
    if ($pages !== null && $pages <= 1) { return; }
    $numbers = $pages === null ? [$current] : array_values(array_unique(array_merge([1], range(max(1, $current - 2), min($pages, $current + 2)), [$pages])));
    sort($numbers);
    $button = static function (string $rawUrl, string $text, ?string $rel = null, bool $active = false, ?string $aria = null): void {
        $parts = parse_url($rawUrl);
        $fields = [];
        if (is_array($parts) && is_string($parts['query'] ?? null)) { parse_str($parts['query'], $fields); }
        $action = is_array($parts) && is_string($parts['path'] ?? null) ? $parts['path'] : $rawUrl;
        if (is_array($parts) && is_string($parts['fragment'] ?? null)) { $action .= '#' . $parts['fragment']; }
        $hidden = static function (array $values, string $prefix = '') use (&$hidden): void {
            foreach ($values as $name => $value) {
                $field = $prefix === '' ? (string) $name : $prefix . '[' . $name . ']';
                if (is_array($value)) { $hidden($value, $field); continue; }
                if (is_scalar($value)) { echo '<input type="hidden" name="' . e($field) . '" value="' . e((string) $value) . '">'; }
            }
        };
        ?>
        <form method="post" action="<?= e($action) ?>" class="list-state-page-form" data-list-state-form>
            <?= list_state_fields() ?><?php $hidden($fields); ?>
            <button type="submit"<?= $rel !== null ? ' rel="' . e($rel) . '"' : '' ?><?= $active ? ' aria-current="page" class="active"' : '' ?><?= $aria !== null ? ' aria-label="' . e($aria) . '"' : '' ?>><?= e($text) ?></button>
        </form>
        <?php
    };
    ?>
    <nav class="<?= e($class) ?>" aria-label="<?= e($label) ?>">
        <?php if ($current > 1): $button($pageUrl($current - 1), 'Anterior', 'prev'); endif; ?>
        <?php $previous = 0; foreach ($numbers as $number): ?>
            <?php if ($previous !== 0 && $number > $previous + 1): ?><span aria-hidden="true">…</span><?php endif; ?>
            <?php $button($pageUrl($number), (string) $number, null, $number === $current, 'Página ' . $number); ?>
        <?php $previous = $number; endforeach; ?>
        <?php if ($pages !== null ? $current < $pages : $hasNext): $button($pageUrl($current + 1), 'Próxima', 'next'); endif; ?>
    </nav>
    <?php
};
