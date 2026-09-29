<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Model/Contrato.php';

final class ContratoController extends Controller
{
    private function model(): Contrato { return new Contrato(); }
    private function actor(): int { return (int)($_SESSION['usuario_id'] ?? 0); }
    private function field(string $key): string
    {
        $value=$_POST[$key] ?? '';
        if (!is_string($value)) { throw new DomainException('Campo inválido: '.$key); }
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
    /** @param callable(): mixed $action */
    private function run(string $back,callable $action): void
    {
        if (preg_match('#^contrato/detalhes/([1-9][0-9]*)$#',$back,$match)===1) {
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
        try { $target=$action(); $this->redirectWithFlash(is_string($target) ? $target : $back,'success','Operação registrada.'); }
        catch (DomainException $e) { $this->redirectWithFlash($back,'danger',$e->getMessage()); }
        catch (PDOException $e) {
            TechnicalLogger::error('contract_database_error',['exception'=>$e::class]);
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
        $this->view('contratos/form',['title'=>'Editar Pedido','record'=>$record,'suppliers'=>$this->model()->suppliers(),'draft'=>is_array($draft)?$draft:[]]);
    }

    public function detalhes(string $id): void
    {
        $contractId=$this->routeId($id); $model=$this->model();
        $record=$this->existing($contractId); $sheets=$model->sheets($contractId); $items=$model->items($contractId);
        try {
            $registered = new DateTimeImmutable((string) $record['criado_em'], new DateTimeZone('UTC'));
            $registeredText = $registered->setTimezone(new DateTimeZone(Config::string('APP_TIMEZONE', 'America/Cuiaba')))->format('d/m/Y');
        } catch (Exception) { $registeredText = 'Data indisponível'; }
        $this->view('contratos/detalhes',['title'=>'Detalhes do Pedido','headerMeta'=>'ID: #'.$id.' | Registrado em: '.$registeredText,'record'=>$record,'sheets'=>$sheets,'items'=>$items,'key'=>bin2hex(random_bytes(16)),'isAdmin'=>Auth::isAdmin()]);
    }

    public function folha(string $id): void
    {
        $this->run('contrato/detalhes/'.$id,function() use ($id): void {
            $this->model()->addSheet(Contrato::integer($id,1),$this->field('observacao'),$this->number('revisao'),$this->actor(),$this->field('copiar')==='' ? null : $this->number('copiar'),$this->field('chave'));
        });
    }
    public function editarFolha(string $id): void
    {
        $this->run('contrato/detalhes/'.$id,function() use ($id): void {
            $this->model()->updateSheet(Contrato::integer($id,1),$this->number('folha'),$this->field('observacao'),$this->number('revisao'),$this->actor());
        });
    }
    public function faturar(string $id): void
    {
        $this->run('contrato/detalhes/'.$id,function() use ($id): void {
            $this->model()->billSheet(Contrato::integer($id,1),$this->number('folha'),$this->field('data'),$this->field('motivo'),$this->number('revisao'),$this->actor(),Auth::isAdmin());
        });
    }
    public function produto(string $id): void
    {
        $this->run('contrato/detalhes/'.$id,function() use ($id): void {
            $data=['nome'=>$this->field('nome'),'marca'=>$this->field('marca'),'unidade'=>$this->field('unidade'),'quantidade'=>$this->field('quantidade'),'preco'=>$this->field('preco')];
            $item=$this->field('produto');
            if ($item==='') { $this->model()->addItem(Contrato::integer($id,1),$this->number('folha'),$data,$this->number('revisao'),$this->actor()); }
            else { $this->model()->updateItem(Contrato::integer($id,1),$this->number('produto'),$data,$this->number('revisao'),$this->actor()); }
        });
    }
    public function estoque(string $id): void
    {
        $this->run('contrato/detalhes/'.$id,function() use ($id): void {
            $open=$this->field('abertura');
            if ($open!=='' && $this->field('confirmar_abertura')!=='1') { throw new DomainException('Confirme expressamente o saldo de abertura, inclusive quando for zero.'); }
            if ($open==='' && $this->field('confirmar_abertura')==='1') { throw new DomainException('Informe o saldo de abertura confirmado.'); }
            $this->model()->configureStock(Contrato::integer($id,1),$this->number('produto'),$this->number('minimo',0),$this->number('maximo'),$this->number('revisao'),$this->actor(),$open==='' ? null : $this->number('abertura',0),$this->field('chave'),Auth::isAdmin());
        });
    }
    public function conferirAbertura(string $id): void
    {
        $this->requireAdmin();
        $this->run('contrato/detalhes/'.$id,function() use ($id): void {
            if ($this->field('confirmar')!=='1') { throw new DomainException('Confirme a conferência do saldo zero.'); }
            $this->model()->confirmOldZeroOpening(Contrato::integer($id,1),$this->number('produto'),
                $this->number('revisao'),$this->actor(),$this->field('motivo'),$this->field('chave'));
        });
    }
    public function movimentar(string $id): void
    {
        $this->run('contrato/detalhes/'.$id,function() use ($id): void {
            $type=$this->field('tipo');
            if ($type==='estorno' && !Auth::isAdmin()) { throw new DomainException('Estorno exige administrador.'); }
            $this->model()->move(Contrato::integer($id,1),$this->number('produto'),$type,$this->number('quantidade'),$this->field('motivo'),$this->field('chave'),$this->actor(),$this->number('revisao'),$type==='estorno' ? $this->number('original') : null);
        });
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
