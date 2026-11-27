<section class="relatorio modulo5-hero">
    <p>Pedido #<?= (int) $order['id'] ?></p>
    <h2><?= e($order['titulo']) ?></h2>
    <p><a href="<?= e(url('contrato/detalhes/' . $order['id'])) ?>">Ver detalhes do pedido</a></p>
</section>

<section class="relatorio" aria-labelledby="stock-products-title">
    <h3 id="stock-products-title">Produtos do pedido</h3>
    <?php if ($items === []): ?>
        <p class="empty-state">Este pedido ainda não tem produtos ativos.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table stock-table" aria-labelledby="stock-products-title">
                <thead>
                    <tr><th scope="col">Produto</th><th scope="col">Disponível agora</th><th scope="col">Situação</th><th scope="col">Ações</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $id = (int) $item['id'];
                        $balance = (int) $item['saldo'];
                        $pending = (int) $item['estoque_inicializado'] === 0;
                        $uncertain = (int) $item['abertura_sem_comprovacao'] === 1;
                        $low = !$pending && !$uncertain && $item['estoque_minimo'] !== null && $balance <= (int) $item['estoque_minimo'];
                        ?>
                        <tr id="produto-<?= $id ?>">
                            <td><strong><?= e($item['nome_produto']) ?><?= $item['marca'] ? ' — ' . e($item['marca']) : '' ?></strong><small class="stock-product-source">Nota <?= (int) $item['numero_pagina'] ?></small></td>
                            <td data-label="Disponível agora"><?= $pending || $uncertain ? 'A confirmar' : $balance . ' ' . e($item['unidade']) ?></td>
                            <td data-label="Situação"><span class="stock-status<?= $low ? ' stock-status-low' : '' ?>"><?= $pending ? 'Informe a quantidade inicial' : ($uncertain ? 'Conferência administrativa necessária' : ($balance === 0 ? 'Sem estoque' : ($low ? 'Estoque baixo' : 'Normal'))) ?></span></td>
                            <td data-label="Ações">
                                <div class="stock-row-actions">
                                    <?php if ($pending): ?>
                                        <a class="btn btn-primary btn-sm" href="<?= e(url('estoque/iniciar/' . $id)) ?>">Informar quantidade inicial</a>
                                    <?php elseif ($uncertain): ?>
                                        <?php if (Auth::isAdmin()): ?>
                                            <a class="btn btn-primary btn-sm" href="<?= e(url('contrato/detalhes/' . $order['id'] . '#old-opening-' . $id)) ?>">Conferir quantidade atual</a>
                                        <?php else: ?>
                                            <span>Peça a conferência ao administrador.</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <a class="btn btn-outline-primary btn-sm" href="<?= e(url('estoque/receber/' . $id)) ?>">Receber</a>
                                        <a class="btn btn-outline-primary btn-sm" href="<?= e(url('estoque/consumir/' . $id)) ?>">Registrar consumo</a>
                                    <?php endif; ?>
                                    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('contrato/historico/' . $id . '?origem=estoque')) ?>">Histórico</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($items !== []): ?>
    <section class="relatorio">
        <details class="stock-alerts">
            <summary>Alertas de estoque</summary>
            <p>Configure avisos de quantidade baixa. O bloqueio por quantidade máxima é opcional e fica na configuração avançada.</p>
            <ul class="stock-alert-list">
                <?php foreach ($items as $item): ?>
                    <li>
                        <span>
                            <strong><?= e($item['nome_produto']) ?><?= $item['marca'] ? ' — ' . e($item['marca']) : '' ?></strong>
                            <small class="stock-product-source">Nota <?= (int) $item['numero_pagina'] ?> · <?= $item['estoque_minimo'] === null ? 'Sem aviso de quantidade baixa' : 'Avisar quando restarem ' . (int) $item['estoque_minimo'] . ' ' . e($item['unidade']) ?><?= $item['estoque_maximo'] === null ? '' : ' · Máximo: ' . (int) $item['estoque_maximo'] . ' ' . e($item['unidade']) ?></small>
                        </span>
                        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('estoque/alertas/' . $item['id'])) ?>">Configurar alertas</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    </section>
<?php endif; ?>
