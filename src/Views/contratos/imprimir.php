<?php
$allocated = $allocation['total_allocated'];
$legacy = $allocation['legacy'];
$selectedSheetNumber = null;
if ($sheetId !== null) {
    foreach ($sheets as $sheet) {
        if ((int) $sheet['id'] === $sheetId) {
            $selectedSheetNumber = (int) $sheet['numero_pagina'];
            break;
        }
    }
}
try {
    $registered = new DateTimeImmutable((string) $record['criado_em'], new DateTimeZone('UTC'));
    $registeredText = $registered->setTimezone(new DateTimeZone(Config::string('APP_TIMEZONE', 'America/Cuiaba')))->format('d/m/Y');
} catch (Exception) { $registeredText = 'Data indisponível'; }
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Imprimir <?= $selectedSheetNumber !== null ? 'Nota ' . $selectedSheetNumber : 'Pedido' ?> #<?= (int) $record['id'] ?></title><link rel="stylesheet" href="<?= e(url('assets/css/modulo5-print.css')) ?>"><script src="<?= e(url('assets/js/app.js')) ?>" defer></script></head>
<body data-auto-print>
    <div class="print-toolbar"><button type="button" data-print-page>🖨️ Imprimir</button><a href="<?= e(url('contrato/detalhes/' . (int) $record['id'] . ($sheetId !== null ? '#folha-' . $sheetId : ''))) ?>">Voltar ao pedido</a></div>
    <main class="print-document">
        <header class="print-header"><h1><?= $selectedSheetNumber !== null ? 'Nota ' . $selectedSheetNumber . ' do ' : '' ?>Pedido: <?= e($record['titulo']) ?><?= $record['excluido_em'] !== null ? ' (excluído)' : '' ?></h1><p>ID do Pedido: #<?= (int) $record['id'] ?> | Data: <?= e($registeredText) ?><?php if ($record['fornecedor']): ?> | Fornecedor: <?= e($record['fornecedor']) ?><?php endif; ?></p></header>
        <div class="print-totals">
            <span><strong>Teto original do pedido:</strong> <?= e(Contrato::money($allocation['original_limit'])) ?></span>
            <?php if ($allocation['has_additive']): ?><span><strong>Limite de aditivos (25%):</strong> <?= e(Contrato::money($allocation['additive_limit'])) ?></span><span><strong>Teto com aditivos:</strong> <?= e(Contrato::money($allocation['final_limit'])) ?></span><?php endif; ?>
            <span><strong>Total Acumulado:</strong> <?= $legacy ? 'Revisão pendente' : e(Contrato::money($allocated)) ?></span>
            <span class="print-balance <?= $allocation['remaining']!==null && $allocation['remaining']<0 ? 'negative' : '' ?>"><strong>Saldo Restante:</strong> <?= e(Contrato::money($allocation['remaining'])) ?></span>
        </div>
        <p>Totais e saldos consideram todas as notas ativas do pedido. Saldo para notas comuns: <?= e(Contrato::money($allocation['original_remaining'])) ?>. Saldo para aditivos: <?= e(Contrato::money($allocation['additive_remaining'])) ?>.</p>
        <?php if (($allocation['original_remaining']!==null && $allocation['original_remaining']<0) || ($allocation['additive_remaining']!==null && $allocation['additive_remaining']<0)): ?><p><strong>Atenção: a distribuição anterior ultrapassa o teto original ou o limite de aditivos e precisa de regularização.</strong></p><?php endif; ?>
        <?php foreach ($sheets as $sheet): if ($sheetId !== null && (int) $sheet['id'] !== $sheetId) { continue; } if ($record['excluido_em'] === null && $sheet['excluido_em'] !== null) { continue; } ?>
            <section class="print-note <?= (int)$sheet['aditivo']===1 ? 'print-note-additive' : '' ?>">
                <div class="print-note-heading"><h2>Nota <?= (int) $sheet['numero_pagina'] ?><?= (int)$sheet['aditivo']===1 ? ' · ADITIVO' : '' ?><?= $sheet['excluido_em'] !== null ? ' (excluída)' : '' ?></h2><strong>Total da nota: <?= e(Contrato::money($sheet['valor_centavos'] === null ? null : (int) $sheet['valor_centavos'])) ?></strong></div>
                <?php if (trim((string) ($sheet['observacao'] ?? '')) !== ''): ?><div class="print-note-observation"><strong>Observações:</strong><br><?= nl2br(e($sheet['observacao'])) ?></div><?php endif; ?>
                <table><thead><tr><th>Produto</th><th>Marca</th><th>Qtd</th><th>Unid.</th><th>V. Unitário</th><th>V. Total</th></tr></thead><tbody>
                    <?php foreach ($items as $item): if ((int) $item['numero_pagina'] !== (int) $sheet['numero_pagina'] || ($record['excluido_em'] === null && $item['excluido_em'] !== null)) { continue; } ?>
                        <tr><td><?= e($item['nome_produto']) ?><?= $item['excluido_em'] !== null ? ' (excluído)' : '' ?></td><td><?= e($item['marca'] ?? '') ?></td><td><?= e((string) ($item['quantidade_contratada'] ?? $item['quantidade'])) ?></td><td><?= e($item['unidade']) ?></td><td><?= e(Contrato::money($item['preco_centavos'] === null ? null : (int) $item['preco_centavos'])) ?></td><td><strong><?= e(Contrato::money($item['total_centavos'] === null ? null : (int) $item['total_centavos'])) ?></strong></td></tr>
                    <?php endforeach; ?>
                </tbody></table>
            </section>
        <?php endforeach; ?>
    </main>
</body></html>
