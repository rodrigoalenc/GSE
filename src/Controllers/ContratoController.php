<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Model/Contrato.php';
require_once ROOT_PATH . '/src/Core/ContractFormDraft.php';

final class ContratoController extends Controller
{
    private function model(): Contrato { return new Contrato(); }
    private function actor(): int { return (int)($_SESSION['usuario_id'] ?? 0); }
    private function field(string $key): string
    {
        $value=$_POST[$key] ?? '';
        if (!is_string($value) || !mb_check_encoding($value,'UTF-8') || str_contains($value,"\0")) { throw new DomainException('Campo inválido: '.$key); }
        return $value;
    }
    private function number(string $key,int $min=1): int { return Contrato::integer($this->field($key),$min); }
    private function routeId(string $raw): int
    {
        try { return Contrato::integer($raw,1); }
        catch (DomainException) { render_http_error(404,'Registro não encontrado','Identificador inválido.','contrato'); }
    }
    /** @return array<string,mixed> */
    private function existing(int $id): array
    {
        try { return $this->model()->find($id); }
        catch (DomainException) { render_http_error(404,'Contrato não encontrado','O contrato solicitado não existe.','contrato'); }
    }
    /** @return array{operation:string,sheet:int,item:int,target:string}|null */
    private function formResource(int $contractId,string $operation): ?array
    {
        $model=$this->model();
        try {
            $activeContract=$model->find($contractId)['excluido_em']===null;
            $sheets=$model->sheets($contractId);
            $sheetId=filter_var($_POST['folha'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $itemId=filter_var($_POST['produto'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (in_array($operation,['estoque','conferir-abertura','movimentar'],true) && $itemId===false) { return null; }
            if ($operation==='produto' && ($_POST['produto'] ?? '')!=='' && $itemId===false) { return null; }
            $item=null; $sheet=null;
            if (in_array($operation,['produto','estoque','conferir-abertura','movimentar'],true) && $itemId!==false) {
                foreach ($model->items($contractId) as $candidate) {
                    if ((int)$candidate['id']===$itemId) { $item=$candidate; break; }
                }
                if ($item===null) { return null; }
                foreach ($sheets as $candidate) {
                    if ((int)$candidate['numero_pagina']===(int)$item['numero_pagina']) { $sheet=$candidate; break; }
                }
            } else {
                foreach ($sheets as $candidate) { if ((int)$candidate['id']===$sheetId) { $sheet=$candidate; break; } }
            }
            if ($operation==='nova-folha') {
                if (($_POST['copiar'] ?? '')!=='') { return null; }
                return ['operation'=>$operation,'sheet'=>0,'item'=>0,'target'=>$activeContract ? 'new-note' : 'draft-review'];
            }
            if ($sheet===null) { return null; }
            $sheetId=(int)$sheet['id']; $itemId=$item===null ? 0 : (int)$item['id'];
            if ($operation==='produto') { $operation=$item===null ? 'adicionar-produto' : 'editar-produto'; }
            if ($operation==='movimentar' && ($_POST['tipo'] ?? '')==='estorno') { $operation='estornar'; }
            $target=match($operation) {
                'adicionar-produto'=>'add-product-'.$sheetId, 'editar-produto'=>'edit-product-'.$itemId,
                'observacao'=>'note-observation-'.$sheetId, 'estoque'=>'stock-config-'.$itemId,
                'conferir-abertura'=>'old-opening-'.$itemId, 'movimentar'=>'move-stock-'.$itemId,
                'estornar'=>'reverse-stock-'.$itemId, 'faturar'=>'billing-note-'.$sheetId,
                default=>null,
            };
            if ($target===null) { return null; }
            if (!$activeContract || $sheet['excluido_em']!==null || ($item!==null && $item['excluido_em']!==null)) { $target='draft-review'; }
            return ['operation'=>$operation,'sheet'=>$sheetId,'item'=>$itemId,'target'=>$target];
        } catch (DomainException) { return null; }
    }

    /** @param callable(): mixed $action */
    private function run(string $back,callable $action,?string $operation=null): void
    {
        $resource=null; $contractId=0; $base=$back;
        if (preg_match('#^contrato/detalhes/([1-9][0-9]*)$#',$back,$match)===1) {
            $contractId=(int)$match[1];
            if ($operation!==null) { $resource=$this->formResource($contractId,$operation); }
            $sheetId=filter_var($_POST['folha'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if ($sheetId===false && isset($_POST['produto'])) {
                $productId=filter_var($_POST['produto'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if ($productId!==false) {
                    try {
                        $product=$this->model()->itemForHistory($productId);
                        if ((int)$product['id_pedido']===(int)$match[1]) {
                            foreach ($this->model()->sheets((int)$match[1]) as $sheet) {
                                if ((int)$sheet['numero_pagina']===(int)$product['numero_pagina']) { $sheetId=(int)$sheet['id']; break; }
                            }
                        }
                    } catch (DomainException) { /* O erro será apresentado pela operação. */ }
                }
            }
            if ($sheetId!==false) { $back.='#folha-'.$sheetId; }
        }
        try {
            $target=$action();
            if ($resource!==null) { ContractFormDraft::clear($contractId,$resource['operation'],$resource['sheet'],$resource['item'],$_POST['_form_context'] ?? null); }
            $this->redirectWithFlash(is_string($target) ? $target : $back,'success','Operação registrada.');
        }
        catch (DomainException $e) {
            if ($resource!==null) { $draftId=ContractFormDraft::save($contractId,$resource['operation'],$resource['sheet'],$resource['item'],$_POST,$e->getMessage()); redirect($base.'?rascunho='.$draftId.'#'.$resource['target']); }
            $this->redirectWithFlash($back,'danger',$e->getMessage());
        }
        catch (PDOException $e) {
            TechnicalLogger::error('contract_database_error',['exception'=>$e::class]);
            if ($resource!==null) { $draftId=ContractFormDraft::save($contractId,$resource['operation'],$resource['sheet'],$resource['item'],$_POST,'Não foi possível concluir. Verifique os dados e tente novamente.'); redirect($base.'?rascunho='.$draftId.'#'.$resource['target']); }
            $this->redirectWithFlash($back,'danger','Não foi possível concluir. Verifique os dados e tente novamente.');
        }
    }

    public function index(): void
    {
        $search=is_string($_GET['busca'] ?? null) ? $_GET['busca'] : '';
        $page=filter_var($_GET['pagina'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 1;
        $status=($_GET['situacao'] ?? 'ativos') === 'excluidos' ? 'excluidos' : 'ativos';
        $model=$this->model();
        $this->view('contratos/index',['title'=>'Gerenciar Pedidos','search'=>$search,'status'=>$status,'result'=>$model->list($search,$page,$status),'summary'=>$status==='ativos' ? $model->summary($search) : null]);
    }

    public function criar(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
            $_SESSION['contract_draft']=['titulo'=>$_POST['titulo'] ?? '', 'valor'=>$_POST['valor'] ?? '',
                'fornecedor'=>$_POST['fornecedor'] ?? '', 'folhas'=>$_POST['folhas'] ?? []];
            $this->run('contrato/criar',function(): string {
                $sheets=$_POST['folhas'] ?? null;
                if ($sheets!==null && !is_array($sheets)) { throw new DomainException('Folhas inválidas.'); }
                $id=$sheets===null
                    ? $this->model()->create($this->field('titulo'),$this->field('valor'),$this->field('fornecedor'),$this->actor())
                    : $this->model()->createDetailed($this->field('titulo'),$this->field('valor'),$this->field('fornecedor'),$sheets,$this->actor());
                unset($_SESSION['contract_draft']);
                return 'contrato/detalhes/'.$id;
            });
        }
        $draft=$_SESSION['contract_draft'] ?? [];
        unset($_SESSION['contract_draft']);
        $this->view('contratos/form',['title'=>'Cadastrar Novo Pedido','record'=>null,'suppliers'=>$this->model()->suppliers(),'draft'=>is_array($draft)?$draft:[]]);
    }

    public function editar(string $id): void
    {
        $contractId=$this->routeId($id);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
            $_SESSION['contract_edit_draft_'.$id]=['titulo'=>$_POST['titulo'] ?? '', 'valor'=>$_POST['valor'] ?? '', 'fornecedor'=>$_POST['fornecedor'] ?? ''];
            $this->run('contrato/editar/'.$id,function() use ($contractId,$id): string {
                $this->model()->update($contractId,$this->field('titulo'),$this->field('valor'),$this->field('fornecedor'),$this->number('revisao'),$this->actor());
                unset($_SESSION['contract_edit_draft_'.$id]);
                return 'contrato/detalhes/'.$id;
            });
        }
        $record=$this->existing($contractId);
        if ($record['excluido_em']!==null) { render_http_error(404,'Contrato excluído','O contrato está disponível apenas para consulta.','contrato'); }
        $draft=$_SESSION['contract_edit_draft_'.$id] ?? [];
        unset($_SESSION['contract_edit_draft_'.$id]);
        $this->view('contratos/form',['title'=>'✏️ Editar Dados Gerais #'.$record['id'],'record'=>$record,'suppliers'=>$this->model()->suppliers(),'draft'=>is_array($draft)?$draft:[]]);
    }

    public function detalhes(string $id): void
    {
        $contractId=$this->routeId($id); $model=$this->model();
        $record=$this->existing($contractId); $sheets=$model->sheets($contractId); $items=$model->items($contractId);
        try {
            $registered = new DateTimeImmutable((string) $record['criado_em'], new DateTimeZone('UTC'));
            $registeredText = $registered->setTimezone(new DateTimeZone(Config::string('APP_TIMEZONE', 'America/Cuiaba')))->format('d/m/Y');
        } catch (Exception) { $registeredText = 'Data indisponível'; }
        $draft=ContractFormDraft::read($contractId,$_GET['rascunho'] ?? null);
        if ($draft!==null) {
            $current=$record;
            if ($draft['item']!==0) { $current=null; foreach ($items as $item) { if ((int)$item['id']===$draft['item']) { $current=$item; break; } } }
            elseif (!in_array($draft['operation'],['adicionar-produto','nova-folha'],true)) { $current=null; foreach ($sheets as $sheet) { if ((int)$sheet['id']===$draft['sheet']) { $current=$sheet; break; } } }
            $availableSheet=$draft['sheet']===0;
            foreach ($sheets as $sheet) { if ((int)$sheet['id']===$draft['sheet'] && $sheet['excluido_em']===null) { $availableSheet=true; break; } }
            $draft['unavailable']=$current===null || $current['excluido_em']!==null || $record['excluido_em']!==null || !$availableSheet
                || ($draft['operation']==='conferir-abertura' && !Auth::isAdmin());
            $draft['current']=$current ?? [];
            $draft['conflict']=$draft['unavailable'] || $draft['revision']===null || $draft['revision']!==(int)$current['revisao'];
        }
        $this->view('contratos/detalhes',['title'=>'Detalhes do Pedido','headerMeta'=>'ID: #'.$id.' | Registrado em: '.$registeredText,'record'=>$record,'sheets'=>$sheets,'items'=>$items,'key'=>bin2hex(random_bytes(16)),'isAdmin'=>Auth::isAdmin(),'draft'=>$draft,'formContext'=>ContractFormDraft::context($draft['context'] ?? null)]);
    }

    public function folha(string $id): void
    {
        $this->run('contrato/detalhes/'.$id,function() use ($id): void {
            $this->model()->addSheet(Contrato::integer($id,1),$this->field('observacao'),$this->number('revisao'),$this->actor(),$this->field('copiar')==='' ? null : $this->number('copiar'),$this->field('chave'));
        },'nova-folha');
    }
    public function editarFolha(string $id): void
    {
        $this->run('contrato/detalhes/'.$id,function() use ($id): void {
            $this->model()->updateSheet(Contrato::integer($id,1),$this->number('folha'),$this->field('observacao'),$this->number('revisao'),$this->actor());
        },'observacao');
    }
    public function faturar(string $id): void
    {
        $this->run('contrato/detalhes/'.$id,function() use ($id): void {
            $date=$this->field('data');
            if ($this->field('_billing_form')==='1') {
                $billed=$this->field('faturado');
                if (!in_array($billed,['','1'],true)) { throw new DomainException('Situação de faturamento inválida.'); }
                if ($billed==='1' && $date==='') { throw new DomainException('Informe a data para confirmar o faturamento.'); }
                if ($billed==='') { $date=''; }
            }
            $this->model()->billSheet(Contrato::integer($id,1),$this->number('folha'),$date,$this->field('motivo'),$this->number('revisao'),$this->actor(),Auth::isAdmin());
        },'faturar');
    }
    public function produto(string $id): void
    {
        $this->run('contrato/detalhes/'.$id,function() use ($id): void {
            $data=['nome'=>$this->field('nome'),'marca'=>$this->field('marca'),'unidade'=>$this->field('unidade'),'quantidade'=>$this->field('quantidade'),'preco'=>$this->field('preco')];
            $item=$this->field('produto');
            if ($item==='') { $this->model()->addItem(Contrato::integer($id,1),$this->number('folha'),$data,$this->number('revisao'),$this->actor()); }
            else { $this->model()->updateItem(Contrato::integer($id,1),$this->number('produto'),$data,$this->number('revisao'),$this->actor()); }
        },'produto');
    }
    public function estoque(string $id): void
    {
        $this->run('contrato/detalhes/'.$id,function() use ($id): void {
            $open=$this->field('abertura');
            if ($open!=='' && $this->field('confirmar_abertura')!=='1') { throw new DomainException('Confirme expressamente o saldo de abertura, inclusive quando for zero.'); }
            if ($open==='' && $this->field('confirmar_abertura')==='1') { throw new DomainException('Informe o saldo de abertura confirmado.'); }
            $this->model()->configureStock(Contrato::integer($id,1),$this->number('produto'),$this->number('minimo',0),$this->number('maximo'),$this->number('revisao'),$this->actor(),$open==='' ? null : $this->number('abertura',0),$this->field('chave'),Auth::isAdmin());
        },'estoque');
    }
    public function conferirAbertura(string $id): void
    {
        $this->requireAdmin();
        $this->run('contrato/detalhes/'.$id,function() use ($id): void {
            $this->model()->recoverOldOpening(Contrato::integer($id,1),$this->number('produto'),$this->number('quantidade',0),
                $this->field('unidade'),$this->number('minimo',0),$this->number('maximo'),$this->number('revisao'),
                $this->actor(),$this->field('motivo'),$this->field('chave'),$this->field('confirmar')==='1');
        },'conferir-abertura');
    }
    public function movimentar(string $id): void
    {
        $this->run('contrato/detalhes/'.$id,function() use ($id): void {
            $type=$this->field('tipo');
            if ($type==='estorno' && !Auth::isAdmin()) { throw new DomainException('Estorno exige administrador.'); }
            $this->model()->move(Contrato::integer($id,1),$this->number('produto'),$type,$this->number('quantidade'),$this->field('motivo'),$this->field('chave'),$this->actor(),$this->number('revisao'),$type==='estorno' ? $this->number('original') : null);
        },'movimentar');
    }
    public function excluir(string $id): void
    {
        $this->run('contrato/detalhes/'.$id,function() use ($id): void {
            if ($this->field('confirmar')!=='1') { throw new DomainException('Confirme a exclusão lógica.'); }
            $sheet=$this->field('folha'); $item=$this->field('produto');
            $this->model()->delete(Contrato::integer($id,1),$sheet==='' ? null : $this->number('folha'),$item==='' ? null : $this->number('produto'),$this->number('revisao'),$this->actor());
        });
    }
    public function conciliar(string $id): void
    {
        $this->requireAdmin();
        $this->run('contrato/detalhes/'.$id,function() use ($id): void {
            $item=$this->field('produto');
            if ($item==='') { $this->model()->reconcileLegacyContract(Contrato::integer($id,1),$this->field('valor'),$this->number('revisao'),$this->actor()); }
            else { $this->model()->reconcileLegacyItem(Contrato::integer($id,1),$this->number('produto'),$this->number('quantidade'),$this->field('preco'),$this->number('revisao'),$this->actor()); }
        });
    }
    public function historico(string $id): void
    {
        $item=$this->routeId($id); $model=$this->model();
        try { $product=$model->itemForHistory($item); }
        catch (DomainException) { render_http_error(404,'Produto não encontrado','O produto solicitado não existe.','contrato'); }
        $this->view('contratos/historico',['title'=>'Histórico do produto #'.$id,'product'=>$product,'movements'=>$model->movements($item)]);
    }
    public function imprimir(string $id): void
    {
        $contractId=$this->routeId($id); $model=$this->model();
        $record=$this->existing($contractId);
        $sheet=isset($_GET['folha']) ? filter_var($_GET['folha'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) : null;
        $sheets=$model->sheets($contractId); $items=$model->items($contractId);
        if (isset($_GET['folha']) && ($sheet===false || !in_array($sheet,array_column($sheets,'id'),false))) { render_http_error(404,'Folha indisponível','Folha não encontrada.'); }
        $this->view('contratos/imprimir',['record'=>$record,'sheets'=>$sheets,'items'=>$items,'sheetId'=>$sheet],false);
    }
}
