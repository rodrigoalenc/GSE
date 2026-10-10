<?php
$phone = static function (?string $value): string {
    $digits = (string) $value;
    if (strlen($digits) === 11) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7));
    }
    if (strlen($digits) === 10) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6));
    }
    return 'Não informado';
};
$studentPhoneDigits = (string) preg_replace('/\D/', '', (string) $student['telefone_aluno']);
$guardianPhoneDigits = (string) preg_replace('/\D/', '', (string) $student['telefone_responsavel']);
$studentPhoneValid = in_array(strlen($studentPhoneDigits), [10, 11], true);
$guardianPhoneValid = in_array(strlen($guardianPhoneDigits), [10, 11], true);
$dvaStatus = (string) $student['dva_status'];
$daysRemaining = $student['dva_dias_restantes'];
$dvaDate = $student['data_vencimento'] ? date('d/m/Y', strtotime((string) $student['data_vencimento'])) : null;
$dvaHeading = match ($dvaStatus) {
    DvaStatus::VENCIDA => 'DVA VENCIDA',
    DvaStatus::VENCE_HOJE, DvaStatus::A_VENCER => 'ATENÇÃO',
    DvaStatus::VIGENTE => 'DVA VIGENTE',
    default => 'SEM DVA REGISTRADA',
};
$dvaTiming = match ($dvaStatus) {
    DvaStatus::VENCIDA => 'Venceu há ' . abs((int) $daysRemaining) . ' dia(s) (' . $dvaDate . ').',
    DvaStatus::VENCE_HOJE => 'Vence hoje (' . $dvaDate . ').',
    DvaStatus::A_VENCER => 'Vence em ' . (int) $daysRemaining . ' dia(s) (' . $dvaDate . ').',
    DvaStatus::VIGENTE => 'Vence em ' . $dvaDate . '.',
    default => 'Este aluno não possui data de vencimento cadastrada.',
};
$historyTime = static function (?string $value): string {
    if (!$value) { return '—'; }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(Config::string('APP_TIMEZONE', 'America/Cuiaba')))
            ->format('d/m/Y H:i');
    } catch (Throwable) { return 'Data indisponível'; }
};
?>
<div class="profile-shell card-perfil">
<section class="profile-head">
    <div>
        <h2><?= e((string) $student['nome_completo']) ?></h2>
        <p>Resumo completo do cadastro, situação da DVA e canais de contato disponíveis.</p>
    </div>
    <div class="profile-identifiers">
        <span class="perfil-id">ID: #<?= e((string) $student['id']) ?></span>
        <span class="badge-status <?= (int) $student['ativo'] === 1 ? 'badge-ativo' : 'badge-inativo' ?>"><?= (int) $student['ativo'] === 1 ? 'Ativo' : 'Inativo' ?></span>
    </div>
</section>

<section class="status-box dva-status-large dva-<?= e($dvaStatus) ?>" aria-label="Situação atual da DVA">
    <h2><?= e($dvaHeading) ?></h2>
    <p><?php if ($dvaStatus === DvaStatus::VENCIDA): ?>Venceu há <strong><?= abs((int) $daysRemaining) ?> dias</strong> (<?= e($dvaDate) ?>)<?php elseif ($dvaStatus === DvaStatus::A_VENCER): ?>Vence em <strong><?= (int) $daysRemaining ?> dias</strong> (<?= e($dvaDate) ?>)<?php else: ?><?= e($dvaTiming) ?><?php endif; ?></p>
</section>

<dl class="perfil-info-grid">
    <div class="info-item"><dt class="info-label">Nome completo</dt><dd class="info-valor"><?= e((string) $student['nome_completo']) ?></dd></div>
    <div class="info-item"><dt class="info-label">Turma</dt><dd class="info-valor"><?= e((string) ($student['nome_turma'] ?: 'Sem turma')) ?><?= $student['ano_letivo'] ? ' — ' . e((string) $student['ano_letivo']) : '' ?></dd></div>
    <div class="info-item"><dt class="info-label">Data de nascimento</dt><dd class="info-valor"><?= e(date('d/m/Y', strtotime((string) $student['data_nascimento']))) ?></dd></div>
    <div class="info-item"><dt class="info-label">Vencimento DVA</dt><dd class="info-valor"><?= $student['data_vencimento'] ? e(date('d/m/Y', strtotime((string) $student['data_vencimento']))) : '—' ?></dd></div>
    <div class="info-item wide"><dt class="info-label">Observações DVA</dt><dd class="info-valor textual"><?= nl2br(e((string) ($student['dva_observacao'] ?: '-'))) ?></dd></div>
