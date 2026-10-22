<?php $isBulkPreview = is_array($preview) && !empty($preview['todas_turmas']); ?>
<section id="<?= $isBulkPreview ? 'movement-preview' : 'movement-all' ?>" class="relatorio movement-panel <?= $isBulkPreview ? 'movement-preview' : '' ?>" aria-labelledby="movement-all-title">
    <div class="section-head"><div><h2 id="movement-all-title"><?= $isBulkPreview ? 'Conferir progressão de todas as turmas' : 'Progredir todas as turmas de uma só vez' ?></h2>
    <p><?= $isBulkPreview ? 'Confira os destinos e os alunos que permanecerão antes de confirmar. Nada foi alterado ainda.' : 'Cada turma avança automaticamente para a série seguinte. Marque somente os alunos que devem permanecer na série.' ?></p></div></div>
    <?php if ($isBulkPreview): ?>
        <p><strong><?= e((string) $preview['total']) ?> aluno(s) vão progredir</strong> — referência <?= e((string) $preview['ano']) ?>.</p>
        <?php foreach ($preview['grupos'] as $group): ?>
        <div class="movement-bulk-class">
            <h3><?= e($labelClass($group['alunos'][0])) ?> → <?= e($labelClass($group['destino'])) ?></h3>
            <p><?= e((string) count($group['alunos'])) ?> aluno(s) — <?= e($series[(int) $group['alunos'][0]['serie']]) ?> → <?= e($series[(int) $group['destino']['serie']]) ?></p>
            <ul class="movement-bulk-names"><?php foreach ($group['alunos'] as $student): ?><li><?= e((string) $student['nome_completo']) ?></li><?php endforeach; ?></ul>
        </div>
        <?php endforeach; ?>
        <?php $remaining = $preview['permanecem']; ?>
        <form method="post" action="<?= e(url('aluno/movimentar/confirmar')) ?>">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="ano" value="<?= e((string) $preview['ano']) ?>"><input type="hidden" name="tipo" value="progressao"><input type="hidden" name="todas_turmas" value="1"><input type="hidden" name="preview_token" value="<?= e($previewToken) ?>">
            <label class="movement-check"><input type="checkbox" name="confirmar" value="1" required> Conferi todos os destinos e os alunos que permanecerão. Autorizo a progressão dos <?= e((string) $preview['total']) ?> aluno(s) listados.</label>
            <div class="form-actions"><button class="btn-primary" type="submit">Confirmar progressão de todas as turmas</button><a class="btn-secondary" href="<?= e(url($basePath . '#movement-all-title')) ?>">Voltar e ajustar destinos ou alunos</a></div>
        </form>
    <?php else: ?>
        <?php $remaining = $bulkOptions['permanecem']; ?>
        <?php if (!$state['liberada']): ?><p class="warning-message">Conclua o envio dos concluintes do 3º ano em “Mandar para o passivo” e identifique as séries pendentes para liberar a progressão de todas as turmas.</p><?php endif; ?>
        <?php if ($bulkOptions['turmas'] === []): ?><p>Nenhuma turma tem alunos disponíveis para progredir neste ano de referência.</p><?php else: ?>
        <form method="post" action="<?= e(url('aluno/movimentar/preview')) ?>" data-movement-bulk>
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="ano" value="<?= e((string) $year) ?>"><input type="hidden" name="tipo" value="progressao"><input type="hidden" name="todas_turmas" value="1">
            <p class="form-help">O sistema identifica a turma correspondente, como 1º A → 2º A, considerando também o turno e o ano letivo. Só pede sua escolha quando não consegue determinar um único destino.</p>
            <?php foreach ($bulkOptions['turmas'] as $group): ?>
            <?php $source = (int) $group['origem_id']; $selected = (int) ($bulkDraft['destinos'][$source] ?? $group['sugerido']); ?>
            <div class="movement-bulk-class" data-bulk-class>
                <h3><?= e($labelClass($group['origem'])) ?></h3>
                <p><?= e($series[(int) $group['origem']['serie']]) ?> — <?= e((string) count($group['alunos'])) ?> aluno(s) disponíveis.</p>
                <?php if ($group['sugerido'] > 0): ?>
                <?php $automatic = array_column($group['destinos'], null, 'id')[$group['sugerido']]; ?>
                <div class="movement-automatic" data-bulk-automatic="<?= e((string) $automatic['id']) ?>"><p>Destino identificado automaticamente</p><strong><?= e($labelClass($automatic)) ?></strong><p><?= e($series[(int) $automatic['serie']]) ?></p></div>
                <?php elseif ($group['destinos'] !== []): ?>
                <p class="warning-message">Não foi possível identificar uma única turma correspondente. Escolha o destino desta turma.</p>
                <label for="bulk-destination-<?= e((string) $source) ?>">Resolver destino — uma série acima</label>
                <select id="bulk-destination-<?= e((string) $source) ?>" name="destinos[<?= e((string) $source) ?>]" data-bulk-destination>
                    <option value="">Selecione o destino</option><?php foreach ($group['destinos'] as $destination): ?><option value="<?= e((string) $destination['id']) ?>" <?= $selected === (int) $destination['id'] ? 'selected' : '' ?>><?= e($labelClass($destination)) ?> — <?= e($series[(int) $destination['serie']]) ?></option><?php endforeach; ?>
                </select>
                <?php endif; ?>
                <?php if ($group['destinos'] === []): ?><p class="warning-message">Não há turma ativa da série seguinte cadastrada. <a href="<?= e(url('turma')) ?>">Confira as turmas</a> ou marque todos os alunos desta turma para permanecer.</p><?php endif; ?>
                <p><strong>Marque quem deve permanecer na série:</strong></p>
                <ul class="movement-bulk-pupils"><?php foreach ($group['alunos'] as $student): ?><li><label class="movement-check"><input type="checkbox" name="manter[]" value="<?= e((string) $student['id']) ?>" <?= in_array((string) $student['id'], array_map('strval', $bulkDraft['manter'] ?? []), true) ? 'checked' : '' ?>> <?= e((string) $student['nome_completo']) ?> — permanecer</label></li><?php endforeach; ?></ul>
                <p data-bulk-count role="status" aria-live="polite"></p>
            </div>
            <?php endforeach; ?>
            <p data-bulk-total role="status" aria-live="polite"></p>
            <input type="hidden" name="todas_completo" value="1">
            <button class="btn-primary" type="submit" <?= !$state['liberada'] ? 'disabled' : '' ?>>Conferir progressão de todas as turmas</button>
        </form>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($remaining !== []): ?>
    <div class="movement-bulk-remaining"><h3>Alunos que permanecerão nas turmas atuais</h3>
        <ul class="movement-bulk-names"><?php foreach ($remaining as $student): ?><li><strong><?= e((string) $student['nome_completo']) ?></strong> — <?= e((string) ($student['nome_turma'] ?? 'Sem turma')) ?>. <?= e((string) ($student['motivo'] ?? 'Marcado para permanecer na série')) ?>.</li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
</section>
