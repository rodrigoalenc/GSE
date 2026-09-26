<?php
$formatDate=static function (?string $value): string {
    if (!$value) { return 'Não registrado'; }
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    return $date!==false && $date->format('Y-m-d')===$value ? $date->format('d/m/Y') : 'Data indisponível';
};
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Contrato #<?= (int)$record['id'] ?></title><link rel="stylesheet" href="<?= e(url('assets/css/modulo5-print.css')) ?>"><script src="<?= e(url('assets/js/app.js')) ?>" defer></script></head><body>
<button data-print-page>Imprimir</button><h1>Contrato #<?= (int)$record['id'] ?> — <?= e($record['titulo']) ?><?= $record['excluido_em']!==null ? ' (excluído)' : '' ?></h1><p>Fornecedor: <?= e($record['fornecedor'] ?? 'Não informado') ?></p><p>Valor contratado: <?= e(Contrato::money($record['valor_centavos']===null ? null : (int)$record['valor_centavos'])) ?></p>
<?php foreach ($sheets as $sheet): if ($sheetId!==null && (int)$sheet['id']!==$sheetId) { continue; } ?><section><h2>Folha <?= (int)$sheet['numero_pagina'] ?><?= $sheet['excluido_em']!==null ? ' (excluída)' : '' ?></h2><p><?= nl2br(e($sheet['observacao'] ?? '')) ?></p><table><thead><tr><th>Produto</th><th>Unidade</th><th>Quantidade contratada</th><th>Preço</th><th>Total</th></tr></thead><tbody><?php foreach ($items as $item): if ((int)$item['numero_pagina']!==(int)$sheet['numero_pagina'] || ($record['excluido_em']===null && $item['excluido_em']!==null)) { continue; } ?><tr><td><?= e($item['nome_produto']) ?><?= $item['excluido_em']!==null ? ' (excluído)' : '' ?></td><td><?= e($item['unidade']) ?></td><td><?= e($item['quantidade_contratada'] ?? $item['quantidade']) ?></td><td><?= e(Contrato::money($item['preco_centavos']===null ? null : (int)$item['preco_centavos'])) ?></td><td><?= e(Contrato::money($item['total_centavos']===null ? null : (int)$item['total_centavos'])) ?></td></tr><?php endforeach; ?></tbody></table><p>Total da folha: <?= e(Contrato::money($sheet['valor_centavos']===null ? null : (int)$sheet['valor_centavos'])) ?></p><p>Faturamento documental: <?= e($formatDate($sheet['data_faturamento'])) ?></p></section><?php endforeach; ?>
</body></html>
