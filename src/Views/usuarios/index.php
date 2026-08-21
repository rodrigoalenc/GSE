<div class="usuarios-toolbar relatorio">
    <a class="btn-primary" href="<?= e(url('usuario/criar')) ?>">+ Novo Usuário</a>
    <form class="usuario-search" method="get" action="<?= e(url('usuario')) ?>" role="search">
        <label class="visually-hidden" for="q">Pesquisar por nome ou e-mail</label>
        <input id="q" name="q" value="<?= e($termo) ?>" placeholder="Pesquisar por nome ou e-mail" maxlength="100">
        <button class="btn-secondary" type="submit">Pesquisar</button>
        <?php if ($termo !== ''): ?>
            <a class="search-clear" href="<?= e(url('usuario')) ?>">Limpar</a>
        <?php endif; ?>
    </form>
</div>

<section class="relatorio">
        <?php if (!$usuarios): ?>
            <p class="empty-state">Nenhum usuário encontrado.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="tabela-filtrada">
                    <thead>
                    <tr>
                        <th>Nome</th>
                        <th>E-mail</th>
                        <th>Tipo</th>
                        <th>Situação</th>
                        <th>Alertas DVA</th>
                        <th class="col-acoes">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($usuarios as $item): ?>
                        <tr>
                            <td><?= e((string) $item['nome']) ?></td>
                            <td><?= e((string) $item['email']) ?></td>
                            <td><span class="badge-perfil <?= $item['tipo'] === Usuario::PERFIL_ADMINISTRADOR ? 'badge-admin' : 'badge-funcionario' ?>"><?= e(nome_perfil((string) $item['tipo'])) ?></span></td>
                            <td><span class="badge-status <?= (int) $item['ativo'] === 1 ? 'badge-ativo' : 'badge-inativo' ?>"><?= (int) $item['ativo'] === 1 ? 'Ativo' : 'Inativo' ?></span></td>
                            <td><span class="badge-alerta <?= (int) $item['recebe_alertas_dva'] === 1 ? 'badge-alerta-on' : 'badge-alerta-off' ?>"><?= (int) $item['recebe_alertas_dva'] === 1 ? 'Habilitados' : 'Desabilitados' ?></span></td>
                            <td class="col-acoes">
                                <a class="btn-acao btn-editar" href="<?= e(url('usuario/editar/' . (int) $item['id'])) ?>">✏️ Editar</a>
                                <form class="inline-form" method="post" action="<?= e(url('usuario/status/' . (int) $item['id'])) ?>" data-confirm-status="<?= (int) $item['ativo'] === 1 ? 'Inativar este usuário?' : 'Ativar este usuário?' ?>">
                                    <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="ativo" value="<?= (int) $item['ativo'] === 1 ? '0' : '1' ?>">
                                    <button class="btn-acao <?= (int) $item['ativo'] === 1 ? 'btn-inativar' : 'btn-ativar' ?>" type="submit" <?= (int) $item['id'] === (int) $_SESSION['usuario_id'] && (int) $item['ativo'] === 1 ? 'disabled' : '' ?>>
                                        <?= (int) $item['ativo'] === 1 ? '⛔ Inativar' : '✓ Ativar' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
</section>
