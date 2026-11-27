<?php

declare(strict_types=1);

$moduleGet = static fn (string $path): array => request('GET', $baseUrl . '/' . $path, $cookieEmployee);
$moduleDashboard = $moduleGet('dashboard');
checkHttp($moduleGet('contrato')['status'] === 403
    && $moduleGet('estoque/pedido/1')['status'] === 403
    && $moduleGet('certidao/pdf/1')['status'] === 403
    && $moduleGet('formulario/rascunho?form=certidao/cadastrar')['status'] === 403
    && !str_contains($moduleDashboard['body'], 'class="sidebar-label">Pedidos')
    && !str_contains($moduleDashboard['body'], 'class="sidebar-label">Certidões'),
    'Funcionário sem autorização não acessa os módulos pelo menu, por URL ou pelo endpoint de rascunho');

$moduleEmployeeEdit = $baseUrl . '/usuario/editar/' . $employeeActorId;
$moduleSetGrants = static function (array $permissions) use ($moduleEmployeeEdit, $cookieAdmin): array {
    $form = request('GET', $moduleEmployeeEdit, $cookieAdmin);
    $approval = ['enabled' => '1', 'reference' => 'Autorização fictícia HTTP', 'until' => '2099-12-31'];
    return request('POST', $moduleEmployeeEdit, $cookieAdmin, [
        '_csrf_token' => csrf($form['body']),
        'grants_revision' => hiddenValue($form['body'], 'grants_revision'),
        'nome' => 'Funcionário HTTP Editado', 'email' => 'employee@example.test', 'tipo' => 'funcionario',
        'grants' => array_fill_keys($permissions, $approval),
    ]);
};
checkHttp($moduleSetGrants(['orders.access'])['status'] === 302
    && $moduleGet('contrato')['status'] === 200
    && $moduleGet('estoque')['status'] === 200
    && $moduleGet('certidao')['status'] === 403,
    'Conceder Pedidos libera Estoque e mantém Certidões restrita');
checkHttp($moduleSetGrants(['certificates.access'])['status'] === 302
    && $moduleGet('contrato')['status'] === 403
    && $moduleGet('estoque')['status'] === 403
    && $moduleGet('certidao')['status'] === 200,
    'Certidões pode ser concedida separadamente e a revogação vale na sessão atual');
checkHttp($moduleSetGrants(['orders.access', 'certificates.access'])['status'] === 302,
    'Administrador concede as duas permissões para os fluxos autorizados da suíte');
