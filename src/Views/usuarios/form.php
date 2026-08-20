<?php
$action = $edicao ? 'usuario/editar/' . (int) $usuarioId : 'usuario/criar';
?>
<div class="usuario-form-card">
        <?php if ($erros): ?>
            <div class="error-message" role="alert">
                <strong>Revise os dados informados:</strong>
                <ul>
                    <?php foreach ($erros as $erro): ?>
                        <li><?= e($erro) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="relatorio">
                <form class="usuario-form" method="post" action="<?= e(url($action)) ?>">
                    <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                    <h3>Dados da Conta</h3>

                    <label for="nome">Nome Completo:</label>
                    <input id="nome" name="nome" value="<?= e($dados['nome']) ?>" maxlength="150" autocomplete="name" required>

                    <label for="email">E-mail de Acesso:</label>
                    <input type="email" id="email" name="email" value="<?= e($dados['email']) ?>" maxlength="254" autocomplete="username" required>

                    <div class="usuario-form-grid">
                        <div>
                            <label for="tipo">Tipo de Permissão:</label>
                            <select id="tipo" name="tipo" required>
                                <option value="funcionario" <?= $dados['tipo'] === 'funcionario' ? 'selected' : '' ?>>Funcionário</option>
                                <option value="administrador" <?= $dados['tipo'] === 'administrador' ? 'selected' : '' ?>>Administrador</option>
                            </select>
                        </div>
                        <div class="senha-opcional">
                            <label for="senha"><?= $edicao ? 'Redefinir Senha Temporária (Opcional):' : 'Senha Temporária:' ?></label>
                            <input type="password" id="senha" name="senha" autocomplete="new-password" maxlength="128" placeholder="<?= $edicao ? 'Deixe em branco para manter a atual' : 'Mínimo de 12 caracteres' ?>" <?= $edicao ? '' : 'required' ?>>
                        </div>
                    </div>

                    <label class="checkbox-field" for="recebe_alertas_dva">
                        <input type="checkbox" id="recebe_alertas_dva" name="recebe_alertas_dva" value="1" <?= !empty($dados['recebe_alertas_dva']) ? 'checked' : '' ?>>
                        Receber o resumo diário de DVAs por e-mail (somente administradores ativos)
                    </label>

                    <label for="confirmar_senha">Confirmar Senha:</label>
                    <input type="password" id="confirmar_senha" name="confirmar_senha" autocomplete="new-password" maxlength="128" placeholder="Repita a senha" <?= $edicao ? '' : 'required' ?>>
                    <small class="form-help">Use de 12 a 128 caracteres. Frases-senha, espaços e caracteres Unicode são aceitos; senhas comuns ou semelhantes ao nome/e-mail são recusadas.<?= $edicao ? ' Deixe os dois campos em branco para manter a senha atual.' : '' ?> A senha definida por administrador será temporária.</small>

                    <div class="form-actions">
                        <button class="btn-primary" type="submit"><?= $edicao ? 'Salvar Alterações' : 'Salvar' ?></button>
                        <a class="cancelar" href="<?= e(url('usuario')) ?>">Cancelar</a>
                    </div>
                </form>
        </section>
</div>
