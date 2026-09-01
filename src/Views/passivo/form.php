<section class="page-intro">
    <p class="hero-kicker">Arquivo Passivo</p>
    <h2><?= $editing ? 'Atualizar localização física' : 'Cadastrar ex-aluno manualmente' ?></h2>
    <p>Nome e caixa são obrigatórios. O número pode ficar vazio até a enumeração administrativa.</p>
</section>

<?php if ($errors !== []): ?><div class="error-message" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<section class="relatorio passivo-form-shell">
    <form class="passivo-form" method="post" action="<?= e(url($editing ? 'passivo/editar/' . $recordId : 'passivo/criar')) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="passivo-form-grid">
            <div class="field-wide"><label for="nome-completo">Nome completo</label><input id="nome-completo" name="nome_completo" maxlength="<?= e((string) Passivo::NAME_MAX_LENGTH) ?>" required value="<?= e($data['nome_completo']) ?>" autocomplete="name"></div>
            <div><label for="data-nascimento">Data de nascimento</label><input id="data-nascimento" type="date" name="data_nascimento" value="<?= e($data['data_nascimento']) ?>"></div>
            <div><label for="numero">Número ou posição</label><input id="numero" name="numero" maxlength="<?= e((string) Passivo::NUMBER_MAX_LENGTH) ?>" value="<?= e($data['numero']) ?>" placeholder="Ex.: 127 ou P-12"></div>
            <div><label for="caixa">Caixa</label><input id="caixa" class="passivo-box-input" name="caixa" maxlength="<?= e((string) Passivo::BOX_MAX_LENGTH) ?>" required value="<?= e($data['caixa']) ?>" placeholder="Ex.: CX-10"></div>
        </div>
        <div class="form-actions"><button class="btn-primary" type="submit"><?= $editing ? 'Salvar alterações' : 'Cadastrar registro' ?></button><a class="btn-secondary" href="<?= e(url('passivo')) ?>">Cancelar</a></div>
    </form>
</section>