</dl>

<section class="contatos-container" aria-labelledby="contatos-titulo">
    <h2 id="contatos-titulo" class="contatos-titulo">Contatos Registrados</h2>
    <div class="contatos-grid">
        <div class="contato-card">
            <span class="contato-label">Aluno</span>
            <strong class="contato-numero"><?php if ($studentPhoneValid): ?><?= e($phone($studentPhoneDigits)) ?><?php else: ?><span class="contato-vazio">Não informado</span><?php endif; ?></strong>
            <?php if ($studentPhoneValid): ?><a class="btn-whatsapp-full" href="<?= e('https://wa.me/55' . $studentPhoneDigits) ?>" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp do aluno">Chamar Aluno</a><?php endif; ?>
        </div>
        <div class="contato-card">
            <span class="contato-label">Responsável</span>
            <strong class="contato-numero"><?php if ($guardianPhoneValid): ?><?= e($phone($guardianPhoneDigits)) ?><?php else: ?><span class="contato-vazio">Não informado</span><?php endif; ?></strong>
            <?php if ($guardianPhoneValid): ?><a class="btn-whatsapp-full" href="<?= e('https://wa.me/55' . $guardianPhoneDigits) ?>" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp do responsável">Chamar Responsável</a><?php endif; ?>
        </div>
    </div>
</section>

<div class="profile-actions perfil-actions">
    <a class="btn-primary" href="<?= e(url('aluno/editar/' . (int) $student['id'])) ?>">✏️ Editar Dados</a>
    <?php if ((int) $student['ativo'] === 1): ?><a class="btn-secondary" href="<?= e(url('aluno/dva/' . (int) $student['id'])) ?>"><?= $student['dva_id'] ? 'Renovar DVA' : 'Registrar DVA' ?></a><?php endif; ?>
    <?php if ($canManageStudent): ?><form class="student-status-form" method="post" action="<?= e(url('aluno/status/' . (int) $student['id'])) ?>" data-confirm-status="<?= (int) $student['ativo'] === 1 ? 'Inativar este aluno? O histórico será preservado.' : 'Reativar este aluno?' ?>"><input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="ativo" value="<?= (int) $student['ativo'] === 1 ? '0' : '1' ?>"><button class="btn-secondary student-status-button" type="submit"><?= (int) $student['ativo'] === 1 ? '⛔ Inativar aluno' : '✓ Reativar aluno' ?></button></form><?php endif; ?>
    <?php if ($canArchivePassive): ?><details class="profile-more-actions"><summary>Arquivo Passivo</summary><a class="btn-secondary" href="<?= e(url('aluno/arquivar/' . (int) $student['id'])) ?>">Enviar para o Arquivo Passivo</a></details><?php endif; ?>
    <a class="cancelar" href="<?= e(url('aluno')) ?>">Voltar para a Lista</a>
</div>
</div>

<details class="relatorio student-history">
    <summary>Histórico de DVAs</summary>
    <div class="table-scroll">
        <table class="tabela-filtrada"><thead><tr><th>Situação</th><th>Vencimento</th><th>Registrada em</th><th>Substituída em</th><th>Responsável</th><th>Observação</th></tr></thead>
            <tbody>
            <?php if ($history === []): ?><tr><td colspan="6" class="empty-state">Nenhuma DVA registrada.</td></tr><?php endif; ?>
            <?php foreach ($history as $item): ?>
                <tr><td><?= (int) $item['ativo'] === 1 ? 'Atual' : 'Arquivada' ?></td><td><?= e(date('d/m/Y', strtotime((string) $item['data_vencimento']))) ?></td><td><?= e($historyTime($item['criado_em'])) ?></td><td><?= e($historyTime($item['substituido_em'])) ?></td><td><?= e((string) ($item['usuario_registro'] ?: '—')) ?></td><td><?= e((string) ($item['observacao'] ?: '—')) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</details>
