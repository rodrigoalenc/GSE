<?php
$certificateWarnings = $certificateWarnings ?? [];
$creationBlocked = $certificateWarnings !== [];
?>
<section class="warning-message contract-certificate-warning" role="alert" data-contract-certificate-alert
    <?= !$creationBlocked ? 'hidden' : '' ?>
    <?php if (!empty($dynamicCertificateGuard)): ?>data-certificate-report="<?= e(json_encode($certificateGroups ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>" data-certificate-details-base="<?= e(url('certidao/detalhes/')) ?>"<?php endif; ?>>
    <strong>Fornecedor com certidões vencidas</strong>
    <p>A criação de pedidos e de novas notas para os fornecedores abaixo está bloqueada. Regularize as certidões para continuar.</p>
    <ul data-contract-certificate-list>
        <?php foreach ($certificateWarnings as $certificate): ?>
            <li><a href="<?= e(url('certidao/detalhes/' . $certificate['id'])) ?>"><?= e($certificate['tipo_certidao']) ?></a>
                — <?= e($certificate['fornecedor']) ?> — vencida em <?= e((new DateTimeImmutable($certificate['data_vencimento']))->format('d/m/Y')) ?>.</li>
        <?php endforeach; ?>
    </ul>
</section>
