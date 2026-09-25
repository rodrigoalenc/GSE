<section class="relatorio">
    <p><a href="<?= e(url($record===null ? 'contrato' : 'contrato/detalhes/'.$record['id'])) ?>">Voltar</a></p>
    <form method="post" action="<?= e(url($record===null ? 'contrato/criar' : 'contrato/editar/'.$record['id'])) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <?php if ($record!==null): ?><input type="hidden" name="revisao" value="<?= (int)$record['revisao'] ?>"><?php endif; ?>
        <div class="mb-3"><label for="titulo">Título</label><input class="form-control" id="titulo" name="titulo" maxlength="150" required value="<?= e($record['titulo'] ?? '') ?>"></div>
        <div class="mb-3"><label for="valor">Valor total contratado (R$, vírgula decimal)</label><input class="form-control" id="valor" name="valor" inputmode="decimal" required value="<?= e($record===null || $record['valor_centavos']===null ? '' : number_format((int)$record['valor_centavos']/100,2,',','')) ?>"></div>
        <div class="mb-3"><label for="fornecedor">Fornecedor</label><select class="form-select" id="fornecedor" name="fornecedor"><option value="">Não informado</option><?php foreach ($suppliers as $supplier): if (!(int)$supplier['ativo'] && ($record===null || (int)$record['id_fornecedor']!==(int)$supplier['id'])) { continue; } ?><option value="<?= (int)$supplier['id'] ?>" <?= $record!==null && (int)$record['id_fornecedor']===(int)$supplier['id'] ? 'selected' : '' ?>><?= e($supplier['nome']) ?><?= (int)$supplier['ativo']===0 ? ' (inativo; vínculo histórico)' : '' ?></option><?php endforeach; ?></select><small>Fornecedores são geridos em Certidões e Fornecedores.</small></div>
        <button class="btn btn-primary" type="submit">Salvar contrato</button>
    </form>
</section>
