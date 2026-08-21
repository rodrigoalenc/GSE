<?php
$phone = static function (?string $value): string {
    $digits = (string) $value;
    if (strlen($digits) === 11) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7));
    }
    if (strlen($digits) === 10) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6));
    }
    return '—';
};
$studentPhoneValid = in_array(strlen((string) $student['telefone_aluno']), [10, 11], true);
$guardianPhoneValid = in_array(strlen((string) $student['telefone_responsavel']), [10, 11], true);
$dvaStatus = (string) $student['dva_status'];
$daysRemaining = $student['dva_dias_restantes'];
?>
<div class="profile-shell card-perfil">
<section class="profile-head">
    <div>
        <p class="hero-kicker">Perfil do aluno</p>
        <h2><?= e((string) $student['nome_completo']) ?></h2>
        <p>Cadastro acadêmico, contatos e histórico documental.</p>
    </div>
    <div class="profile-identifiers">
        <span class="perfil-id">ID <?= e((string) $student['id']) ?></span>
        <span class="badge-status <?= (int) $student['ativo'] === 1 ? 'badge-ativo' : 'badge-inativo' ?>"><?= (int) $student['ativo'] === 1 ? 'Ativo' : 'Inativo' ?></span>
    </div>
</section>

<section class="status-box dva-status-large dva-<?= e($dvaStatus) ?>" aria-label="Situação atual da DVA">
    <span class="dva-badge dva-<?= e($dvaStatus) ?>"><?= e(DvaStatus::label($dvaStatus)) ?></span>
    <?php if ($student['data_vencimento']): ?>
        <h2>Vencimento em <?= e(date('d/m/Y', strtotime((string) $student['data_vencimento']))) ?></h2>
        <p><?= e((string) $daysRemaining) ?> dia(s) em relação à data de referência.</p>
    <?php else: ?>
        <h2>Nenhuma DVA registrada</h2>
        <p>Registre a primeira DVA para iniciar o acompanhamento.</p>
    <?php endif; ?>
</section>

<div class="profile-actions">
    <a class="btn-primary" href="<?= e(url('aluno/editar/' . (int) $student['id'])) ?>">Editar dados</a>
    <?php if ((int) $student['ativo'] === 1): ?>
        <a class="btn-secondary" href="<?= e(url('aluno/dva/' . (int) $student['id'])) ?>"><?= $student['dva_id'] ? 'Renovar DVA' : 'Registrar DVA' ?></a>
    <?php endif; ?>
    <a class="btn-secondary" href="<?= e(url('aluno')) ?>">Voltar</a>
</div>

<section class="profile-grid">
    <article class="relatorio profile-card">
        <h2>Dados pessoais</h2>
        <dl>
            <div><dt>Nome</dt><dd><?= e((string) $student['nome_completo']) ?></dd></div>
            <div><dt>Nascimento</dt><dd><?= e(date('d/m/Y', strtotime((string) $student['data_nascimento']))) ?></dd></div>
            <div><dt>Turma</dt><dd><?= e((string) ($student['nome_turma'] ?: 'Sem turma')) ?><?= $student['ano_letivo'] ? ' — ' . e((string) $student['ano_letivo']) : '' ?></dd></div>
            <div><dt>Situação</dt><dd><span class="badge-status <?= (int) $student['ativo'] === 1 ? 'badge-ativo' : 'badge-inativo' ?>"><?= (int) $student['ativo'] === 1 ? 'Ativo' : 'Inativo' ?></span></dd></div>
        </dl>
    </article>
    <article class="relatorio profile-card contact-profile-card">
        <h2>Contatos</h2>
        <dl>
            <div><dt>Aluno</dt><dd><?= e($phone($student['telefone_aluno'])) ?></dd></div>
            <div><dt>Responsável</dt><dd><?= e($phone($student['telefone_responsavel'])) ?></dd></div>
        </dl>
        <div class="contact-actions">
        <?php if ($studentPhoneValid): ?>
            <a class="btn-whatsapp" href="<?= e('https://wa.me/55' . (string) $student['telefone_aluno']) ?>" target="_blank" rel="noopener noreferrer">WhatsApp do aluno</a>
        <?php endif; ?>
        <?php if ($guardianPhoneValid): ?>
            <a class="btn-whatsapp" href="<?= e('https://wa.me/55' . (string) $student['telefone_responsavel']) ?>" target="_blank" rel="noopener noreferrer">WhatsApp do responsável</a>
        <?php endif; ?>
        </div>
    </article>
    <article class="relatorio profile-card dva-current">
        <h2>DVA atual</h2>
        <p><span class="dva-badge dva-<?= e((string) $student['dva_status']) ?>"><?= e(DvaStatus::label((string) $student['dva_status'])) ?></span></p>
        <?php if ($student['data_vencimento']): ?>
            <dl>
                <div><dt>Vencimento</dt><dd><?= e(date('d/m/Y', strtotime((string) $student['data_vencimento']))) ?></dd></div>
                <div><dt>Prazo</dt><dd><?= e((string) $student['dva_dias_restantes']) ?> dia(s)</dd></div>
                <div><dt>Observação</dt><dd><?= e((string) ($student['dva_observacao'] ?: '—')) ?></dd></div>
            </dl>
        <?php else: ?><p>Nenhuma DVA registrada.</p><?php endif; ?>
    </article>
</section>
</div>

<?php if (Auth::isAdmin()): ?>
    <section class="relatorio status-panel">
        <h2>Administração do cadastro</h2>
        <form method="post" action="<?= e(url('aluno/status/' . (int) $student['id'])) ?>" data-confirm-status="<?= (int) $student['ativo'] === 1 ? 'Inativar este aluno? O histórico será preservado.' : 'Reativar este aluno?' ?>">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="ativo" value="<?= (int) $student['ativo'] === 1 ? '0' : '1' ?>">
            <button class="<?= (int) $student['ativo'] === 1 ? 'btn-danger' : 'btn-primary' ?>" type="submit"><?= (int) $student['ativo'] === 1 ? 'Inativar aluno' : 'Reativar aluno' ?></button>
        </form>
        <p class="form-help">O sistema não oferece exclusão física de alunos.</p>
    </section>
<?php endif; ?>

<section class="relatorio">
    <h2>Histórico de DVAs</h2>
    <div class="table-scroll">
        <table class="tabela-filtrada"><thead><tr><th>Situação</th><th>Vencimento</th><th>Registrada em UTC</th><th>Substituída em UTC</th><th>Responsável</th><th>Observação</th></tr></thead>
            <tbody>
            <?php if ($history === []): ?><tr><td colspan="6" class="empty-state">Nenhuma DVA registrada.</td></tr><?php endif; ?>
            <?php foreach ($history as $item): ?>
                <tr><td><?= (int) $item['ativo'] === 1 ? 'Atual' : 'Arquivada' ?></td><td><?= e(date('d/m/Y', strtotime((string) $item['data_vencimento']))) ?></td><td><?= e((string) $item['criado_em']) ?></td><td><?= e((string) ($item['substituido_em'] ?: '—')) ?></td><td><?= e((string) ($item['usuario_registro'] ?: '—')) ?></td><td><?= e((string) ($item['observacao'] ?: '—')) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
