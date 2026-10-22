<?php
$menuPath='aluno/movimentar?ano='.$year;
$allClasses=$allClasses ?? false;
$basePath=$menuPath.'&tipo='.$type.($allClasses?'&todas=1':'');
$actions=[
    'passivo'=>['Mandar para o passivo','Envie os concluintes e desmarque os reprovados que devem continuar ativos.'],
    'progressao'=>['Progredir turmas para novo ano letivo','Avance todos os alunos uma série em uma única operação.'],
    'regressao'=>['Regredir alunos','Selecione os reprovados em Gestão de alunos para voltar uma série.'],
];
$labelClass=static fn(array $class): string => $class['nome_turma'].(!empty($class['ano_letivo'])?' — '.$class['ano_letivo']:'');
$savedDestinations=$savedDestinations ?? [];
$seniorClosure=$seniorClosure ?? null;
$regressionToken=$regressionToken ?? '';
?>
<link rel="stylesheet" href="<?= e(url('assets/css/alunos-movimentacao.css?v='.substr((string)hash_file('sha256',ROOT_PATH.'/public/assets/css/alunos-movimentacao.css'),0,12))) ?>">
<section class="page-intro">
    <p class="hero-kicker">Gestão de alunos · Movimentar alunos</p>
    <h2 <?= $type!==''?'data-movement-action':'' ?>><?= e($actions[$type][0] ?? 'Movimentar alunos') ?></h2>
    <p><?= e($actions[$type][1] ?? 'Escolha o que deseja fazer com os alunos.') ?></p>
    <?php if($type!==''): ?><a class="btn-secondary" data-movement-back href="<?= e(url($menuPath)) ?>">Escolher outra ação</a><?php else: ?><a class="btn-secondary" href="<?= e(url('aluno')) ?>">Voltar para Gestão de alunos</a><?php endif; ?>
</section>
<?php if($type===''): ?>
<nav class="movement-choices" aria-label="Escolha a ação de movimentação">
    <?php foreach($actions as $action=>[$label,$description]): ?>
    <a class="relatorio movement-choice" data-movement-choice="<?= e($action) ?>" href="<?= e(url($menuPath.'&tipo='.$action)) ?>"><strong><?= e($label) ?></strong><span><?= e($description) ?></span><span class="movement-choice-open" aria-hidden="true">Abrir →</span></a>
    <?php endforeach; ?>
</nav>
<?php else: ?>
<section class="relatorio movement-panel">
    <form class="movement-controls" method="get" action="<?= e(url('aluno/movimentar')) ?>">
        <?php if($allClasses): ?><input type="hidden" name="todas" value="1"><?php endif; ?>
        <input type="hidden" name="tipo" value="<?= e($type) ?>">
        <?php if($regressionToken!==''): ?><input type="hidden" name="selecao" value="<?= e($regressionToken) ?>"><?php endif; ?>
        <div><label for="movement-year">Ano letivo de destino / referência</label><input id="movement-year" type="number" name="ano" min="2000" max="<?= e((string)((int)date('Y')+5)) ?>" value="<?= e((string)$year) ?>" required></div>
        <div><button class="btn-secondary" type="submit">Abrir ano</button></div>
    </form>
    <p class="form-help">Use a mesma referência no envio dos concluintes e na progressão, por exemplo, 2027. Os nomes e os anos letivos cadastrados nas turmas são preservados.</p>
</section>
<?php
if($allClasses && empty($state['concluida'])) require ROOT_PATH.'/src/Views/alunos/movimentar-todas.php';
elseif(is_array($preview)) require ROOT_PATH.'/src/Views/alunos/movimentar-previa.php';
elseif($type==='passivo') require ROOT_PATH.'/src/Views/alunos/movimentar-passivo.php';
elseif($type==='progressao') require ROOT_PATH.'/src/Views/alunos/movimentar-anual.php';
else require ROOT_PATH.'/src/Views/alunos/movimentar-regressao.php';
if(!is_array($preview) && ($type!=='passivo' || $configure || $state['pendentes']>0)) require ROOT_PATH.'/src/Views/alunos/movimentar-series.php';
?>
<?php endif; ?>
<script src="<?= e(url('assets/js/alunos-movimentacao.js?v='.substr((string)hash_file('sha256',ROOT_PATH.'/public/assets/js/alunos-movimentacao.js'),0,12))) ?>" defer></script>
