<?php
return static function (string $name, string $value, ?string $current = null, bool $onlyCurrent = false): void {
    $options = $onlyCurrent && $current !== null ? [$current] : ['UN', 'K', 'Litros'];
    if (!$onlyCurrent && $current !== null && !in_array($current, $options, true)) {
        $options[] = $current;
    }
    $invalid = !in_array($value, $options, true);
    ?>
    <select class="form-select" name="<?= e($name) ?>" required data-contract-unit<?= $invalid ? ' aria-invalid="true"' : '' ?>>
        <?php if ($invalid): ?><option value="" selected disabled data-unit-invalid>Unidade não permitida: <?= e($value === '' ? '(não informada)' : $value) ?></option><?php endif; ?>
        <?php foreach ($options as $option): ?><option value="<?= e($option) ?>"<?= $option === $value ? ' selected' : '' ?>><?= e($option) ?><?= !$onlyCurrent && $option === $current && !in_array($current, ['UN', 'K', 'Litros'], true) ? ' (unidade atual)' : '' ?></option><?php endforeach; ?>
    </select>
    <?php if ($invalid): ?><small data-unit-warning role="status"><?= $onlyCurrent ? 'Escolha a unidade atual do produto para confirmar.' : 'Selecione UN, K ou Litros'.($current !== null ? ', ou mantenha a unidade atual do produto' : '').'.' ?></small><?php endif; ?>
    <?php
};
