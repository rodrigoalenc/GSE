<section class="passivo-detail-shell">
    <div class="passivo-detail-head">
        <div><p class="hero-kicker">Arquivo Passivo</p><h2><?= e((string) $record['nome_completo']) ?></h2><p>Localizacao fisica e trilha historica do registro.</p></div>
        <div class="passivo-detail-badges"><span class="passivo-box-badge">Caixa <?= e((string) ($record['caixa'] ?: 'pendente')) ?></span><span class="passivo-status <?= (int) $record['ativo'] === 1 ? 'active' : 'inactive' ?>"><?= (int) $record['ativo'] === 1 ? 'Ativo' : 'Inativo' ?></span></div>
    </div>
    <?php if ((int) $record['localizacao_pendente'] === 1): ?><div class="warning-message" role="alert">Este dado legado precisa de revisao de localizacao fisica.</div><?php endif; ?>
    <dl class="passivo-detail-grid">
        <div><dt>Nome completo</dt><dd><?= e((string) $record['nome_completo']) ?></dd></div>
        <div><dt>Data de nascimento</dt><dd><?= $record['data_nascimento'] ? e((string) $record['data_nascimento']) : '&mdash;' ?></dd></div>
        <div><dt>Numero ou posicao</dt><dd><?= e((string) ($record['numero'] ?: 'Sem numero')) ?></dd></div>
        <div><dt>Caixa</dt><dd><?= e((string) ($record['caixa'] ?: 'Pendente')) ?></dd></div>
        <div><dt>Criado em UTC</dt><dd><?= e((string) $record['criado_em']) ?></dd></div>
        <div><dt>Atualizado em UTC</dt><dd><?= e((string) $record['atualizado_em']) ?></dd></div>
        <div><dt>Origem</dt><dd><?php if ($record['aluno_origem_id']): ?><a href="<?= e(url('aluno/perfil/' . (int) $record['aluno_origem_id'])) ?>">Aluno #<?= e((string) $record['aluno_origem_id']) ?></a><?php else: ?>Cadastro manual<?php endif; ?></dd></div>
        <div><dt>Responsavel atualizacao</dt><dd><?= e((string) ($record['atualizado_por_nome'] ?: 'Nao informado')) ?></dd></div>
    </dl>
    <div class="form-actions"><a class="btn-primary" href="<?= e(url('passivo/editar/' . (int) $record['id'])) ?>">Editar</a><a class="btn-secondary" href="<?= e(url('passivo?caixa=' . rawurlencode((string) $record['caixa']))) ?>">Voltar para a caixa</a></div>
</section>

<?php if ($canAdminister): ?>
<section class="relatorio passivo-admin-panel">
    <h2>Administracao do registro</h2>
    <p>A operacao preserva o registro e pode ser revertida. Exclusao fisica nao esta disponivel.</p>
    <form method="post" action="<?= e(url('passivo/status/' . (int) $record['id'])) ?>" data-confirm-status="<?= (int) $record['ativo'] === 1 ? 'Inativar este registro preservando seu historico?' : 'Restaurar este registro no acervo ativo?' ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="ativo" value="<?= (int) $record['ativo'] === 1 ? '0' : '1' ?>">
        <button class="<?= (int) $record['ativo'] === 1 ? 'btn-danger' : 'btn-primary' ?>" type="submit"><?= (int) $record['ativo'] === 1 ? 'Inativar registro' : 'Restaurar registro' ?></button>
    </form>
</section>
<?php endif; ?>
