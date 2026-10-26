<section class="cert-panel cert-form-panel">
    <?php $fieldErrors = []; require __DIR__ . '/../partials/form-errors.php'; ?>
    <?php if ($mode === 'renovar'): ?><p class="info-message">Renovação da <a href="<?= e(url('certidao/detalhes/' . (int) $record['id'])) ?>"><?= e($record['tipo_certidao']) ?> de <?= e($record['fornecedor']) ?></a>. A anterior e seu PDF, quando houver, serão preservados no arquivo.</p><?php endif; ?>
    <?php if ($mode === 'editar'): ?><p>Esta tela corrige dados cadastrais e mantém o PDF, quando houver. <?php if ($record['estado'] === 'corrente'): ?>Para registrar um novo documento, utilize <a href="<?= e(url('certidao/renovar/' . (int) $record['id'])) ?>">Renovar</a>.<?php else: ?>Para registrar um novo documento, desarquive a certidão e depois utilize a renovação.<?php endif; ?></p><?php endif; ?>
    <form method="post" enctype="multipart/form-data" action="<?= e(url($path)) ?>" class="cert-form">
        <?php if ($mode === 'editar'): ?><div class="cert-existing-pdf <?= !empty($record['pdf_privado']) ? 'available' : 'missing' ?>">
            <span><?= !empty($record['pdf_privado']) ? 'Consulte o PDF atual desta certidão.' : (!empty($record['arquivo_pdf']) ? 'O PDF legado aguarda migração para o armazenamento privado.' : 'Esta certidão não possui PDF anexado.') ?></span>
            <?php if (!empty($record['pdf_privado'])): ?><div class="cert-pdf-actions"><a href="<?= e(url('certidao/pdf/' . (int) $record['id'])) ?>" target="_blank" rel="noopener">Visualizar PDF</a><a href="<?= e(url('certidao/pdf/' . (int) $record['id'] . '?baixar=1')) ?>">Baixar PDF</a></div><?php endif; ?>
        </div><?php endif; ?>
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="revisao" value="<?= e((string) ($data['revisao'] ?? '')) ?>">
        <?php if (!empty($conflictReview)): ?>
            <section class="conflict-review cert-conflict-review" data-conflict-review data-source-revision="<?= e($conflictReview['sourceRevision']) ?>" aria-labelledby="cert-conflict-title">
                <h2 id="cert-conflict-title">Confira as duas versões da certidão</h2>
                <p>Nenhuma alteração desta tentativa foi salva. Seu preenchimento foi preservado nos campos abaixo. Compare com os dados atuais e revise o que deseja salvar.</p>
                <div class="table-scroll"><table><thead><tr><th>Campo</th><th>Dados atuais</th><th>Sua tentativa</th></tr></thead><tbody>
                    <?php foreach (['id_fornecedor' => 'Fornecedor', 'id_tipo_certidao' => 'Tipo de certidão', 'data_emissao' => 'Emissão', 'data_vencimento' => 'Vencimento', 'observacao' => 'Observação'] as $field => $label): ?>
                    <tr><th scope="row"><?= e($label) ?></th>
                        <?php foreach (['current', 'attempt'] as $version): ?>
                        <?php
                        $value = (string) ($conflictReview[$version][$field] ?? '');
                        if (in_array($field, ['id_fornecedor', 'id_tipo_certidao'], true)) {
                            $options = $field === 'id_fornecedor' ? $fornecedores : $tipos;
                            $matches = array_values(array_filter($options, static fn (array $option): bool => (string) $option['id'] === $value));
                            $value = (string) ($matches[0]['nome'] ?? 'Opção indisponível');
                        } elseif (in_array($field, ['data_emissao', 'data_vencimento'], true)) {
                            $value = UiPresentation::civilDate($value);
                        }
                        ?>
                        <td><?= e($value) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody></table></div>
                <input type="hidden" name="_conflict_token" value="<?= e($conflictReview['token']) ?>">
                <label><input type="checkbox" name="confirmar_revisao" value="1" required> Conferi os dados atuais e revisei o preenchimento que desejo salvar.</label>
            </section>
        <?php endif; ?>
        <div class="cert-form-grid">
            <?php foreach (['id_fornecedor' => ['Fornecedor', $fornecedores], 'id_tipo_certidao' => ['Tipo de Certidão', $tipos]] as $field => [$label, $options]): ?>
                <div><label for="<?= e($field) ?>"><?= e($label) ?>:</label><select id="<?= e($field) ?>" name="<?= e($field) ?>" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($options as $option): ?>
                        <?php if ((int) $option['ativo'] !== 1 && (string) ($data[$field] ?? '') !== (string) $option['id']) { continue; } ?>
                        <?php if ($mode === 'renovar' && (int) $record[$field] !== (int) $option['id']) { continue; } ?>
                        <option value="<?= (int) $option['id'] ?>" <?= (string) ($data[$field] ?? '') === (string) $option['id'] ? 'selected' : '' ?>><?= e($option['nome']) ?><?= (int) $option['ativo'] === 0 ? ' (inativo)' : '' ?></option>
                    <?php endforeach; ?>
                </select></div>
            <?php endforeach; ?>
        </div>
        <div class="cert-form-dates">
            <div><label for="data_emissao">Emissão:</label><input type="date" id="data_emissao" name="data_emissao" required value="<?= e((string) ($data['data_emissao'] ?? '')) ?>"></div>
            <div><label for="data_vencimento">Vencimento:</label><input type="date" id="data_vencimento" name="data_vencimento" required value="<?= e((string) ($data['data_vencimento'] ?? '')) ?>"></div>
            <div><label for="cert-validity-days">Validade (Dias):</label><input type="text" id="cert-validity-days" value="..." readonly aria-label="Dias entre emissão e vencimento"></div>
        </div>
        <?php if ($mode !== 'editar'): ?><div class="cert-upload"><label for="arquivo_pdf"><i class="cert-icon cert-icon-paperclip" aria-hidden="true"></i> Anexar PDF (Opcional): <small>até <?= e((string) round((int) Config::string('CERTIDAO_PDF_MAX_BYTES', '10485760') / 1048576, 2)) ?> MiB</small></label><input type="file" id="arquivo_pdf" name="arquivo_pdf" accept="application/pdf,.pdf" aria-describedby="pdf-help" data-pdf-input><div class="cert-pdf-preview" data-pdf-preview hidden><div class="cert-pdf-preview-head"><div><span>Pré-visualização para conferência</span><strong data-pdf-name></strong><small data-pdf-meta></small></div><button type="button" class="btn-secondary" data-pdf-clear>Remover seleção</button></div><iframe data-pdf-frame title="Prévia do PDF selecionado"></iframe><a data-pdf-open href="#" target="_blank" rel="noopener noreferrer" hidden>Abrir PDF para conferir</a><p class="cert-pdf-preview-help">Confira o documento antes de salvar a certidão.</p></div></div><?php endif; ?>
        <div class="cert-form-observation"><label for="observacao">Observação:</label><textarea id="observacao" name="observacao" rows="3" maxlength="2000" placeholder="Adicione notas adicionais aqui..."><?= e((string) ($data['observacao'] ?? '')) ?></textarea></div>
        <div class="cert-form-footer"><button type="submit" class="btn-primary"><i class="cert-icon cert-icon-check" aria-hidden="true"></i> <?= match ($mode) { 'renovar' => 'Salvar nova e arquivar anterior', 'editar' => 'Atualizar dados', default => 'Salvar Certidão' } ?></button><a href="<?= e(url('certidao')) ?>"><i class="cert-icon cert-icon-arrow-left" aria-hidden="true"></i> Voltar</a></div>
        <?php if ($mode !== 'editar'): ?><details class="cert-upload-help"><summary>Orientações para anexar o PDF</summary><p id="pdf-help">O PDF é opcional. O limite é <?= e((string) round((int) Config::string('CERTIDAO_PDF_MAX_BYTES', '10485760') / 1048576, 2)) ?> MiB. Use um nome simples com uma única extensão .pdf. Após um erro, selecione o arquivo novamente.</p></details><?php endif; ?>
    </form>
</section>
