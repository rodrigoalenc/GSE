<?php
$returning = $type === 'retorno';
$back = $returning ? 'passivo/detalhes/' . (int) $record['id'] : 'aluno/perfil/' . (int) $student['id'];
$seriesLabel = static fn (int $series): string => $series <= 9 ? $series . 'º ano do ensino fundamental' : ($series - 9) . 'º ano do ensino médio';
?>
<section class="page-intro">
    <p class="hero-kicker">Movimentação escolar · Administrador</p>
    <h2><?= $returning ? 'Retorno do Arquivo Passivo' : 'Transferir entre turmas' ?></h2>
    <p><?= $returning ? 'Escolha a turma, confira os dados e confirme. O aluno voltará a ficar ativo e seu vínculo ativo no Arquivo Passivo será encerrado no mesmo ato.' : 'Escolha outra turma da mesma série e do mesmo ano letivo, confira os dados e confirme a transferência.' ?></p>
</section>

<section class="relatorio form-shell" aria-labelledby="school-link-selection">
    <h2 id="school-link-selection">1. Escolher a turma</h2>
    <dl class="passivo-student-summary">
        <div><dt>Aluno</dt><dd><?= e((string) $student['nome_completo']) ?> · #<?= (int) $student['id'] ?></dd></div>
        <div><dt>Nascimento</dt><dd><?= e(UiPresentation::civilDate($student['data_nascimento'])) ?></dd></div>
        <div><dt><?= $returning ? 'Última turma' : 'Turma atual' ?></dt><dd><?= e((string) ($student['nome_turma'] ?: 'Sem turma')) ?><?= $student['ano_letivo'] ? ' — ' . e((string) $student['ano_letivo']) : '' ?></dd></div>
        <?php if ($returning): ?><div><dt>Localização no passivo</dt><dd>Caixa <?= e((string) ($record['caixa'] ?: 'pendente')) ?> · posição <?= e((string) ($record['numero'] ?: 'pendente')) ?></dd></div><?php endif; ?>
    </dl>
    <?php if ($classes === []): ?>
    <p class="warning-message" role="status"><?= $returning ? 'Não há turma ativa com série e ano letivo válidos. Confira o cadastro das turmas antes de retornar o aluno.' : 'Não há outra turma ativa da mesma série e do mesmo ano letivo. Confira a identificação da série e o ano das turmas antes de transferir.' ?></p>
    <div class="form-actions"><a class="btn-secondary" href="<?= e(url('aluno/movimentar?configurar=1')) ?>">Conferir séries das turmas</a><a class="btn-secondary" href="<?= e(url($back)) ?>">Voltar</a></div>
    <?php else: ?>
    <form class="student-form" method="post" action="<?= e(url($previewPath)) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="form-grid"><div class="wide"><label for="school-link-destination">Turma de destino</label>
            <select id="school-link-destination" name="destino" required aria-describedby="school-link-destination-help"><option value="">Selecione a turma</option>
                <?php foreach ($classes as $class): ?><option value="<?= (int) $class['id'] ?>" <?= $selected === (int) $class['id'] ? 'selected' : '' ?>><?= e((string) $class['nome_turma']) ?> — <?= (int) $class['ano_letivo'] ?> · <?= e($seriesLabel((int) $class['serie'])) ?></option><?php endforeach; ?>
            </select>
            <small id="school-link-destination-help"><?= $returning ? 'Escolha a turma em que o aluno será matriculado ao retornar à escola.' : 'Esta operação mantém a série e o ano letivo do aluno.' ?></small>
        </div></div>
        <div class="form-actions"><button class="btn-primary" type="submit">Gerar prévia</button><a class="btn-secondary" href="<?= e(url($back)) ?>">Cancelar</a></div>
    </form>
    <?php endif; ?>
</section>

<?php if ($preview !== null): ?>
<section class="relatorio form-shell" id="school-link-review" aria-labelledby="school-link-review-title" tabindex="-1">
    <h2 id="school-link-review-title">2. <?= $returning ? 'Conferir o retorno' : 'Conferir a transferência' ?></h2>
    <dl class="passivo-detail-grid">
        <div><dt>Aluno</dt><dd><?= e((string) $preview['aluno']['nome_completo']) ?> · #<?= (int) $preview['aluno']['id'] ?></dd></div>
        <div><dt>Nascimento</dt><dd><?= e(UiPresentation::civilDate($preview['aluno']['data_nascimento'])) ?></dd></div>
        <div><dt>Origem</dt><dd><?= $returning ? 'Arquivo Passivo — registro #' . (int) $preview['passivo']['id'] : e((string) $preview['origem']['nome_turma']) . ' — ' . (int) $preview['origem']['ano_letivo'] ?></dd></div>
        <div><dt>Destino</dt><dd><?= e((string) $preview['destino']['nome_turma']) ?> — <?= (int) $preview['destino']['ano_letivo'] ?><br><?= e($seriesLabel((int) $preview['destino']['serie'])) ?></dd></div>
    </dl>
    <p>O cadastro original, as DVAs e os registros históricos serão preservados.</p>
    <h3>3. Confirmar</h3>
    <form class="student-form" method="post" action="<?= e(url('aluno/vinculo/confirmar')) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="preview_token" value="<?= e($previewToken) ?>">
        <input type="hidden" name="_request_token" value="<?= e($requestToken) ?>">
        <label class="passivo-confirm"><input type="checkbox" name="confirmar" value="1" required> <?= $returning ? 'Conferi o aluno e a turma. Confirmo o retorno à escola e o encerramento do vínculo ativo no Arquivo Passivo.' : 'Conferi o aluno e as turmas. Confirmo a transferência dentro da mesma série e do mesmo ano letivo.' ?></label>
        <div class="form-actions"><button class="btn-primary" type="submit"><?= $returning ? 'Confirmar retorno à escola' : 'Confirmar transferência' ?></button><a class="btn-secondary" href="<?= e(url($path)) ?>">Rever a escolha</a></div>
    </form>
</section>
<?php endif; ?>
