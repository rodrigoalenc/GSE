<?php require __DIR__.'/nav.php'; ?>
<section class="cert-panel cert-form-panel">
    <h2><?= e($title) ?></h2>
    <?php if ($mode === 'renovar'): ?><p class="info-message">Renovação da <a href="<?= e(url('certidao/detalhes/'.(int)$record['id'])) ?>">certidão #<?= (int)$record['id'] ?></a>. A anterior e seu PDF serão preservados no arquivo.</p><?php endif; ?>
    <?php if ($mode === 'editar'): ?><p>Esta tela corrige dados cadastrais e mantém o PDF existente. Para um novo documento, utilize <a href="<?= e(url('certidao/renovar/'.(int)$record['id'])) ?>">Renovar</a>.</p><?php endif; ?>
    <form method="post" enctype="multipart/form-data" action="<?= e(url($path)) ?>" class="cert-form">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="revisao" value="<?= e((string)($data['revisao'] ?? '')) ?>">
        <div class="cert-form-grid">
        <?php foreach (['id_fornecedor'=>['Fornecedor',$fornecedores],'id_tipo_certidao'=>['Tipo de certidão',$tipos]] as $field=>[$label,$options]): ?>
            <div><label for="<?= e($field) ?>"><?= e($label) ?></label><select id="<?= e($field) ?>" name="<?= e($field) ?>" required>
                <option value="">Selecione</option>
                <?php foreach ($options as $option): ?>
                    <?php if ((int)$option['ativo'] !== 1 && (string)($data[$field] ?? '') !== (string)$option['id']) { continue; } ?>
                    <?php if ($mode === 'renovar' && (int)$record[$field] !== (int)$option['id']) { continue; } ?>
                    <option value="<?= (int)$option['id'] ?>" <?= (string)($data[$field] ?? '') === (string)$option['id'] ? 'selected' : '' ?>><?= e($option['nome']) ?><?= (int)$option['ativo'] === 0 ? ' (inativo)' : '' ?></option>
                <?php endforeach; ?>
            </select></div>
        <?php endforeach; ?>
        <div><label for="data_emissao">Data de emissão</label><input type="date" id="data_emissao" name="data_emissao" required value="<?= e((string)($data['data_emissao'] ?? '')) ?>"></div>
        <div><label for="data_vencimento">Data de vencimento</label><input type="date" id="data_vencimento" name="data_vencimento" required value="<?= e((string)($data['data_vencimento'] ?? '')) ?>"></div>
        </div>
        <div><label for="observacao">Observações</label><textarea id="observacao" name="observacao" rows="4" maxlength="2000"><?= e((string)($data['observacao'] ?? '')) ?></textarea></div>
        <?php if ($mode !== 'editar'): ?><div class="cert-upload"><label for="arquivo_pdf">Documento PDF obrigatório</label><input type="file" id="arquivo_pdf" name="arquivo_pdf" accept="application/pdf,.pdf" required aria-describedby="pdf-help"><p id="pdf-help">Limite configurado: <?= e((string)round((int)Config::string('CERTIDAO_PDF_MAX_BYTES','10485760')/1048576,2)) ?> MiB. Use um nome simples com uma única extensão .pdf. Após um erro, selecione o arquivo novamente.</p></div><?php endif; ?>
        <div class="cert-actions"><button type="submit" class="btn-primary"><?= $mode === 'renovar' ? 'Salvar nova e arquivar anterior' : 'Salvar certidão' ?></button><a class="btn-secondary" href="<?= e(url('certidao')) ?>">Cancelar</a></div>
    </form>
</section>
