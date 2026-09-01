<section class="page-intro"><p class="hero-kicker">Operações administrativas</p><h2>Ferramentas de caixa</h2><p>A enumeração usa prévia e transação. A exportação gera um TXT sem cache e com nome de arquivo seguro.</p></section>

<div class="passivo-tools-grid">
    <section class="relatorio passivo-tool-card blue">
        <h2>Enumerar registros sem posição</h2>
        <p>Considera apenas uma caixa, preserva números existentes e segue a ordem alfabética normalizada.</p>
        <form class="passivo-form" method="post" action="<?= e(url('passivo/ferramentas/enumerar/preview')) ?>">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
            <label for="enumerar-caixa">Caixa existente</label>
            <select id="enumerar-caixa" name="caixa" required><option value="">Selecione</option><?php foreach ($boxes as $box): ?><option value="<?= e($box['caixa']) ?>"><?= e($box['caixa']) ?> (<?= e((string) $box['total']) ?>)</option><?php endforeach; ?></select>
            <button class="btn-primary" type="submit">Gerar prévia</button>
        </form>
    </section>
    <section class="relatorio passivo-tool-card green">
        <h2>Exportar lista da caixa</h2>
        <p>Gera a listagem UTF-8 no formato "Número - Nome", com ordenação consistente.</p>
        <form class="passivo-form" method="post" action="<?= e(url('passivo/exportar')) ?>">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
            <label for="exportar-caixa">Caixa existente</label>
            <select id="exportar-caixa" name="caixa" required><option value="">Selecione</option><?php foreach ($boxes as $box): ?><option value="<?= e($box['caixa']) ?>"><?= e($box['caixa']) ?> (<?= e((string) $box['total']) ?>)</option><?php endforeach; ?></select>
            <button class="btn-primary" type="submit">Baixar TXT</button>
        </form>
    </section>
</div>

<?php if ($preview !== null): ?>
<section class="relatorio passivo-preview">
    <div class="section-head"><div><h2>Prévia da caixa <?= e((string) $preview['caixa']) ?></h2><p><?= e((string) count($preview['assignments'])) ?> registro(s) receberão número.</p></div></div>
    <?php if ($preview['assignments'] === []): ?><p class="info-message">Todos os registros desta caixa já possuem número.</p><?php else: ?>
        <div class="table-scroll"><table class="tabela-filtrada"><thead><tr><th>Nome</th><th>Novo número</th></tr></thead><tbody><?php foreach ($preview['assignments'] as $assignment): ?><tr><td><?= e((string) $assignment['nome']) ?></td><td><?= e((string) $assignment['numero']) ?></td></tr><?php endforeach; ?></tbody></table></div>
        <form method="post" action="<?= e(url('passivo/ferramentas/enumerar/confirmar')) ?>" data-confirm-status="Aplicar esta enumeração na caixa selecionada?">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="preview_token" value="<?= e($previewToken) ?>"><button class="btn-primary" type="submit">Confirmar enumeração</button>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<div class="form-actions"><a class="btn-secondary" href="<?= e(url('passivo')) ?>">Voltar ao Arquivo Passivo</a></div>
