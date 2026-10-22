<section class="relatorio movement-panel" aria-labelledby="movement-annual-title">
    <h2 id="movement-annual-title">Progressão geral de <?= e((string)$year) ?></h2>
    <?php if(!empty($state['concluida'])): ?>
    <p class="success-message">A progressão geral deste ano já foi realizada. Para corrigir um reprovado, marque o aluno em Gestão de alunos e escolha “Regredir aluno(s)”.</p>
    <a class="btn-secondary" href="<?= e(url('aluno')) ?>">Abrir Gestão de alunos</a>
    <?php else: ?>
    <?php if($state['pendentes']>0): ?><p class="warning-message">Identifique as séries das turmas antes de progredir. Há <?= e((string)$state['pendentes']) ?> aluno(s) sem série identificada ou sem turma.</p><?php endif; ?>
    <?php if(($state['turmas_pendentes'] ?? [])!==[]): ?><p class="warning-message">Antes de progredir, conclua o envio do 3º ano ao Arquivo Passivo na opção “Mandar para o passivo”. Desmarque os reprovados durante esse envio. Não é necessário conferir os demais alunos individualmente.</p><?php endif; ?>
    <?php if($state['liberada']): ?><p class="success-message">Envio dos concluintes concluído. A progressão geral está disponível; os reprovados do 3º ano permanecerão ativos na mesma turma.</p><?php endif; ?>
    <p>O sistema identifica a turma da série seguinte considerando etapa, turma, turno e ano letivo. Escolha apenas os destinos que não puderam ser identificados. Os destinos confirmados ficam salvos para o próximo ciclo.</p>
    <p><a class="btn-secondary" data-movement-all href="<?= e(url($menuPath.'&tipo=progressao&todas=1#movement-all-title')) ?>">Progredir todas as turmas de uma só vez — conferir alunos que permanecerão</a></p>
    <form method="post" action="<?= e(url('aluno/movimentar/preview')) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="ano" value="<?= e((string)$year) ?>"><input type="hidden" name="tipo" value="progressao">
        <div class="table-scroll"><table class="tabela-filtrada"><thead><tr><th>Turma de origem</th><th>Alunos ativos</th><th>Turma da série seguinte</th></tr></thead><tbody>
        <?php foreach($classes as $source): if(($annualCounts[(int)$source['id']] ?? 0)===0 || $source['serie']===null || (int)$source['serie']>=12) continue;
            $candidates=array_filter($classes,static fn(array $c): bool => (int)$c['ativo']===1 && (int)$c['serie']===(int)$source['serie']+1);
            $automatic=$automaticDestinations[(int)$source['id']] ?? null;
            $selected=$automatic['id'] ?? $savedDestinations[(int)$source['id']] ?? (count($candidates)===1?(int)reset($candidates)['id']:0);
        ?>
        <tr><td><?= e($labelClass($source)) ?><small><?= e($series[(int)$source['serie']]) ?></small></td><td><?= e((string)$annualCounts[(int)$source['id']]) ?></td><td><?php if(is_array($automatic)): ?><div class="movement-automatic" data-movement-automatic="<?= e((string)$automatic['id']) ?>"><p>Destino identificado automaticamente</p><strong><?= e($labelClass($automatic)) ?></strong></div><?php else: ?><label class="visually-hidden" for="annual-target-<?= e((string)$source['id']) ?>">Destino de <?= e($source['nome_turma']) ?></label><select id="annual-target-<?= e((string)$source['id']) ?>" name="destinos[<?= e((string)$source['id']) ?>]" required><option value="">Escolha a turma existente</option><?php foreach($candidates as $target): ?><option value="<?= e((string)$target['id']) ?>" <?= (int)$selected===(int)$target['id']?'selected':'' ?>><?= e($labelClass($target)) ?></option><?php endforeach; ?></select><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <div class="form-actions"><button class="btn-primary" type="submit" <?= !$state['liberada']?'disabled':'' ?>>Progredir turmas para novo ano letivo</button></div>
    </form>
    <?php endif; ?>
</section>
