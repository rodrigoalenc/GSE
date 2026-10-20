<?php

declare(strict_types=1);

// Included by http-smoke.php; uses only its temporary database and fictitious users.
/** @return array<string,string> */
function noteReceiptFields(string $html,string $formId): array
{
    $document=new DOMDocument(); $previous=libxml_use_internal_errors(true);
    try { $document->loadHTML('<?xml encoding="UTF-8">'.$html); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    $form=(new DOMXPath($document))->query('//form[@id="'.$formId.'"]')?->item(0);
    if (!$form instanceof DOMElement) { throw new RuntimeException('Formulário de recebimento indisponível: '.$formId); }
    $fields=[];
    foreach ($form->getElementsByTagName('input') as $input) {
        if ($input->hasAttribute('name')) { $fields[$input->getAttribute('name')]=$input->getAttribute('value'); }
    }
    return $fields;
}

$noteReceiptCreatePage=request('GET',$baseUrl.'/contrato/criar',$cookieAdmin);
$noteReceiptCreated=request('POST',$baseUrl.'/contrato/criar',$cookieAdmin,[
    '_csrf_token'=>csrf($noteReceiptCreatePage['body']),'_request_token'=>hiddenValue($noteReceiptCreatePage['body'],'_request_token',32),
    'titulo'=>'Pedido fictício de recebimento por nota','valor'=>'100,00','fornecedor'=>'','folhas'=>[['observacao'=>'Entrega parcial','produtos'=>[
        ['nome'=>'Açúcar recebimento','marca'=>'CHAM','unidade'=>'UN','quantidade'=>'5','preco'=>'1,00'],
        ['nome'=>'Arroz recebimento','marca'=>'Teste','unidade'=>'K','quantidade'=>'4','preco'=>'1,00'],
        ['nome'=>'Produto sem contagem','marca'=>'Teste','unidade'=>'UN','quantidade'=>'2','preco'=>'1,00'],
    ]]],
]);
preg_match('#/contrato/detalhes/([0-9]+)#',$noteReceiptCreated['headers']['location'] ?? '',$noteReceiptMatch);
checkHttp(isset($noteReceiptMatch[1]),'Recebimento: pedido fictício com três produtos criado');
$noteReceiptContract=(int)$noteReceiptMatch[1];
$noteReceiptDb=new PDO('sqlite:'.$database,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$noteReceiptSheet=(int)$noteReceiptDb->query('SELECT id FROM pedido_paginas WHERE id_pedido='.$noteReceiptContract)->fetchColumn();
$noteReceiptItems=$noteReceiptDb->query('SELECT * FROM pedido_produtos WHERE id_pedido='.$noteReceiptContract.' ORDER BY id')->fetchAll();
$noteReceiptFirst=(int)$noteReceiptItems[0]['id']; $noteReceiptSecond=(int)$noteReceiptItems[1]['id']; $noteReceiptPending=(int)$noteReceiptItems[2]['id'];
$noteReceiptUrl=$baseUrl.'/contrato/recebimento/'.$noteReceiptContract.'?folha='.$noteReceiptSheet;
$noteReceiptPostUrl=$baseUrl.'/contrato/recebimento/'.$noteReceiptContract;
$noteReceiptPage=request('GET',$noteReceiptUrl,$cookieEmployee);
$noteReceiptInitial=noteReceiptFields($noteReceiptPage['body'],'note-receipt-form');
checkHttp($noteReceiptPage['status']===200 && $noteReceiptInitial['quantidades['.$noteReceiptPending.']']==='0'
    && str_contains($noteReceiptPage['body'],'/estoque/iniciar/'.$noteReceiptPending),'Recebimento: produtos sem contagem começam em zero e permitem confirmar a quantidade inicial');

foreach (array_slice($noteReceiptItems,0,2) as $noteReceiptIndex=>$noteReceiptItem) {
    $noteReceiptOpened=request('POST',$baseUrl.'/contrato/estoque/'.$noteReceiptContract,$cookieEmployee,[
        '_csrf_token'=>csrf($noteReceiptPage['body']),'produto'=>(string)$noteReceiptItem['id'],'revisao'=>(string)$noteReceiptItem['revisao'],
        'minimo'=>'','maximo'=>'','abertura'=>$noteReceiptIndex===0 ? '20' : '0','confirmar_abertura'=>'1','chave'=>bin2hex(random_bytes(16)),
    ]);
    checkHttp($noteReceiptOpened['status']===302 && !str_contains($noteReceiptOpened['headers']['location'] ?? '', 'rascunho='),'Recebimento: quantidade inicial confirmada com alertas opcionais');
}
$noteReceiptPage=request('GET',$noteReceiptUrl,$cookieEmployee);
$noteReceiptFields=noteReceiptFields($noteReceiptPage['body'],'note-receipt-form');
checkHttp($noteReceiptFields['quantidades['.$noteReceiptFirst.']']==='5' && $noteReceiptFields['quantidades['.$noteReceiptSecond.']']==='4',
    'Recebimento: quantidades da nota preenchidas para conferir o que realmente chegou');
$noteReceiptBefore=(int)$noteReceiptDb->query('SELECT COUNT(*) FROM estoque_movimentos')->fetchColumn();
$noteReceiptReview=request('POST',$noteReceiptPostUrl,$cookieEmployee,$noteReceiptFields);
checkHttp($noteReceiptReview['status']===200 && str_contains($noteReceiptReview['body'],'Disponível antes')
    && str_contains($noteReceiptReview['body'],'Disponível depois') && str_contains($noteReceiptReview['body'],'25 UN')
    && (int)$noteReceiptDb->query('SELECT COUNT(*) FROM estoque_movimentos')->fetchColumn()===$noteReceiptBefore,
    'Recebimento: revisão mostra quantidades antes e depois sem alterar o estoque');
$noteReceiptConfirm=noteReceiptFields($noteReceiptReview['body'],'confirm-note-receipt'); $noteReceiptConfirm['etapa']='confirmar';
$noteReceiptSaved=request('POST',$noteReceiptPostUrl,$cookieEmployee,$noteReceiptConfirm);
checkHttp($noteReceiptSaved['status']===302 && str_ends_with($noteReceiptSaved['headers']['location'] ?? '', '#folha-'.$noteReceiptSheet)
    && (int)$noteReceiptDb->query('SELECT SUM(quantidade) FROM estoque_movimentos WHERE produto_id='.$noteReceiptFirst)->fetchColumn()===25
    && (int)$noteReceiptDb->query('SELECT SUM(quantidade) FROM estoque_movimentos WHERE produto_id='.$noteReceiptSecond)->fetchColumn()===4,
    'Recebimento: uma confirmação atualiza todos os produtos recebidos na nota');
$noteReceiptRepeated=request('POST',$noteReceiptPostUrl,$cookieEmployee,$noteReceiptConfirm);
checkHttp($noteReceiptRepeated['status']===302 && (int)$noteReceiptDb->query('SELECT COUNT(*) FROM estoque_movimentos')->fetchColumn()===$noteReceiptBefore+2,
    'Recebimento: repetir a mesma confirmação não duplica a entrada');

$noteReceiptPartial=noteReceiptFields(request('GET',$noteReceiptUrl,$cookieEmployee)['body'],'note-receipt-form');
$noteReceiptPartial['quantidades['.$noteReceiptFirst.']']='2'; $noteReceiptPartial['quantidades['.$noteReceiptSecond.']']='0';
$noteReceiptPartialReview=request('POST',$noteReceiptPostUrl,$cookieEmployee,$noteReceiptPartial);
$noteReceiptCorrect=noteReceiptFields($noteReceiptPartialReview['body'],'confirm-note-receipt'); $noteReceiptCorrect['etapa']='editar';
$noteReceiptCorrection=request('POST',$noteReceiptPostUrl,$cookieEmployee,$noteReceiptCorrect);
$noteReceiptCorrectionFields=noteReceiptFields($noteReceiptCorrection['body'],'note-receipt-form');
checkHttp($noteReceiptCorrectionFields['quantidades['.$noteReceiptFirst.']']==='2' && $noteReceiptCorrectionFields['quantidades['.$noteReceiptSecond.']']==='0'
    && (int)$noteReceiptDb->query('SELECT SUM(quantidade) FROM estoque_movimentos WHERE produto_id='.$noteReceiptFirst)->fetchColumn()===25,
    'Recebimento: corrigir volta ao formulário com valores preservados sem atualizar o estoque');
$noteReceiptPartialReview=request('POST',$noteReceiptPostUrl,$cookieEmployee,$noteReceiptCorrectionFields);
$noteReceiptPartialConfirm=noteReceiptFields($noteReceiptPartialReview['body'],'confirm-note-receipt'); $noteReceiptPartialConfirm['etapa']='confirmar';
request('POST',$noteReceiptPostUrl,$cookieEmployee,$noteReceiptPartialConfirm);
checkHttp((int)$noteReceiptDb->query('SELECT SUM(quantidade) FROM estoque_movimentos WHERE produto_id='.$noteReceiptFirst)->fetchColumn()===27
    && (int)$noteReceiptDb->query('SELECT SUM(quantidade) FROM estoque_movimentos WHERE produto_id='.$noteReceiptSecond)->fetchColumn()===4
    && (int)$noteReceiptDb->query('SELECT estoque_inicializado FROM pedido_produtos WHERE id='.$noteReceiptPending)->fetchColumn()===0,
    'Recebimento: entrega parcial respeita zero e não inicia automaticamente produtos pendentes');

$noteReceiptStaleFields=noteReceiptFields(request('GET',$noteReceiptUrl,$cookieEmployee)['body'],'note-receipt-form');
$noteReceiptStaleFields['quantidades['.$noteReceiptFirst.']']='2'; $noteReceiptStaleFields['quantidades['.$noteReceiptSecond.']']='1';
$noteReceiptStaleReview=request('POST',$noteReceiptPostUrl,$cookieEmployee,$noteReceiptStaleFields);
$noteReceiptSecondRevision=(int)$noteReceiptDb->query('SELECT revisao FROM pedido_produtos WHERE id='.$noteReceiptSecond)->fetchColumn();
request('POST',$baseUrl.'/contrato/movimentar/'.$noteReceiptContract,$cookieEmployee,[
    '_csrf_token'=>csrf($noteReceiptStaleReview['body']),'produto'=>(string)$noteReceiptSecond,'revisao'=>(string)$noteReceiptSecondRevision,
    'tipo'=>'entrada','quantidade'=>'1','motivo'=>'Outra entrega fictícia simultânea','chave'=>bin2hex(random_bytes(16)),
]);
$noteReceiptStaleConfirm=noteReceiptFields($noteReceiptStaleReview['body'],'confirm-note-receipt'); $noteReceiptStaleConfirm['etapa']='confirmar';
$noteReceiptRejected=request('POST',$noteReceiptPostUrl,$cookieEmployee,$noteReceiptStaleConfirm);
$noteReceiptRecovered=noteReceiptFields($noteReceiptRejected['body'],'note-receipt-form');
checkHttp($noteReceiptRejected['status']===200 && $noteReceiptRecovered['quantidades['.$noteReceiptFirst.']']==='2'
    && $noteReceiptRecovered['quantidades['.$noteReceiptSecond.']']==='1'
    && (int)$noteReceiptDb->query('SELECT SUM(quantidade) FROM estoque_movimentos WHERE produto_id='.$noteReceiptFirst)->fetchColumn()===27
    && (int)$noteReceiptDb->query('SELECT SUM(quantidade) FROM estoque_movimentos WHERE produto_id='.$noteReceiptSecond)->fetchColumn()===5,
    'Recebimento: alteração simultânea impede toda a entrega e preserva as quantidades digitadas');
$noteReceiptRecovered['quantidades['.$noteReceiptPending.']']='1';
$noteReceiptPendingRejected=request('POST',$noteReceiptPostUrl,$cookieEmployee,$noteReceiptRecovered);
checkHttp($noteReceiptPendingRejected['status']===200 && str_contains($noteReceiptPendingRejected['body'],'Confirme a quantidade inicial')
    && (int)$noteReceiptDb->query('SELECT COUNT(*) FROM estoque_movimentos WHERE produto_id='.$noteReceiptPending)->fetchColumn()===0,
    'Recebimento: produto sem quantidade inicial impede confirmação e mantém histórico vazio');
$noteReceiptBypass=request('POST',$noteReceiptPostUrl,$cookieEmployee,[
    '_csrf_token'=>csrf($noteReceiptPendingRejected['body']),'folha'=>(string)$noteReceiptSheet,'chave'=>bin2hex(random_bytes(16)),'etapa'=>'confirmar',
]);
checkHttp($noteReceiptBypass['status']===200 && str_contains($noteReceiptBypass['body'],'A confirmação expirou'),
    'Recebimento: envio direto sem revisão válida não altera o estoque');
$noteReceiptDb=null;
