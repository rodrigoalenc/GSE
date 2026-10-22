<?php
$pendingClasses = array_values(array_filter($classes, static fn (array $class): bool => (int) $class['ativo'] === 1 && $class['serie'] === null));
$setupClasses = $configure ? $classes : $pendingClasses;
$showSetup = $configure || $pendingClasses !== [];
?>
<section class="relatorio movement-panel" aria-labelledby="movement-series-title">
    <div class="section-head"><div><h2 id="movement-series-title">Séries das turmas</h2><p>O sistema identifica a série pelo nome da turma. Informe apenas as séries que não puderam ser reconhecidas.</p></div>
    <?php if (!$configure): ?><a class="btn-secondary" href="<?= e(url($basePath . '&configurar=1#movement-series-title')) ?>">Revisar todas as séries</a><?php else: ?><a class="btn-secondary" href="<?= e(url($basePath . '#movement-series-title')) ?>">Voltar às pendências</a><?php endif; ?></div>
    <?php if ($state['pendentes'] > 0): ?><p class="warning-message" role="status"><?= e((string) $state['pendentes']) ?> aluno(s) ativo(s) estão em turmas sem série identificada ou sem turma. Informe abaixo as séries pendentes. Para alunos sem turma, ajuste o cadastro em Gestão de alunos.</p><?php endif; ?>
    <?php if ($pendingClasses === [] && $state['pendentes'] === 0): ?><p class="success-message">Todas as turmas ativas têm série identificada. Você já pode seguir para a movimentação.</p><?php endif; ?>
    <?php if ($showSetup): ?>
    <form method="post" action="<?= e(url('aluno/movimentar/series')) ?>">
        <?php if ($allClasses): ?><input type="hidden" name="todas_turmas" value="1"><?php endif; ?>
        <input type="hidden" name="tipo" value="<?= e($type) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="ano" value="<?= e((string) $year) ?>">
        <div class="table-scroll"><table class="tabela-filtrada movement-series-table"><thead><tr><th>Turma cadastrada</th><th>Alunos ativos</th><th>Série</th></tr></thead><tbody>
        <?php foreach ($setupClasses as $class): ?>
            <tr><td><label for="series-<?= e((string) $class['id']) ?>"><?= e($labelClass($class)) ?><?= (int) $class['ativo'] === 0 ? ' (inativa)' : '' ?></label><?php if ($class['serie_automatica']): ?><small>Identificada automaticamente: <?= e($series[(int) $class['serie']]) ?></small><?php elseif ($class['serie'] !== null): ?><small>Identificação manual: <?= e($series[(int) $class['serie']]) ?></small><?php endif; ?></td><td><span class="movement-series-label">Alunos ativos</span><?= e((string) $class['alunos_ativos']) ?></td><td><label class="movement-series-label" for="series-<?= e((string) $class['id']) ?>">Série</label><select id="series-<?= e((string) $class['id']) ?>" name="series[<?= e((string) $class['id']) ?>]"><option value="" <?= $class['serie_informada'] === null ? 'selected' : '' ?>><?= $configure ? 'Automática pelo nome' : 'Selecione a série' ?></option><?php foreach ($series as $value => $label): ?><option value="<?= e((string) $value) ?>" <?= (int) $class['serie_informada'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></td></tr>
        <?php endforeach; ?>
        <?php if ($setupClasses === []): ?><tr><td colspan="3">Nenhuma turma cadastrada. <a href="<?= e(url('turma')) ?>">Gerenciar turmas</a></td></tr><?php endif; ?>
        </tbody></table></div>
        <?php if ($setupClasses !== []): ?><div class="form-actions"><button class="btn-primary" type="submit">Salvar identificação das séries</button></div><?php endif; ?>
    </form>
    <?php endif; ?>
</section>
