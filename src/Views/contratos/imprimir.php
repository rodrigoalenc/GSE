<?php
$allocated = 0;
$legacy = false;
foreach ($items as $item) {
    if ($item['excluido_em'] !== null) { continue; }
    if ($item['total_centavos'] === null) { $legacy = true; }
    else { $allocated += (int) $item['total_centavos']; }
}
try {
    $registered = new DateTimeImmutable((string) $record['criado_em'], new DateTimeZone('UTC'));
    $registeredText = $registered->setTimezone(new DateTimeZone(Config::string('APP_TIMEZONE', 'America/Cuiaba')))->format('d/m/Y');
} catch (Exception) { $registeredText = 'Data indisponível'; }
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Imprimir Pedido #<?= (int) $record['id'] ?></title><link rel="stylesheet" href="<?= e(url('assets/css/modulo5-print.css')) ?>"><script src="<?= e(url('assets/js/app.js')) ?>" defer></script></head>
<body data-auto-print>
    <div class="print-toolbar"><button type="button" data-print-page>Imprimir ou salvar em PDF</button><a href="<?= e(url('contrato/detalhes/' . (int) $record['id'])) ?>">Voltar ao pedido</a></div>
    <main class="print-document">
        <header class="print-header"><h1>Pedido: <?= e($record['titulo']) ?><?= $record['excluido_em'] !== null ? ' (excluído)' : '' ?></h1><p>ID do Pedido: #<?= (int) $record['id'] ?> | Data: <?= e($registeredText) ?></p></header>
        <div class="print-totals">
            <span><strong>Valor total do pedido:</strong> <?= e(Contrato::money($record['valor_centavos'] === null ? null : (int) $record['valor_centavos'])) ?></span>
            <span><strong>Total em itens:</strong> <?= $legacy ? 'Revisão pendente' : e(Contrato::money($allocated)) ?></span>
            <span class="print-balance"><strong>Saldo restante:</strong> <?= $legacy || $record['valor_centavos'] === null ? 'Revisão pendente' : e(Contrato::money((int) $record['valor_centavos'] - $allocated)) ?></span>
        </div>
        <?php if ($record['fornecedor']): ?><p class="print-supplier">Fornecedor: <?= e($record['fornecedor']) ?></p><?php endif; ?>
        <?php foreach ($sheets as $sheet): if ($sheetId !== null && (int) $sheet['id'] !== $sheetId) { continue; } if ($record['excluido_em'] === null && $sheet['excluido_em'] !== null) { continue; } ?>
            <section class="print-note">
                <div class="print-note-heading"><h2>Nota <?= (int) $sheet['numero_pagina'] ?><?= $sheet['excluido_em'] !== null ? ' (excluída)' : '' ?></h2><strong>Total da nota: <?= e(Contrato::money($sheet['valor_centavos'] === null ? null : (int) $sheet['valor_centavos'])) ?></strong></div>
                <table><thead><tr><th>Produto</th><th>Marca</th><th>Qtd</th><th>Unid.</th><th>V. Unitário</th><th>V. Total</th></tr></thead><tbody>
                    <?php foreach ($items as $item): if ((int) $item['numero_pagina'] !== (int) $sheet['numero_pagina'] || ($record['excluido_em'] === null && $item['excluido_em'] !== null)) { continue; } ?>
                        <tr><td><?= e($item['nome_produto']) ?><?= $item['excluido_em'] !== null ? ' (excluído)' : '' ?></td><td><?= e($item['marca'] ?? '') ?></td><td><?= e((string) ($item['quantidade_contratada'] ?? $item['quantidade'])) ?></td><td><?= e($item['unidade']) ?></td><td><?= e(Contrato::money($item['preco_centavos'] === null ? null : (int) $item['preco_centavos'])) ?></td><td><strong><?= e(Contrato::money($item['total_centavos'] === null ? null : (int) $item['total_centavos'])) ?></strong></td></tr>
                    <?php endforeach; ?>
                </tbody></table>
                <?php if (trim((string) ($sheet['observacao'] ?? '')) !== ''): ?><p class="print-note-observation"><strong>Observações:</strong> <?= nl2br(e($sheet['observacao'])) ?></p><?php endif; ?>
            </section>
        <?php endforeach; ?>
    </main>
</body></html>
