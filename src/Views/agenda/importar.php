<?php
require_once ROOT_PATH . '/src/Core/UploadLimits.php';
$limitePdf = UploadLimits::label(UploadLimits::fileBytes(CalendarioEscolarImportador::MAX_PDF_BYTES));
?>
<div class="agenda-page agenda-importacao">
    <div class="agenda-actions agenda-voltar"><a class="btn-secondary" href="<?= e(url('agenda')) ?>">Voltar à agenda</a></div>
    <?php if ($errors !== []): ?><ul class="error-message" role="alert"><?php foreach ($errors as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if ($avisos !== []): ?><ul class="warning-message" role="status"><?php foreach ($avisos as $aviso): ?><li><?= e($aviso) ?></li><?php endforeach; ?></ul><?php endif; ?>

    <section class="relatorio" aria-labelledby="agenda-importacao-origem">
        <h2 id="agenda-importacao-origem">Calendário escolar</h2>
        <p class="form-help">Envie o PDF ou cole o texto do calendário. Confira os itens identificados antes de salvar.</p>
        <form method="post" action="<?= e(url('agenda/importar')) ?>" enctype="multipart/form-data">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="acao" value="previsualizar">
            <div class="agenda-importacao-campos">
                <div class="agenda-campo"><label for="agenda-importacao-ano">Ano letivo</label><input id="agenda-importacao-ano" type="number" name="ano" min="2020" max="2100" required value="<?= (int) $ano ?>"></div>
                <div class="agenda-campo"><label for="agenda-importacao-pdf">Arquivo PDF</label><input id="agenda-importacao-pdf" type="file" name="arquivo" accept="application/pdf,.pdf" aria-describedby="agenda-importacao-limite"><p class="form-help" id="agenda-importacao-limite">Até <?= e($limitePdf) ?>.</p></div>
            </div>
            <div class="agenda-campo"><label for="agenda-importacao-texto">Texto do calendário</label><textarea id="agenda-importacao-texto" name="texto" rows="5" maxlength="262144"><?= e($texto) ?></textarea></div>
            <button class="btn-primary" type="submit">Analisar calendário</button>
        </form>
    </section>

    <?php if ($preview !== null && $preview['itens'] !== []): ?>
        <section class="relatorio" aria-labelledby="agenda-importacao-previa">
            <h2 id="agenda-importacao-previa">Conferir avisos</h2>
            <p class="form-help">Marque os avisos que deseja salvar. A prévia fica disponível por 15 minutos.</p>
            <form method="post" action="<?= e(url('agenda/importar')) ?>" data-agenda-importacao>
                <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="acao" value="confirmar">
                <input type="hidden" name="ano" value="<?= (int) $ano ?>">
                <input type="hidden" name="token" value="<?= e($preview['token']) ?>">
                <div class="agenda-actions agenda-importacao-atalhos" hidden>
                    <button class="btn-secondary" type="button" data-agenda-marcar>Marcar todos</button>
                    <button class="btn-secondary" type="button" data-agenda-desmarcar>Desmarcar todos</button>
                    <span data-agenda-selecionados role="status"></span>
                </div>
                <div class="table-scroll" tabindex="0" aria-label="Avisos reconhecidos no calendário escolar">
                    <table class="tabela-filtrada">
                        <thead><tr><th scope="col">Incluir</th><th scope="col">Data</th><th scope="col">Aviso</th><th scope="col">Tipo</th></tr></thead>
                        <tbody>
                        <?php foreach ($preview['itens'] as $indice => $item): ?>
                            <tr>
                                <td><input type="checkbox" name="selecionados[]" value="<?= (int) $indice ?>" checked aria-label="<?= e('Incluir ' . $item['titulo'] . ', dia ' . $item['data_aviso']) ?>"></td>
                                <td><time datetime="<?= e($item['data_aviso']) ?>"><?= e((new DateTimeImmutable($item['data_aviso']))->format('d/m/Y')) ?></time></td>
                                <td><?= e($item['titulo']) ?><?php if ($item['descricao'] !== ''): ?><p class="form-help"><?= e($item['descricao']) ?></p><?php endif; ?></td>
                                <td><?= e($item['tipo']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button class="btn-primary" type="submit">Salvar selecionados na agenda</button>
            </form>
        </section>
    <?php endif; ?>
</div>
