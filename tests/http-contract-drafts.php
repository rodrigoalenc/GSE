<?php

declare(strict_types=1);

// Uses only the isolated database, server and fictitious sessions from http-smoke.php.
function contractDraftElement(string $html,string $id): DOMElement
{
    $document=new DOMDocument(); $before=libxml_use_internal_errors(true);
    try { $document->loadHTML('<?xml encoding="UTF-8">'.$html); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($before); }
    $element=(new DOMXPath($document))->query('//*[@id="'.$id.'"]')?->item(0);
    if (!$element instanceof DOMElement) { throw new RuntimeException('Elemento do formulário não encontrado: '.$id); }
    return $element;
}

/** @return array<string,string> */
function contractDraftFields(string $html,string $id): array
{
    $element=contractDraftElement($html,$id); $fields=[];
    $form=$element->getElementsByTagName('form')->item(0);
    if (!$form instanceof DOMElement) { throw new RuntimeException('Formulário não encontrado: '.$id); }
    foreach ($form->getElementsByTagName('input') as $input) {
        if (!$input->hasAttribute('name') || ($input->getAttribute('type')==='checkbox' && !$input->hasAttribute('checked'))) { continue; }
        $fields[$input->getAttribute('name')]=$input->getAttribute('value');
    }
    foreach ($form->getElementsByTagName('textarea') as $input) { $fields[$input->getAttribute('name')]=$input->textContent; }
    foreach ($form->getElementsByTagName('select') as $select) {
        foreach ($select->getElementsByTagName('option') as $option) {
            if (!isset($fields[$select->getAttribute('name')]) || $option->hasAttribute('selected')) { $fields[$select->getAttribute('name')]=$option->getAttribute('value'); }
        }
    }
    return $fields;
}

function contractDraftRecovery(array $response,string $cookie): array
{
    checkHttp($response['status']===302 && str_contains($response['headers']['location'] ?? '', '?rascunho='),'Erro de contrato usa recuperação individual do preenchimento');
    return request('GET',$response['headers']['location'],$cookie);
}

