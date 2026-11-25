<?php
$documentos = [
    ['telefone', 'Telefone', 'tel'],
    ['id', 'ID', ''],
    ['cpf', 'CPF', 'cpf'],
    ['rg', 'RG', ''],
    ['sus', 'SUS', 'sus'],
    ['naturalidade', 'Naturalidade', ''],
    ['nascimento', 'Nascimento', 'data'],
    ['pai', 'Pai', ''],
    ['mae', 'Mãe', ''],
    ['endereco', 'Endereço', ''],
    ['endereco-2', 'Endereço (linha 2)', ''],
    ['endereco-3', 'Endereço (linha 3)', ''],
];
$historico = [
    'ano' => 'Ano letivo',
    'numero' => 'Nº',
    'turma' => 'Turma',
    'turno' => 'Turno',
    'resultado' => 'R. fin.',
];

$campoFicha = static function (string $id, string $rotulo, string $mascara = '', string $classeRotulo = ''): void {
    ?>
    <div class="etiquetas-campo-ficha">
        <label for="<?= e($id) ?>" class="<?= e($classeRotulo) ?>"><?= e($rotulo) ?></label>
        <input type="text" id="<?= e($id) ?>" autocomplete="off" data-etiquetas-campo<?= $mascara !== '' ? ' inputmode="numeric" data-etiquetas-mascara="' . e($mascara) . '"' : '' ?>>
        <span class="etiquetas-so-impressao etiquetas-valor-ficha" data-etiquetas-valor></span>
    </div>
    <?php
};
?>
<div class="etiquetas-editor" data-etiquetas-editor>
    <div class="etiquetas-edicao">
        <p class="etiquetas-introducao">Preencha as fichas separadamente. As duas saem na mesma folha A4.</p>
        <div class="etiquetas-acoes">
            <button class="btn-primary" type="button" data-etiquetas-imprimir>Imprimir as duas fichas</button>
            <button class="btn-secondary" type="button" data-etiquetas-reset>Limpar fichas</button>
            <a class="context-return" href="<?= e(url('etiqueta')) ?>">Voltar aos modelos</a>
        </div>
        <p class="etiquetas-status" role="status" data-etiquetas-status></p>
    </div>

    <div class="etiquetas-folha-pasta">
        <?php for ($ficha = 1; $ficha <= 2; $ficha++): ?>
            <section class="relatorio etiquetas-ficha" aria-labelledby="etiquetas-ficha-titulo-<?= $ficha ?>">
                <div class="section-head etiquetas-edicao">
                    <h2 id="etiquetas-ficha-titulo-<?= $ficha ?>">Ficha <?= $ficha ?></h2>
                </div>
                <div class="etiquetas-so-impressao etiquetas-escola">
                    <img src="<?= e(url('assets/image/logo_escola.png')) ?>" alt="" width="42" height="42">
                    <strong>E.E. São José — Vicentina/MS</strong>
                </div>
                <div class="etiquetas-aluno-identificacao">
                    <?php $campoFicha('ficha-' . $ficha . '-estudante', 'Estudante'); ?>
                    <?php $campoFicha('ficha-' . $ficha . '-sgde', 'SGDE'); ?>
                </div>
                <div class="etiquetas-aluno-detalhes">
                    <div class="etiquetas-aluno-documentos">
                        <?php foreach ($documentos as $indice => [$id, $rotulo, $mascara]): ?>
                            <?php if ($indice === 2): ?>
                                <h3>Documentos</h3>
                            <?php endif; ?>
                            <?php $campoFicha('ficha-' . $ficha . '-' . $id, $rotulo, $mascara, $indice >= 10 ? 'etiquetas-edicao' : ''); ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="etiquetas-historico">
                        <h3 class="etiquetas-edicao" id="etiquetas-historico-titulo-<?= $ficha ?>">Histórico anual</h3>
                        <div class="etiquetas-historico-scroll" tabindex="0" role="region" aria-labelledby="etiquetas-historico-titulo-<?= $ficha ?>">
                            <table aria-label="Histórico anual da ficha <?= $ficha ?>">
                                <thead>
                                    <tr>
                                        <?php foreach ($historico as $coluna): ?>
                                            <th scope="col"><?= e($coluna) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php for ($linha = 1; $linha <= 12; $linha++): ?>
                                        <tr>
                                            <?php foreach ($historico as $id => $coluna): ?>
                                                <td><?php $campoFicha('ficha-' . $ficha . '-historico-' . $linha . '-' . $id, $coluna . ', linha ' . $linha . ', ficha ' . $ficha, '', 'visually-hidden'); ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>
        <?php endfor; ?>
    </div>
</div>
