<?php
declare(strict_types=1);

// Included by http-smoke.php; uses its isolated database, server and authenticated sessions.
$certPage=request('GET',$baseUrl.'/certidao/cadastrar',$cookieEmployee);
checkHttp($certPage['status']===200,'Funcionário acessa cadastro de certidões');
$certToken=csrf($certPage['body']);
foreach (['fornecedor'=>'Fornecedor HTTP Certidão','tipo'=>'Fiscal HTTP Certidão'] as $kind=>$name) {
    $saved=request('POST',$baseUrl.'/certidao/configurar',$cookieEmployee,['_csrf_token'=>$certToken,'tipo'=>$kind,'nome'=>$name,'ativo'=>'1']);
    checkHttp($saved['status']===302,'Funcionário cadastra '.$kind);
}
$certDb=new PDO('sqlite:'.$database,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$supplier=(int)$certDb->query("SELECT id FROM lista_fornecedores WHERE nome='Fornecedor HTTP Certidão'")->fetchColumn();
$type=(int)$certDb->query("SELECT id FROM lista_tipos_certidao WHERE nome='Fiscal HTTP Certidão'")->fetchColumn();
$certData=['_csrf_token'=>$certToken,'id_fornecedor'=>(string)$supplier,'id_tipo_certidao'=>(string)$type,'data_emissao'=>'2026-01-01','data_vencimento'=>'2026-12-31','observacao'=>'<script>não executar</script>'];
$certFile=$tempRoot.'/synthetic.pdf';
file_put_contents($certFile,"%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");
$missing=request('POST',$baseUrl.'/certidao/cadastrar',$cookieEmployee,$certData);
checkHttp($missing['status']===302 && (int)$certDb->query('SELECT COUNT(*) FROM certidoes')->fetchColumn()===0,'Novo cadastro exige PDF');
$uploadData=$certData; $uploadData['arquivo_pdf']=new CURLFile($certFile,'application/pdf','Certidao.pdf');
$created=requestMultipart($baseUrl.'/certidao/cadastrar',$cookieEmployee,$uploadData);
$record=$certDb->query('SELECT * FROM certidoes ORDER BY id DESC LIMIT 1')->fetch();
checkHttp($created['status']===302 && is_array($record),'Cadastro HTTP recebe PDF privado válido');
$certId=(int)$record['id']; $privateKey=$record['pdf_privado'];
$matrix=request('GET',$baseUrl.'/certidao',$cookieEmployee);
checkHttp($matrix['status']===200 && str_contains($matrix['body'],'cert-matrix') && str_contains($matrix['body'],'Fornecedor HTTP Certidão'),'Matriz tem tipos e fornecedores');
$details=request('GET',$baseUrl.'/certidao/detalhes/'.$certId,$cookieEmployee);
checkHttp($details['status']===200 && str_contains($details['body'],'&lt;script&gt;'),'Detalhes escapam observações');
$pdf=request('GET',$baseUrl.'/certidao/pdf/'.$certId,$cookieEmployee);
checkHttp($pdf['status']===200 && str_starts_with($pdf['body'],'%PDF-') && str_contains($pdf['headers']['content-disposition'] ?? '','attachment') && str_contains($pdf['headers']['cache-control'] ?? '','no-store'),'PDF protegido com download e no-store');
checkHttp(request('GET',$baseUrl.'/certidao/pdf/'.$certId,$cookieAdmin)['status']===200,'Administrador consulta PDF');
foreach (['certidao','certidao/detalhes/'.$certId,'certidao/pdf/'.$certId,'certidao/configurar','certidao/arquivadas','certidao/excluidas'] as $route) { checkHttp(request('GET',$baseUrl.'/'.$route,$cookieGuest)['status']===302,'Visitante bloqueado em '.$route); }
checkHttp(request('GET',$baseUrl.'/storage/certidoes/'.$privateKey,$cookieGuest)['status']===404,'Nome privado não abre por URL direta');
checkHttp(request('GET',$baseUrl.'/certidao/excluir/'.$certId,$cookieEmployee)['status']===405,'GET não exclui certidão');
checkHttp(request('POST',$baseUrl.'/certidao/excluir/'.$certId,$cookieEmployee,['_csrf_token'=>'bad'])['status']===419,'CSRF protege certidões');
checkHttp(request('GET',$baseUrl.'/certidao/detalhes/0',$cookieEmployee)['status']===404,'ID inválido rejeitado');
checkHttp(request('GET',$baseUrl.'/certidao/pdf/999999',$cookieEmployee)['status']===404,'PDF inexistente retorna 404');
$editData=$certData; $editData['revisao']='1'; $editData['observacao']='Corrigida HTTP';
request('POST',$baseUrl.'/certidao/editar/'.$certId,$cookieEmployee,$editData);
checkHttp($certDb->query('SELECT pdf_privado FROM certidoes WHERE id='.$certId)->fetchColumn()===$privateKey,'Edição mantém PDF anterior');
$renewData=$uploadData; $renewData['revisao']='2';
$renewed=requestMultipart($baseUrl.'/certidao/renovar/'.$certId,$cookieEmployee,$renewData);
$new=$certDb->query('SELECT * FROM certidoes ORDER BY id DESC LIMIT 1')->fetch();
checkHttp($renewed['status']===302 && (int)$new['anterior_id']===$certId && (int)$certDb->query('SELECT arquivado FROM certidoes WHERE id='.$certId)->fetchColumn()===1,'Renovação mantém vínculo e arquiva a selecionada');
checkHttp(request('GET',$baseUrl.'/certidao/pdf/'.$certId,$cookieEmployee)['body']===$pdf['body'],'PDF anterior continua acessível');
checkHttp(request('GET',$baseUrl.'/certidao/arquivadas?ano=todos',$cookieEmployee)['status']===200,'Histórico de todos os anos acessível');
$newId=(int)$new['id'];
request('POST',$baseUrl.'/certidao/arquivar/'.$newId,$cookieEmployee,['_csrf_token'=>$certToken,'revisao'=>'1','confirmar'=>'1']);
checkHttp((int)$certDb->query('SELECT arquivado FROM certidoes WHERE id='.$newId)->fetchColumn()===1,'Funcionário arquiva manualmente');
request('POST',$baseUrl.'/certidao/excluir/'.$newId,$cookieEmployee,['_csrf_token'=>$certToken,'revisao'=>'2','confirmar'=>'1']);
checkHttp($certDb->query('SELECT excluido_em FROM certidoes WHERE id='.$newId)->fetchColumn()!==null,'Funcionário exclui logicamente');
checkHttp(request('GET',$baseUrl.'/certidao/excluidas',$cookieEmployee)['status']===200 && request('GET',$baseUrl.'/certidao/pdf/'.$newId,$cookieEmployee)['status']===200,'Exclusão preserva consulta e documento');
$employeeId=(int)$certDb->query("SELECT id FROM usuarios WHERE tipo='funcionario' AND ativo=1 LIMIT 1")->fetchColumn();
$certDb->exec('UPDATE usuarios SET deve_alterar_senha=1 WHERE id='.$employeeId);
$blocked=request('GET',$baseUrl.'/certidao/pdf/'.$newId,$cookieEmployee);
checkHttp($blocked['status']===302 && str_contains($blocked['headers']['location'] ?? '','senha/alterar'),'Troca obrigatória bloqueia PDF');
$certDb->exec('UPDATE usuarios SET deve_alterar_senha=0,ativo=0 WHERE id='.$employeeId);
checkHttp(request('GET',$baseUrl.'/certidao/pdf/'.$newId,$cookieEmployee)['status']===302,'Conta inativa perde acesso ao PDF');
$certDb->exec('UPDATE usuarios SET ativo=1 WHERE id='.$employeeId);
$certDb=null;