$draftCreatePage=request('GET',$baseUrl.'/contrato/criar',$cookieAdmin);
$draftCreate=request('POST',$baseUrl.'/contrato/criar',$cookieAdmin,[
    '_csrf_token'=>csrf($draftCreatePage['body']),'titulo'=>'Pedido HTTP rascunhos','valor'=>'100,00','fornecedor'=>'',
    'folhas'=>array_map(static fn(int $number): array => ['observacao'=>'Nota de teste '.$number,'produtos'=>[
        ['nome'=>'Produto '.$number,'marca'=>'Marca teste','unidade'=>['un','K','Litros'][$number-1],'quantidade'=>'2','preco'=>'3,00'],
    ]],[1,2,3]),
]);
preg_match('#/contrato/detalhes/([0-9]+)#',$draftCreate['headers']['location'] ?? '',$draftCreatedMatch);
checkHttp(isset($draftCreatedMatch[1]),'Pedido fictício com três notas para regressão de rascunhos');
$draftContract=(int)$draftCreatedMatch[1];
$draftUrl=$baseUrl.'/contrato/detalhes/'.$draftContract;
$draftDb=new PDO('sqlite:'.$database,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$draftNotes=$draftDb->query('SELECT * FROM pedido_paginas WHERE id_pedido='.$draftContract.' ORDER BY numero_pagina')->fetchAll();
$draftProducts=$draftDb->query('SELECT * FROM pedido_produtos WHERE id_pedido='.$draftContract.' ORDER BY numero_pagina')->fetchAll();
checkHttp(array_column($draftProducts,'unidade')===['un','K','Litros'],'Cadastro preserva unidades escolhidas e unidade anterior sem conversão automática');
$draftNote2=(int)$draftNotes[1]['id']; $draftNote3=(int)$draftNotes[2]['id'];
$draftProduct1=(int)$draftProducts[0]['id']; $draftProduct2=(int)$draftProducts[1]['id']; $draftProduct3=(int)$draftProducts[2]['id'];

// Two browser tabs submit failures before either follows the redirect.
$draftTabA=request('GET',$draftUrl,$cookieEmployee); $draftTabB=request('GET',$draftUrl,$cookieEmployee);
$draftAdd=contractDraftFields($draftTabA['body'],'add-product-'.$draftNote2);
$draftBill=contractDraftFields($draftTabB['body'],'billing-note-'.$draftNote3);
$draftUnitSelect=contractDraftElement($draftTabA['body'],'add-product-'.$draftNote2)->getElementsByTagName('select')->item(0);
$draftUnitOptions=[];
if ($draftUnitSelect instanceof DOMElement) {
    foreach ($draftUnitSelect->getElementsByTagName('option') as $option) { $draftUnitOptions[]=$option->getAttribute('value'); }
}
checkHttp($draftUnitSelect instanceof DOMElement && $draftUnitSelect->getAttribute('name')==='unidade'
    && $draftUnitOptions===['UN','K','Litros'] && $draftAdd['unidade']==='UN','Produto novo oferece seleção UN, K ou Litros com padrão UN');
checkHttp(contractDraftFields($draftTabA['body'],'edit-product-'.$draftProduct1)['unidade']==='un','Edição mantém selecionada a unidade anterior do produto');
checkHttp($draftAdd['_form_context']!==$draftBill['_form_context'],'Duas abas recebem contextos de preenchimento distintos');
$draftAdd=array_replace($draftAdd,['nome'=>'<Rascunho A>','marca'=>'Marca A','unidade'=>'Litros','quantidade'=>'2','preco'=>'inválido']);
$draftBill=array_replace($draftBill,['data'=>'2026-02-30','motivo'=>'<Motivo B>','faturado'=>'1']);
$draftFailureA=request('POST',$baseUrl.'/contrato/produto/'.$draftContract,$cookieEmployee,$draftAdd);
$draftFailureB=request('POST',$baseUrl.'/contrato/faturar/'.$draftContract,$cookieEmployee,$draftBill);
$draftRecoveryA=contractDraftRecovery($draftFailureA,$cookieEmployee); $draftRecoveryB=contractDraftRecovery($draftFailureB,$cookieEmployee);
checkHttp(contractDraftElement($draftRecoveryA['body'],'add-product-'.$draftNote2)->hasAttribute('open')
    && !contractDraftElement($draftRecoveryA['body'],'add-product-'.$draftNote3)->hasAttribute('open')
    && str_ends_with($draftFailureA['headers']['location'],'#add-product-'.$draftNote2),'Erro reabre somente adicionar produto na segunda nota');
checkHttp(contractDraftFields($draftRecoveryA['body'],'add-product-'.$draftNote2)['nome']==='<Rascunho A>'
    && contractDraftFields($draftRecoveryA['body'],'add-product-'.$draftNote2)['unidade']==='Litros'
    && str_contains($draftRecoveryA['body'],'&lt;Rascunho A&gt;') && !str_contains($draftRecoveryA['body'],'<Rascunho A>'),'Valores de produto recuperados e escapados');
checkHttp(str_contains($draftRecoveryA['body'],'Valor inválido.') && !str_contains($draftRecoveryA['body'],'Data de faturamento inválida.')
    && str_contains($draftRecoveryB['body'],'Data de faturamento inválida.') && !str_contains($draftRecoveryB['body'],'Rascunho A'),'Mensagens e campos recusados permanecem isolados entre operações e abas');
checkHttp(contractDraftElement($draftRecoveryB['body'],'billing-note-'.$draftNote3)->hasAttribute('open')
    && contractDraftFields($draftRecoveryB['body'],'billing-note-'.$draftNote3)['motivo']==='<Motivo B>'
    && str_contains($draftRecoveryB['body'],'2026-02-30'),'Erro de data mantém faturamento na terceira nota e mostra a data recusada');
checkHttp(!str_contains(request('GET',$draftUrl,$cookieEmployee)['body'],'Rascunho A'),'Página normal não importa o rascunho de outra aba');
$draftRefreshed=contractDraftFields(request('GET',$draftFailureA['headers']['location'],$cookieEmployee)['body'],'add-product-'.$draftNote2);
checkHttp($draftRefreshed['nome']==='<Rascunho A>' && $draftRefreshed['unidade']==='Litros','Atualizar a página mantém a tentativa e a unidade escolhida da operação correta');
$draftWrongContract=request('GET',$baseUrl.'/contrato/detalhes/'.(int)$completeMatch[1].'?'.parse_url($draftFailureA['headers']['location'],PHP_URL_QUERY),$cookieEmployee);
checkHttp(!str_contains($draftWrongContract['body'],'Rascunho A'),'Rascunho não é exibido em outro pedido');

// Required operations recover only their intended form and bounded fields.
$draftFailures=[
    ['produto','edit-product-'.$draftProduct1,['nome'=>'<Editar recuperado>','quantidade'=>'0'],'nome','<Editar recuperado>'],
    ['editar-folha','note-observation-'.$draftNote2,['observacao'=>str_repeat('á',2001)],'observacao',str_repeat('á',2000)],
    ['estoque','stock-config-'.$draftProduct1,['minimo'=>'9','maximo'=>'3','abertura'=>''],'minimo','9'],
    ['estoque','stock-config-'.$draftProduct1,['minimo'=>'0','maximo'=>'20','abertura'=>'4','confirmar_abertura'=>''],'abertura','4'],
];
foreach ($draftFailures as [$endpoint,$formId,$changes,$field,$expected]) {
    $page=request('GET',$draftUrl,$cookieEmployee); $data=array_replace(contractDraftFields($page['body'],$formId),$changes);
    $draftValidationFailure=request('POST',$baseUrl.'/contrato/'.$endpoint.'/'.$draftContract,$cookieEmployee,$data);
    $recovery=contractDraftRecovery($draftValidationFailure,$cookieEmployee);
    checkHttp(contractDraftElement($recovery['body'],$formId)->hasAttribute('open') && contractDraftFields($recovery['body'],$formId)[$field]===$expected,'Preenchimento recuperado: '.$formId.' / '.$field);
    if (str_starts_with($formId,'edit-product-') || str_starts_with($formId,'stock-config-')) {
        checkHttp(!contractDraftElement($recovery['body'],'item-actions-'.$draftProduct1)->hasAttribute('hidden'),'Linha de ações reabre junto com o formulário do produto');
    }
}

// The inline checkbox has explicit server behavior and retains the correction restrictions.
$draftBillingPage=request('GET',$draftUrl,$cookieEmployee);
$draftBillingMissing=array_replace(contractDraftFields($draftBillingPage['body'],'billing-note-'.$draftNote3),['faturado'=>'1','data'=>'']);
$draftBillingMissingResponse=request('POST',$baseUrl.'/contrato/faturar/'.$draftContract,$cookieEmployee,$draftBillingMissing);
$draftBillingMissingRecovery=contractDraftRecovery($draftBillingMissingResponse,$cookieEmployee);
checkHttp(str_contains($draftBillingMissingRecovery['body'],'Informe a data para confirmar o faturamento.')
    && contractDraftFields($draftBillingMissingRecovery['body'],'billing-note-'.$draftNote3)['faturado']==='1','Checkbox faturado exige data e mantém a escolha após erro');
$draftBillingConfirmed=array_replace(contractDraftFields($draftBillingMissingRecovery['body'],'billing-note-'.$draftNote3),['data'=>'2026-10-02']);
$draftBillingConfirmedResponse=request('POST',$baseUrl.'/contrato/faturar/'.$draftContract,$cookieEmployee,$draftBillingConfirmed);
checkHttp(!str_contains($draftBillingConfirmedResponse['headers']['location'],'rascunho=')
    && $draftDb->query('SELECT data_faturamento FROM pedido_paginas WHERE id='.$draftNote3)->fetchColumn()==='2026-10-02','Funcionário confirma o primeiro faturamento com checkbox e data válida');
$draftEmployeeCorrectionPage=request('GET',$draftUrl,$cookieEmployee);
$draftEmployeeCorrection=contractDraftFields($draftEmployeeCorrectionPage['body'],'billing-note-'.$draftNote3);
unset($draftEmployeeCorrection['faturado']);
$draftEmployeeCorrection['motivo']='Tentativa de remover faturamento';
$draftEmployeeCorrectionRecovery=contractDraftRecovery(request('POST',$baseUrl.'/contrato/faturar/'.$draftContract,$cookieEmployee,$draftEmployeeCorrection),$cookieEmployee);
checkHttp(str_contains($draftEmployeeCorrectionRecovery['body'],'Correção de faturamento exige administrador e motivo.')
    && !isset(contractDraftFields($draftEmployeeCorrectionRecovery['body'],'billing-note-'.$draftNote3)['faturado'])
    && $draftDb->query('SELECT data_faturamento FROM pedido_paginas WHERE id='.$draftNote3)->fetchColumn()==='2026-10-02','Funcionário não remove faturamento; tentativa desmarcada permanece desmarcada sem alterar o registro');
$draftAdminCorrectionPage=request('GET',$draftUrl,$cookieAdmin);
$draftAdminCorrection=contractDraftFields($draftAdminCorrectionPage['body'],'billing-note-'.$draftNote3);
unset($draftAdminCorrection['faturado']);
$draftAdminCorrection['motivo']='';
$draftAdminCorrectionRecovery=contractDraftRecovery(request('POST',$baseUrl.'/contrato/faturar/'.$draftContract,$cookieAdmin,$draftAdminCorrection),$cookieAdmin);
checkHttp(str_contains($draftAdminCorrectionRecovery['body'],'Correção de faturamento exige administrador e motivo.'),'Administrador deve justificar a remoção de faturamento mesmo no controle inline');
$draftAdminReviewed=array_replace(contractDraftFields($draftAdminCorrectionRecovery['body'],'billing-note-'.$draftNote3),['motivo'=>'Correção documental conferida HTTP']);
$draftAdminCorrectionResponse=request('POST',$baseUrl.'/contrato/faturar/'.$draftContract,$cookieAdmin,$draftAdminReviewed);
checkHttp(!str_contains($draftAdminCorrectionResponse['headers']['location'],'rascunho=')
    && $draftDb->query('SELECT data_faturamento FROM pedido_paginas WHERE id='.$draftNote3)->fetchColumn()===null,'Administrador desmarca e remove faturamento com justificativa auditada');
$draftBillingInvalidPage=request('GET',$draftUrl,$cookieEmployee);
$draftBillingInvalid=array_replace(contractDraftFields($draftBillingInvalidPage['body'],'billing-note-'.$draftNote3),['faturado'=>'1','data'=>"2026-10-02\0"]);
$draftBillingInvalidRecovery=contractDraftRecovery(request('POST',$baseUrl.'/contrato/faturar/'.$draftContract,$cookieEmployee,$draftBillingInvalid),$cookieEmployee);
checkHttp(str_contains($draftBillingInvalidRecovery['body'],'Campo inválido: data')
    && !str_contains($draftBillingInvalidRecovery['body'],"\0"),'Data com estrutura inválida é recusada sem erro interno nem armazenamento de byte nulo');

// A stale edit is visible as an attempt; the current revision and current values remain editable.
$draftEmployeePage=request('GET',$draftUrl,$cookieEmployee); $draftAdminPage=request('GET',$draftUrl,$cookieAdmin);
$draftStale=contractDraftFields($draftEmployeePage['body'],'edit-product-'.$draftProduct1);
$draftCurrent=contractDraftFields($draftAdminPage['body'],'edit-product-'.$draftProduct1);
$draftCurrent['nome']='Nome atual de outro operador';
request('POST',$baseUrl.'/contrato/produto/'.$draftContract,$cookieAdmin,$draftCurrent);
$draftStale['nome']='<Tentativa antiga não salva>';
$draftStale['unidade']='Litros';
$draftConflictResponse=request('POST',$baseUrl.'/contrato/produto/'.$draftContract,$cookieEmployee,$draftStale);
$draftConflict=contractDraftRecovery($draftConflictResponse,$cookieEmployee);
$draftReviewed=contractDraftFields($draftConflict['body'],'edit-product-'.$draftProduct1);
checkHttp($draftReviewed['nome']==='Nome atual de outro operador' && $draftReviewed['unidade']==='un' && (int)$draftReviewed['revisao']===(int)$draftStale['revisao']+1
    && str_contains($draftConflict['body'],'Tentativa não salva') && str_contains($draftConflict['body'],'&lt;Tentativa antiga não salva&gt;'),'Conflito mostra dados atuais e tentativa escapada sem reaplicar a edição antiga');
checkHttp($draftDb->query('SELECT nome_produto FROM pedido_produtos WHERE id='.$draftProduct1)->fetchColumn()==='Nome atual de outro operador','Conflito HTTP preserva a edição do outro operador');
$draftReviewed['nome']='Alteração expressamente revisada';
$draftSaved=request('POST',$baseUrl.'/contrato/produto/'.$draftContract,$cookieEmployee,$draftReviewed);
checkHttp($draftSaved['status']===302 && !str_contains($draftSaved['headers']['location'],'rascunho=')
    && !str_contains(request('GET',$draftSaved['headers']['location'],$cookieEmployee)['body'],'Tentativa antiga não salva')
    && !str_contains(request('GET',$draftConflictResponse['headers']['location'],$cookieEmployee)['body'],'Tentativa antiga não salva'),'Envio revisado salva com a revisão atual e limpa a recuperação');

// Opening confirmation is still mandatory, and movement failures retain the selected operation.
$draftStockPage=request('GET',$draftUrl,$cookieEmployee);
$draftOpening=array_replace(contractDraftFields($draftStockPage['body'],'stock-config-'.$draftProduct1),['minimo'=>'0','maximo'=>'20','abertura'=>'5','confirmar_abertura'=>'1']);
request('POST',$baseUrl.'/contrato/estoque/'.$draftContract,$cookieEmployee,$draftOpening);
$draftUnitLockedPage=request('GET',$draftUrl,$cookieEmployee);
$draftUnitLocked=array_replace(contractDraftFields($draftUnitLockedPage['body'],'edit-product-'.$draftProduct1),['unidade'=>'K']);
$draftUnitLockedRecovery=contractDraftRecovery(request('POST',$baseUrl.'/contrato/produto/'.$draftContract,$cookieEmployee,$draftUnitLocked),$cookieEmployee);
checkHttp(str_contains($draftUnitLockedRecovery['body'],'Unidade não pode mudar após movimentações.')
    && $draftDb->query('SELECT unidade FROM pedido_produtos WHERE id='.$draftProduct1)->fetchColumn()==='un','Seleção diferente não altera unidade de produto com movimento histórico');
$draftMovePage=request('GET',$draftUrl,$cookieEmployee);
$draftMove=array_replace(contractDraftFields($draftMovePage['body'],'move-stock-'.$draftProduct1),['tipo'=>'saida','quantidade'=>'6','motivo'=>'<Destino recusado>']);
$draftMoveRecovery=contractDraftRecovery(request('POST',$baseUrl.'/contrato/movimentar/'.$draftContract,$cookieEmployee,$draftMove),$cookieEmployee);
$draftRecoveredMove=contractDraftFields($draftMoveRecovery['body'],'move-stock-'.$draftProduct1);
checkHttp(contractDraftElement($draftMoveRecovery['body'],'move-stock-'.$draftProduct1)->hasAttribute('open')
    && $draftRecoveredMove['tipo']==='saida' && $draftRecoveredMove['quantidade']==='6' && $draftRecoveredMove['motivo']==='<Destino recusado>'
    && $draftRecoveredMove['chave']!==$draftMove['chave'],'Movimentação recusada preserva tipo, quantidade e motivo com nova chave de envio');

// Simulate exactly the unproved legacy flag in the isolated fixture, never in an institutional database.
$draftDb->exec('UPDATE pedido_produtos SET estoque_inicializado=1,estoque_minimo=0,estoque_maximo=10 WHERE id IN ('.$draftProduct2.','.$draftProduct3.')');
$draftLegacyEmployee=request('GET',$draftUrl,$cookieEmployee);
checkHttp(!str_contains($draftLegacyEmployee['body'],'id="old-opening-'.$draftProduct3.'"'),'Conferência da abertura antiga é exibida somente para administradores');
checkHttp(request('POST',$baseUrl.'/contrato/conferir-abertura/'.$draftContract,$cookieEmployee,['_csrf_token'=>csrf($draftLegacyEmployee['body'])])['status']===403,'Funcionário não confirma abertura antiga pela URL direta');
$draftLegacyA=request('GET',$draftUrl,$cookieAdmin); $draftLegacyB=request('GET',$draftUrl,$cookieAdmin);
$draftLegacyDataA=array_replace(contractDraftFields($draftLegacyA['body'],'old-opening-'.$draftProduct3),['quantidade'=>'4','motivo'=>'<Documento da tentativa antiga>']);
$draftLegacyFailure=request('POST',$baseUrl.'/contrato/conferir-abertura/'.$draftContract,$cookieAdmin,$draftLegacyDataA);
$draftLegacyDataB=array_replace(contractDraftFields($draftLegacyB['body'],'old-opening-'.$draftProduct3),['quantidade'=>'5','motivo'=>'Contagem física atual e documento HTTP 123','confirmar'=>'1']);
$draftLegacySuccess=request('POST',$baseUrl.'/contrato/conferir-abertura/'.$draftContract,$cookieAdmin,$draftLegacyDataB);
checkHttp($draftLegacySuccess['status']===302 && !str_contains($draftLegacySuccess['headers']['location'],'rascunho=')
    && (int)$draftDb->query('SELECT SUM(quantidade) FROM estoque_movimentos WHERE produto_id='.$draftProduct3)->fetchColumn()===5,'Administrador recupera saldo físico positivo do legado por conferência atual');
$draftLegacyClosed=contractDraftRecovery($draftLegacyFailure,$cookieAdmin);
$draftClosedElement=contractDraftElement($draftLegacyClosed['body'],'old-opening-'.$draftProduct3);
checkHttp($draftClosedElement->hasAttribute('open') && $draftClosedElement->getElementsByTagName('form')->length===0
    && str_contains($draftClosedElement->textContent,'A abertura já foi conferida.')
    && str_contains($draftClosedElement->textContent,'<Documento da tentativa antiga>') && str_contains($draftClosedElement->textContent,'5'),'Conferência concluída por outro operador mantém tentativa visível e impede nova recuperação');
$draftBeforeReplay=(int)$draftDb->query('SELECT COUNT(*) FROM estoque_movimentos WHERE produto_id='.$draftProduct3)->fetchColumn();
$draftLegacyReplay=request('POST',$baseUrl.'/contrato/conferir-abertura/'.$draftContract,$cookieAdmin,$draftLegacyDataB);
checkHttp(str_contains($draftLegacyReplay['headers']['location'],'rascunho=')
    && (int)$draftDb->query('SELECT COUNT(*) FROM estoque_movimentos WHERE produto_id='.$draftProduct3)->fetchColumn()===$draftBeforeReplay,'Reenvio de conferência positiva não duplica movimento');

// Required audit failure must preserve the user's correction while rolling back the whole opening.
$draftAuditPage=request('GET',$draftUrl,$cookieAdmin);
$draftAuditData=array_replace(contractDraftFields($draftAuditPage['body'],'old-opening-'.$draftProduct2),['quantidade'=>'3','motivo'=>'Documento fictício para rollback HTTP','confirmar'=>'1']);
$draftDb->exec("CREATE TRIGGER fail_contract_opening_audit BEFORE INSERT ON security_audit WHEN NEW.action='contrato.old_opening_recovered' BEGIN SELECT RAISE(ABORT,'forced'); END");
$draftAuditFailure=request('POST',$baseUrl.'/contrato/conferir-abertura/'.$draftContract,$cookieAdmin,$draftAuditData);
$draftDb->exec('DROP TRIGGER fail_contract_opening_audit');
$draftAuditRecovery=contractDraftRecovery($draftAuditFailure,$cookieAdmin);
checkHttp((int)$draftDb->query('SELECT COUNT(*) FROM estoque_movimentos WHERE produto_id='.$draftProduct2)->fetchColumn()===0
    && (int)$draftDb->query('SELECT COUNT(*) FROM modulo5_operacoes WHERE recurso_id='.$draftProduct2)->fetchColumn()===0
    && contractDraftFields($draftAuditRecovery['body'],'old-opening-'.$draftProduct2)['quantidade']==='3'
    && !preg_match('/forced|PDOException|SQLSTATE/',$draftAuditRecovery['body']),'Falha de auditoria desfaz conferência, mantém preenchimento e não revela erro técnico');
$draftZero=array_replace(contractDraftFields($draftAuditRecovery['body'],'old-opening-'.$draftProduct2),['quantidade'=>'0','confirmar'=>'1']);
$draftZeroResponse=request('POST',$baseUrl.'/contrato/conferir-abertura/'.$draftContract,$cookieAdmin,$draftZero);
$draftZeroPage=request('GET',$draftZeroResponse['headers']['location'],$cookieAdmin);
checkHttp(!str_contains($draftZeroResponse['headers']['location'],'rascunho=') && str_contains($draftZeroPage['body'],'Saldo zero confirmado'),'Contagem física zero continua sendo uma abertura confirmada explícita');

// Exclusion after loading the form, even before its submission, preserves the attempt for consultation.
$draftBeforeDeletion=request('GET',$draftUrl,$cookieEmployee);
$draftDeletedAttempt=array_replace(contractDraftFields($draftBeforeDeletion['body'],'edit-product-'.$draftProduct2),['nome'=>'<Tentativa sobre produto excluído>']);
$draftDeletionPage=request('GET',$draftUrl,$cookieAdmin);
$draftDeletionData=array_replace(contractDraftFields($draftDeletionPage['body'],'delete-contract'),['produto'=>(string)$draftProduct2,'confirmar'=>'1']);
request('POST',$baseUrl.'/contrato/excluir/'.$draftContract,$cookieAdmin,$draftDeletionData);
$draftDeletedResponse=request('POST',$baseUrl.'/contrato/produto/'.$draftContract,$cookieEmployee,$draftDeletedAttempt);
$draftDeletedRecovery=contractDraftRecovery($draftDeletedResponse,$cookieEmployee);
checkHttp(str_ends_with($draftDeletedResponse['headers']['location'],'#draft-review')
    && str_contains(contractDraftElement($draftDeletedRecovery['body'],'draft-review')->textContent,'<Tentativa sobre produto excluído>')
    && str_contains(contractDraftElement($draftDeletedRecovery['body'],'draft-review')->textContent,'Produto 2')
    && !str_contains($draftDeletedRecovery['body'],'id="edit-product-'.$draftProduct2.'"'),'Exclusão concorrente antes do POST preserva a tentativa consultiva e mantém a edição bloqueada');

$draftDb=null;
