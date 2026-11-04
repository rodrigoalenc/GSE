<?php $formErrors = $erros; $fieldErrors = UiPresentation::fieldErrors($formErrors, ['nome' => ['Informe um nome'], 'email' => ['Informe um e-mail', 'e-mail informado'], 'tipo' => ['perfil de acesso'], 'senha' => ['Use uma senha', 'A senha pode', 'A senha não pode', 'senha menos comum', 'nova senha deve', 'senha temporária inicial'], 'confirmar_senha' => ['confirmação da senha'], 'senha_atual' => ['senha atual corretamente']]); require __DIR__ . '/../partials/form-errors.php'; ?>
<?php
$action = $edicao ? 'usuario/editar/' . (int) $usuarioId : 'usuario/criar';
$editingSelf = $editingSelf ?? false;
?>
<div class="usuario-form-card<?= $edicao ? ' usuario-form-card-edit' : '' ?>">
    <section class="relatorio">
        <form class="usuario-form" method="post" action="<?= e(url($action)) ?>">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
            <?php if ($edicao): ?><h3>Dados da conta</h3><?php endif; ?>
            <div class="usuario-form-grid usuario-identity-grid">
                <div>
                    <label for="nome">Nome completo</label>
                    <input id="nome" name="nome" value="<?= e($dados['nome']) ?>" maxlength="150" autocomplete="name" placeholder="Ex.: Maria Oliveira Silva" required <?= UiPresentation::fieldAttributes($fieldErrors, 'nome') ?>><?php if (isset($fieldErrors['nome'])): ?><small class="field-error" id="nome-error"><?= e(implode(' ', $fieldErrors['nome'])) ?></small><?php endif; ?>
                </div>
                <div>
                    <label for="email">E-mail</label>
                    <input type="email" id="email" name="email" value="<?= e($dados['email']) ?>" maxlength="254" autocomplete="username" placeholder="Ex.: maria@escola.sp.gov.br" required <?= UiPresentation::fieldAttributes($fieldErrors, 'email') ?>><?php if (isset($fieldErrors['email'])): ?><small class="field-error" id="email-error"><?= e(implode(' ', $fieldErrors['email'])) ?></small><?php endif; ?>
                </div>
            </div>

            <div class="usuario-password-grid">
                <div>
                    <label for="senha"><?= $edicao ? ($editingSelf ? 'Alterar minha senha (opcional)' : 'Redefinir senha temporária (opcional)') : 'Senha temporária' ?></label>
                    <div class="input-with-toggle">
                        <input type="password" id="senha" name="senha" autocomplete="new-password" data-password-max="128" data-password-min="15" placeholder="<?= $edicao ? 'Em branco para manter a atual' : 'Digite uma senha temporária' ?>" <?= $edicao ? '' : 'required' ?> <?= UiPresentation::fieldAttributes($fieldErrors, 'senha') ?>>
                        <button type="button" class="toggle-password" data-password-toggle="senha" aria-label="Mostrar senha" title="Mostrar senha"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/></svg></button>
                    </div>
                    <?php if (isset($fieldErrors['senha'])): ?><small class="field-error" id="senha-error"><?= e(implode(' ', $fieldErrors['senha'])) ?></small><?php endif; ?>
                </div>
                <div>
                    <label for="confirmar_senha">Confirmar senha</label>
                    <div class="input-with-toggle">
                        <input type="password" id="confirmar_senha" name="confirmar_senha" autocomplete="new-password" data-password-max="128" placeholder="Confirme a senha" <?= $edicao ? '' : 'required' ?> <?= UiPresentation::fieldAttributes($fieldErrors, 'confirmar_senha') ?>>
                        <button type="button" class="toggle-password" data-password-toggle="confirmar_senha" aria-label="Mostrar senha" title="Mostrar senha"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/></svg></button>
                    </div>
                    <?php if (isset($fieldErrors['confirmar_senha'])): ?><small class="field-error" id="confirmar_senha-error"><?= e(implode(' ', $fieldErrors['confirmar_senha'])) ?></small><?php endif; ?>
                </div>
            </div>

            <label for="tipo">Perfil de acesso</label>
            <select id="tipo" name="tipo" required <?= UiPresentation::fieldAttributes($fieldErrors, 'tipo') ?>>
                <option value="funcionario" <?= $dados['tipo'] === 'funcionario' ? 'selected' : '' ?>>Funcionário</option>
                <option value="administrador" <?= $dados['tipo'] === 'administrador' ? 'selected' : '' ?>>Administrador</option>
            </select><?php if (isset($fieldErrors['tipo'])): ?><small class="field-error" id="tipo-error"><?= e(implode(' ', $fieldErrors['tipo'])) ?></small><?php endif; ?>

            <?php if (!$edicao): ?>
                <p class="form-help usuario-password-help">Mínimo de 15 caracteres. A troca será solicitada no primeiro acesso.</p>
            <?php elseif ($editingSelf): ?>
                <label for="senha_atual">Senha atual (necessária ao alterar seu e-mail ou senha)</label>
                <input type="password" id="senha_atual" name="senha_atual" autocomplete="current-password" data-password-max="128" <?= UiPresentation::fieldAttributes($fieldErrors, 'senha_atual') ?>><?php if (isset($fieldErrors['senha_atual'])): ?><small class="field-error" id="senha_atual-error"><?= e(implode(' ', $fieldErrors['senha_atual'])) ?></small><?php endif; ?>
                <p class="form-help">Ao alterar seu e-mail ou senha, todas as sessões serão encerradas. Entre novamente com os dados atualizados.</p>
            <?php else: ?>
                <p class="form-help">A senha redefinida para outro usuário é temporária e deverá ser trocada no próximo acesso.</p>
            <?php endif; ?>

            <label class="checkbox-field usuario-check-option" for="recebe_alertas_dva">
                <input type="checkbox" id="recebe_alertas_dva" name="recebe_alertas_dva" value="1" <?= !empty($dados['recebe_alertas_dva']) ? 'checked' : '' ?>>
                <span><strong>Receber o resumo diário de DVAs</strong><small>Opção voluntária disponível somente para administradores ativos.</small></span>
            </label>

            <div class="form-actions">
                <details class="usuario-access-help"><summary>Orientações de acesso</summary><p>Use de 15 a 128 caracteres. Frases-senha, espaços e letras com acentos são aceitas; senhas comuns ou semelhantes ao nome/e-mail são recusadas.<?= $edicao ? ' Deixe os campos de nova senha e confirmação em branco para manter a senha atual.' : '' ?> <?= $editingSelf ? 'Sua nova senha será definitiva. A alteração do próprio e-mail ou senha exige confirmação da senha atual.' : 'A senha definida para outro usuário será temporária.' ?> Alterações de e-mail, perfil de acesso e senha invalidam as sessões anteriores.</p></details>
                <a class="cancelar" href="<?= e(url('usuario')) ?>">Cancelar</a>
                <button type="submit"><?= $edicao ? 'Salvar alterações' : 'Salvar usuário' ?></button>
            </div>
        </form>
    </section>
</div>
