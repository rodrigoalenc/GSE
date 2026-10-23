<?php if ($formErrors !== []): ?>
<div class="error-message form-error-summary" role="alert" tabindex="-1" data-form-errors>
    <strong>Revise os dados informados:</strong>
    <ul><?php foreach ($formErrors as $formError): $errorField = null;
        foreach ($fieldErrors as $fieldId => $messages) { if (in_array($formError, $messages, true)) { $errorField = $fieldId; break; } }
    ?><li><?php if ($errorField !== null): ?><a href="#<?= e($errorField) ?>"><?= e($formError) ?></a><?php else: ?><?= e($formError) ?><?php endif; ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>
