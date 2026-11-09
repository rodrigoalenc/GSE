<?php
$nomesMeses = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
$aVencer = array_merge($alunosPorStatus[DvaStatus::VENCE_HOJE], $alunosPorStatus[DvaStatus::A_VENCER]);
$secoesDva = [
    ['titulo' => 'Alunos Sem DVA', 'classe' => 'sem-dva', 'alunos' => $alunosPorStatus[DvaStatus::SEM_DVA], 'total' => $moduloDois['sem_dva'], 'links' => [DvaStatus::SEM_DVA => 'Ver todos sem DVA']],
    ['titulo' => 'DVAs Vencidas', 'classe' => 'vencidas', 'alunos' => $alunosPorStatus[DvaStatus::VENCIDA], 'total' => $moduloDois['vencidas'], 'links' => [DvaStatus::VENCIDA => 'Ver todas as vencidas']],
    ['titulo' => 'A Vencer', 'classe' => 'a-vencer', 'alunos' => $aVencer, 'total' => $moduloDois['vence_hoje'] + $moduloDois['a_vencer'], 'links' => [DvaStatus::VENCE_HOJE => 'Ver todas que vencem hoje', DvaStatus::A_VENCER => 'Ver todas a vencer']],
    ['titulo' => 'Vigentes', 'classe' => 'vigentes', 'alunos' => $alunosPorStatus[DvaStatus::VIGENTE], 'total' => $moduloDois['vigentes'], 'links' => [DvaStatus::VIGENTE => 'Ver todas as vigentes']],
];
$certidoesAtencao = (int) ($certidoes['a_vencer'] ?? 0) + (int) ($certidoes['vence_hoje'] ?? 0);
$dataAtual = new DateTimeImmutable((new DvaStatus())->today());
$agora = UiPresentation::localNow();
$horaAtual = (int) $agora->format('G');
$saudacao = $horaAtual < 12 ? 'Bom dia' : ($horaAtual < 18 ? 'Boa tarde' : 'Boa noite');
$primeiroNome = explode(' ', trim((string) ($_SESSION['usuario_nome'] ?? '')))[0];
$pendencias = array_slice(array_merge(
    $alunosPorStatus[DvaStatus::VENCIDA],
    $alunosPorStatus[DvaStatus::VENCE_HOJE],
    $alunosPorStatus[DvaStatus::SEM_DVA],
    $alunosPorStatus[DvaStatus::A_VENCER]
), 0, 8);
$totalPendencias = (int) $moduloDois['sem_dva'] + (int) $moduloDois['vencidas'] + (int) $moduloDois['vence_hoje'] + (int) $moduloDois['a_vencer'];
?>

