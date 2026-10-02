<?php
$action = $edicao ? 'usuario/editar/' . (int) $usuarioId : 'usuario/criar';
?>
<div class="usuario-form-card<?= $edicao ? ' usuario-form-card-edit' : '' ?>">
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
                    <?php if ($edicao): ?><h3>Dados da Conta</h3><?php endif; ?>
                    <label for="nome"><?= $edicao ? 'Nome Completo:' : 'Nome:' ?></label>
                    <input id="nome" name="nome" value="<?= e($dados['nome']) ?>" maxlength="150" autocomplete="name" required>

                    <label for="email"><?= $edicao ? 'E-mail de Acesso:' : 'E-mail:' ?></label>
                    <input type="email" id="email" name="email" value="<?= e($dados['email']) ?>" maxlength="254" autocomplete="username" required>

                    <?php if ($edicao): ?>
                    <div class="usuario-edit-grid">
                        <div><label for="tipo">Tipo de Permissão:</label><select id="tipo" name="tipo" required>
                            <option value="funcionario" <?= $dados['tipo'] === 'funcionario' ? 'selected' : '' ?>>Funcionário</option>
                            <option value="administrador" <?= $dados['tipo'] === 'administrador' ? 'selected' : '' ?>>Administrador</option>
                        </select></div>
                        <div class="senha-opcional"><label for="senha">Redefinir Senha (Opcional):</label><div class="usuario-edit-password-row">
                            <input type="password" id="senha" name="senha" autocomplete="new-password" maxlength="128" placeholder="Nova senha" title="Deixe em branco para manter a senha atual">
                            <label class="usuario-confirm-label"><span class="visually-hidden">Confirmar senha:</span><input type="password" id="confirmar_senha" name="confirmar_senha" autocomplete="new-password" maxlength="128" placeholder="Confirmar senha"></label>
                        </div></div>
                    </div>
                    <?php else: ?>
                    <div class="usuario-password-grid">
                        <div>
                            <label for="senha"><?= $edicao ? 'Nova senha (opcional):' : 'Senha:' ?></label>
                            <input type="password" id="senha" name="senha" autocomplete="new-password" maxlength="128" placeholder="<?= $edicao ? 'Deixe em branco para manter a atual' : 'Mínimo de 12 caracteres' ?>" <?= $edicao ? '' : 'required' ?>>
                        </div>
                        <div>
                            <label for="confirmar_senha">Confirmar senha:</label>
                            <input type="password" id="confirmar_senha" name="confirmar_senha" autocomplete="new-password" maxlength="128" placeholder="Repita a senha" <?= $edicao ? '' : 'required' ?>>
                        </div>
                    </div>

                    <label for="tipo">Tipo:</label>
                    <select id="tipo" name="tipo" required>
                        <option value="funcionario" <?= $dados['tipo'] === 'funcionario' ? 'selected' : '' ?>>Funcionário</option>
                        <option value="administrador" <?= $dados['tipo'] === 'administrador' ? 'selected' : '' ?>>Administrador</option>
                    </select>
                    <?php endif; ?>

                    <label class="checkbox-field usuario-check-option" for="recebe_alertas_dva">
                        <input type="checkbox" id="recebe_alertas_dva" name="recebe_alertas_dva" value="1" <?= !empty($dados['recebe_alertas_dva']) ? 'checked' : '' ?>>
                        <span><strong>Receber o resumo diário de DVAs</strong><small>Opção voluntária disponível somente para administradores ativos.</small></span>
                    </label>

                    <div class="form-actions">
                        <button type="submit"><?= $edicao ? 'Salvar Alterações' : 'Salvar' ?></button>
                        <a class="cancelar" href="<?= e(url('usuario')) ?>">Cancelar</a>
                        <details class="usuario-access-help"><summary>Orientações de acesso</summary><p>Use de 12 a 128 caracteres. Frases-senha, espaços e caracteres Unicode são aceitos; senhas comuns ou semelhantes ao nome/e-mail são recusadas.<?= $edicao ? ' Deixe os dois campos em branco para manter a senha atual.' : '' ?> A senha definida por administrador será temporária. Alterações de perfil e senha invalidam as sessões anteriores.</p></details>
                    </div>
                </form>
        </section>
</div>
