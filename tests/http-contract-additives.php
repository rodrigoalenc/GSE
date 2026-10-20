<?php

declare(strict_types=1);

// Only the disposable HTTP database and fictitious sessions from http-smoke.php are used.
function contractAdditiveXPath(string $html): DOMXPath
{
    $document=new DOMDocument(); $before=libxml_use_internal_errors(true);
    try { $document->loadHTML('<?xml encoding="UTF-8">'.$html); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($before); }
    return new DOMXPath($document);
}

/** @return array<string,string> */
function contractAdditiveCopyFields(string $html,int $sheet): array
{
    $xpath=contractAdditiveXPath($html);
    $inputs=$xpath->query('//*[@id="folha-'.$sheet.'"]//form[contains(@action,"/contrato/folha/")]//input[@name]');
    $fields=[];
    foreach ($inputs as $input) { $fields[$input->getAttribute('name')]=$input->getAttribute('value'); }
    if (($fields['copiar'] ?? '')!==(string)$sheet) { throw new RuntimeException('Formulário de duplicação não encontrado.'); }
    return $fields;
}

$additiveForm=request('GET',$baseUrl.'/contrato/criar',$cookieEmployee);
$additiveCreated=request('POST',$baseUrl.'/contrato/criar',$cookieEmployee,[
    '_csrf_token'=>csrf($additiveForm['body']),'_request_token'=>hiddenValue($additiveForm['body'],'_request_token',32),
    'titulo'=>'Pedido HTTP aditivos','valor'=>'100,00','fornecedor'=>'',
]);
preg_match('#/contrato/detalhes/([0-9]+)#',$additiveCreated['headers']['location'] ?? '',$additiveMatch);
checkHttp($additiveCreated['status']===302 && isset($additiveMatch[1]),'Funcionário cria pedido fictício para o fluxo de aditivos');
$additiveContract=(int)$additiveMatch[1];
$additiveUrl=$baseUrl.'/contrato/detalhes/'.$additiveContract;
$additiveDb=new PDO('sqlite:'.$database,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$additiveOriginalSheet=(int)$additiveDb->query('SELECT id FROM pedido_paginas WHERE id_pedido='.$additiveContract)->fetchColumn();
$additiveState=static function() use ($additiveDb,$additiveContract): array {
    $state=[];
    foreach (['pedidos'=>'id','pedido_paginas'=>'id_pedido','pedido_produtos'=>'id_pedido'] as $table=>$field) {
        $state[$table]=$additiveDb->query('SELECT * FROM '.$table.' WHERE '.$field.'='.$additiveContract.' ORDER BY id')->fetchAll();
    }
    $state['operacoes']=$additiveDb->query('SELECT * FROM modulo5_operacoes ORDER BY idempotencia')->fetchAll();
    return $state;
};
$additivePage=request('GET',$additiveUrl,$cookieEmployee);
$additiveOriginalItem=array_replace(contractDraftFields($additivePage['body'],'add-product-'.$additiveOriginalSheet),[
    'nome'=>'Produto comum no teto','marca'=>'','unidade'=>'UN','quantidade'=>'1','preco'=>'100,00',
]);
$additiveOriginalSaved=request('POST',$baseUrl.'/contrato/produto/'.$additiveContract,$cookieEmployee,$additiveOriginalItem);
checkHttp($additiveOriginalSaved['status']===302
    && (int)$additiveDb->query('SELECT SUM(total_centavos) FROM pedido_produtos WHERE id_pedido='.$additiveContract)->fetchColumn()===10000,
    'Nota comum utiliza exatamente o teto original de R$ 100,00');
$additivePage=request('GET',$additiveUrl,$cookieEmployee);
$additiveCommonOverflow=array_replace(contractDraftFields($additivePage['body'],'add-product-'.$additiveOriginalSheet),[
    'nome'=>'Excesso comum preservado','marca'=>'','unidade'=>'UN','quantidade'=>'1','preco'=>'0,01',
]);
$additiveBefore=$additiveState();
$additiveCommonRecovery=contractDraftRecovery(request('POST',$baseUrl.'/contrato/produto/'.$additiveContract,$cookieEmployee,$additiveCommonOverflow),$cookieEmployee);
$additiveCommonFields=contractDraftFields($additiveCommonRecovery['body'],'add-product-'.$additiveOriginalSheet);
checkHttp($additiveState()===$additiveBefore && $additiveCommonFields['nome']==='Excesso comum preservado'
    && $additiveCommonFields['preco']==='0,01' && str_contains($additiveCommonRecovery['body'],'teto original'),
    'Excesso de um centavo na nota comum é recusado sem alterações e mantém os campos para revisão');

$additiveCreateFields=contractDraftFields($additivePage['body'],'new-additive');
$additiveCreateFields['observacao']='Aditivo HTTP conferido';
checkHttp($additiveCreateFields['aditivo']==='1','Formulário Adicionar aditivos identifica expressamente a nota adicional');
$additiveNoteCreated=request('POST',$baseUrl.'/contrato/folha/'.$additiveContract,$cookieEmployee,$additiveCreateFields);
$additiveSheet=(int)$additiveDb->query('SELECT id FROM pedido_paginas WHERE id_pedido='.$additiveContract.' AND aditivo=1')->fetchColumn();
checkHttp($additiveNoteCreated['status']===302 && $additiveSheet>0
    && str_ends_with($additiveNoteCreated['headers']['location'] ?? '','#folha-'.$additiveSheet),
    'Funcionário cria nota de aditivo e retorna diretamente para a nota criada');
$additivePage=request('GET',$additiveUrl,$cookieEmployee);
$additiveXPath=contractAdditiveXPath($additivePage['body']);
checkHttp($additiveXPath->query('//a[@href="#folha-'.$additiveSheet.'" and contains(concat(" ",normalize-space(@class)," ")," contract-tab-additive ")]')->length===1
    && str_contains(contractDraftElement($additivePage['body'],'folha-'.$additiveSheet)->getAttribute('class'),'contract-note-additive')
    && str_contains(contractDraftElement($additivePage['body'],'folha-'.$additiveSheet)->textContent,'Aditivo'),
    'Aba e nota de aditivo recebem destaque próprio e identificação textual');
$additiveItemFields=array_replace(contractDraftFields($additivePage['body'],'add-product-'.$additiveSheet),[
    'nome'=>'Produto do aditivo','marca'=>'','unidade'=>'UN','quantidade'=>'1','preco'=>'25,00',
]);
$additiveItemSaved=request('POST',$baseUrl.'/contrato/produto/'.$additiveContract,$cookieEmployee,$additiveItemFields);
$additiveItem=(int)$additiveDb->query('SELECT i.id FROM pedido_produtos i JOIN pedido_paginas pg ON pg.id_pedido=i.id_pedido AND pg.numero_pagina=i.numero_pagina WHERE pg.id='.$additiveSheet)->fetchColumn();
checkHttp($additiveItemSaved['status']===302 && $additiveItem>0
    && (int)$additiveDb->query('SELECT SUM(total_centavos) FROM pedido_produtos WHERE id_pedido='.$additiveContract)->fetchColumn()===12500,
    'Aditivo aceita os R$ 25,00 adicionais além do teto original integralmente utilizado');
$additivePage=request('GET',$additiveUrl,$cookieEmployee);
$additiveBefore=$additiveState();
$additiveOverflow=array_replace(contractDraftFields($additivePage['body'],'edit-product-'.$additiveItem),['quantidade'=>'1','preco'=>'25,01']);
$additiveRecovery=contractDraftRecovery(request('POST',$baseUrl.'/contrato/produto/'.$additiveContract,$cookieEmployee,$additiveOverflow),$cookieEmployee);
checkHttp($additiveState()===$additiveBefore
    && contractDraftFields($additiveRecovery['body'],'edit-product-'.$additiveItem)['preco']==='25,01'
    && str_contains($additiveRecovery['body'],'25%'),
    'Edição de R$ 25,01 é recusada pelo limite conjunto dos aditivos e preserva o valor tentado');

foreach (['',$additiveSheet] as $additivePrintSheet) {
    $additivePrint=request('GET',$baseUrl.'/contrato/imprimir/'.$additiveContract.($additivePrintSheet!=='' ? '?folha='.$additivePrintSheet : ''),$cookieEmployee);
    $additivePrintXPath=contractAdditiveXPath($additivePrint['body']);
    $additivePrintTotals=$additivePrintXPath->query('//*[contains(concat(" ",normalize-space(@class)," ")," print-totals ")]')->item(0)?->textContent ?? '';
    $additivePrintBalance=$additivePrintXPath->query('//*[contains(concat(" ",normalize-space(@class)," ")," print-balance ")]')->item(0)?->textContent ?? '';
    checkHttp($additivePrint['status']===200 && str_contains($additivePrintTotals,'R$ 125,00')
        && str_contains($additivePrintBalance,'R$ 0,00') && !str_contains($additivePrintBalance,'R$ -')
        && str_contains($additivePrint['body'],'ADITIVO')
        && $additivePrintXPath->query('//section[contains(concat(" ",normalize-space(@class)," ")," print-note ")]')->length===($additivePrintSheet==='' ? 2 : 1),
        'Impressão '.($additivePrintSheet==='' ? 'do pedido' : 'da nota adicional').' mantém teto global, saldo final zero e identificação ADITIVO');
}
$additiveBefore=$additiveState();
$additiveDuplicateRejected=request('POST',$baseUrl.'/contrato/folha/'.$additiveContract,$cookieEmployee,contractAdditiveCopyFields($additivePage['body'],$additiveSheet));
checkHttp($additiveDuplicateRejected['status']===302 && $additiveState()===$additiveBefore,
    'Duplicar aditivo já no limite é recusado sem criar nota, produto ou operação parcial');
$additivePage=request('GET',$additiveUrl,$cookieAdmin);
$additiveReduced=array_replace(contractDraftFields($additivePage['body'],'edit-product-'.$additiveItem),['preco'=>'12,50']);
request('POST',$baseUrl.'/contrato/produto/'.$additiveContract,$cookieAdmin,$additiveReduced);
$additivePage=request('GET',$additiveUrl,$cookieAdmin);
$additiveDuplicated=request('POST',$baseUrl.'/contrato/folha/'.$additiveContract,$cookieAdmin,contractAdditiveCopyFields($additivePage['body'],$additiveSheet));
checkHttp($additiveDuplicated['status']===302
    && (int)$additiveDb->query('SELECT COUNT(*) FROM pedido_paginas WHERE id_pedido='.$additiveContract.' AND aditivo=1')->fetchColumn()===2
    && (int)$additiveDb->query('SELECT SUM(i.total_centavos) FROM pedido_produtos i JOIN pedido_paginas pg ON pg.id_pedido=i.id_pedido AND pg.numero_pagina=i.numero_pagina WHERE pg.id_pedido='.$additiveContract.' AND pg.aditivo=1')->fetchColumn()===2500
    && (int)$additiveDb->query('SELECT COUNT(*) FROM pedido_produtos WHERE id_pedido='.$additiveContract.' AND estoque_inicializado<>0')->fetchColumn()===0,
    'Administrador duplica aditivo dentro do saldo, preservando tipo adicional e limite conjunto sem gerar estoque');
unset($additiveState,$additiveDb);
