<?php
$certificateWarnings = $certificateWarnings ?? [];
$creationBlocked = $certificateWarnings !== [];
$canViewCertificates = $canViewCertificates ?? false;
$certificateReport = $canViewCertificates ? ($certificateGroups ?? []) : array_fill_keys(array_keys($certificateGroups ?? []), true);
?>
<section class="error-message contract-certificate-warning" role="alert" data-contract-certificate-alert
    <?= !$creationBlocked ? 'hidden' : '' ?>
    <?php if (!empty($dynamicCertificateGuard)): ?>data-certificate-report="<?= e(json_encode($certificateReport, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>"<?php if ($canViewCertificates): ?> data-certificate-details-base="<?= e(url('certidao/detalhes/')) ?>"<?php endif; ?><?php endif; ?>>
    <strong class="contract-certificate-warning-title"><?= gse_icon('alert') ?> Fornecedor com certidões vencidas</strong>
    <p>A criação de pedidos e de novas notas para os fornecedores abaixo está bloqueada. Regularize as certidões para continuar.</p>
    <?php if ($canViewCertificates): ?><ul data-contract-certificate-list>
        <?php foreach ($certificateWarnings as $certificate): ?>
            <li><a href="<?= e(url('certidao/detalhes/' . $certificate['id'])) ?>"><?= e($certificate['tipo_certidao']) ?></a>
                — <?= e($certificate['fornecedor']) ?> — vencida em <?= e((new DateTimeImmutable($certificate['data_vencimento']))->format('d/m/Y')) ?>.</li>
        <?php endforeach; ?>
    </ul><?php endif; ?>
</section>
