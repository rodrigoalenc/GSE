<?php
$menuPath = 'aluno/movimentar?ano=' . $year;
$basePath = $menuPath . '&tipo=' . $type;
$actions = [
    'passivo' => ['Mandar para o passivo', 'Escolha a turma e os alunos que serão enviados ao Arquivo Passivo.'],
    'progressao' => ['Progredir turma', 'Avance os alunos uma série, escolhendo uma turma de destino existente.'],
    'regressao' => ['Regredir turma', 'Retorne os alunos uma série, escolhendo uma turma de destino existente.'],
];
$showSetup = $configure;
$showReview = !$state['liberada'] || input_string($_GET['revisar'] ?? '') === '1';
$labelClass = static fn (array $class): string => (string) $class['nome_turma']
    . ($class['ano_letivo'] ? ' — ' . $class['ano_letivo'] : ' — ano letivo não informado');
?>
<link rel="stylesheet" href="<?= e(url('assets/css/alunos-movimentacao.css?v=' . substr((string) hash_file('sha256', ROOT_PATH . '/public/assets/css/alunos-movimentacao.css'), 0, 12))) ?>">
<section class="page-intro">
    <p class="hero-kicker">Gestão de alunos · Movimentar alunos</p><h2 <?= $type !== '' ? 'data-movement-action' : '' ?>><?= e($actions[$type][0] ?? 'Movimentar alunos') ?></h2>
    <p><?= e($actions[$type][1] ?? 'Escolha o que deseja fazer com os alunos.') ?></p>
    <?php if ($type !== ''): ?><a class="btn-secondary" data-movement-back href="<?= e(url($menuPath)) ?>">Escolher outra ação</a><?php else: ?><a class="btn-secondary" href="<?= e(url('aluno')) ?>">Voltar para Gestão de alunos</a><?php endif; ?>
</section>

<?php if ($type === ''): ?>
<nav class="movement-choices" aria-label="Escolha a ação de movimentação">
    <?php foreach ($actions as $action => [$label, $description]): ?>
    <a class="relatorio movement-choice" data-movement-choice="<?= e($action) ?>" href="<?= e(url($menuPath . '&tipo=' . $action)) ?>"><strong><?= e($label) ?></strong><span><?= e($description) ?></span><span class="movement-choice-open" aria-hidden="true">Abrir →</span></a>
    <?php endforeach; ?>
</nav>
<?php elseif ($type === 'passivo'): ?>
<?php require ROOT_PATH . '/src/Views/alunos/movimentar-passivo.php'; ?>
<?php else: ?>
<section class="relatorio movement-panel">
    <form class="movement-controls" method="get" action="<?= e(url('aluno/movimentar')) ?>">
        <input type="hidden" name="tipo" value="<?= e($type) ?>">
        <div><label for="movement-year">Ano de referência</label><input id="movement-year" name="ano" type="number" min="2000" max="<?= e((string) ((int) date('Y') + 5)) ?>" value="<?= e((string) $year) ?>" required></div>
        <div><button class="btn-secondary" type="submit">Abrir ano</button></div>
    </form>
    <p class="form-help">Use o ano em que a movimentação será aplicada, por exemplo, 2027. <?= $type === 'progressao' ? 'A conferência e o controle de progressões são separados por ano.' : 'O histórico da regressão usará esse ano de referência.' ?> O ano letivo cadastrado nas turmas não é alterado.</p>
</section>

