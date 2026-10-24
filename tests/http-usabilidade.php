<?php
// Included by http-smoke.php; all records and credentials are disposable fixtures.
$usabilityUrl = $baseUrl . '/formulario/rascunho';
$usabilityAnonymous = $tempRoot . DIRECTORY_SEPARATOR . 'usability-anonymous.cookies';
checkHttp(request('GET', $usabilityUrl . '?form=aluno/criar', $usabilityAnonymous)['status'] === 302, 'Rascunhos exigem autenticação');
$usabilityForm = request('GET', $baseUrl . '/aluno/criar', $cookieAdmin);
$usabilityToken = csrf($usabilityForm['body']);
$usabilityOpen = request('GET', $usabilityUrl . '?form=aluno/criar', $cookieAdmin);
$usabilityContext = json_decode($usabilityOpen['body'], true)['context'] ?? '';
checkHttp($usabilityOpen['status'] === 200 && preg_match('/^[a-f0-9]{32}$/D', $usabilityContext) === 1, 'Rascunho recebe contexto autenticado e temporário');
checkHttp(str_contains($usabilityOpen['headers']['cache-control'] ?? '', 'no-store'), 'Resposta de rascunho não pode ser armazenada em cache');
$usabilityFields = ['_csrf_token' => $usabilityToken, 'form' => 'aluno/criar', 'context' => $usabilityContext, 'operation' => 'save', 'fields' => json_encode(['nome_completo' => 'Pessoa fictícia do rascunho', 'senha' => 'segredo-excluido', '_csrf_token' => 'segredo-excluido', 'id_turma' => '1'])];
$usabilityRejected = $usabilityFields; $usabilityRejected['_csrf_token'] = 'invalid';
checkHttp(request('POST', $usabilityUrl, $cookieAdmin, $usabilityRejected)['status'] === 419, 'Gravação de rascunho exige CSRF');
checkHttp(request('POST', $usabilityUrl, $cookieAdmin, $usabilityFields)['status'] === 200, 'Rascunho preserva preenchimento autorizado na sessão');
$usabilityRead = request('GET', $usabilityUrl . '?form=aluno/criar', $cookieAdmin);
$usabilityDraft = json_decode($usabilityRead['body'], true)['draft'];
checkHttp($usabilityDraft['fields'] === ['nome_completo' => 'Pessoa fictícia do rascunho', 'id_turma' => '1'] && !str_contains($usabilityRead['body'], 'segredo-excluido'), 'Rascunho exclui senhas e campos de controle');
$usabilityRejected = $usabilityFields; $usabilityRejected['form'] = 'usuario/criar';
checkHttp(request('POST', $usabilityUrl, $cookieAdmin, $usabilityRejected)['status'] === 422, 'Formulários de credenciais não aceitam rascunhos');
$usabilityRejected = $usabilityFields; $usabilityRejected['context'] = str_repeat('0', 32);
checkHttp(request('POST', $usabilityUrl, $cookieAdmin, $usabilityRejected)['status'] === 409, 'Contexto inexistente não pode sobrescrever rascunho');
$usabilityFields['operation'] = 'discard';
checkHttp(request('POST', $usabilityUrl, $cookieAdmin, $usabilityFields)['status'] === 200 && json_decode(request('GET', $usabilityUrl . '?form=aluno/criar', $cookieAdmin)['body'], true)['draft'] === null, 'Descartar remove somente o rascunho da sessão');
$usabilityList = request('GET', $baseUrl . '/aluno?q=Pessoa&page=2', $cookieAdmin);
checkHttp(str_contains($usabilityList['body'], 'data-list-context=') && str_contains($usabilityList['body'], 'q=Pessoa'), 'Contexto de retorno preserva os filtros da lista no servidor');
checkHttp(str_contains($usabilityForm['body'], 'Pular para o conteúdo') && str_contains($usabilityForm['body'], 'id="dva-initial-help"'), 'Formulário oferece atalho de teclado e ajuda DVA junto ao campo');
