<?php
$detailDate = static fn ($value): string => CertidaoStatus::validDate((string) $value)
    ? (new DateTimeImmutable((string) $value))->format('d/m/Y') : 'Data pendente';
require __DIR__.'/nav.php';
?>
<section class="cert-panel cert-detail">
    <h2><?= e($record['tipo_certidao']) ?> · <?= e($record['fornecedor']) ?></h2>
    <dl class="cert-details"><dt>Ciclo de vida</dt><dd><?= e(['corrente'=>'Corrente','arquivada'=>'Arquivada','excluida'=>'Excluída logicamente'][$record['estado']]) ?></dd><dt>Validade</dt><dd><span class="cert-detail-status cert-<?= e($record['validade']) ?>"><?= e(CertidaoStatus::LABELS[$record['validade']]) ?></span></dd><dt>Emissão</dt><dd><?= e($detailDate($record['data_emissao'])) ?></dd><dt>Vencimento</dt><dd><?= e($detailDate($record['data_vencimento'])) ?></dd><dt>Observações</dt><dd class="cert-notes"><?= e((string)($record['observacao'] ?: '—')) ?></dd></dl>
    <?php if ($record['anterior_id']): ?><p>Renova a <a href="<?= e(url('certidao/detalhes/'.(int)$record['anterior_id'])) ?>">certidão #<?= (int)$record['anterior_id'] ?></a>.</p><?php endif; ?>
    <?php if ($record['pdf_privado']): ?><p>Arquivo: <?= e((string)$record['pdf_nome']) ?> · <?= e((string)$record['pdf_bytes']) ?> bytes</p><a class="cert-detail-pdf" href="<?= e(url('certidao/pdf/'.(int)$record['id'])) ?>">Baixar PDF</a><?php elseif (!empty($record['arquivo_pdf'])): ?><p class="warning-message">O PDF legado precisa de migração para o armazenamento privado.</p><?php else: ?><p>Sem PDF anexado.</p><?php endif; ?>
    <?php if ($record['estado'] === 'corrente'): ?><div class="cert-actions"><a class="btn-secondary" href="<?= e(url('certidao/editar/'.(int)$record['id'])) ?>">Editar dados</a><a class="btn-primary" href="<?= e(url('certidao/renovar/'.(int)$record['id'])) ?>">Renovar certidão</a></div><?php elseif ($record['estado'] === 'arquivada'): ?><div class="cert-actions"><a class="btn-secondary" href="<?= e(url('certidao/editar/'.(int)$record['id'])) ?>">Editar dados</a></div><?php endif; ?>
</section>
<?php if ($record['estado'] !== 'excluida'): ?><section class="cert-panel"><h2>Organização e histórico</h2><div class="cert-form-grid">
    <?php foreach (['arquivar'=>'Arquivar','desarquivar'=>'Desarquivar','excluir'=>'Excluir logicamente'] as $action=>$label): ?>
        <?php if ($action === 'arquivar' && $record['estado'] !== 'corrente') { continue; } ?>
        <?php if ($action === 'desarquivar' && $record['estado'] !== 'arquivada') { continue; } ?>
        <form id="cert-<?= e($action) ?>" method="post" action="<?= e(url('certidao/'.$action.'/'.(int)$record['id'])) ?>" class="cert-confirm cert-confirm-<?= e($action) ?>">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="revisao" value="<?= (int)$record['revisao'] ?>">
            <h3><?= e($label) ?></h3><p><?= match ($action) { 'arquivar' => 'Retira da matriz corrente e mantém no arquivo histórico.', 'desarquivar' => 'Devolve o registro à matriz corrente, preservando seu documento e histórico.', default => 'Retira das consultas correntes e arquivadas. O documento permanece na consulta de excluídas.' } ?></p>
            <label><input type="checkbox" name="confirmar" value="1" required> Confirmo <?= e(mb_strtolower($label)) ?> esta certidão.</label><button type="submit" class="btn-secondary"><?= e($label) ?></button>
        </form>
    <?php endforeach; ?>
</div></section><?php endif; ?>