<?php if (is_array($preview)): ?>
<section id="movement-preview" class="relatorio movement-panel movement-preview" aria-labelledby="movement-preview-title">
    <div class="section-head"><div><h2 id="movement-preview-title">Conferir <?= $preview['tipo'] === 'progressao' ? 'progressão' : 'regressão' ?></h2><p><?= e((string) count($preview['ids'])) ?> aluno(s) — referência <?= e((string) $preview['ano']) ?>. Nada foi alterado ainda.</p></div></div>
    <div class="table-scroll"><table class="tabela-filtrada"><thead><tr><th>Aluno</th><th>Origem</th><th>Destino</th></tr></thead><tbody>
    <?php foreach ($preview['alunos'] as $student): ?>
        <tr><td><?= e((string) $student['nome_completo']) ?></td><td><?= e($labelClass($student)) ?><small><?= e($series[(int) $student['serie']]) ?></small></td><td><?= e($labelClass($preview['destino'])) ?><small><?= e($series[(int) $preview['destino']['serie']]) ?></small></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <form method="post" action="<?= e(url('aluno/movimentar/confirmar')) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="ano" value="<?= e((string) $preview['ano']) ?>"><input type="hidden" name="tipo" value="<?= e($type) ?>"><input type="hidden" name="preview_token" value="<?= e($previewToken) ?>">
        <label class="movement-check"><input type="checkbox" name="confirmar" value="1" required> Conferi os alunos e autorizo a movimentação para a turma de destino exibida.</label>
        <div class="form-actions"><button class="btn-primary" type="submit">Confirmar movimentação de <?= e((string) count($preview['ids'])) ?> aluno(s)</button><a class="btn-secondary" href="<?= e(url($basePath . '&' . http_build_query(['turma' => $preview['alunos'][0]['id_turma']]))) ?>">Voltar e refazer a seleção</a></div>
    </form>
</section>
<?php endif; ?>

