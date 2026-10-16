<section class="page-intro"><p class="hero-kicker">Operações administrativas</p><h2>Ferramentas de caixa</h2><p>A renumeração usa prévia e transação. A exportação gera um TXT sem cache e com nome de arquivo seguro.</p></section>

<div class="passivo-tools-grid">
    <section class="relatorio passivo-tool-card blue">
        <h2>Ordenar e renumerar a caixa</h2>
        <p>Coloca todos os registros ativos da caixa em ordem alfabética e refaz a numeração: 1, 2, 3… Os números atuais serão substituídos.</p>
        <form class="passivo-form" method="post" action="<?= e(url('passivo/ferramentas/enumerar/preview')) ?>">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
            <label for="enumerar-caixa">Caixa existente</label>
            <select id="enumerar-caixa" name="caixa" required><option value="">Selecione</option><?php foreach ($boxes as $box): ?><option value="<?= e($box['caixa']) ?>" <?= $preview !== null && src\Core\TextNormalizer::searchKey((string) $preview['caixa']) === $box['caixa_normalizada'] ? 'selected' : '' ?>><?= e($box['caixa']) ?> (<?= e((string) $box['total']) ?>)</option><?php endforeach; ?></select>
            <button class="btn-primary" type="submit">Gerar prévia</button>
        </form>
    </section>
    <section class="relatorio passivo-tool-card green">
        <h2>Exportar lista da caixa</h2>
        <p>Gera a listagem UTF-8 no formato "Número - Nome", com ordenação consistente.</p>
        <form class="passivo-form" method="post" data-submit-download="true" action="<?= e(url('passivo/exportar')) ?>">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
            <label for="exportar-caixa">Caixa existente</label>
            <select id="exportar-caixa" name="caixa" required><option value="">Selecione</option><?php foreach ($boxes as $box): ?><option value="<?= e($box['caixa']) ?>"><?= e($box['caixa']) ?> (<?= e((string) $box['total']) ?>)</option><?php endforeach; ?></select>
            <button class="btn-primary" type="submit">Baixar TXT</button>
        </form>
    </section>
</div>

<?php if ($preview !== null): ?>
<section class="relatorio passivo-preview">
    <div class="section-head"><div><h2>Prévia da caixa <?= e((string) $preview['caixa']) ?></h2><p><?= e((string) count($preview['assignments'])) ?> registro(s) em ordem alfabética, com numeração a partir de 1.</p></div></div>
    <?php if ($preview['assignments'] === []): ?><p class="info-message">Esta caixa não possui registros ativos para renumerar.</p><?php else: ?>
        <div class="table-scroll"><table class="tabela-filtrada"><thead><tr><th>Nome</th><th>Número atual</th><th>Novo número</th></tr></thead><tbody>
            <?php foreach ($preview['assignments'] as $assignment): ?>
                <?php $currentNumber = src\Core\TextNormalizer::displayName((string) ($assignment['numero_anterior'] ?? '')); ?>
                <tr><td><?= e((string) $assignment['nome']) ?></td><td><?= e($currentNumber === '' ? 'Sem número' : $currentNumber) ?></td><td><?= e((string) $assignment['numero']) ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
        <form method="post" action="<?= e(url('passivo/ferramentas/enumerar/confirmar')) ?>" data-confirm-status="Substituir a numeração atual de todos os registros ativos desta caixa pela sequência alfabética apresentada?">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="preview_token" value="<?= e($previewToken) ?>"><button class="btn-primary" type="submit">Confirmar renumeração</button>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<div class="form-actions"><a class="btn-secondary" href="<?= e(url('passivo')) ?>">Voltar ao Arquivo Passivo</a></div>
