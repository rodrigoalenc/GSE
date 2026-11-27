<section class="relatorio" aria-labelledby="stock-orders-title">
    <h2 id="stock-orders-title">Pedidos no estoque</h2>
    <form method="post" action="<?= e(url('estoque')) ?>" class="mb-3" role="search" data-list-state-form>
        <?= list_state_fields() ?>
        <label for="busca">Buscar pedido, produto ou marca</label>
        <div class="d-flex gap-2">
            <input id="busca" class="form-control" name="busca" value="<?= e($search) ?>">
            <button class="btn btn-primary" type="submit">Buscar</button>
            <?php if ($search !== ''): ?><button class="btn btn-outline-secondary" type="submit" form="stock-clear-search">Limpar</button><?php endif; ?>
        </div>
    </form>
    <?php if ($search !== ''): ?>
        <form id="stock-clear-search" method="post" action="<?= e(url('estoque')) ?>" data-list-state-form><?= list_state_fields() ?></form>
    <?php endif; ?>

    <?php if ($result['items'] === []): ?>
        <p class="empty-state">Nenhum pedido encontrado.</p>
    <?php else: ?>
        <ul class="stock-order-list">
            <?php foreach ($result['items'] as $order): ?>
                <?php $count = (int) $order['produtos']; ?>
                <li>
                    <a href="<?= e(url('estoque/pedido/' . $order['id'])) ?>">
                        <span><small>Pedido #<?= (int) $order['id'] ?></small><strong><?= e($order['titulo']) ?></strong></span>
                        <span class="stock-order-count"><?= $count ?> <?= $count === 1 ? 'produto' : 'produtos' ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
        $renderPagination = require __DIR__ . '/../partials/pagination.php';
        $renderPagination((int) $result['page'], (int) $result['pages'], static fn (int $page): string => url('estoque?' . http_build_query([
            'busca' => $search,
            'pagina' => $page,
        ])), 'Páginas dos pedidos no estoque');
        ?>
    <?php endif; ?>
</section>
