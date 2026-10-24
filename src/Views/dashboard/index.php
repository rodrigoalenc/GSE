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
$aniversariantesHoje = array_filter($aniversariantesMes, static fn (array $item): bool =>
    substr((string) $item['data_nascimento'], 5) === $dataAtual->format('m-d')
);
?>

<div class="dashboard-home" data-dashboard-home>
    <?php if ($certidoesAtencao > 0): ?>
        <details class="dashboard-certificate-alert">
            <summary>Atenção: certidões prestes a vencer (<?= $certidoesAtencao ?>)<span class="dashboard-chevron" aria-hidden="true"></span></summary>
            <div class="dashboard-alert-content">
                <p><?= (int) ($certidoes['vence_hoje'] ?? 0) ?> vence(m) hoje e <?= (int) ($certidoes['a_vencer'] ?? 0) ?> está(ão) a vencer.</p>
                <?php if ((int) ($certidoes['vence_hoje'] ?? 0) > 0): ?><a href="<?= e(url('certidao?validade=vence_hoje')) ?>">Ver certidões que vencem hoje</a><?php endif; ?>
                <?php if ((int) ($certidoes['a_vencer'] ?? 0) > 0): ?><a href="<?= e(url('certidao?validade=a_vencer')) ?>">Ver certidões a vencer</a><?php endif; ?>
            </div>
        </details>
    <?php endif; ?>

    <?php if ($aniversariantesHoje !== []): ?>
        <section class="dashboard-birthday-today" aria-labelledby="dashboard-birthday-today-title">
            <h2 id="dashboard-birthday-today-title">🎈 Feliz aniversário</h2>
            <?php foreach ($aniversariantesHoje as $item): ?>
                <div class="dashboard-birthday-today-card">
                    <span>🎈 Hoje é o dia de <strong><?= e((string) $item['nome_completo']) ?></strong>, completando <strong><?= (int) (new DateTimeImmutable((string) $item['data_nascimento']))->diff($dataAtual)->y ?> anos</strong>.</span>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <section class="dashboard-summary" aria-label="Indicadores dos alunos e DVAs">
        <div class="dashboard-summary-grid">
            <a class="dashboard-number-card total" href="<?= e(url('aluno?ativo=1')) ?>"><span>Total de alunos</span><strong><?= (int) $moduloDois['alunos_ativos'] ?></strong></a>
            <a class="dashboard-number-card missing" href="<?= e(url('dva?dva=sem_dva')) ?>"><span>Sem DVA</span><strong><?= (int) $moduloDois['sem_dva'] ?></strong></a>
            <a class="dashboard-number-card expired" href="<?= e(url('dva?dva=vencida')) ?>"><span>DVAs vencidas</span><strong><?= (int) $moduloDois['vencidas'] ?></strong></a>
            <a class="dashboard-number-card due" href="<?= e(url('dva?dva=a_vencer')) ?>"><span>DVAs a vencer</span><strong><?= (int) $moduloDois['a_vencer'] ?></strong></a>
        </div>
    </section>

    <section class="dashboard-search" aria-labelledby="dashboard-search-title">
        <h2 id="dashboard-search-title">Buscar em todos os alunos</h2>
        <form id="dashboard-search-form" method="get" action="<?= e(url('dashboard')) ?>#dashboard-search-results">
            <div class="dashboard-search-fields">
                <div class="dashboard-search-name">
                    <label for="dashboard-search-input">Nome do aluno</label>
                    <input id="dashboard-search-input" name="q" type="search" maxlength="100" value="<?= e($searchFilters['q']) ?>" placeholder="Digite o nome ou parte dele" autocomplete="off" aria-describedby="dashboard-search-help">
                </div>
                <div>
                    <label for="dashboard-search-active">Situação do aluno</label>
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
                <div>
                    <label for="dashboard-search-dva">Situação da DVA</label>
                    <select id="dashboard-search-dva" name="dva" class="form-select">
                        <option value="">Todas as situações</option>
                        <?php foreach (DvaStatus::ALL as $status): ?>
                            <option value="<?= e($status) ?>" <?= $searchFilters['dva'] === $status ? 'selected' : '' ?>><?= e(DvaStatus::label($status)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <p id="dashboard-search-help">A pesquisa inclui todo o cadastro, mesmo alunos fora dos resumos abaixo. Confira turma e nascimento para diferenciar nomes iguais.</p>
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
                    <thead><tr><th scope="col">Aluno</th><th scope="col">Nascimento</th><th scope="col">Turma</th><th scope="col">Situação</th><th scope="col">DVA</th></tr></thead>
                    <tbody>
                        <?php foreach ($searchResult['items'] as $item): ?>
                            <tr>
                                <td><a href="<?= e(url('aluno/perfil/' . (int) $item['id'])) ?>"><?= e((string) $item['nome_completo']) ?></a></td>
                                <td><?= e(UiPresentation::civilDate($item['data_nascimento'])) ?></td>
                                <td><?= e((string) ($item['nome_turma'] ?: 'Sem turma')) ?></td>
                                <td><?= (int) $item['ativo'] === 1 ? 'Ativo' : ((int) $item['no_passivo'] === 1 ? 'Inativo · Arquivo Passivo' : 'Inativo') ?></td>
                                <td><?= e(DvaStatus::label($item['dva_status'])) ?></td>
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

    <section id="dashboard-pending" class="dashboard-pending" aria-labelledby="dashboard-pending-title">
        <h2 id="dashboard-pending-title">Pendências para acompanhar</h2>
        <p>Abra uma lista para conferir os registros e realizar a próxima ação.</p>
        <div class="dashboard-pending-grid">
            <div class="dashboard-pending-group">
                <h3>DVAs dos alunos ativos</h3>
                <ul>
                    <?php foreach (['sem_dva' => 'Alunos sem DVA', 'vencida' => 'DVAs vencidas', 'vence_hoje' => 'DVAs que vencem hoje', 'a_vencer' => 'DVAs a vencer'] as $status => $label):
                        $key = $status === 'vencida' ? 'vencidas' : $status; ?>
                        <li><a href="<?= e(url('dva?dva=' . $status)) ?>"><span><?= e($label) ?></span><strong><?= (int) $moduloDois[$key] ?></strong></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="dashboard-pending-group">
                <h3>Certidões correntes</h3>
                <ul>
                    <?php foreach (['vencida' => 'Certidões vencidas', 'vence_hoje' => 'Certidões que vencem hoje', 'a_vencer' => 'Certidões a vencer', 'pendente' => 'Certidões com data pendente'] as $status => $label): ?>
                        <li><a href="<?= e(url('certidao?validade=' . $status)) ?>"><span><?= e($label) ?></span><strong><?= (int) ($certidoes[$status] ?? 0) ?></strong></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="dashboard-pending-group">
                <h3>Arquivo Passivo</h3>
                <ul><li><a href="<?= e(url('passivo?ativo=1&localizacao=pendente')) ?>"><span>Registros ativos com localização pendente</span><strong><?= (int) $passivoResumo['pendentes'] ?></strong></a></li></ul>
                <p>Informe caixa e número para localizar o documento no acervo.</p>
            </div>
            <div class="dashboard-pending-group">
                <h3>Estoque</h3>
                <ul>
                    <?php foreach (['quantidade_inicial' => 'Informar quantidade inicial', 'conferencia' => 'Conferência administrativa necessária', 'sem_estoque' => 'Produtos sem estoque', 'estoque_baixo' => 'Produtos com estoque baixo'] as $state => $label): ?>
                        <li><a href="<?= e(url('estoque')) ?>" data-stock-pending="<?= e($state) ?>"><span><?= e($label) ?></span><strong><?= (int) $estoquePendencias[$state] ?></strong></a></li>
                    <?php endforeach; ?>
                </ul>
                <p>Os atalhos abrem a lista completa de estoque. Confira a situação e a ação indicada em cada produto.</p>
            </div>
        </div>
    </section>

    <section class="dashboard-birthdays" aria-labelledby="dashboard-birthdays-title" data-dashboard-section>
        <h2 id="dashboard-birthdays-title">Aniversariantes de <?= e($nomesMeses[$mesAtual]) ?></h2>
        <?php if ($aniversariantesMes === []): ?>
            <p class="dashboard-empty">Nenhum aniversariante neste mês.</p>
        <?php else: ?>
            <div class="dashboard-birthday-grid">
                <?php foreach ($aniversariantesMes as $item): ?>
                    <a class="dashboard-birthday-card" href="<?= e(url('aluno/perfil/' . (int) $item['id'])) ?>" data-dashboard-item>
                        <span class="dashboard-birthday-icon" aria-hidden="true">🎂</span>
                        <span><strong><?= e((string) $item['nome_completo']) ?></strong><small>Dia <?= e(substr(UiPresentation::civilDate($item['data_nascimento']), 0, 2)) ?></small></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="dashboard-dva-groups" aria-label="Situação das DVAs">
        <?php foreach ($secoesDva as $secao): ?>
            <details class="dashboard-dva-panel <?= e($secao['classe']) ?>" data-dashboard-section>
                <summary><?= e($secao['titulo']) ?> (<?= (int) $secao['total'] ?>)<span class="dashboard-chevron" aria-hidden="true"></span></summary>
                <div class="dashboard-dva-body">
                    <p>Exibindo <?= count($secao['alunos']) ?> de <?= (int) $secao['total'] ?> alunos. <?php foreach ($secao['links'] as $status => $label): ?><a href="<?= e(url('dva?dva=' . $status)) ?>"><?= e($label) ?></a> <?php endforeach; ?></p>
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

</div>
