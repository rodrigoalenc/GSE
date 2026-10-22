<section id="movement-preview" class="relatorio movement-panel movement-preview" aria-labelledby="movement-preview-title">
    <h2 id="movement-preview-title"><?= $type==='progressao'?'Confirmar progressão geral':'Conferir regressão' ?></h2>
    <p><?= e((string)count($preview['ids'])) ?> aluno(s) — referência <?= e((string)$preview['ano']) ?>. Nada foi alterado ainda.</p>
    <div class="table-scroll"><table class="tabela-filtrada"><thead><tr><th><?= $type==='progressao'?'Turma de origem':'Aluno' ?></th><th>Destino</th><th><?= $type==='progressao'?'Alunos':'Origem' ?></th></tr></thead><tbody>
    <?php if($type==='progressao'): foreach($preview['resumo'] as $group): ?>
        <tr><td><?= e($labelClass($group['origem'])) ?></td><td><?= e($labelClass($group['destino'])) ?></td><td><?= e((string)$group['total']) ?></td></tr>
    <?php endforeach; else: foreach($preview['alunos'] as $student): ?>
        <tr><td><?= e($student['nome_completo']) ?></td><td><?= e($labelClass($student['destino'])) ?></td><td><?= e($labelClass($student)) ?></td></tr>
    <?php endforeach; endif; ?>
    </tbody></table></div>
    <form method="post" action="<?= e(url('aluno/movimentar/confirmar')) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="ano" value="<?= e((string)$preview['ano']) ?>"><input type="hidden" name="tipo" value="<?= e($type) ?>"><input type="hidden" name="preview_token" value="<?= e($previewToken) ?>">
        <label class="movement-check"><input type="checkbox" name="confirmar" value="1" required> <?= $type==='progressao'?'Confirmo a progressão geral das turmas acima. Os reprovados mantidos no 3º ano ficarão na mesma turma.':'Confirmo o retorno dos alunos selecionados em uma série. Eles continuarão ativos.' ?></label>
        <div class="form-actions"><button class="btn-primary" type="submit"><?= $type==='progressao'?'Progredir todas as turmas':'Confirmar regressão' ?></button><a class="btn-secondary" href="<?= e(url($basePath.($regressionToken!==''?'&selecao='.$regressionToken:''))) ?>">Voltar</a></div>
    </form>
</section>