<section class="relatorio movement-panel" aria-labelledby="movement-series-title">
    <div class="section-head"><div><h2 id="movement-series-title">Séries das turmas</h2><p>Identifique a série das turmas de origem e destino para conferir a movimentação.</p></div>
    <?php if (!$showSetup): ?><a class="btn-secondary" href="<?= e(url($basePath . '&configurar=1#movement-series-title')) ?>">Identificar séries das turmas</a><?php endif; ?></div>
    <?php if ($state['pendentes'] > 0): ?><p class="warning-message" role="status"><?= e((string) $state['pendentes']) ?> aluno(s) ativo(s) estão em turmas sem série identificada ou sem turma. Use “Identificar séries das turmas” para informar as séries; para alunos sem turma, ajuste o cadastro em Gestão de alunos.</p><?php endif; ?>
    <?php if ($showSetup): ?>
    <form method="post" action="<?= e(url('aluno/movimentar/series')) ?>">
        <input type="hidden" name="tipo" value="<?= e($type) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="ano" value="<?= e((string) $year) ?>">
        <div class="table-scroll"><table class="tabela-filtrada"><thead><tr><th>Turma cadastrada</th><th>Alunos ativos</th><th>Série</th></tr></thead><tbody>
        <?php foreach ($classes as $class): ?>
            <tr><td><label for="series-<?= e((string) $class['id']) ?>"><?= e($labelClass($class)) ?><?= (int) $class['ativo'] === 0 ? ' (inativa)' : '' ?></label></td><td><?= e((string) $class['alunos_ativos']) ?></td><td><select id="series-<?= e((string) $class['id']) ?>" name="series[<?= e((string) $class['id']) ?>]"><option value="">Não identificada</option><?php foreach ($series as $value => $label): ?><option value="<?= e((string) $value) ?>" <?= (int) $class['serie'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></td></tr>
        <?php endforeach; ?>
        <?php if ($classes === []): ?><tr><td colspan="3">Nenhuma turma cadastrada. <a href="<?= e(url('turma')) ?>">Gerenciar turmas</a></td></tr><?php endif; ?>
        </tbody></table></div>
        <?php if ($classes !== []): ?><div class="form-actions"><button class="btn-primary" type="submit">Salvar identificação das séries</button></div><?php endif; ?>
    </form>
    <?php endif; ?>
</section>

<?php if ($type === 'progressao'): ?>
<section class="relatorio movement-panel" aria-labelledby="movement-senior-title">
    <div class="section-head"><div><h2 id="movement-senior-title">Conferência para liberar a progressão</h2><p>Depois de enviar os concluintes ao Arquivo Passivo, confira os alunos do 3º ano que permanecerão ativos por reprovação.</p></div><span class="result-pill"><?= $state['pendentes'] > 0 ? 'Identificação das séries pendente' : ($state['liberada'] ? 'Conferência concluída' : 'Conferência pendente') ?></span></div>
    <?php if ($state['liberada']): ?><p class="success-message">Progressão liberada para <?= e((string) $year) ?>. <?= e((string) count($state['alunos'])) ?> aluno(s) do 3º ano permanecem ativos por reprovação. Alunos que chegaram ao 3º ano pela progressão deste ciclo não entram nessa conferência.</p><?php endif; ?>
    <?php if (!$showReview): ?><a class="btn-secondary" href="<?= e(url($basePath . '&revisar=1#movement-senior-title')) ?>">Revisar alunos do 3º ano</a><?php endif; ?>
    <?php if ($showReview): ?>
        <?php if ($state['pendentes'] > 0): ?>
        <div class="warning-message" role="status"><strong>Identifique as séries antes de concluir a conferência.</strong> Há <?= e((string) $state['pendentes']) ?> aluno(s) ativo(s) sem turma ou em turmas cuja série ainda não foi identificada. Ainda não é possível determinar todos os alunos do 3º ano; esses alunos continuam ativos. <a href="#movement-series-title">Confira a identificação das turmas</a> e selecione “3º ano do ensino médio” nas turmas correspondentes, depois salve. Para alunos sem turma, ajuste o cadastro em Gestão de alunos.</div>
        <?php endif; ?>
        <?php if ($state['alunos'] !== []): ?>
            <div class="table-scroll"><table class="tabela-filtrada"><thead><tr><th>Situação</th><th>Aluno</th><th>Turma atual</th></tr></thead><tbody>
            <?php foreach ($state['alunos'] as $student): ?><tr><td>Permanece ativo</td><td><?= e((string) $student['nome_completo']) ?></td><td><?= e((string) $student['nome_turma']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php elseif ($state['pendentes'] === 0): ?><p>Não há alunos ativos do 3º ano pendentes desta conferência. Se você esperava encontrar alunos aqui, confira a identificação das séries das turmas antes de confirmar.</p><?php endif; ?>
        <?php if ($state['pendentes'] === 0): ?>
        <form class="movement-review-confirm" method="post" action="<?= e(url('aluno/movimentar/conferencia')) ?>">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="ano" value="<?= e((string) $year) ?>"><input type="hidden" name="fingerprint" value="<?= e($state['fingerprint']) ?>">
            <label class="movement-check"><input type="checkbox" name="confirmar" value="1" required> <?php if ($state['alunos'] !== []): ?>Todos os concluintes já foram enviados ao Arquivo Passivo. Os <?= e((string) count($state['alunos'])) ?> aluno(s) listados acima são reprovados e devem continuar ativos.<?php else: ?>Conferi a identificação das séries e confirmo que não há concluintes pendentes de envio ao Arquivo Passivo nesta conferência.<?php endif; ?></label>
            <button class="btn-primary" type="submit">Concluir conferência e liberar progressão</button>
        </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="relatorio movement-panel" aria-labelledby="movement-selection-title">
    <div class="section-head"><div><h2 id="movement-selection-title">Selecionar alunos e destino</h2><p>Escolha uma turma para listar todos os seus alunos ativos ou pesquise pelo nome. Marque somente os alunos que deseja movimentar.</p></div></div>
    <form class="movement-controls" method="get" action="<?= e(url('aluno/movimentar')) ?>">
        <input type="hidden" name="ano" value="<?= e((string) $year) ?>">
        <input type="hidden" name="tipo" value="<?= e($type) ?>">
        <div><label for="movement-source">Turma de origem</label><select id="movement-source" name="turma"><option value="0">Pesquisar em todas as turmas</option><?php foreach ($classes as $class): ?><option value="<?= e((string) $class['id']) ?>" <?= $classId === (int) $class['id'] ? 'selected' : '' ?>><?= e($labelClass($class)) ?></option><?php endforeach; ?></select></div>
        <div><label for="movement-search">Nome do aluno</label><input id="movement-search" name="q" maxlength="100" value="<?= e($search) ?>"></div>
        <div><button class="btn-secondary" type="submit">Listar alunos</button></div>
    </form>
    <?php if ($type === 'progressao' && !$state['liberada']): ?><p class="warning-message">A progressão ficará disponível após a conferência do 3º ano.</p><?php endif; ?>
    <form method="post" action="<?= e(url('aluno/movimentar/preview')) ?>" data-movement-selection>
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="ano" value="<?= e((string) $year) ?>"><input type="hidden" name="tipo" value="<?= e($type) ?>"><input type="hidden" name="turma" value="<?= e((string) $classId) ?>"><input type="hidden" name="q" value="<?= e($search) ?>">
        <div class="movement-selection-actions"><strong><?= e((string) count($students)) ?> aluno(s) na lista</strong><button class="btn-secondary" type="button" data-movement-select-all hidden>Marcar todos da lista</button><button class="btn-secondary" type="button" data-movement-clear hidden>Desmarcar todos</button><span data-movement-count role="status" aria-live="polite">Nenhum aluno selecionado.</span></div>
        <div class="table-scroll"><table class="tabela-filtrada"><thead><tr><th>Selecionar</th><th>Aluno</th><th>Turma atual</th><th>Série</th></tr></thead><tbody>
        <?php foreach ($students as $student): ?><tr><td><input type="checkbox" name="alunos[]" value="<?= e((string) $student['id']) ?>" aria-label="Movimentar <?= e((string) $student['nome_completo']) ?>"></td><td><?= e((string) $student['nome_completo']) ?></td><td><?= e((string) ($student['nome_turma'] ?? 'Sem turma')) ?></td><td><?= e($series[(int) $student['serie']] ?? 'Não identificada') ?></td></tr><?php endforeach; ?>
        <?php if ($students === []): ?><tr><td colspan="4" class="empty-state"><?= $classId === 0 && $search === '' ? 'Escolha uma turma ou pesquise um nome para listar os alunos.' : 'Nenhum aluno ativo encontrado.' ?></td></tr><?php endif; ?>
        </tbody></table></div>
        <div class="movement-destination"><label for="movement-destination">Turma de destino — <?= $type === 'progressao' ? 'uma série acima' : 'uma série abaixo' ?></label><select id="movement-destination" name="destino" required><option value="">Selecione uma turma existente</option><?php foreach ($classes as $class): ?><?php if ((int) $class['ativo'] === 1 && $class['serie'] !== null): ?><option value="<?= e((string) $class['id']) ?>"><?= e($labelClass($class)) ?> — <?= e($series[(int) $class['serie']]) ?></option><?php endif; ?><?php endforeach; ?></select></div>
        <p class="form-help">Limite de 200 alunos por lote. Os reprovados que permanecerão na mesma série devem ficar desmarcados.</p>
        <div class="form-actions"><button class="btn-primary" type="submit" <?= $type === 'progressao' && !$state['liberada'] ? 'disabled' : '' ?>>Conferir alunos selecionados</button><?php if ($classId > 0): ?><button class="btn-secondary" type="submit" name="turma_inteira" value="1" <?= $type === 'progressao' && !$state['liberada'] ? 'disabled' : '' ?>>Conferir turma inteira, incluindo alunos fora da busca</button><?php endif; ?></div>
    </form>
</section>
<?php endif; ?>
<script src="<?= e(url('assets/js/alunos-movimentacao.js')) ?>" defer></script>
