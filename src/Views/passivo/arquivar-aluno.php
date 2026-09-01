<section class="page-intro"><p class="hero-kicker">Integração com aluno inativo</p><h2>Enviar para o Arquivo Passivo</h2><p>O cadastro do aluno e todo o histórico de DVA continuarão preservados. Informe a localização física explicitamente.</p></section>

<?php if ((int) $student['ativo'] === 1): ?><div class="error-message" role="alert">Este aluno está ativo e não pode ser enviado ao Arquivo Passivo.</div><?php endif; ?>
<?php if ($errors !== []): ?><div class="error-message" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<section class="relatorio passivo-form-shell">
    <dl class="passivo-student-summary"><div><dt>Aluno</dt><dd><?= e((string) $student['nome_completo']) ?></dd></div><div><dt>Nascimento</dt><dd><?= e((string) $student['data_nascimento']) ?></dd></div><div><dt>Situação</dt><dd><?= (int) $student['ativo'] === 1 ? 'Ativo' : 'Inativo' ?></dd></div></dl>
    <?php if ((int) $student['ativo'] === 0): ?>
    <form class="passivo-form" method="post" action="<?= e(url('aluno/arquivar/' . (int) $student['id'])) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="passivo-form-grid"><div><label for="archive-box">Caixa</label><input id="archive-box" class="passivo-box-input" name="caixa" maxlength="<?= e((string) Passivo::BOX_MAX_LENGTH) ?>" required value="<?= e($data['caixa']) ?>"></div><div><label for="archive-number">Número ou posição</label><input id="archive-number" name="numero" maxlength="<?= e((string) Passivo::NUMBER_MAX_LENGTH) ?>" value="<?= e($data['numero']) ?>"></div></div>
        <label class="passivo-confirm"><input type="checkbox" name="confirmar" value="1" required> Confirmo que a pasta física será vinculada a esta caixa e que o aluno original não deve ser excluído.</label>
        <div class="form-actions"><button class="btn-primary" type="submit">Enviar ao Arquivo Passivo</button><a class="btn-secondary" href="<?= e(url('aluno/perfil/' . (int) $student['id'])) ?>">Cancelar</a></div>
    </form>
    <?php else: ?><a class="btn-secondary" href="<?= e(url('aluno/perfil/' . (int) $student['id'])) ?>">Voltar ao perfil</a><?php endif; ?>
</section>
