<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e((string) $statusCode) ?> - GSE</title>
    <link rel="icon" type="image/png" href="<?= e(url('assets/image/logo_escola.png')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/error.css')) ?>">
</head>
<body>
    <main class="error-card">
        <p class="error-code"><?= e((string) $statusCode) ?></p>
        <h1><?= e($heading) ?></h1>
        <p><?= e($message) ?></p>
        <?php if ($technicalDetail): ?>
            <pre><?= e($technicalDetail) ?></pre>
        <?php endif; ?>
        <?php if ($requestId !== ''): ?>
            <p class="request-id">Identificador da requisição: <code><?= e($requestId) ?></code></p>
        <?php endif; ?>
        <a href="<?= e(url($returnPath)) ?>">Voltar</a>
    </main>
</body>
</html>