<div class="dashboard-home" data-dashboard-home>
    <div class="dashboard-introduction">
        <div>
            <p class="dashboard-greeting"><?= e($saudacao) ?><?= $primeiroNome !== '' ? ', ' . e($primeiroNome) : '' ?></p>
            <p class="dashboard-school">E.E. São José <span aria-hidden="true">·</span> Ano letivo <?= e($agora->format('Y')) ?></p>
        </div>
        <?php if (AccessPolicy::allows('student.manage')): ?><a class="btn btn-primary dashboard-new-student" href="<?= e(url('aluno/criar')) ?>"><?= gse_icon('plus') ?> Novo aluno</a><?php endif; ?>
    </div>

    <?php if ($certidoesAtencao > 0): ?>
        <details class="dashboard-certificate-alert">
            <summary>Atenção: certidões prestes a vencer (<?= $certidoesAtencao ?>)<span class="dashboard-chevron" aria-hidden="true"></span></summary>
            <div class="dashboard-alert-content">
                <p><?= (int) ($certidoes['vence_hoje'] ?? 0) ?> vence(m) hoje e <?= (int) ($certidoes['a_vencer'] ?? 0) ?> está(ão) a vencer.</p>
                <?php if ((int) ($certidoes['vence_hoje'] ?? 0) > 0): ?><form method="post" action="<?= e(url('certidao')) ?>" data-list-state-form><?= list_state_fields() ?><input type="hidden" name="validade" value="vence_hoje"><button type="submit" class="dashboard-filter-link">Ver certidões que vencem hoje</button></form><?php endif; ?>
                <?php if ((int) ($certidoes['a_vencer'] ?? 0) > 0): ?><form method="post" action="<?= e(url('certidao')) ?>" data-list-state-form><?= list_state_fields() ?><input type="hidden" name="validade" value="a_vencer"><button type="submit" class="dashboard-filter-link">Ver certidões a vencer</button></form><?php endif; ?>
            </div>
        </details>
    <?php endif; ?>

    <?php if ($aniversariantesHoje !== []): ?>
        <section class="dashboard-birthday-today" aria-labelledby="dashboard-birthday-today-title">
            <h2 id="dashboard-birthday-today-title"><span aria-hidden="true">🎈</span> Aniversariantes de hoje</h2>
            <?php foreach ($aniversariantesHoje as $item): ?>
                <div class="dashboard-birthday-today-card">
                    <span aria-hidden="true">🎈</span>
                    <span><strong><?= e((string) $item['nome_completo']) ?></strong> completa <strong><?= (int) (new DateTimeImmutable((string) $item['data_nascimento']))->diff($dataAtual)->y ?> anos</strong> hoje.</span>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <section class="dashboard-summary" aria-label="Indicadores dos alunos e DVAs">
        <div class="dashboard-summary-grid">
            <form method="post" action="<?= e(url('aluno')) ?>" data-list-state-form><?= list_state_fields() ?><input type="hidden" name="ativo" value="1"><button class="dashboard-number-card total" type="submit"><?= gse_icon('users') ?><span>Alunos ativos</span><strong><?= (int) $moduloDois['alunos_ativos'] ?></strong></button></form>
            <?php if ($canViewHealth): ?>
            <?php foreach ([['sem_dva','missing','file','Sem DVA','sem_dva'],['vencida','expired','calendar','DVAs vencidas','vencidas'],['a_vencer','due','calendar','DVAs a vencer','a_vencer']] as [$status,$cardClass,$icon,$label,$countKey]): ?><form method="post" action="<?= e(url('dva')) ?>" data-list-state-form><?= list_state_fields() ?><input type="hidden" name="dva" value="<?= e($status) ?>"><button class="dashboard-number-card <?= e($cardClass) ?>" type="submit"><?= gse_icon($icon) ?><span><?= e($label) ?></span><strong><?= (int) $moduloDois[$countKey] ?></strong></button></form><?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="dashboard-search" aria-labelledby="dashboard-search-title">
        <h2 id="dashboard-search-title"><?= gse_icon('search') ?> Buscar aluno</h2>
        <form id="dashboard-search-form" method="post" action="<?= e(url('dashboard')) ?>#dashboard-search-results" data-list-state-form><?= list_state_fields() ?>
            <div class="dashboard-search-fields">
                <div class="dashboard-search-name">
                    <label for="dashboard-search-input">Nome do aluno</label>
                    <input id="dashboard-search-input" name="q" type="search" maxlength="100" value="<?= e($searchFilters['q']) ?>" placeholder="Buscar aluno por nome" autocomplete="off" aria-describedby="dashboard-search-help">
                </div>
                <div>
                    <label for="dashboard-search-active">Situação</label>
                    <select id="dashboard-search-active" name="ativo" class="form-select">
                        <option value="">Ativos e inativos</option>
                        <option value="1" <?= $searchFilters['ativo'] === '1' ? 'selected' : '' ?>>Ativos</option>
                        <option value="0" <?= $searchFilters['ativo'] === '0' ? 'selected' : '' ?>>Inativos</option>
                    </select>
                </div>
                <div>
                    <label for="dashboard-search-class">Turma</label>
                    <select id="dashboard-search-class" name="turma" class="form-select">
                        <option value="">Todas as turmas</option>
                        <?php foreach ($turmasBusca as $turma): ?>
                            <option value="<?= (int) $turma['id'] ?>" <?= $searchFilters['turma'] === (string) $turma['id'] ? 'selected' : '' ?>><?= e((string) $turma['nome_turma']) ?><?= (int) $turma['ativo'] === 0 ? ' (inativa)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($canViewHealth): ?><div>
                    <label for="dashboard-search-dva">DVA</label>
                    <select id="dashboard-search-dva" name="dva" class="form-select">
                        <option value="">Todas as situações</option>
                        <?php foreach (DvaStatus::ALL as $status): ?>
                            <option value="<?= e($status) ?>" <?= $searchFilters['dva'] === $status ? 'selected' : '' ?>><?= e(DvaStatus::label($status)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div><?php endif; ?>
            </div>
            <p id="dashboard-search-help">Busque em todo o cadastro. Confira turma e nascimento para diferenciar alunos com o mesmo nome.</p>
            <div class="dashboard-search-actions"><button class="btn btn-primary" type="submit">Buscar alunos</button><a class="btn btn-outline-secondary" href="<?= e(url('dashboard')) ?>">Limpar busca</a></div>
        </form>
    </section>

    <?php if ($searchResult !== null): ?>
        <section id="dashboard-search-results" class="dashboard-search-results" aria-labelledby="dashboard-search-results-title" tabindex="-1">
            <h2 id="dashboard-search-results-title">Resultados da busca</h2>
            <p><?= (int) $searchResult['total'] ?> aluno(s) encontrado(s). Página <?= (int) $searchResult['page'] ?> de <?= (int) $searchResult['pages'] ?>.</p>
            <?php if ($searchResult['items'] === []): ?>
                <p class="dashboard-empty">Nenhum aluno encontrado. Confira o nome ou amplie os filtros.</p>
            <?php else: ?>
                <div class="table-scroll"><table class="dashboard-student-table dashboard-search-table">
                    <caption class="visually-hidden">Alunos encontrados em todo o cadastro</caption>
                    <thead><tr><th scope="col">Aluno</th><th scope="col">Nascimento</th><th scope="col">Turma</th><th scope="col">Situação</th><?php if ($canViewHealth): ?><th scope="col">DVA</th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php foreach ($searchResult['items'] as $item): ?>
                            <tr>
                                <td><a href="<?= e(url('aluno/perfil/' . (int) $item['id'])) ?>"><?= e((string) $item['nome_completo']) ?></a></td>
                                <td><?= e(UiPresentation::civilDate($item['data_nascimento'])) ?></td>
                                <td><?= e((string) ($item['nome_turma'] ?: 'Sem turma')) ?></td>
                                <td><?= (int) $item['ativo'] === 1 ? 'Ativo' : ((int) $item['no_passivo'] === 1 ? 'Inativo · Arquivo Passivo' : 'Inativo') ?></td>
                                <?php if ($canViewHealth): ?><td><?= e(DvaStatus::label($item['dva_status'])) ?></td><?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php $renderPagination = require __DIR__ . '/../partials/pagination.php';
                $renderPagination((int) $searchResult['page'], (int) $searchResult['pages'], static fn (int $page): string => url('dashboard?' . http_build_query($searchFilters + ['page' => $page])) . '#dashboard-search-results', 'Páginas da busca de alunos'); ?>
            <?php endif; ?>
            <a href="<?= e(url('aluno')) ?>">Abrir lista completa de alunos</a>
        </section>
    <?php endif; ?>

    <div class="dashboard-workspace<?= $canViewHealth ? '' : ' dashboard-workspace-basic' ?>">
        <?php if ($canViewHealth): ?>
        <section class="dashboard-pending" aria-labelledby="dashboard-pending-title">
            <div class="dashboard-section-heading">
                <h2 id="dashboard-pending-title"><?= gse_icon('clipboard') ?> Pendências da secretaria</h2>
                <a href="<?= e(url('dva')) ?>">Ver DVAs <?= gse_icon('arrow-right') ?></a>
            </div>
            <?php if ($pendencias === []): ?>
                <div class="dashboard-empty"><p>Nenhuma pendência de DVA no momento.</p><span>As declarações dos alunos ativos estão em dia.</span></div>
            <?php else: ?>
                <p class="dashboard-section-meta">Exibindo <?= count($pendencias) ?> de <?= $totalPendencias ?> pendências.</p>
                <ul class="dashboard-pending-list">
                    <?php foreach ($pendencias as $item): ?>
                    <li>
                        <a href="<?= e(url('aluno/dva/' . (int) $item['id'])) ?>">
                            <span class="dashboard-pending-icon"><?= gse_icon('file') ?></span>
                            <span class="dashboard-pending-student"><strong><?= e((string) $item['nome_completo']) ?></strong><small><?= e((string) ($item['nome_turma'] ?: 'Sem turma')) ?><?= $item['data_vencimento'] ? ' · Vencimento: ' . e(UiPresentation::civilDate($item['data_vencimento'])) : ' · Declaração não cadastrada' ?></small></span>
                            <span class="dashboard-status <?= e((string) $item['dva_status']) ?>"><?= e(DvaStatus::label((string) $item['dva_status'])) ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <?php endif; ?>

    <section class="dashboard-birthdays" aria-labelledby="dashboard-birthdays-title" data-dashboard-section>
        <h2 id="dashboard-birthdays-title"><?= gse_icon('gift') ?> Aniversariantes do mês</h2>
        <p class="dashboard-section-meta"><?= e($nomesMeses[$mesAtual]) ?> <span aria-hidden="true">·</span> Exibindo <?= count($aniversariantesMes) ?> de <?= (int) $birthdayTotal ?> alunos.</p>
        <?php if ($aniversariantesMes === []): ?>
            <p class="dashboard-empty">Nenhum aniversariante neste mês.</p>
        <?php else: ?>
            <div class="dashboard-birthday-grid">
                <?php foreach ($aniversariantesMes as $item): ?>
                    <a class="dashboard-birthday-card" href="<?= e(url('aluno/perfil/' . (int) $item['id'])) ?>" data-dashboard-item>
                        <span class="dashboard-birthday-icon" aria-hidden="true"><?= gse_icon('calendar') ?></span>
                        <span><strong><?= e((string) $item['nome_completo']) ?></strong><small><?= e(substr(UiPresentation::civilDate($item['data_nascimento']), 0, 2)) ?> de <?= e(mb_strtolower($nomesMeses[$mesAtual], 'UTF-8')) ?></small></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php $renderBirthdayPagination = require __DIR__ . '/../partials/pagination.php';
        $renderBirthdayPagination((int) $birthdayPage, (int) $birthdayPages, static fn (int $page): string => url('dashboard?' . http_build_query($searchFilters + ['aniversariantes_pagina' => $page])) . '#dashboard-birthdays-title', 'Páginas de aniversariantes'); ?>
    </section>
    </div>

    <?php if ($canViewHealth): ?>
    <h2 class="dashboard-dva-groups-title">Consultar por situação da DVA</h2>
    <div class="dashboard-dva-groups" aria-label="Situação das DVAs">
        <?php foreach ($secoesDva as $secao): ?>
            <details class="dashboard-dva-panel <?= e($secao['classe']) ?>" data-dashboard-section>
                <summary><?= e($secao['titulo']) ?> (<?= (int) $secao['total'] ?>)<span class="dashboard-chevron" aria-hidden="true"></span></summary>
                <div class="dashboard-dva-body">
                    <p>Exibindo <?= count($secao['alunos']) ?> de <?= (int) $secao['total'] ?> alunos.</p><?php foreach ($secao['links'] as $status => $label): ?><form method="post" action="<?= e(url('dva')) ?>" data-list-state-form><?= list_state_fields() ?><input type="hidden" name="dva" value="<?= e($status) ?>"><button type="submit" class="dashboard-filter-link"><?= e($label) ?></button></form><?php endforeach; ?>
                    <?php if ($secao['alunos'] === []): ?>
                        <p class="dashboard-empty">Nenhum aluno nesta situação.</p>
                    <?php else: ?>
                        <div class="table-scroll"><table class="dashboard-student-table">
                            <thead><tr><th>Aluno</th><th>Turma</th><th>Vencimento</th></tr></thead>
                            <tbody>
                                <?php foreach ($secao['alunos'] as $item): ?>
                                    <tr data-dashboard-item>
                                        <td><a href="<?= e(url('aluno/perfil/' . (int) $item['id'])) ?>"><?= e((string) $item['nome_completo']) ?></a></td>
                                        <td><?= e((string) ($item['nome_turma'] ?: 'Sem turma')) ?></td>
                                        <td><?= $item['data_vencimento'] ? e(UiPresentation::civilDate($item['data_vencimento'])) : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table></div>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>
