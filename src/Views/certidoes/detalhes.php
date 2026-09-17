<?php require __DIR__.'/nav.php'; ?>
<section class="cert-panel cert-detail">
    <h2><?= e($record['tipo_certidao']) ?> · <?= e($record['fornecedor']) ?></h2>
    <dl class="cert-details"><dt>Ciclo de vida</dt><dd><?= e(['corrente'=>'Corrente','arquivada'=>'Arquivada','excluida'=>'Excluída logicamente'][$record['estado']]) ?></dd><dt>Validade</dt><dd><?= e(CertidaoStatus::LABELS[$record['validade']]) ?></dd><dt>Emissão</dt><dd><?= e($record['data_emissao']) ?></dd><dt>Vencimento</dt><dd><?= e($record['data_vencimento']) ?></dd><dt>Observações</dt><dd class="cert-notes"><?= e((string)($record['observacao'] ?? '')) ?></dd></dl>
    <?php if ($record['anterior_id']): ?><p>Renova a <a href="<?= e(url('certidao/detalhes/'.(int)$record['anterior_id'])) ?>">certidão #<?= (int)$record['anterior_id'] ?></a>.</p><?php endif; ?>
    <?php if ($record['pdf_privado']): ?><p>Arquivo: <?= e((string)$record['pdf_nome']) ?> · <?= e((string)$record['pdf_bytes']) ?> bytes</p><a class="btn-primary" href="<?= e(url('certidao/pdf/'.(int)$record['id'])) ?>">Baixar PDF</a><?php else: ?><p class="warning-message">PDF pendente: registro legado sem documento privado disponível. Solicite revisão ou migração ao administrador.</p><?php endif; ?>
    <?php if ($record['estado'] === 'corrente'): ?><div class="cert-actions"><a class="btn-secondary" href="<?= e(url('certidao/editar/'.(int)$record['id'])) ?>">Editar dados</a><a class="btn-primary" href="<?= e(url('certidao/renovar/'.(int)$record['id'])) ?>">Renovar com novo PDF</a></div><?php endif; ?>
</section>
<?php if ($record['estado'] !== 'excluida'): ?><section class="cert-panel"><h2>Organização e histórico</h2><div class="cert-form-grid">
    <?php foreach (['arquivar'=>'Arquivar','excluir'=>'Excluir logicamente'] as $action=>$label): ?>
        <?php if ($action === 'arquivar' && $record['estado'] !== 'corrente') { continue; } ?>
        <form method="post" action="<?= e(url('certidao/'.$action.'/'.(int)$record['id'])) ?>" class="cert-confirm">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="revisao" value="<?= (int)$record['revisao'] ?>">
            <h3><?= e($label) ?></h3><p><?= $action === 'arquivar' ? 'Retira da matriz corrente e mantém no arquivo histórico.' : 'Retira das consultas correntes e arquivadas. O documento permanece na consulta de excluídas.' ?></p>
            <label><input type="checkbox" name="confirmar" value="1" required> Confirmo <?= e(mb_strtolower($label)) ?> esta certidão.</label><button type="submit" class="btn-secondary"><?= e($label) ?></button>
        </form>
    <?php endforeach; ?>
</div></section><?php endif; ?>
