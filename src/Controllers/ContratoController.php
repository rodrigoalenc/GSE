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
    /** @param callable(): mixed $action */
    private function run(string $back,callable $action): void
    {
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
        $this->view('contratos/index',['title'=>'Contratos','search'=>$search,'result'=>$this->model()->list($search,$page)]);
    }

    public function criar(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
            $this->run('contrato/criar',fn(): string => 'contrato/detalhes/'.$this->model()->create($this->field('titulo'),$this->field('valor'),$this->field('fornecedor'),$this->actor()));
        }
        $this->view('contratos/form',['title'=>'Novo contrato','record'=>null,'suppliers'=>$this->model()->suppliers()]);
    }

    public function editar(string $id): void
    {
        $contractId=Contrato::integer($id,1);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
            $this->run('contrato/editar/'.$id,function() use ($contractId,$id): string {
                $this->model()->update($contractId,$this->field('titulo'),$this->field('valor'),$this->field('fornecedor'),$this->number('revisao'),$this->actor());
                return 'contrato/detalhes/'.$id;
            });
        }
        $this->view('contratos/form',['title'=>'Editar contrato','record'=>$this->model()->find($contractId),'suppliers'=>$this->model()->suppliers()]);
    }

    public function detalhes(string $id): void
    {
        $contractId=Contrato::integer($id,1); $model=$this->model();
        $record=$model->find($contractId); $sheets=$model->sheets($contractId); $items=$model->items($contractId);
        $this->view('contratos/detalhes',['title'=>'Contrato #'.$id,'record'=>$record,'sheets'=>$sheets,'items'=>$items,'key'=>bin2hex(random_bytes(16)),'isAdmin'=>Auth::isAdmin()]);
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
            $this->model()->configureStock(Contrato::integer($id,1),$this->number('produto'),$this->number('minimo',0),$this->number('maximo'),$this->number('revisao'),$this->actor(),$open==='' ? null : $this->number('abertura',0),$this->field('chave'),Auth::isAdmin());
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
        $item=Contrato::integer($id,1); $model=$this->model();
        $this->view('contratos/historico',['title'=>'Histórico do produto #'.$id,'movements'=>$model->movements($item)]);
    }
    public function imprimir(string $id): void
    {
        $contractId=Contrato::integer($id,1); $model=$this->model();
        $sheet=isset($_GET['folha']) ? filter_var($_GET['folha'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) : false;
        $sheets=$model->sheets($contractId); $items=$model->items($contractId);
        if ($sheet!==false && !in_array($sheet,array_column($sheets,'id'),false)) { render_http_error(404,'Folha indisponível','Folha não encontrada.'); }
        $this->view('contratos/imprimir',['record'=>$model->find($contractId),'sheets'=>$sheets,'items'=>$items,'sheetId'=>$sheet ?: null],false);
    }
}
