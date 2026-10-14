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
?>
<section class="relatorio modulo5-hero"><p><a href="<?= e(url('contrato/detalhes/'.$product['id_pedido'].($return ? '?'.http_build_query($return) : ''))) ?>">Voltar ao contrato</a></p>
<h2>Histórico de <?= e($product['nome_produto']) ?></h2>
<p>Contrato #<?= (int)$product['id_pedido'] ?>: <?= e($product['contrato_titulo']) ?> · Folha <?= (int)$product['numero_pagina'] ?> · Unidade: <?= e($product['unidade']) ?></p>
<p>Movimentos confirmados permanecem no histórico. Saídas e estornos aparecem com quantidade negativa.</p></section>
<section class="relatorio"><div class="table-scroll"><table class="table"><thead><tr><th>ID</th><th>Data e hora</th><th>Tipo</th><th>Quantidade</th><th>Motivo</th><th>Responsável</th><th>Estorno de</th></tr></thead><tbody>
<?php foreach ($movements as $move): ?><tr><td><?= (int)$move['id'] ?></td><td><?= e($formatTime($move['criado_em'])) ?></td><td><?= e($move['tipo']) ?></td><td><?= (int)$move['quantidade'] ?> <?= e($move['unidade']) ?></td><td><?= e($move['motivo']) ?></td><td><?= e($move['usuario']) ?></td><td><?= e($move['movimento_original_id'] ?? '—') ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php if ($movements===[]): ?><p class="empty-state">Nenhum movimento registrado.</p><?php endif; ?><button type="button" data-print-page class="btn btn-secondary">Imprimir histórico</button></section>
