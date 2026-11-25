<?php

declare(strict_types=1);

$agendaUrl = $baseUrl . '/agenda';
$agendaGuest = request('GET', $agendaUrl, $cookieGuest);
checkHttp($agendaGuest['status'] === 302 && str_contains($agendaGuest['headers']['location'] ?? '', '/login'),
    'Agenda exige autenticação');
$agendaEmployee = request('GET', $agendaUrl, $cookieEmployee);
checkHttp($agendaEmployee['status'] === 200 && str_contains($agendaEmployee['body'], 'Calendário mensal')
    && !str_contains($agendaEmployee['body'], 'Importar calendário'), 'Funcionário consulta o calendário');
checkHttp(request('GET', $agendaUrl . '/importar', $cookieEmployee)['status'] === 403,
    'Importação do calendário é restrita ao administrador');

$agendaAdmin = request('GET', $agendaUrl, $cookieAdmin);
preg_match('/name="data_aviso"[^>]*value="(\d{4}-\d{2}-\d{2})"/', $agendaAdmin['body'], $agendaDateMatch);
checkHttp($agendaAdmin['status'] === 200 && isset($agendaDateMatch[1]), 'Agenda carrega com o dia atual do GSE');
$agendaHoje = $agendaDateMatch[1];
$agendaFields = ['_csrf_token' => csrf($agendaAdmin['body']), 'data_aviso' => $agendaHoje, 'data' => $agendaHoje,
    'visao' => 'semana', 'titulo' => 'Reunião HTTP <em>fictícia</em>', 'descricao' => 'Dados de teste'];
checkHttp(request('POST', $agendaUrl . '/cadastrar', $cookieAdmin, array_replace($agendaFields, ['_csrf_token'=>'incorreto']))['status'] === 419,
    'Agenda recusa alterações sem token CSRF válido');
$agendaCriado = request('POST', $agendaUrl . '/cadastrar', $cookieAdmin, $agendaFields);
checkHttp($agendaCriado['status'] === 302 && str_contains($agendaCriado['headers']['location'] ?? '', 'visao=semana'),
    'Agenda cadastra aviso e mantém a vista semanal');
$agendaDb = new PDO('sqlite:' . $database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$agendaAvisoId = (int) $agendaDb->query('SELECT MAX(id) FROM agenda_avisos')->fetchColumn();
$agendaPainel = request('GET', $baseUrl . '/dashboard', $cookieAdmin);
checkHttp(str_contains($agendaPainel['body'], 'Próximos compromissos')
    && str_contains($agendaPainel['body'], 'Reunião HTTP &lt;em&gt;fictícia&lt;/em&gt;'), 'Painel exibe o aviso com o título escapado');

$agendaEmployeeFields = array_replace($agendaFields, ['_csrf_token'=>csrf($agendaEmployee['body']), 'revisao'=>'1']);
checkHttp(request('POST', $agendaUrl . '/editar/' . $agendaAvisoId, $cookieEmployee, $agendaEmployeeFields)['status'] === 403,
    'Funcionário não edita aviso de outro usuário');
checkHttp(request('POST', $agendaUrl . '/excluir/' . $agendaAvisoId, $cookieAdmin, $agendaFields + ['revisao'=>'1'])['status'] === 302
    && (string) $agendaDb->query('SELECT excluido_em FROM agenda_avisos WHERE id=' . $agendaAvisoId)->fetchColumn() !== '',
    'Exclusão de aviso preserva a linha no banco');

$agendaImport = request('GET', $agendaUrl . '/importar', $cookieAdmin);
$agendaImportFields = ['_csrf_token'=>csrf($agendaImport['body']), 'ano'=>substr($agendaHoje, 0, 4)];
$agendaPreview = request('POST', $agendaUrl . '/importar', $cookieAdmin, $agendaImportFields + [
    'acao'=>'previsualizar', 'texto'=>"10 de março - Recesso escolar\n11 de março - Planejamento",
]);
preg_match('/name="token" value="([a-f0-9]{64})"/', $agendaPreview['body'], $agendaTokenMatch);
checkHttp($agendaPreview['status'] === 200 && isset($agendaTokenMatch[1])
    && substr_count($agendaPreview['body'], 'name="selecionados[]"') === 2,
    'Importação prepara uma prévia selecionável do calendário');
$agendaConfirmar = $agendaImportFields + ['acao'=>'confirmar', 'token'=>$agendaTokenMatch[1], 'selecionados'=>['0']];
checkHttp(request('POST', $agendaUrl . '/importar', $cookieAdmin, $agendaConfirmar)['status'] === 302
    && (int) $agendaDb->query('SELECT COUNT(*) FROM agenda_avisos WHERE excluido_em IS NULL')->fetchColumn() === 1,
    'Importação salva apenas o aviso selecionado');
$agendaRepetido = request('POST', $agendaUrl . '/importar', $cookieAdmin, $agendaConfirmar);
checkHttp($agendaRepetido['status'] === 422 && str_contains($agendaRepetido['body'], 'já foi utilizada'),
    'Agenda recusa a reutilização do token de prévia');
$agendaDb = null;
