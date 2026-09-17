<?php require __DIR__.'/nav.php'; ?>
<section class="cert-panel"><h2>Configuração de fornecedores e tipos</h2><p>Inativar impede novos cadastros, mantendo documentos e referências históricas.</p><form class="cert-filters" method="get"><div><label for="busca">Pesquisar nomes</label><input id="busca" name="busca" maxlength="150" value="<?= e($search) ?>"></div><button type="submit" class="btn-primary">Pesquisar</button></form></section>
<div class="cert-config-grid">
<?php foreach (['fornecedor'=>['Fornecedores',$fornecedores],'tipo'=>['Tipos de certidão',$tipos]] as $kind=>[$label,$options]): ?>
    <section class="cert-panel"><h2><?= e($label) ?></h2>
        <form class="cert-option" method="post" action="<?= e(url('certidao/configurar')) ?>">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="tipo" value="<?= e($kind) ?>"><input type="hidden" name="ativo" value="1">
            <label for="novo-<?= e($kind) ?>">Novo nome</label><input id="novo-<?= e($kind) ?>" name="nome" minlength="2" maxlength="150" required><button type="submit" class="btn-primary">Cadastrar</button>
        </form>
        <p><?= count($options) ?> opções encontradas.</p>
        <?php foreach ($options as $option): ?><details class="cert-option"><summary><?= e($option['nome']) ?> · <?= (int)$option['ativo'] === 1 ? 'Ativo' : 'Inativo' ?></summary>
            <form method="post" action="<?= e(url('certidao/configurar')) ?>">
                <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="tipo" value="<?= e($kind) ?>"><input type="hidden" name="id" value="<?= (int)$option['id'] ?>">
                <label for="nome-<?= e($kind) ?>-<?= (int)$option['id'] ?>">Nome</label><input id="nome-<?= e($kind) ?>-<?= (int)$option['id'] ?>" name="nome" minlength="2" maxlength="150" required value="<?= e($option['nome']) ?>">
                <label for="ativo-<?= e($kind) ?>-<?= (int)$option['id'] ?>">Situação</label><select id="ativo-<?= e($kind) ?>-<?= (int)$option['id'] ?>" name="ativo"><option value="1" <?= (int)$option['ativo']===1 ? 'selected' : '' ?>>Ativo</option><option value="0" <?= (int)$option['ativo']===0 ? 'selected' : '' ?>>Inativo</option></select>
                <button class="btn-secondary" type="submit">Salvar alteração</button>
            </form>
        </details><?php endforeach; ?>
    </section>
<?php endforeach; ?>
</div>
