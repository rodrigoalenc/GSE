<?php $params = http_build_query($result['filters']); ?>
<section class="relatorio-hero">
    <div><h2>Gerador central de relatórios da secretaria</h2><p>Monte exportações por turma e situação da DVA em poucos cliques, com saída pronta para PDF ou CSV.</p></div>
    <div class="relatorio-hero-stats">
        <div class="relatorio-stat"><strong><?= count($classes) ?></strong><span>Turmas</span></div>
        <div class="relatorio-stat"><strong><?= count(DvaStatus::ALL) ?></strong><span>Status de DVA</span></div>
        <div class="relatorio-stat"><strong>2</strong><span>Formatos</span></div>
    </div>
</section>
<div class="box-relatorio">
    <form action="<?= e(url('relatorio/pdf')) ?>" method="get" class="sistema report-generator" data-report-generator data-pdf-url="<?= e(url('relatorio/pdf')) ?>" data-csv-url="<?= e(url('relatorio/csv')) ?>">
        <div class="report-filter-group"><label for="turma" class="label-destaque">1. Filtrar por Turma (Opcional):</label><select id="turma" name="turma" class="select-grande"><option value="">Todas as Turmas</option><?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>" <?= (string) $result['filters']['turma'] === (string) $class['id'] ? 'selected' : '' ?>><?= e($class['nome_turma']) ?></option><?php endforeach; ?></select></div>
        <div class="report-filter-group"><label for="dva" class="label-destaque">2. Status da DVA:</label><select id="dva" name="dva" class="select-grande"><option value="">Todos (Geral)</option><?php foreach (DvaStatus::ALL as $status): ?><option value="<?= e($status) ?>" <?= $result['filters']['dva'] === $status ? 'selected' : '' ?>><?= e(DvaStatus::label($status)) ?></option><?php endforeach; ?></select></div>
        <div class="report-filter-group"><label for="ativo" class="label-destaque">3. Situação do Aluno:</label><select id="ativo" name="ativo" class="select-grande"><option value="1" <?= $result['filters']['ativo'] === '1' ? 'selected' : '' ?>>Ativos</option><option value="0" <?= $result['filters']['ativo'] === '0' ? 'selected' : '' ?>>Inativos</option><option value="todos" <?= $result['filters']['ativo'] === 'todos' ? 'selected' : '' ?>>Todos</option></select></div>
        <fieldset class="report-format-group"><legend class="label-destaque">4. Formato do Arquivo:</legend><div class="opcoes-formato"><label class="radio-card pdf"><input type="radio" name="formato" value="pdf" checked> 📄 PDF</label><label class="radio-card excel"><input type="radio" name="formato" value="csv"> 📊 CSV</label></div></fieldset>
        <button type="submit" class="btn-primary btn-gerar">📥 Gerar Relatório</button>
        <div class="report-secondary-actions"><button type="submit" class="btn-secondary" formaction="<?= e(url('relatorio')) ?>">Atualizar prévia</button><button type="submit" class="btn-secondary" data-report-csv formaction="<?= e(url('relatorio/csv')) ?>">Baixar CSV</button></div>
        <p class="report-limits">PDF: até 500 alunos. CSV: até 10.000 alunos. Para resultados maiores, escolha uma turma ou refine a situação.</p>
    </form>
</div>
<section class="relatorio report-preview" aria-labelledby="report-preview-title">
    <div class="section-head"><div><h2 id="report-preview-title">Prévia do relatório</h2><p><?= (int) $result['total'] ?> resultado(s) no filtro. Consulta de DVAs correntes. Cada aluno aparece uma vez.</p></div><button class="btn-secondary" type="button" data-print-page>Imprimir prévia</button></div>
    <p class="modulo5-hint">Exportações incluem todas as páginas e refletem o momento da geração.</p>
    <div class="table-scroll"><table class="tabela-filtrada"><thead><tr><th>Aluno</th><th>Turma</th><th>Nascimento</th><th>Vencimento DVA</th><th>Situação DVA</th><th>Situação aluno</th></tr></thead><tbody><?php foreach ($result['items'] as $row): ?><tr><td><?= e($row['nome_completo']) ?></td><td><?= e($row['nome_turma'] ?? 'Sem turma') ?></td><td><?= e(RelatorioAlunos::displayDate($row['data_nascimento'])) ?></td><td><?= e(RelatorioAlunos::displayDate($row['data_vencimento'])) ?></td><td><?= e(DvaStatus::label($row['dva_status'])) ?></td><td><?= $row['ativo'] ? 'Ativo' : 'Inativo' ?></td></tr><?php endforeach; ?><?php if ($result['items'] === []): ?><tr><td colspan="6" class="empty-state">Nenhum aluno encontrado para os filtros.</td></tr><?php endif; ?></tbody></table></div>
    <nav class="contract-pagination" aria-label="Páginas do relatório"><?php for ($p = 1; $p <= $result['pages']; $p++): ?><a class="btn-secondary <?= $p === $result['page'] ? 'active' : '' ?>" href="<?= e(url('relatorio?' . $params . '&pagina=' . $p)) ?>"><?= $p ?></a><?php endfor; ?></nav>
</section>
