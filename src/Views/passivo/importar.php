<section class="page-intro">
    <p class="hero-kicker">Importação administrativa</p>
    <h2>Prévia segura antes de gravar</h2>
    <p>Formato: <strong>Nome;Data;Numero;Caixa</strong>. Datas aceitas: AAAA-MM-DD e DD/MM/AAAA. O modo comum apenas adiciona registros válidos e nunca apaga o acervo.</p>
</section>

<section class="relatorio passivo-form-shell">
    <div class="section-head"><div><h2>1. Validar arquivo</h2><p>Limites: <?= e((string) intdiv($maxFileSize, 1024)) ?> KiB e <?= e((string) $maxRows) ?> linhas.</p></div></div>
    <form class="passivo-form" method="post" enctype="multipart/form-data" action="<?= e(url('passivo/importar/preview')) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <label for="arquivo-csv">Arquivo CSV UTF-8</label>
        <input id="arquivo-csv" type="file" name="arquivo_csv" accept=".csv,text/csv,text/plain" required>
        <div class="form-actions"><button class="btn-primary" type="submit">Gerar prévia</button><a class="btn-secondary" href="<?= e(url('passivo')) ?>">Cancelar</a></div>
    </form>
</section>

<?php if ($preview !== null): ?>
<section class="relatorio passivo-preview" aria-labelledby="import-preview-title">
    <div class="section-head"><div><h2 id="import-preview-title">2. Conferir e confirmar</h2><p>A prévia expira em até 15 minutos e só pode ser usada uma vez.</p></div></div>
    <div class="passivo-preview-counts">
        <span class="valid"><strong><?= e((string) $preview['valid']) ?></strong> válidos</span>
        <span class="invalid"><strong><?= e((string) $preview['invalid']) ?></strong> inválidos</span>
        <span class="duplicate"><strong><?= e((string) $preview['duplicate']) ?></strong> duplicados</span>
        <span class="conflict"><strong><?= e((string) $preview['conflict']) ?></strong> conflitos</span>
    </div>
    <?php if (($preview['errors'] ?? []) !== []): ?>
        <div class="warning-message" role="alert"><strong>Ocorrências encontradas:</strong><ul><?php foreach ($preview['errors'] as $error): ?><li>Linha <?= e((string) $error['line']) ?>: <?= e((string) $error['message']) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ((int) $preview['valid'] > 0): ?>
        <form method="post" action="<?= e(url('passivo/importar/confirmar')) ?>" data-confirm-status="Importar somente as linhas válidas sem apagar o acervo atual?">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="preview_token" value="<?= e($previewToken) ?>">
            <button class="btn-primary" type="submit">Confirmar importação aditiva</button>
        </form>
    <?php else: ?><p class="error-message">Corrija o arquivo antes de tentar importar.</p><?php endif; ?>
</section>
<?php endif; ?>

<section class="relatorio passivo-safety-note"><h2>Comportamento removido por segurança</h2><p>A substituição completa e destrutiva do sistema original não faz parte deste módulo. Uma eliminação definitiva futura dependerá de política formal de LGPD da escola.</p></section>
