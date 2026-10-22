<section class="relatorio movement-panel" aria-labelledby="movement-selection-title">
    <h2 id="movement-selection-title">Regredir alunos selecionados</h2>
    <?php if($students===[]): ?><p>Marque os alunos em Gestão de alunos. O botão “Regredir aluno(s)” aparece ao selecionar as caixinhas.</p><a class="btn-primary" href="<?= e(url('aluno')) ?>">Selecionar alunos em Gestão de alunos</a>
    <?php else: ?>
    <p>Quando houver histórico de progressão, o sistema usará a turma anterior do aluno. Se houver várias opções sem histórico, escolha o destino abaixo.</p>
    <form method="post" action="<?= e(url('aluno/movimentar/preview')) ?>" data-movement-selection>
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="ano" value="<?= e((string)$year) ?>"><input type="hidden" name="tipo" value="regressao"><input type="hidden" name="selecao" value="<?= e($regressionToken) ?>">
        <div class="movement-selection-actions"><strong><?= e((string)count($students)) ?> aluno(s)</strong><button class="btn-secondary" type="button" data-movement-select-all hidden>Marcar todos</button><button class="btn-secondary" type="button" data-movement-clear hidden>Desmarcar todos</button><span data-movement-count role="status"></span></div>
        <div class="table-scroll"><table class="tabela-filtrada"><thead><tr><th>Regredir</th><th>Aluno</th><th>Turma atual</th></tr></thead><tbody>
        <?php foreach($students as $student): ?><tr><td><input type="checkbox" name="alunos[]" value="<?= e((string)$student['id']) ?>" aria-label="Regredir <?= e($student['nome_completo']) ?>" checked></td><td><?= e($student['nome_completo']) ?></td><td><?= e((string)$student['nome_turma']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php $seen=[]; foreach($students as $student): $source=(int)$student['id_turma']; if(isset($seen[$source])) continue; $seen[$source]=true;
            $candidates=array_filter($classes,static fn(array $c): bool => (int)$c['ativo']===1 && (int)$c['serie']===(int)$student['serie']-1);
            if(count($candidates)<=1) continue;
        ?>
        <div class="movement-destination"><label for="regression-target-<?= e((string)$source) ?>">Destino dos alunos de <?= e((string)$student['nome_turma']) ?></label><select id="regression-target-<?= e((string)$source) ?>" name="destinos[<?= e((string)$source) ?>]"><option value="">Usar a turma anterior registrada no histórico</option><?php foreach($candidates as $target): ?><option value="<?= e((string)$target['id']) ?>"><?= e($labelClass($target)) ?></option><?php endforeach; ?></select></div>
        <?php endforeach; ?>
        <div class="form-actions"><button class="btn-primary" type="submit">Conferir regressão dos selecionados</button></div>
    </form>
    <?php endif; ?>
</section>
