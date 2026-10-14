<?php
$nomesMeses = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
$aVencer = array_merge($alunosPorStatus[DvaStatus::VENCE_HOJE], $alunosPorStatus[DvaStatus::A_VENCER]);
$secoesDva = [
    ['titulo' => 'Alunos Sem DVA', 'classe' => 'sem-dva', 'alunos' => $alunosPorStatus[DvaStatus::SEM_DVA]],
    ['titulo' => 'DVAs Vencidas', 'classe' => 'vencidas', 'alunos' => $alunosPorStatus[DvaStatus::VENCIDA]],
    ['titulo' => 'A Vencer', 'classe' => 'a-vencer', 'alunos' => $aVencer],
    ['titulo' => 'Vigentes', 'classe' => 'vigentes', 'alunos' => $alunosPorStatus[DvaStatus::VIGENTE]],
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
                <a href="<?= e(url('certidao')) ?>">Consultar certidões</a>
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
            <a class="dashboard-number-card due" href="<?= e(url('dva')) ?>"><span>A vencer</span><strong><?= (int) $moduloDois['vence_hoje'] + (int) $moduloDois['a_vencer'] ?></strong></a>
        </div>
    </section>

    <div class="dashboard-search">
        <label for="dashboard-search-input">Pesquisar na tela</label>
        <input id="dashboard-search-input" type="search" placeholder="Digite o nome do aluno ou aniversariante..." autocomplete="off" data-dashboard-search>
        <p class="dashboard-search-feedback" role="status" aria-live="polite" data-dashboard-search-feedback hidden></p>
    </div>

    <section class="dashboard-birthdays" aria-labelledby="dashboard-birthdays-title" data-dashboard-section>
        <h2 id="dashboard-birthdays-title">Aniversariantes de <?= e($nomesMeses[$mesAtual]) ?></h2>
        <?php if ($aniversariantesMes === []): ?>
            <p class="dashboard-empty">Nenhum aniversariante neste mês.</p>
        <?php else: ?>
            <div class="dashboard-birthday-grid">
                <?php foreach ($aniversariantesMes as $item): ?>
                    <a class="dashboard-birthday-card" href="<?= e(url('aluno/perfil/' . (int) $item['id'])) ?>" data-dashboard-item>
                        <span class="dashboard-birthday-icon" aria-hidden="true">🎂</span>
                        <span><strong><?= e((string) $item['nome_completo']) ?></strong><small>Dia <?= e(date('d', strtotime((string) $item['data_nascimento']))) ?></small></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="dashboard-dva-groups" aria-label="Situação das DVAs">
        <?php foreach ($secoesDva as $secao): ?>
            <details class="dashboard-dva-panel <?= e($secao['classe']) ?>" data-dashboard-section>
                <summary><?= e($secao['titulo']) ?> (<?= count($secao['alunos']) ?>)<span class="dashboard-chevron" aria-hidden="true"></span></summary>
                <div class="dashboard-dva-body">
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
                                        <td><?= $item['data_vencimento'] ? e(date('d/m/Y', strtotime((string) $item['data_vencimento']))) : '—' ?></td>
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
