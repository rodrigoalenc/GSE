<?php if (($schoolLinks ?? []) !== []): ?>
<section class="relatorio student-history" aria-labelledby="school-links-title">
    <div class="section-head"><h2 id="school-links-title">Histórico de transferências e retornos</h2></div>
    <div class="table-scroll"><table class="tabela-filtrada"><thead><tr><th>Operação</th><th>Origem</th><th>Destino</th><th>Data</th><th>Responsável</th></tr></thead><tbody>
    <?php foreach ($schoolLinks as $link): ?><tr>
        <td><?= $link['tipo'] === 'retorno' ? 'Retorno à escola' : 'Transferência de turma' ?></td>
        <td><?php if ($link['tipo'] === 'retorno'): ?><a href="<?= e(url('passivo/detalhes/' . (int) $link['passivo_id'])) ?>">Arquivo Passivo #<?= (int) $link['passivo_id'] ?></a><?php else: ?><?= e((string) $link['origem_nome']) ?> — <?= (int) $link['origem_ano'] ?><?php endif; ?></td>
        <td><?= e((string) $link['destino_nome']) ?> — <?= (int) $link['destino_ano'] ?></td>
        <td><?= e(UiPresentation::localDateTime((string) $link['realizado_em'])) ?></td>
        <td><?= e((string) ($link['responsavel'] ?: '—')) ?></td>
    </tr><?php endforeach; ?>
    </tbody></table></div>
</section>
<?php endif; ?>
