<?php if ($errors !== []): ?>
    <div class="error-message form-shell" role="alert"><strong>Revise os dados:</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<section class="page-intro">
    <p class="hero-kicker"><?= $editing ? 'Atualização de turma' : 'Nova turma' ?></p>
    <h2><?= $editing ? 'Edite a identificação da turma' : 'Cadastre uma turma' ?></h2>
    <p>O par nome e ano letivo deve ser único, sem diferenciação entre letras maiúsculas e minúsculas.</p>
</section>

<section class="relatorio form-shell">
    <form class="student-form class-form" method="post" action="<?= e(url($editing ? 'turma/editar/' . (int) $classId : 'turma/criar')) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="form-grid">
            <div><label for="nome_turma">Nome da turma</label><input id="nome_turma" name="nome_turma" value="<?= e($data['nome_turma']) ?>" maxlength="100" required></div>
            <div><label for="ano_letivo">Ano letivo</label><input type="number" id="ano_letivo" name="ano_letivo" value="<?= e($data['ano_letivo']) ?>" min="2000" max="<?= e((string) ((int) date('Y') + 5)) ?>" required></div>
        </div>
        <div class="form-actions"><button class="btn-primary" type="submit"><?= $editing ? 'Salvar alterações' : 'Cadastrar turma' ?></button><a class="btn-secondary" href="<?= e(url('turma')) ?>">Cancelar</a></div>
    </form>
</section>
