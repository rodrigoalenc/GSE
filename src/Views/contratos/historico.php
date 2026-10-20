<?php
$formatTime=static function (string $value): string {
    try { return (new DateTimeImmutable($value,new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone(Config::string('APP_TIMEZONE','America/Cuiaba')))->format('d/m/Y H:i'); }
    catch (Exception) { return 'Data indisponível'; }
};
$return=[];
if (isset($_GET['busca']) && is_string($_GET['busca'])) { $return['busca']=$_GET['busca']; }
if (($_GET['situacao'] ?? '')==='excluidos') { $return['situacao']='excluidos'; }
if (isset($_GET['pagina']) && filter_var($_GET['pagina'],FILTER_VALIDATE_INT)) { $return['pagina']=$_GET['pagina']; }
$fromStock=($_GET['origem'] ?? '')==='estoque';
$labels=['abertura'=>'Quantidade inicial','entrada'=>'Recebimento','saida'=>'Consumo','estorno'=>'Estorno'];
$confirmed=(int)$product['estoque_inicializado']===1 && $movements!==[];
$totals=['available'=>0,'received'=>0,'consumed'=>0]; $types=[];
foreach ($movements as $move) { if ($move['id']!==null) { $types[(int)$move['id']]=$move['tipo']; } }
foreach ($movements as $move) {
    $quantity=(int)$move['quantidade']; $totals['available']+=$quantity;
    $type=$move['tipo']==='estorno' ? ($types[(int)$move['movimento_original_id']] ?? '') : $move['tipo'];
    if ($type==='entrada') { $totals['received']+=$quantity; }
    if ($type==='saida') { $totals['consumed']-=$quantity; }
}
?>
<section class="relatorio modulo5-hero"><p><a href="<?= e(url(($fromStock ? 'estoque' : 'contrato/detalhes/'.$product['id_pedido']).($return ? '?'.http_build_query($return) : ''))) ?>"><?= $fromStock ? 'Voltar ao estoque' : 'Voltar ao contrato' ?></a></p>
<h2>Histórico de <?= e($product['nome_produto']) ?></h2>
<p>Contrato #<?= (int)$product['id_pedido'] ?>: <?= e($product['contrato_titulo']) ?> · Folha <?= (int)$product['numero_pagina'] ?> · Unidade: <?= e($product['unidade']) ?></p>
<p>Cada registro mostra quando ocorreu, a quantidade e quem registrou. Consumos reduzem a quantidade disponível; estornos desfazem o movimento indicado.</p>
<div class="stock-history-totals"><p>Disponível agora<strong><?= !$confirmed ? 'A confirmar' : $totals['available'].' '.e($product['unidade']) ?></strong></p><p>Total recebido<strong><?= $totals['received'] ?> <?= e($product['unidade']) ?></strong></p><p>Total utilizado<strong><?= $totals['consumed'] ?> <?= e($product['unidade']) ?></strong></p></div><p>Os totais consideram os estornos. A quantidade inicial é apresentada separadamente no histórico.</p></section>
<section class="relatorio"><div class="table-scroll"><table class="table"><thead><tr><th>ID</th><th>Data e hora</th><th>Tipo</th><th>Quantidade</th><th>Motivo</th><th>Responsável</th><th>Estorno de</th></tr></thead><tbody>
<?php foreach ($movements as $move): ?><tr><td><?= $move['id']===null ? '—' : (int)$move['id'] ?></td><td><?= e($formatTime($move['criado_em'])) ?></td><td><?= e($labels[$move['tipo']] ?? $move['tipo']) ?></td><td><?= (int)$move['quantidade'] ?> <?= e($move['unidade']) ?></td><td><?= e($move['motivo']) ?></td><td><?= e($move['usuario']) ?></td><td><?= e($move['movimento_original_id'] ?? '—') ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php if ($movements===[]): ?><p class="empty-state">Nenhum movimento registrado.</p><?php endif; ?><button type="button" data-print-page class="btn btn-secondary">Imprimir histórico</button></section>
