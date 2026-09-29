<?php
$state = $filters['estado'];
$route = match ($state) { 'arquivada' => 'certidao/arquivadas', 'excluida' => 'certidao/excluidas', default => 'certidao' };
$columns = [];
$rows = [];
$cells = [];
$documentPages = [];
foreach ($result['suppliers'] ?? [] as $supplier) {
    $columns[(int) $supplier['id']] = $supplier['nome'];
    $documentPages[(int) $supplier['id']] = (int) $supplier['page'];
}
foreach ($result['items'] as $item) {
    $rows[(int) $item['id_tipo_certidao']] = $item['tipo_certidao'];
    $cells[(int) $item['id_tipo_certidao']][(int) $item['id_fornecedor']][] = $item;
}
$dates = new CertidaoStatus();
$displayDate = static fn ($value): string => CertidaoStatus::validDate((string) $value) ? (new DateTimeImmutable($value))->format('d/m/y') : 'Data pendente';
$displayFullDate = static fn ($value): string => CertidaoStatus::validDate((string) $value) ? (new DateTimeImmutable($value))->format('d/m/Y') : 'Data pendente';
$statusUrl = static fn (string $validade): string => url($route . '?' . http_build_query(array_merge($filters, ['validade' => $validade, 'page' => 1])));
$activeSuppliers = count(array_filter($fornecedores, static fn (array $option): bool => (int) $option['ativo'] === 1));
$activeTypes = count(array_filter($tipos, static fn (array $option): bool => (int) $option['ativo'] === 1));
$overviewTitle = match ($state) { 'arquivada' => 'Histórico completo das certidões movimentadas', 'excluida' => 'Certidões excluídas', default => 'Painel central das certidões' };
$overviewDescription = match ($state) { 'arquivada' => 'Consulte documentos arquivados por ano e mantenha o histórico sem perder rastreabilidade.', 'excluida' => 'Consulte os registros excluídos e seus documentos preservados.', default => 'Acompanhe rapidamente os documentos ativos, filtre por fornecedor e destaque o que precisa de renovação ou atenção imediata.' };
?>

<section class="cert-overview" aria-label="Resumo das certidões">
    <div><h2><?= e($overviewTitle) ?></h2><p><?= e($overviewDescription) ?></p></div>
    <?php if ($state === 'corrente'): ?><div class="cert-stats"><span><strong><?= array_sum($summary) ?></strong>Certidões correntes</span><span><strong><?= $activeSuppliers ?></strong>Fornecedores ativos</span><span><strong><?= $activeTypes ?></strong>Tipos ativos</span></div>
    <?php else: ?><div class="cert-stats"><span><strong><?= (int) $result['total'] ?></strong>Registros no filtro</span><span><strong><?= $filters['ano'] === '' || $filters['ano'] === 'todos' ? 'Todos' : e($filters['ano']) ?></strong>Ano selecionado</span><span><strong><?= count($years) ?></strong>Anos disponíveis</span></div><?php endif; ?>
</section>

