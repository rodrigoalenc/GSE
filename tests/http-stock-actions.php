<?php

declare(strict_types=1);

// Runs only against the isolated database and fictitious authenticated users from http-smoke.php.
function stockActionDocument(string $html): DOMDocument
{
    $document=new DOMDocument(); $before=libxml_use_internal_errors(true);
    try { $document->loadHTML('<?xml encoding="UTF-8">'.$html); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($before); }
    return $document;
}

/** @return array<string,string> */
function stockActionFields(string $html): array
{
    $document=stockActionDocument($html);
    $form=(new DOMXPath($document))->query('//form[contains(concat(" ",normalize-space(@class)," ")," stock-action-form ")]')?->item(0);
    if (!$form instanceof DOMElement) { throw new RuntimeException('Formulário central de estoque não encontrado.'); }
    $fields=[];
    foreach ($form->getElementsByTagName('input') as $input) {
        if ($input->hasAttribute('name')) { $fields[$input->getAttribute('name')]=$input->getAttribute('value'); }
    }
    return $fields;
}

function stockActionText(string $html): string
{
    return preg_replace('/\s+/u',' ',stockActionDocument($html)->textContent) ?? '';
}

$stockActionCreatePage=request('GET',$baseUrl.'/contrato/criar',$cookieAdmin);
$stockActionCreated=request('POST',$baseUrl.'/contrato/criar',$cookieAdmin,[
    '_csrf_token'=>csrf($stockActionCreatePage['body']),'_request_token'=>hiddenValue($stockActionCreatePage['body'],'_request_token',32),
    'titulo'=>'Pedido HTTP controle simples de estoque','valor'=>'100,00','fornecedor'=>'',
    'folhas'=>[['observacao'=>'Nota fictícia para testar estoque','produtos'=>[
        ['nome'=>'Açúcar cristalizado HTTP','marca'=>'CHAM','unidade'=>'UN','quantidade'=>'2','preco'=>'3,00'],
        ['nome'=>'Produto HTTP sem unidades','marca'=>'Teste','unidade'=>'UN','quantidade'=>'1','preco'=>'3,00'],
    ]]],
]);
preg_match('#/contrato/detalhes/([0-9]+)#',$stockActionCreated['headers']['location'] ?? '',$stockActionMatch);
checkHttp($stockActionCreated['status']===302 && isset($stockActionMatch[1]),'Pedido fictício para testar o fluxo central de estoque');
$stockActionContract=(int)$stockActionMatch[1];
$stockActionDb=new PDO('sqlite:'.$database,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$stockActionProducts=$stockActionDb->query('SELECT * FROM pedido_produtos WHERE id_pedido='.$stockActionContract.' ORDER BY id')->fetchAll();
$stockActionProduct=(int)$stockActionProducts[0]['id']; $stockActionZeroProduct=(int)$stockActionProducts[1]['id'];
$stockActionBalance=static fn(): int => (int)$stockActionDb->query('SELECT COALESCE(SUM(quantidade),0) FROM estoque_movimentos WHERE produto_id='.$stockActionProduct)->fetchColumn();
$stockActionCount=static fn(): int => (int)$stockActionDb->query('SELECT COUNT(*) FROM estoque_movimentos WHERE produto_id='.$stockActionProduct)->fetchColumn();
$stockActionSuffix='?busca='.rawurlencode('Pedido HTTP controle simples de estoque').'&pagina=1';
$stockActionInitialUrl=$baseUrl.'/estoque/iniciar/'.$stockActionProduct.$stockActionSuffix;
$stockActionReceiveUrl=$baseUrl.'/estoque/receber/'.$stockActionProduct.$stockActionSuffix;
$stockActionConsumeUrl=$baseUrl.'/estoque/consumir/'.$stockActionProduct.$stockActionSuffix;
$stockActionAlertsUrl=$baseUrl.'/estoque/alertas/'.$stockActionProduct.$stockActionSuffix;
$stockActionList=request('GET',$baseUrl.'/estoque'.$stockActionSuffix,$cookieEmployee);
$stockActionHeaders=(new DOMXPath(stockActionDocument($stockActionList['body'])))->query('//table/thead/tr/th');
$stockActionHeaderNames=[];
foreach ($stockActionHeaders ?: [] as $stockActionHeader) { $stockActionHeaderNames[]=trim($stockActionHeader->textContent); }
checkHttp($stockActionList['status']===200 && $stockActionHeaderNames===['Produto','Disponível agora','Situação','Ações']
    && str_contains($stockActionList['body'],'Açúcar cristalizado HTTP') && str_contains($stockActionList['body'],'CHAM'),
    'Estoque concentra produto e marca, disponível agora, situação e ações em quatro colunas');
checkHttp(!str_contains($stockActionList['body'],'saldo de abertura') && !str_contains($stockActionList['body'],'Abertura pendente'),
    'Tela diária usa termos claros para a quantidade inicial');
checkHttp(request('GET',$stockActionInitialUrl,$cookieGuest)['status']===302,'Quantidade inicial exige autenticação');
$stockActionInitial=request('GET',$stockActionInitialUrl,$cookieEmployee);
$stockActionInitialFields=stockActionFields($stockActionInitial['body']);
checkHttp(str_contains($stockActionInitial['body'],'Quantas unidades estão disponíveis na escola agora?')
    && isset($stockActionInitialFields['quantidade']) && !isset($stockActionInitialFields['minimo']) && !isset($stockActionInitialFields['maximo'])
    && !isset($stockActionInitialFields['motivo']) && !isset($stockActionInitialFields['tipo']),'Quantidade inicial pede somente a contagem física, sem limites ou justificativa');
$stockActionBlocked=request('GET',$stockActionReceiveUrl,$cookieEmployee);
checkHttp(!str_contains($stockActionBlocked['body'],'class="stock-action-form"')
    && str_contains($stockActionBlocked['body'],'Confirme primeiro quantas unidades'),'Recebimento exige uma quantidade inicial confirmada');
checkHttp(request('POST',$stockActionInitialUrl,$cookieEmployee,array_replace($stockActionInitialFields,['_csrf_token'=>'invalid','quantidade'=>'20','etapa'=>'confirmar']))['status']===419,
    'Formulário central mantém proteção CSRF');
$stockActionUnreviewed=request('POST',$stockActionInitialUrl,$cookieEmployee,array_replace($stockActionInitialFields,['quantidade'=>'20','etapa'=>'confirmar']));
checkHttp($stockActionUnreviewed['status']===200 && str_contains($stockActionUnreviewed['body'],'Confira a quantidade antes de confirmar')
    && $stockActionBalance()===0 && $stockActionCount()===0,'Quantidade inicial exige revisão antes da confirmação');
$stockActionPreview=request('POST',$stockActionInitialUrl,$cookieEmployee,array_replace($stockActionInitialFields,['quantidade'=>'20','etapa'=>'revisar']));
checkHttp($stockActionPreview['status']===200 && str_contains(stockActionText($stockActionPreview['body']),'20 unidades')
    && $stockActionBalance()===0 && (int)$stockActionDb->query('SELECT estoque_inicializado FROM pedido_produtos WHERE id='.$stockActionProduct)->fetchColumn()===0,
    'Conferir quantidade inicial apresenta revisão sem alterar o estoque');
$stockActionInitialPreviewFields=stockActionFields($stockActionPreview['body']);
$stockActionEdit=request('POST',$stockActionInitialUrl,$cookieEmployee,array_replace($stockActionInitialPreviewFields,['etapa'=>'editar']));
checkHttp(stockActionFields($stockActionEdit['body'])['quantidade']==='20' && !str_contains($stockActionEdit['body'],'class="stock-confirmation"') && $stockActionBalance()===0,
    'Alterar quantidade volta ao formulário com a quantidade preservada e sem registrar movimento');
$stockActionPreview=request('POST',$stockActionInitialUrl,$cookieEmployee,array_replace(stockActionFields($stockActionEdit['body']),['etapa'=>'revisar']));
$stockActionConfirmed=request('POST',$stockActionInitialUrl,$cookieEmployee,array_replace(stockActionFields($stockActionPreview['body']),['etapa'=>'confirmar']));
$stockActionConfigured=$stockActionDb->query('SELECT * FROM pedido_produtos WHERE id='.$stockActionProduct)->fetch();
checkHttp($stockActionConfirmed['status']===302 && str_contains($stockActionConfirmed['headers']['location'],'busca=')
    && str_ends_with($stockActionConfirmed['headers']['location'],'#produto-'.$stockActionProduct)
    && $stockActionBalance()===20 && (int)$stockActionConfigured['estoque_inicializado']===1
    && $stockActionConfigured['estoque_minimo']===null && $stockActionConfigured['estoque_maximo']===null,
    'Confirmar quantidade inicial registra vinte unidades sem exigir limites e preserva a busca no retorno');
$stockActionAfterInitial=request('GET',$baseUrl.'/estoque'.$stockActionSuffix,$cookieEmployee);
checkHttp(!str_contains($stockActionAfterInitial['body'],'estoque/iniciar/'.$stockActionProduct.'?')
    && str_contains($stockActionAfterInitial['body'],'estoque/receber/'.$stockActionProduct)
    && str_contains($stockActionAfterInitial['body'],'estoque/consumir/'.$stockActionProduct)
    && !str_contains(request('GET',$stockActionInitialUrl,$cookieEmployee)['body'],'class="stock-action-form"'),
    'Depois da confirmação a quantidade inicial deixa de ser oferecida e surgem receber e consumir');

$stockActionReceive=request('GET',$stockActionReceiveUrl,$cookieEmployee);
$stockActionReceivePreview=request('POST',$stockActionReceiveUrl,$cookieEmployee,array_replace(stockActionFields($stockActionReceive['body']),['quantidade'=>'10','etapa'=>'revisar']));
checkHttp(str_contains(stockActionText($stockActionReceivePreview['body']),'A quantidade disponível passará de 20 para 30.') && $stockActionBalance()===20,
    'Receber mostra a quantidade anterior e a quantidade resultante antes de confirmar');
$stockActionReceiveFields=stockActionFields($stockActionReceivePreview['body']);
$stockActionReceived=request('POST',$stockActionReceiveUrl,$cookieEmployee,array_replace($stockActionReceiveFields,['etapa'=>'confirmar']));
checkHttp($stockActionReceived['status']===302 && $stockActionBalance()===30 && $stockActionCount()===2,'Recebimento confirmado atualiza o estoque mesmo sem máximo configurado');
$stockActionReceiveReplay=request('POST',$stockActionReceiveUrl,$cookieEmployee,array_replace($stockActionReceiveFields,['etapa'=>'confirmar']));
checkHttp($stockActionReceiveReplay['status']===200 && $stockActionBalance()===30 && $stockActionCount()===2,'Reenvio da mesma confirmação não duplica o recebimento');

$stockActionConsume=request('GET',$stockActionConsumeUrl,$cookieEmployee);
$stockActionConsumeFields=stockActionFields($stockActionConsume['body']);
$stockActionOverdraw=request('POST',$stockActionConsumeUrl,$cookieEmployee,array_replace($stockActionConsumeFields,['quantidade'=>'31','etapa'=>'revisar']));
$stockActionOverdrawFields=stockActionFields($stockActionOverdraw['body']);
checkHttp($stockActionOverdraw['status']===200 && str_contains($stockActionOverdraw['body'],'não pode ser maior que a quantidade disponível')
    && $stockActionOverdrawFields['quantidade']==='31' && $stockActionOverdrawFields['chave']!==$stockActionConsumeFields['chave']
    && $stockActionBalance()===30 && $stockActionCount()===2,'Servidor recusa consumir além do disponível e mantém a quantidade com nova chave');
$stockActionConsumePreview=request('POST',$stockActionConsumeUrl,$cookieEmployee,array_replace($stockActionOverdrawFields,['quantidade'=>'6','etapa'=>'revisar']));
checkHttp(str_contains(stockActionText($stockActionConsumePreview['body']),'Você vai registrar o consumo de 6 unidades. A quantidade disponível passará de 30 para 24.')
    && $stockActionBalance()===30,'Consumo informa a quantidade utilizada e a redução exata antes de confirmar');
$stockActionConsumed=request('POST',$stockActionConsumeUrl,$cookieEmployee,array_replace(stockActionFields($stockActionConsumePreview['body']),['etapa'=>'confirmar']));
checkHttp($stockActionConsumed['status']===302 && $stockActionBalance()===24 && $stockActionCount()===3,'Consumo confirmado desconta a quantidade e grava um movimento');
$stockActionHistory=request('GET',$baseUrl.'/contrato/historico/'.$stockActionProduct,$cookieEmployee);
$stockActionLast=$stockActionDb->query('SELECT * FROM estoque_movimentos WHERE produto_id='.$stockActionProduct.' ORDER BY id DESC LIMIT 1')->fetch();
$stockActionHistoryCells=(new DOMXPath(stockActionDocument($stockActionHistory['body'])))->query('//table/tbody/tr[1]/td');
$stockActionExpectedTime=(new DateTimeImmutable($stockActionLast['criado_em'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Cuiaba'))->format('d/m/Y H:i');
checkHttp($stockActionHistory['status']===200 && str_contains(stockActionText($stockActionHistory['body']),'Consumo')
    && str_contains(stockActionText($stockActionHistory['body']),'-6') && (int)$stockActionLast['quantidade']===-6 && $stockActionLast['criado_em']!==''
    && (int)$stockActionLast['usuario_id']===$employeeActorId && trim($stockActionHistoryCells?->item(1)?->textContent ?? '')===$stockActionExpectedTime
    && trim($stockActionHistoryCells?->item(5)?->textContent ?? '')==='Funcionário HTTP Editado',
    'Histórico mantém consumo, quantidade, data e autoria da movimentação');

$stockActionAlerts=request('GET',$stockActionAlertsUrl,$cookieEmployee);
$stockActionAlertDocument=stockActionDocument($stockActionAlerts['body']);
$stockActionAlertXPath=new DOMXPath($stockActionAlertDocument);
checkHttp($stockActionAlerts['status']===200 && str_contains($stockActionAlerts['body'],'Avisar quando restarem')
    && str_contains($stockActionAlerts['body'],'Configuração avançada')
    && $stockActionAlertXPath->query('//input[(@name="minimo" or @name="maximo") and @required]')?->length===0,
    'Alertas ficam em formulário próprio com mínimo opcional e máximo avançado opcional');
$stockActionAlertSave=request('POST',$stockActionAlertsUrl,$cookieEmployee,array_replace(stockActionFields($stockActionAlerts['body']),['minimo'=>'24','maximo'=>'']));
$stockActionAlertState=$stockActionDb->query('SELECT estoque_minimo,estoque_maximo FROM pedido_produtos WHERE id='.$stockActionProduct)->fetch();
checkHttp($stockActionAlertSave['status']===302 && (int)$stockActionAlertState['estoque_minimo']===24 && $stockActionAlertState['estoque_maximo']===null
    && str_contains(request('GET',$baseUrl.'/estoque'.$stockActionSuffix,$cookieEmployee)['body'],'Estoque baixo'),'Mínimo opcional sinaliza estoque baixo sem configurar bloqueio máximo');
$stockActionAlerts=request('GET',$stockActionAlertsUrl,$cookieEmployee);
$stockActionBadCap=request('POST',$stockActionAlertsUrl,$cookieEmployee,array_replace(stockActionFields($stockActionAlerts['body']),['minimo'=>'0','maximo'=>'23']));
checkHttp($stockActionBadCap['status']===200 && stockActionFields($stockActionBadCap['body'])['maximo']==='23'
    && $stockActionDb->query('SELECT estoque_maximo FROM pedido_produtos WHERE id='.$stockActionProduct)->fetchColumn()===null,
    'Máximo inferior ao disponível é recusado sem perder o preenchimento');
$stockActionCap=request('POST',$stockActionAlertsUrl,$cookieEmployee,array_replace(stockActionFields($stockActionBadCap['body']),['minimo'=>'0','maximo'=>'25']));
$stockActionReceive=request('GET',$stockActionReceiveUrl,$cookieEmployee);
$stockActionCapBlocked=request('POST',$stockActionReceiveUrl,$cookieEmployee,array_replace(stockActionFields($stockActionReceive['body']),['quantidade'=>'2','etapa'=>'revisar']));
checkHttp($stockActionCap['status']===302 && $stockActionCapBlocked['status']===200 && str_contains($stockActionCapBlocked['body'],'máximo permitido')
    && $stockActionBalance()===24,'Máximo configurado bloqueia um recebimento que ultrapassaria o limite');
$stockActionAlerts=request('GET',$stockActionAlertsUrl,$cookieEmployee);
$stockActionClear=request('POST',$stockActionAlertsUrl,$cookieEmployee,array_replace(stockActionFields($stockActionAlerts['body']),['minimo'=>'','maximo'=>'']));
$stockActionAlertState=$stockActionDb->query('SELECT estoque_minimo,estoque_maximo FROM pedido_produtos WHERE id='.$stockActionProduct)->fetch();
checkHttp($stockActionClear['status']===302 && $stockActionAlertState['estoque_minimo']===null && $stockActionAlertState['estoque_maximo']===null,
    'Limpar os campos remove o aviso e o bloqueio sem alterar movimentos');

// Another operator changes the quantity between review and confirmation.
$stockActionConsume=request('GET',$stockActionConsumeUrl,$cookieEmployee);
$stockActionStalePreview=request('POST',$stockActionConsumeUrl,$cookieEmployee,array_replace(stockActionFields($stockActionConsume['body']),['quantidade'=>'5','etapa'=>'revisar']));
$stockActionStaleFields=stockActionFields($stockActionStalePreview['body']);
$stockActionOther=request('GET',$stockActionReceiveUrl,$cookieAdmin);
$stockActionOtherPreview=request('POST',$stockActionReceiveUrl,$cookieAdmin,array_replace(stockActionFields($stockActionOther['body']),['quantidade'=>'1','etapa'=>'revisar']));
request('POST',$stockActionReceiveUrl,$cookieAdmin,array_replace(stockActionFields($stockActionOtherPreview['body']),['etapa'=>'confirmar']));
$stockActionStale=request('POST',$stockActionConsumeUrl,$cookieEmployee,array_replace($stockActionStaleFields,['etapa'=>'confirmar']));
$stockActionStaleRecovered=stockActionFields($stockActionStale['body']);
checkHttp($stockActionStale['status']===200 && str_contains($stockActionStale['body'],'mudou desde que você abriu')
    && $stockActionStaleRecovered['quantidade']==='5' && (int)$stockActionStaleRecovered['revisao']>(int)$stockActionStaleFields['revisao']
    && $stockActionStaleRecovered['chave']!==$stockActionStaleFields['chave'] && $stockActionBalance()===25,
    'Mudança entre revisão e confirmação preserva a tentativa, atualiza revisão e chave e não desconta o consumo antigo');
$stockActionReviewed=request('POST',$stockActionConsumeUrl,$cookieEmployee,array_replace($stockActionStaleRecovered,['etapa'=>'revisar']));
checkHttp(str_contains(stockActionText($stockActionReviewed['body']),'A quantidade disponível passará de 25 para 20.'),'Revisão após conflito usa a quantidade atual');
$stockActionReviewedSave=request('POST',$stockActionConsumeUrl,$cookieEmployee,array_replace(stockActionFields($stockActionReviewed['body']),['etapa'=>'confirmar']));
checkHttp($stockActionReviewedSave['status']===302 && $stockActionBalance()===20,'Tentativa revista pode ser confirmada com os dados atuais');

$stockActionZeroUrl=$baseUrl.'/estoque/iniciar/'.$stockActionZeroProduct;
$stockActionZero=request('GET',$stockActionZeroUrl,$cookieEmployee);
$stockActionZeroPreview=request('POST',$stockActionZeroUrl,$cookieEmployee,array_replace(stockActionFields($stockActionZero['body']),['quantidade'=>'0','etapa'=>'revisar']));
$stockActionZeroSaved=request('POST',$stockActionZeroUrl,$cookieEmployee,array_replace(stockActionFields($stockActionZeroPreview['body']),['etapa'=>'confirmar']));
checkHttp($stockActionZeroSaved['status']===302 && (int)$stockActionDb->query('SELECT estoque_inicializado FROM pedido_produtos WHERE id='.$stockActionZeroProduct)->fetchColumn()===1
    && (int)$stockActionDb->query('SELECT COUNT(*) FROM estoque_movimentos WHERE produto_id='.$stockActionZeroProduct)->fetchColumn()===0
    && (int)$stockActionDb->query("SELECT COUNT(*) FROM modulo5_operacoes WHERE recurso_id=".$stockActionZeroProduct." AND tipo='abertura_estoque'")->fetchColumn()===1,
    'Quantidade inicial zero é confirmada expressamente sem criar um movimento fictício');
unset($stockActionBalance,$stockActionCount);
$stockActionDb=null;
