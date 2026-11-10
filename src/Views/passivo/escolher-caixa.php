<?php $formErrors = $errors; $fieldErrors = []; require __DIR__ . '/../partials/form-errors.php'; ?>

<section class="page-intro passivo-create-intro">
    <p class="hero-kicker">Novo Registro · Etapa 1 de 2</p>
    <h2>Escolha a caixa do aluno</h2>
    <p>Selecione uma caixa existente ou crie a próxima caixa. Depois, preencha os dados do aluno. O sistema organizará os nomes em ordem alfabética e atualizará a numeração em sequência entre todas as caixas.</p>
</section>

<div class="passivo-tools-grid passivo-box-choice">
    <section class="relatorio passivo-tool-card" aria-labelledby="existing-box-title">
        <h2 id="existing-box-title">Selecionar caixa existente</h2>
        <p>O aluno será inserido na posição alfabética de seu nome. A numeração dos demais registros será ajustada automaticamente.</p>
        <?php if ($boxes === []): ?>
            <p class="passivo-form-tip">Nenhuma caixa cadastrada. Use “Criar próxima caixa” para começar.</p>
        <?php else: ?>
            <form class="passivo-form" method="get" action="<?= e(url('passivo/criar')) ?>">
                <input type="hidden" name="fluxo" value="<?= e($flow) ?>">
                <input type="hidden" name="tipo_caixa" value="existente">
                <label for="caixa-existente">Caixa existente</label>
                <select id="caixa-existente" name="caixa" class="passivo-box-input" required>
                    <option value="">Selecione uma caixa</option>
                    <?php foreach ($boxes as $box): ?>
                        <option value="<?= e((string) $box['caixa']) ?>" <?= (string) $box['caixa'] === (string) ($data['caixa'] ?? '') ? 'selected' : '' ?>>Caixa <?= e((string) $box['caixa']) ?> · <?= e((string) $box['total']) ?> registro(s)</option>
                    <?php endforeach; ?>
                </select>
                <button class="btn-primary" type="submit">Continuar nesta caixa</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="relatorio passivo-tool-card" aria-labelledby="next-box-title">
        <h2 id="next-box-title">Criar próxima caixa</h2>
        <?php if ($nextBox !== false): ?>
            <p>A próxima caixa será a <strong>Caixa <?= e($nextBox) ?></strong>. A numeração dos alunos continuará a sequência do acervo.</p>
            <p>A caixa será criada quando o registro for salvo. Se outra caixa for criada antes, o sistema usará a próxima numeração disponível.</p>
            <form class="passivo-form" method="get" action="<?= e(url('passivo/criar')) ?>">
                <input type="hidden" name="fluxo" value="<?= e($flow) ?>">
                <input type="hidden" name="tipo_caixa" value="nova">
                <button class="btn-primary" type="submit">Criar próxima caixa</button>
            </form>
        <?php else: ?>
            <p class="warning-message" role="status">Não foi possível calcular a próxima caixa. Selecione uma caixa existente ou tente novamente.</p>
        <?php endif; ?>
    </section>
</div>
<div class="form-actions"><a class="cancelar" href="<?= e(url('passivo')) ?>">Cancelar</a></div>
