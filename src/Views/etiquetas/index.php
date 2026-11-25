<div class="etiquetas-modelos">
    <p class="etiquetas-introducao">Escolha um modelo, preencha os dados e imprima em papel A4.</p>
    <div class="etiquetas-grade-modelos">
        <section class="relatorio etiquetas-cartao-modelo" aria-labelledby="etiquetas-modelo-pasta">
            <span class="etiquetas-icone-modelo"><?= gse_icon('file') ?></span>
            <h2 id="etiquetas-modelo-pasta">Pasta de aluno</h2>
            <p>Preencha duas fichas por folha, com identificação, documentos e histórico anual.</p>
            <a class="btn-secondary" href="<?= e(url('etiqueta/pasta')) ?>">Abrir pasta de aluno <?= gse_icon('arrow-right') ?></a>
        </section>

        <section class="relatorio etiquetas-cartao-modelo" aria-labelledby="etiquetas-modelo-passivo">
            <span class="etiquetas-icone-modelo"><?= gse_icon('box') ?></span>
            <h2 id="etiquetas-modelo-passivo">Arquivo passivo</h2>
            <p>Identifique caixas e livros do acervo. Digite os registros ou importe um arquivo XML ou TXT.</p>
            <a class="btn-secondary" href="<?= e(url('etiqueta/caixa')) ?>">Abrir caixa e livro <?= gse_icon('arrow-right') ?></a>
        </section>
    </div>
</div>
