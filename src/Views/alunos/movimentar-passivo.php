<section class="relatorio movement-panel" aria-labelledby="movement-archive-title">
    <div class="section-head"><div><h2 id="movement-archive-title">1. Enviar turma ao Arquivo Passivo</h2><p>Escolha a turma e confira os alunos. Desmarque os reprovados e quem deve continuar ativo.</p></div></div>
    <form class="movement-controls" method="get" action="<?= e(url('aluno/movimentar')) ?>" data-movement-archive-class>
        <input type="hidden" name="ano" value="<?= e((string) $year) ?>">
        <div><label for="movement-archive-class">Turma para enviar ao passivo</label><select id="movement-archive-class" name="passivo_turma" required><option value="">Selecione uma turma cadastrada</option><?php foreach ($classes as $class): ?><option value="<?= e((string) $class['id']) ?>" <?= $archiveClassId === (int) $class['id'] ? 'selected' : '' ?>><?= e($labelClass($class)) ?> — <?= e((string) $class['alunos_ativos']) ?> ativo(s)</option><?php endforeach; ?></select></div>
        <div><button class="btn-secondary" type="submit">Listar alunos da turma</button></div>
    </form>
    <?php if ($archiveClassId > 0): ?>
        <?php if ($archiveAfter): ?><p class="success-message">Os alunos enviados já estão no Arquivo Passivo. Os alunos restantes continuam ativos e estão desmarcados abaixo.</p><?php endif; ?>
        <?php if ($archiveStudents !== []): ?>
        <form method="post" action="<?= e(url('aluno/arquivar-lote/selecionar')) ?>" data-movement-selection>
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="movimentacao_ano" value="<?= e((string) $year) ?>"><input type="hidden" name="movimentacao_turma" value="<?= e((string) $archiveClassId) ?>">
            <div class="movement-selection-actions"><strong><?= e((string) count($archiveStudents)) ?> aluno(s) ativo(s)</strong><button class="btn-secondary" type="button" data-movement-select-all hidden>Marcar todos da turma</button><button class="btn-secondary" type="button" data-movement-clear hidden>Desmarcar todos</button><span data-movement-count role="status" aria-live="polite">Confira os alunos selecionados.</span></div>
            <div class="table-scroll"><table class="tabela-filtrada movement-archive-table"><thead><tr><th>Enviar</th><th>Aluno</th></tr></thead><tbody>
            <?php foreach ($archiveStudents as $student): ?><tr><td><input type="checkbox" name="alunos[]" value="<?= e((string) $student['id']) ?>" aria-label="Enviar <?= e((string) $student['nome_completo']) ?> ao Arquivo Passivo" <?= !$archiveAfter && (is_array($archiveSelected ?? null) ? in_array((int) $student['id'], $archiveSelected, true) : true) ? 'checked' : '' ?>></td><td><?= e((string) $student['nome_completo']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
            <p class="form-help">Somente os alunos marcados serão enviados. Na próxima tela, escolha a caixa e confira a prévia antes de confirmar. Limite de 200 alunos por lote.</p>
            <div class="form-actions"><button class="btn-primary" type="submit">Escolher caixa e conferir envio</button></div>
        </form>
        <?php else: ?><p>Esta turma não possui alunos ativos para enviar ao Arquivo Passivo.</p><?php endif; ?>
    <?php endif; ?>
</section>
