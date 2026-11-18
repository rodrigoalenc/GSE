<?php
declare(strict_types=1);

// Included by the smoke runner against its disposable database.
$classInputCreateUrl = $baseUrl . '/turma/criar';
$classInputEditUrl = $baseUrl . '/turma/editar/1';
$classInputYear = date('Y');
$classInputPage = request('GET', $classInputCreateUrl, $cookieAdmin);
$classInputFields = ['_csrf_token' => csrf($classInputPage['body']), 'nome_turma' => 'Turma teste de ano'];
foreach (['2e03', $classInputYear . '5', ' ' . $classInputYear, '２０２６', '1999', (string) ((int) $classInputYear + 6)] as $classInputInvalidYear) {
    $classInputResult = request('POST', $classInputCreateUrl, $cookieAdmin, $classInputFields + ['ano_letivo' => $classInputInvalidYear]);
    checkHttp($classInputResult['status'] === 422
        && str_contains($classInputResult['body'], 'value="' . $classInputInvalidYear . '"')
        && str_contains($classInputResult['body'], 'aria-describedby="ano_letivo-help ano_letivo-error"'),
        'Turma recusa ano inválido mantendo o valor e associando o erro ' . json_encode($classInputInvalidYear, JSON_UNESCAPED_UNICODE));
}
$classInputMissingYear = request('POST', $classInputCreateUrl, $cookieAdmin, $classInputFields);
checkHttp($classInputMissingYear['status'] === 422 && str_contains($classInputMissingYear['body'], 'id="ano_letivo-error"'),
    'Cadastro de turma exige o ano letivo no POST');

$classInputPage = request('GET', $classInputEditUrl, $cookieAdmin);
$classInputResult = request('POST', $classInputEditUrl, $cookieAdmin, [
    '_csrf_token' => csrf($classInputPage['body']), 'revisao' => formRevision($classInputPage['body']),
    'nome_turma' => 'Nome que não deve salvar', 'ano_letivo' => $classInputYear . '.0',
]);
checkHttp($classInputResult['status'] === 422
    && str_contains($classInputResult['body'], 'id="ano_letivo-error"')
    && formRevision($classInputResult['body']) === formRevision($classInputPage['body']),
    'Edição de turma exige quatro dígitos sem converter decimais');

$studentInputUrl = $baseUrl . '/aluno/criar';
$studentInputPage = request('GET', $studentInputUrl, $cookieAdmin);
$studentInputPhone = '(65) 99999-1234' . str_repeat(' ', 20) . 'ramal';
$studentInputFields = [
    '_csrf_token' => csrf($studentInputPage['body']), '_request_token' => bin2hex(random_bytes(16)),
    'nome_completo' => 'Aluno teste de telefone', 'data_nascimento' => '2010-05-10', 'id_turma' => '1',
    'telefone_aluno' => $studentInputPhone, 'telefone_responsavel' => '+65 3333-1234',
];
$studentInputResult = request('POST', $studentInputUrl, $cookieAdmin, $studentInputFields);
checkHttp($studentInputResult['status'] === 422
    && str_contains($studentInputResult['body'], 'value="' . $studentInputPhone . '"')
    && str_contains($studentInputResult['body'], 'aria-describedby="student-phones-help telefone_aluno-error"')
    && str_contains($studentInputResult['body'], 'aria-describedby="student-phones-help telefone_responsavel-error"'),
    'Cadastro de aluno preserva telefone longo e associa os dois erros aos respectivos campos');
$studentInputFields['telefone_aluno'] = '';
$studentInputResult = request('POST', $studentInputUrl, $cookieAdmin, $studentInputFields);
checkHttp($studentInputResult['status'] === 422
    && !str_contains($studentInputResult['body'], 'id="telefone_aluno-error"')
    && str_contains($studentInputResult['body'], 'id="telefone_responsavel-error"'),
    'Telefone em branco permanece opcional ao corrigir apenas o telefone do responsável');
