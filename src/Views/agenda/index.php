<?php
$meses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
$diasSemana = ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'];
$iso = $data->format('Y-m-d');
$hojeIso = $hoje->format('Y-m-d');
$semana = $visao === 'semana';
$inicio = $semana ? $data->modify('monday this week') : $data->modify('first day of this month');
$fim = $semana ? $inicio->modify('+6 days') : $inicio->modify('last day of this month');
$passo = $semana ? 'week' : 'month';
$link = static fn (DateTimeImmutable $dia, string $modo, array $extra = []): string => url('agenda?' . http_build_query(
    ['data' => $dia->format('Y-m-d'), 'visao' => $modo] + $extra
));
$eventos = [];
foreach ($feriados as $feriado) {
    if ($feriado['data'] >= $inicio->format('Y-m-d') && $feriado['data'] <= $fim->format('Y-m-d')) {
        $eventos[$feriado['data']][] = ['feriado' => true] + $feriado;
    }
}
foreach ($avisos as $aviso) {
    $eventos[$aviso['data_aviso']][] = ['feriado' => false] + $aviso;
}
ksort($eventos);
$tituloPeriodo = $semana
    ? $inicio->format('d/m/Y') . ' a ' . $fim->format('d/m/Y')
    : ucfirst($meses[(int) $data->format('n') - 1]) . ' de ' . $data->format('Y');
$agendaAdmin = Auth::isAdmin();
$agendaActor = (int) ($_SESSION['usuario_id'] ?? 0);
$canEdit = static fn (array $aviso): bool => $agendaAdmin || (int) $aviso['usuario_id'] === $agendaActor;
$editando = $edicao !== null;
?>

