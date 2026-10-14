<section class="cert-config-intro"><div><h2>Organize os catálogos usados nas certidões</h2><p>Use esta tela para manter a lista de fornecedores e os tipos de certidão atualizados. As alterações feitas aqui já aparecem nos formulários de cadastro e edição.</p></div><span><i class="cert-icon cert-icon-shield" aria-hidden="true"></i> Alterações com feedback imediato</span></section>
<?php if (is_array($draft)): ?>
<section class="cert-panel" aria-labelledby="draft-title"><h2 id="draft-title">Sua edição não salva</h2>
<p>Copie o nome abaixo e compare com o cadastro atual. Abra a opção correspondente e escolha novamente a situação antes de salvar. Este rascunho permanece disponível até salvar uma configuração.</p>
<p><?= e($draft['tipo']) ?> #<?= e($draft['id']) ?> · revisão enviada <?= e($draft['revisao']) ?> · situação pretendida: <?= $draft['ativo'] === '1' ? 'Ativo' : 'Inativo' ?></p>
<label for="draft-name">Nome digitado (selecione para copiar)</label><input id="draft-name" readonly value="<?= e($draft['nome']) ?>">
</section>
<?php endif; ?>
<div class="cert-config-grid">
<?php foreach (['fornecedor'=>['Lista de Fornecedores',$fornecedores,'Cadastre e mantenha os nomes usados para vincular cada certidão ao respectivo fornecedor.'],'tipo'=>['Tipos de Certidão',$tipos,'Mantenha a nomenclatura dos documentos padronizada para evitar cadastros duplicados ou nomes divergentes.']] as $kind=>[$label,$options,$description]): ?>
    <section class="cert-panel cert-config-panel"><div class="cert-config-panel-heading"><h2><i class="cert-icon <?= $kind === 'fornecedor' ? 'cert-icon-truck' : 'cert-icon-certificate' ?>" aria-hidden="true"></i> <?= e($label) ?></h2><p><?= e($description) ?></p></div>
        <form class="cert-option" method="post" action="<?= e(url('certidao/configurar')) ?>">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="tipo" value="<?= e($kind) ?>"><input type="hidden" name="ativo" value="1">
            <label class="visually-hidden" for="novo-<?= e($kind) ?>">Novo nome</label><div class="cert-config-add"><input id="novo-<?= e($kind) ?>" name="nome" minlength="2" maxlength="150" required placeholder="Adicionar novo (Ex: <?= $kind === 'fornecedor' ? 'GRB' : 'CND FEDERAL' ?>)"><button type="submit" class="btn-primary" aria-label="Adicionar <?= e($kind) ?>"><i class="cert-icon cert-icon-plus" aria-hidden="true"></i></button></div>
        </form>
        <?php if ($options === []): ?><p class="cert-config-empty">Nenhum<?= $kind === 'fornecedor' ? ' fornecedor registrado.' : ' tipo de certidão registrado.' ?></p><?php endif; ?>
        <?php foreach ($options as $option): ?><details class="cert-option cert-config-item"><summary><span class="cert-config-item-name"><?= e($option['nome']) ?><?= (int)$option['ativo'] === 0 ? ' · Inativo' : '' ?></span><span class="cert-config-item-actions" aria-hidden="true"><i class="cert-icon cert-icon-edit"></i><i class="cert-icon cert-icon-power"></i></span></summary>
            <div class="cert-config-dialog" data-cert-option-dialog aria-labelledby="editar-opcao-<?= e($kind) ?>-<?= (int)$option['id'] ?>">
            <div class="cert-config-dialog-heading"><h2 id="editar-opcao-<?= e($kind) ?>-<?= (int)$option['id'] ?>">Renomear <?= $kind === 'fornecedor' ? 'fornecedor' : 'tipo de certidão' ?></h2><p>Atualize o nome desta opção sem precisar sair da tela de configuração.</p></div>
            <form method="post" action="<?= e(url('certidao/configurar')) ?>">
                <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="tipo" value="<?= e($kind) ?>"><input type="hidden" name="id" value="<?= (int)$option['id'] ?>">
                <label for="nome-<?= e($kind) ?>-<?= (int)$option['id'] ?>">Novo nome</label><input id="nome-<?= e($kind) ?>-<?= (int)$option['id'] ?>" name="nome" minlength="2" maxlength="150" required value="<?= e($option['nome']) ?>">
                <input type="hidden" name="revisao" value="<?= (int)$option['revisao'] ?>">
                <label for="ativo-<?= e($kind) ?>-<?= (int)$option['id'] ?>">Situação</label><select id="ativo-<?= e($kind) ?>-<?= (int)$option['id'] ?>" name="ativo"><option value="1" <?= (int)$option['ativo']===1 ? 'selected' : '' ?>>Ativo</option><option value="0" <?= (int)$option['ativo']===0 ? 'selected' : '' ?>>Inativo</option></select>
                <div class="cert-config-dialog-actions"><button class="btn-secondary" type="button" data-cert-option-cancel hidden>Cancelar</button><button class="btn-primary" type="submit">Salvar alteração</button></div>
            </form>
            </div>
        </details><?php endforeach; ?>
    </section>
<?php endforeach; ?>
</div>
<div class="cert-config-footer"><a href="<?= e(url('certidao')) ?>"><i class="cert-icon cert-icon-arrow-left" aria-hidden="true"></i> Voltar para a Tela Inicial</a></div>
<details class="cert-config-search"><summary>Pesquisar nos catálogos</summary><form class="cert-filters" method="get"><div><label for="busca">Pesquisar nomes</label><input id="busca" name="busca" maxlength="150" value="<?= e($search) ?>"></div><button type="submit" class="btn-primary">Pesquisar</button></form></details>