<section id="cert-workspace" class="cert-workspace" aria-label="Consulta de certidões">
    <div class="cert-fullscreen-status-bar" aria-label="<?= $state === 'corrente' ? 'Filtros da tela cheia' : 'Controle da tela cheia' ?>" hidden>
        <?php if ($state === 'corrente'): ?>
        <button type="button" class="cert-fullscreen-status-btn is-active" data-cert-fullscreen-filter="all" aria-pressed="true">Todas</button>
        <button type="button" class="cert-fullscreen-status-btn" data-cert-fullscreen-filter="vigente" aria-pressed="false">Vigentes</button>
        <button type="button" class="cert-fullscreen-status-btn" data-cert-fullscreen-filter="a_vencer" aria-pressed="false">A vencer</button>
        <button type="button" class="cert-fullscreen-status-btn" data-cert-fullscreen-filter="vencida" aria-pressed="false">Vencidas</button>
        <?php endif; ?>
        <button type="button" class="cert-fullscreen-exit-btn" data-cert-fullscreen-exit>Sair da tela cheia</button>
    </div>
    <div class="cert-panel cert-toolbar">
        <div class="cert-toolbar-heading">
            <div><h2><?= $state === 'corrente' ? 'Ações rápidas' : 'Filtro do histórico' ?></h2><p><?= $state === 'corrente' ? 'Cadastre, configure ou consulte o histórico sem sair do módulo.' : 'Selecione um ano específico ou veja todo o acervo.' ?></p></div>
            <nav class="cert-quick-actions" aria-label="Ações das certidões">
                <a class="btn-primary" href="<?= e(url('certidao/cadastrar')) ?>">＋ Nova Certidão</a>
                <a class="btn-secondary" href="<?= e(url('certidao/configurar')) ?>">⚙ Tipos/Fornecedores</a>
                <a class="btn-secondary" href="<?= e(url('certidao/arquivadas')) ?>">▣ Arquivadas</a>
                <a class="btn-secondary" href="<?= e(url('certidao/excluidas')) ?>">Excluídas</a>
                <?php if ($state !== 'corrente'): ?><a class="btn-secondary" href="<?= e(url('certidao')) ?>">Matriz</a><?php endif; ?>
            </nav>
        </div>
        <div class="cert-toolbar-controls">
            <?php if ($state !== 'corrente'): ?><form method="get" action="<?= e(url($route)) ?>" class="cert-year-filter">
                <?php foreach (['fornecedor', 'tipo', 'validade', 'busca', 'pendencias'] as $field): ?><?php if ($filters[$field] !== ''): ?><input type="hidden" name="<?= e($field) ?>" value="<?= e($filters[$field]) ?>"><?php endif; ?><?php endforeach; ?>
                <label for="cert-year">Ano do histórico:</label><select id="cert-year" name="ano" data-cert-auto-submit><option value="todos" <?= $filters['ano'] === '' || $filters['ano'] === 'todos' ? 'selected' : '' ?>>Todos os Anos</option><?php foreach ($years as $year): ?><option value="<?= e($year) ?>" <?= $filters['ano'] === $year ? 'selected' : '' ?>><?= e($year) ?></option><?php endforeach; ?></select>
                <button class="visually-hidden" type="submit">Aplicar ano</button>
            </form><?php endif; ?>
            <nav class="cert-status-filters" aria-label="Filtrar por validade">
                <a class="cert-filter-chip all <?= $filters['validade'] === '' ? 'active' : '' ?>" href="<?= e($statusUrl('')) ?>">Todas</a>
                <a class="cert-filter-chip expired <?= $filters['validade'] === 'vencida' ? 'active' : '' ?>" href="<?= e($statusUrl('vencida')) ?>">Vencida</a>
                <a class="cert-filter-chip warning <?= $filters['validade'] === 'a_vencer' ? 'active' : '' ?>" href="<?= e($statusUrl('a_vencer')) ?>">A vencer</a>
                <a class="cert-filter-chip valid <?= $filters['validade'] === 'vigente' ? 'active' : '' ?>" href="<?= e($statusUrl('vigente')) ?>">Vigente</a>
            </nav>
            <form method="get" action="<?= e(url($route)) ?>" class="cert-main-filter">
                <?php foreach (['tipo', 'validade', 'ano', 'busca', 'pendencias'] as $field): ?>
                    <?php if ($filters[$field] !== ''): ?><input type="hidden" name="<?= e($field) ?>" value="<?= e($filters[$field]) ?>"><?php endif; ?>
                <?php endforeach; ?>
                <label class="visually-hidden" for="fornecedor">Fornecedor</label>
                <select id="fornecedor" name="fornecedor" data-cert-auto-submit><option value="">Todos os Fornecedores</option><?php foreach ($fornecedores as $option): ?><option value="<?= (int) $option['id'] ?>" <?= (string) $option['id'] === $filters['fornecedor'] ? 'selected' : '' ?>><?= e($option['nome']) ?></option><?php endforeach; ?></select>
                <button class="visually-hidden" type="submit">Aplicar fornecedor</button>
            </form>
            <div class="cert-fullscreen-controls">
                <button type="button" class="btn-secondary" id="cert-fullscreen" aria-pressed="false" aria-controls="cert-workspace" hidden>⛶ Tela cheia</button>
                <span id="cert-fullscreen-status" role="status"></span>
            </div>
            <a class="cert-pending-link <?= $filters['pendencias'] === '1' ? 'active' : '' ?>" href="<?= e(url($route . '?' . http_build_query(array_merge($filters, ['pendencias' => $filters['pendencias'] === '1' ? '' : '1', 'page' => 1])))) ?>">⚠ Somente pendências</a>
        </div>
        <details class="cert-advanced-filters">
            <summary>Mais filtros e opções de consulta</summary>
            <form method="get" action="<?= e(url($route)) ?>" class="cert-filters">
                <div><label for="busca">Buscar fornecedor, tipo ou nº</label><input id="busca" name="busca" maxlength="150" value="<?= e($filters['busca']) ?>"></div>
                <div><label for="tipo">Tipo de certidão</label><select id="tipo" name="tipo"><option value="">Todos</option><?php foreach ($tipos as $option): ?><option value="<?= (int) $option['id'] ?>" <?= (string) $option['id'] === $filters['tipo'] ? 'selected' : '' ?>><?= e($option['nome']) ?></option><?php endforeach; ?></select></div>
                <div><label for="validade">Validade</label><select id="validade" name="validade"><option value="">Todas</option><?php foreach (CertidaoStatus::LABELS as $key => $label): ?><option value="<?= e($key) ?>" <?= $filters['validade'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                <div><label for="ano">Ano de vencimento</label><input id="ano" name="ano" inputmode="numeric" maxlength="4" placeholder="Todos os anos" value="<?= e($filters['ano'] === 'todos' ? '' : $filters['ano']) ?>"></div>
                <?php if ($filters['fornecedor'] !== ''): ?><input type="hidden" name="fornecedor" value="<?= e($filters['fornecedor']) ?>"><?php endif; ?>
                <?php if ($filters['pendencias'] === '1'): ?><input type="hidden" name="pendencias" value="1"><?php endif; ?>
                <div class="cert-filter-actions"><button class="btn-primary" type="submit">Filtrar</button><a href="<?= e(url($route)) ?>">Limpar filtros</a></div>
            </form>
            <p id="pending-help" class="cert-muted">Pendências: vencidas, vencem hoje, a vencer até <?= e($displayDate($dates->limit())) ?> ou com data de vencimento inválida. Combina com os outros filtros. O PDF é opcional.</p>
            <p class="cert-muted">Consulta: <?= e($title) ?> · <?= $filters['pendencias'] === '1' ? 'Somente pendências ativado' : 'Pendências e regulares' ?> · <?= count(array_filter(array_diff_key($filters, ['estado' => true]), static fn ($v) => $v !== '')) ?> filtros preenchidos.</p>
        </details>
    </div>

    <div class="cert-meta"><p>Visualizando <strong><?= (int) $result['total'] ?></strong> <?= $state === 'corrente' ? 'registros distribuídos entre fornecedores e tipos.' : 'registros no histórico selecionado.' ?></p><span><?= count($result['items']) ?> <?= $state === 'corrente' ? 'cartões visíveis' : 'registros nesta página' ?></span></div>
    <?php if ($result['items'] === []): ?>
        <section class="cert-panel empty-state"><h2>Nenhuma certidão encontrada</h2><p><?= $state === 'corrente' ? 'Revise os filtros ou cadastre uma nova certidão.' : 'Revise os filtros ou consulte a matriz de certidões correntes.' ?></p></section>
    <?php elseif ($state !== 'corrente'): ?>
        <div class="cert-archive-scroll" role="region" aria-label="<?= $state === 'arquivada' ? 'Tabela de certidões arquivadas' : 'Tabela de certidões excluídas' ?>" tabindex="0">
            <table class="cert-archive-table">
                <thead><tr><th scope="col">Fornecedor</th><th scope="col">Tipo de Certidão</th><th scope="col">Emissão</th><th scope="col">Vencimento</th><th scope="col">Documento</th><th scope="col">Ações</th></tr></thead>
                <tbody><?php foreach ($result['items'] as $item): ?><tr>
                    <td class="cert-archive-supplier"><?= e($item['fornecedor']) ?></td>
                    <td class="cert-archive-type"><?= e($item['tipo_certidao']) ?></td>
                    <td><span class="cert-archive-date"><?= e($displayFullDate($item['data_emissao'])) ?></span></td>
                    <td><span class="cert-archive-date"><?= e($displayFullDate($item['data_vencimento'])) ?></span></td>
                    <td><?php if (!empty($item['pdf_privado'])): ?><a class="cert-archive-pdf" href="<?= e(url('certidao/pdf/' . (int) $item['id'])) ?>">Baixar PDF</a><?php else: ?><span class="cert-muted"><?= !empty($item['arquivo_pdf']) ? 'PDF legado para revisão' : 'Sem PDF' ?></span><?php endif; ?></td>
                    <td><div class="cert-archive-actions"><a href="<?= e(url('certidao/detalhes/' . (int) $item['id'])) ?>">Detalhes</a><?php if ($state === 'arquivada'): ?><a href="<?= e(url('certidao/editar/' . (int) $item['id'])) ?>">Editar</a><a class="cert-archive-restore" href="<?= e(url('certidao/detalhes/' . (int) $item['id'] . '#cert-desarquivar')) ?>">Desarquivar</a><a class="cert-archive-delete" href="<?= e(url('certidao/detalhes/' . (int) $item['id'] . '#cert-excluir')) ?>">Excluir</a><?php endif; ?></div></td>
                </tr><?php endforeach; ?></tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="cert-matrix-scroll" tabindex="0" role="region" aria-label="Matriz de certidões; use as setas para rolar horizontalmente">
            <table class="cert-matrix"><caption><?= e($title) ?> — até oito fornecedores por página. Use a navegação do cabeçalho para consultar mais documentos de um fornecedor.</caption>
                <thead><tr><th scope="col">Tipo / Fornecedor</th><?php foreach ($result['suppliers'] as $supplier): ?><th scope="col">
                    <?= e($supplier['nome']) ?>
                    <?php if ((int) $supplier['pages'] > 1): ?><nav class="cert-column-pages" aria-label="Documentos de <?= e($supplier['nome']) ?>">
                        <?php foreach ([-1 => '←', 1 => '→'] as $step => $label): $next = $supplier['page'] + $step; if ($next < 1 || $next > $supplier['pages']) { continue; } ?>
                            <a href="<?= e(url($route . '?' . http_build_query(array_merge($filters, ['page' => $result['page'], 'documentos' => array_replace($documentPages, [(int) $supplier['id'] => $next])])))) ?>" aria-label="<?= $step < 0 ? 'Documentos anteriores' : 'Próximos documentos' ?> de <?= e($supplier['nome']) ?>"><?= e($label) ?></a>
                        <?php endforeach; ?>
                    </nav><?php endif; ?>
                </th><?php endforeach; ?></tr></thead>
                <tbody><?php foreach ($rows as $typeId => $name): ?><tr><th scope="row"><?= e($name) ?></th>
                    <?php foreach ($columns as $supplierId => $supplier): ?><td>
                        <?php if (empty($cells[$typeId][$supplierId])): ?><span class="cert-no-document" aria-label="Sem documento">–</span><?php endif; ?>
                        <?php foreach ($cells[$typeId][$supplierId] ?? [] as $item): ?>
                            <article class="cert-card cert-<?= e($item['validade']) ?>" aria-label="<?= e($name . ' de ' . $supplier) ?>">
                                <div class="cert-card-head"><span class="cert-deadline" title="<?= e($dates->deadline($item['data_vencimento'])) ?>"><?= e($dates->badge($item['data_vencimento'])) ?></span></div>
                                <dl class="cert-card-dates"><div><dt>Emissão</dt><dd><?= e($displayDate($item['data_emissao'])) ?></dd></div><div><dt>Validade</dt><dd><?= e($displayDate($item['data_vencimento'])) ?></dd></div></dl>
                                <?php if (empty($item['pdf_privado'])): ?><p class="cert-pdf-note <?= !empty($item['arquivo_pdf']) ? 'legacy' : '' ?>"><?= !empty($item['arquivo_pdf']) ? 'PDF legado para revisão' : 'Sem PDF anexado' ?></p><?php endif; ?>
                                <div class="cert-card-links">
                                    <?php if ($state === 'corrente'): ?><a class="cert-link-renew" href="<?= e(url('certidao/renovar/' . (int) $item['id'])) ?>" aria-label="Renovar certidão <?= (int) $item['id'] ?>" title="Renovar">⟳</a><a class="cert-link-archive" href="<?= e(url('certidao/detalhes/' . (int) $item['id'] . '#cert-arquivar')) ?>" aria-label="Arquivar certidão <?= (int) $item['id'] ?>" title="Arquivar">▣</a><?php endif; ?>
                                    <?php if (!empty($item['pdf_privado'])): ?><a class="cert-link-pdf" href="<?= e(url('certidao/pdf/' . (int) $item['id'])) ?>" aria-label="Baixar PDF da certidão <?= (int) $item['id'] ?>" title="Baixar PDF">PDF</a><?php endif; ?>
                                    <?php if ($state === 'corrente'): ?><a class="cert-link-edit" href="<?= e(url('certidao/editar/' . (int) $item['id'])) ?>" aria-label="Editar certidão <?= (int) $item['id'] ?>" title="Editar">✎</a><?php endif; ?>
                                    <?php if ($state !== 'excluida'): ?><a class="cert-link-delete" href="<?= e(url('certidao/detalhes/' . (int) $item['id'] . '#cert-excluir')) ?>" aria-label="Ver opções para excluir certidão <?= (int) $item['id'] ?>" title="Excluir">×</a><?php else: ?><a href="<?= e(url('certidao/detalhes/' . (int) $item['id'])) ?>" aria-label="Ver detalhes da certidão <?= (int) $item['id'] ?>" title="Detalhes">◉</a><?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
            </table>
        </div>
    <?php endif; ?>
    <nav class="cert-pagination" aria-label="<?= $state === 'corrente' ? 'Páginas de fornecedores' : 'Páginas do histórico' ?>">
        <?php if ($result['page'] > 1): ?><a class="btn-secondary" href="<?= e(url($route . '?' . http_build_query(array_merge($filters, ['page' => $result['page'] - 1])))) ?>">← Anterior</a><?php endif; ?>
        <?php if ($result['page'] < $result['pages']): ?><a class="btn-secondary" href="<?= e(url($route . '?' . http_build_query(array_merge($filters, ['page' => $result['page'] + 1])))) ?>">Próxima →</a><?php endif; ?>
    </nav>
</section>