<div class="agenda-page">
    <section class="relatorio agenda-periodo" aria-labelledby="agenda-periodo-titulo">
        <div class="agenda-periodo-header">
            <div>
                <h2 id="agenda-periodo-titulo"><?= e($tituloPeriodo) ?></h2>
                <p><?= $semana ? 'Lista da semana' : 'Calendário mensal' ?></p>
            </div>
            <div class="agenda-actions">
                <a class="btn-primary" href="#agenda-form">Adicionar aviso</a>
                <?php if ($agendaAdmin): ?><a class="btn-secondary" href="<?= e(url('agenda/importar')) ?>">Importar calendário</a><?php endif; ?>
            </div>
        </div>
        <div class="agenda-toolbar">
            <nav class="agenda-actions" aria-label="Navegar no calendário">
                <a class="btn-secondary" href="<?= e($link($inicio->modify('-1 ' . $passo), $visao)) ?>" aria-label="Período anterior">Anterior</a>
                <a class="btn-secondary" href="<?= e($link($hoje, $visao)) ?>">Hoje</a>
                <a class="btn-secondary" href="<?= e($link($inicio->modify('+1 ' . $passo), $visao)) ?>" aria-label="Próximo período">Próximo</a>
            </nav>
            <nav class="agenda-actions" aria-label="Visualização da agenda">
                <a class="<?= $semana ? 'btn-secondary' : 'btn-primary' ?>" href="<?= e($link($data, 'mes')) ?>"<?= $semana ? '' : ' aria-current="page"' ?>>Mês</a>
                <a class="<?= $semana ? 'btn-primary' : 'btn-secondary' ?>" href="<?= e($link($data, 'semana')) ?>"<?= $semana ? ' aria-current="page"' : '' ?>>Lista da semana</a>
            </nav>
        </div>
        <?php if (!$semana): ?>
        <table class="agenda-calendario" aria-label="Calendário de <?= e($tituloPeriodo) ?>">
            <thead>
                <tr><?php foreach ($diasSemana as $dia): ?><th scope="col" aria-label="<?= e($dia) ?>"><?= e(mb_substr($dia, 0, 3)) ?></th><?php endforeach; ?></tr>
            </thead>
            <tbody>
            <?php
                $deslocamento = (int) $inicio->format('N') - 1;
                $totalDias = (int) $fim->format('j');
                $celulas = (int) ceil(($deslocamento + $totalDias) / 7) * 7;
                for ($i = 0; $i < $celulas; ++$i):
                    if ($i % 7 === 0): ?><tr><?php endif;
                    $numero = $i - $deslocamento + 1;
                    if ($numero < 1 || $numero > $totalDias): ?>
                        <td class="agenda-dia-fora"></td>
                    <?php else:
                        $dia = $inicio->setDate((int) $inicio->format('Y'), (int) $inicio->format('m'), $numero);
                        $diaIso = $dia->format('Y-m-d');
                        $itensDoDia = $eventos[$diaIso] ?? [];
                        $qtd = count($itensDoDia);
                    ?>
                        <td class="agenda-dia<?= $diaIso === $iso ? ' selecionado' : '' ?><?= $diaIso === $hojeIso ? ' hoje' : '' ?>">
                            <a class="agenda-dia-link" href="<?= e($link($dia, 'mes')) ?><?= $qtd ? '#agenda-dia-' . e($diaIso) : '#agenda-form' ?>"
                               aria-label="<?= e($dia->format('d/m/Y') . ($qtd ? ', ' . $qtd . ($qtd === 1 ? ' compromisso' : ' compromissos') : ', adicionar aviso')) ?>"<?= $diaIso === $hojeIso ? ' aria-current="date"' : '' ?>>
                                <time datetime="<?= e($diaIso) ?>"><?= $numero ?></time>
                                <?php if ($qtd): ?><span class="agenda-dia-contagem" aria-hidden="true"><?= $qtd ?></span><?php endif; ?>
                            </a>
                            <?php if ($qtd): ?>
                            <ul class="agenda-dia-previa">
                                <?php foreach (array_slice($itensDoDia, 0, 2) as $item): ?>
                                <li class="<?= $item['feriado'] ? 'agenda-evento-feriado' : 'agenda-evento-aviso' ?>"><a href="#agenda-dia-<?= e($diaIso) ?>"><?= e($item['titulo']) ?></a></li>
                                <?php endforeach; ?>
                                <?php if ($qtd > 2): ?><li><a href="#agenda-dia-<?= e($diaIso) ?>">+<?= $qtd - 2 ?> no dia</a></li><?php endif; ?>
                            </ul>
                            <?php endif; ?>
                        </td>
                    <?php endif;
                    if ($i % 7 === 6): ?></tr><?php endif;
                endfor;
            ?>
            </tbody>
        </table>
        <ul class="agenda-legenda" aria-label="Legenda da agenda">
            <li>Hoje</li>
            <li class="agenda-evento-aviso">Aviso</li>
            <li class="agenda-evento-feriado">Feriado</li>
        </ul>
        <?php endif; ?>
    </section>

    <div class="agenda-detalhes">
        <section class="relatorio agenda-compromissos" aria-labelledby="agenda-compromissos-titulo">
            <h2 id="agenda-compromissos-titulo"><?= $semana ? 'Compromissos da semana' : 'Compromissos do mês' ?></h2>
            <?php if ($eventos === []): ?><p class="agenda-vazio">Nenhum aviso ou feriado neste período.</p><?php endif; ?>
            <?php foreach ($eventos as $diaIso => $itens):
                $dia = new DateTimeImmutable($diaIso);
            ?>
                <div class="agenda-grupo" id="agenda-dia-<?= e($diaIso) ?>">
                    <h3><time datetime="<?= e($diaIso) ?>"><?= e($dia->format('d/m') . ' · ' . $diasSemana[(int) $dia->format('N') - 1]) ?></time></h3>
                    <?php foreach ($itens as $item): ?>
                        <?php if ($item['feriado']): ?>
                            <p class="agenda-item agenda-evento-feriado"><strong><?= e($item['titulo']) ?></strong><span><?= e($item['tipo']) ?></span></p>
                        <?php else: ?>
                            <details class="agenda-item agenda-evento-aviso"<?= $semana ? ' open' : '' ?>>
                                <summary><strong><?= e($item['titulo']) ?></strong><span><?= e($item['tipo'] ?? 'Aviso') ?></span></summary>
                                <div class="agenda-item-conteudo">
                                    <?php if (trim((string) ($item['descricao'] ?? '')) !== ''): ?><p class="agenda-descricao"><?= e($item['descricao']) ?></p><?php endif; ?>
                                    <?php if (!empty($item['autor_nome'])): ?><p class="agenda-autoria">Por <?= e($item['autor_nome']) ?></p><?php endif; ?>
                                    <?php if ($canEdit($item)): ?>
                                    <div class="agenda-actions">
                                        <a class="btn-secondary" href="<?= e($link($data, $visao, ['editar' => (int) $item['id']])) ?>#agenda-form">Editar</a>
                                        <form method="post" action="<?= e(url('agenda/excluir/' . (int) $item['id'])) ?>" data-confirm-status="Excluir este aviso da agenda?">
                                            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="revisao" value="<?= (int) $item['revisao'] ?>">
                                            <input type="hidden" name="data" value="<?= e($iso) ?>">
                                            <input type="hidden" name="visao" value="<?= e($visao) ?>">
                                            <button class="btn-secondary" type="submit">Excluir</button>
                                        </form>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </details>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </section>

        <section id="agenda-form" class="relatorio agenda-form" aria-labelledby="agenda-form-titulo">
            <h2 id="agenda-form-titulo"><?= $editando ? 'Editar aviso' : 'Novo aviso' ?></h2>
            <?php if ($errors !== []): ?>
                <ul class="error-message" role="alert"><?php foreach ($errors as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <form method="post" action="<?= e(url($editando ? 'agenda/editar/' . (int) $edicao['id'] : 'agenda/cadastrar')) ?>">
                <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="data" value="<?= e($iso) ?>">
                <input type="hidden" name="visao" value="<?= e($visao) ?>">
                <?php if ($editando): ?><input type="hidden" name="revisao" value="<?= (int) $edicao['revisao'] ?>"><?php endif; ?>
                <div class="agenda-campo"><label for="agenda-data">Data</label><input id="agenda-data" type="date" name="data_aviso" required value="<?= e($dados['data_aviso'] ?? $iso) ?>"></div>
                <div class="agenda-campo"><label for="agenda-titulo">Título</label><input id="agenda-titulo" name="titulo" maxlength="120" required value="<?= e($dados['titulo'] ?? '') ?>"></div>
                <div class="agenda-campo"><label for="agenda-descricao">Descrição</label><textarea id="agenda-descricao" name="descricao" rows="4" maxlength="1000"><?= e($dados['descricao'] ?? '') ?></textarea></div>
                <div class="agenda-actions">
                    <button class="btn-primary" type="submit"><?= $editando ? 'Salvar alterações' : 'Adicionar aviso' ?></button>
                    <?php if ($editando): ?><a class="btn-secondary" href="<?= e($link($data, $visao)) ?>#agenda-form">Cancelar edição</a><?php endif; ?>
                </div>
            </form>
        </section>
    </div>
</div>
