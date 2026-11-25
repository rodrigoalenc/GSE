<?php
$modelos = ['caixa' => 'Caixa', 'livro' => 'Livro'];
$escola = 'ESCOLA ESTADUAL SÃO JOSÉ';
$tituloModelo = 'CAIXA __';
?>
<div class="etiquetas-editor" data-etiquetas-editor>
    <div class="etiquetas-edicao">
        <p class="etiquetas-introducao">Edite Caixa e Livro e imprima cada modelo em uma folha A4. Um arquivo XML de Word ou TXT preenche os dois modelos.</p>
        <div class="etiquetas-acoes">
            <button class="btn-primary" type="button" data-etiquetas-imprimir>Imprimir caixa e livro</button>
            <button class="btn-secondary" type="button" data-etiquetas-selecionar>Importar XML ou TXT</button>
            <input type="file" id="etiquetas-arquivo" accept=".xml,.txt" hidden aria-label="Arquivo XML ou TXT">
            <button class="btn-secondary" type="button" data-etiquetas-reset>Limpar modelos</button>
            <a class="context-return" href="<?= e(url('etiqueta')) ?>">Voltar aos modelos</a>
        </div>
        <p class="etiquetas-status" role="status" data-etiquetas-status></p>

        <div class="etiquetas-grade-modelos">
            <?php foreach ($modelos as $id => $nome): ?>
                <section class="relatorio etiquetas-passivo-editor" aria-labelledby="etiquetas-titulo-<?= e($id) ?>">
                    <div class="section-head">
                        <h2 id="etiquetas-titulo-<?= e($id) ?>"><?= e($nome) ?></h2>
                    </div>
                    <div class="etiquetas-passivo-campos">
                        <label for="<?= e($id) ?>-escola">
                            Escola
                            <input id="<?= e($id) ?>-escola" type="text" autocomplete="off" value="<?= e($escola) ?>" data-etiquetas-campo>
                        </label>
                        <label for="<?= e($id) ?>-titulo">
                            Título
                            <input id="<?= e($id) ?>-titulo" type="text" autocomplete="off" value="<?= e($tituloModelo) ?>" data-etiquetas-campo data-etiquetas-titulo>
                        </label>
                        <label for="<?= e($id) ?>-registros">
                            Registros
                            <textarea id="<?= e($id) ?>-registros" rows="10" autocomplete="off" spellcheck="false" data-etiquetas-campo data-etiquetas-registros></textarea>
                        </label>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="etiquetas-so-impressao etiquetas-folhas-passivo">
        <?php foreach ($modelos as $id => $nome): ?>
            <div class="etiquetas-pagina-passivo">
                <div class="etiquetas-folha-passivo<?= $id === 'caixa' ? ' etiquetas-folha-caixa' : '' ?>">
                    <div class="etiquetas-passivo-conteudo">
                        <div class="etiquetas-passivo-escola" data-etiquetas-valor="<?= e($id) ?>-escola"><?= e($escola) ?></div>
                        <div class="etiquetas-passivo-titulo" data-etiquetas-valor="<?= e($id) ?>-titulo"><?= e($tituloModelo) ?></div>
                        <div class="etiquetas-passivo-registros" data-etiquetas-valor="<?= e($id) ?>-registros"></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
