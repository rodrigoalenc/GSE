<?php if($configure || $state['pendentes']>0): ?>
<section class="relatorio movement-panel" aria-labelledby="movement-series-title">
    <h2 id="movement-series-title">Identificar séries das turmas</h2><p>Informe a série de cada turma existente. Essa configuração não altera os nomes nem movimenta alunos.</p>
    <form method="post" action="<?= e(url('aluno/movimentar/series')) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="ano" value="<?= e((string)$year) ?>"><input type="hidden" name="tipo" value="<?= e($type) ?>">
        <div class="table-scroll"><table class="tabela-filtrada"><thead><tr><th>Turma</th><th>Série</th></tr></thead><tbody>
        <?php foreach($classes as $class): ?><tr><td><label for="series-<?= e((string)$class['id']) ?>"><?= e($labelClass($class)) ?></label></td><td><select id="series-<?= e((string)$class['id']) ?>" name="series[<?= e((string)$class['id']) ?>]"><option value="">Não identificada</option><?php foreach($series as $value=>$label): ?><option value="<?= e((string)$value) ?>" <?= (int)$class['serie']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?>
        </tbody></table></div><div class="form-actions"><button class="btn-primary" type="submit">Salvar identificação das séries</button></div>
    </form>
</section>
<?php elseif($type!=='passivo'): ?><p><a class="btn-secondary" href="<?= e(url($basePath.'&configurar=1')) ?>">Identificar séries das turmas</a></p><?php endif; ?>
