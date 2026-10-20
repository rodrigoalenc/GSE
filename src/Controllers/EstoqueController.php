<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Model/Contrato.php';

final class EstoqueController extends Controller
{
    public function index(): void
    {
        $search=is_string($_GET['busca'] ?? null) ? $_GET['busca'] : '';
        $page=filter_var($_GET['pagina'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 1;
        $this->view('estoque/index',['title'=>'Estoque','search'=>$search,'result'=>(new Contrato())->stockList($search,$page)]);
    }

    public function iniciar(string $id): void { $this->form($id,'iniciar'); }
    public function receber(string $id): void { $this->form($id,'receber'); }
    public function consumir(string $id): void { $this->form($id,'consumir'); }
    public function alertas(string $id): void { $this->form($id,'alertas'); }

    private function field(string $name): string
    {
        $value=$_POST[$name] ?? '';
        if (!is_string($value) || !mb_check_encoding($value,'UTF-8') || str_contains($value,"\0")) {
            throw new DomainException('Campo inválido: '.$name);
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private function product(Contrato $model,int $id): array
    {
        $reference=$model->itemForHistory($id);
        $contract=$model->find((int)$reference['id_pedido']);
        if ($contract['excluido_em']!==null || $reference['excluido_em']!==null) { throw new DomainException('Produto indisponível.'); }
        $activeSheet=false;
        foreach ($model->sheets((int)$reference['id_pedido']) as $sheet) {
            if ((int)$sheet['numero_pagina']===(int)$reference['numero_pagina'] && $sheet['excluido_em']===null) { $activeSheet=true; }
        }
        if (!$activeSheet) { throw new DomainException('Nota indisponível.'); }
        foreach ($model->items((int)$reference['id_pedido']) as $item) {
            if ((int)$item['id']===$id) { return $item+['contrato_titulo'=>$contract['titulo']]; }
        }
        throw new DomainException('Produto não encontrado.');
    }

    /** @param array<string,mixed> $product */
    private function blocked(string $mode,array $product): string
    {
        $pending=(int)$product['estoque_inicializado']===0;
        $uncertain=(int)$product['abertura_sem_comprovacao']===1;
        if ($mode==='iniciar' && !$pending) { return $uncertain ? 'Um administrador precisa conferir a quantidade atual deste registro antigo.' : 'A quantidade inicial já foi confirmada. Consulte a quantidade disponível no estoque.'; }
        if ($mode==='iniciar' && $product['legado'] && !Auth::isAdmin()) { return 'Um administrador precisa confirmar a quantidade inicial deste produto antigo.'; }
        if (in_array($mode,['receber','consumir'],true) && ($pending || $uncertain)) { return $pending ? 'Confirme primeiro quantas unidades estão disponíveis na escola agora.' : 'Um administrador precisa conferir a quantidade atual deste registro antigo.'; }
        return '';
    }

    private function form(string $rawId,string $mode): void
    {
        $model=new Contrato();
        try { $id=Contrato::integer($rawId,1); $product=$this->product($model,$id); }
        catch (DomainException) { render_http_error(404,'Produto indisponível','O produto solicitado não está disponível.','estoque'); }
        $query=[];
        if (isset($_GET['busca']) && is_string($_GET['busca'])) { $query['busca']=mb_substr($_GET['busca'],0,100); }
        $page=filter_var($_GET['pagina'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($page!==false) { $query['pagina']=$page; }
        $suffix=$query ? '?'.http_build_query($query) : '';
        $back='estoque'.$suffix;
        $previews=$_SESSION['stock_previews'] ?? [];
        $previews=is_array($previews) ? $previews : [];
        foreach ($previews as $token=>$saved) {
            if (!is_array($saved) || (int)($saved['expires'] ?? 0)<time()) { unset($previews[$token]); }
        }
        $_SESSION['stock_previews']=$previews;
        $error=''; $quantity=''; $preview=null; $key=bin2hex(random_bytes(16));
        $minimum=(string)($product['estoque_minimo'] ?? ''); $maximum=(string)($product['estoque_maximo'] ?? '');
        $blocked=$this->blocked($mode,$product);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST' && $blocked==='') {
            try {
                if ($mode==='alertas') { $minimum=$this->field('minimo'); $maximum=$this->field('maximo'); }
                else { $quantity=$this->field('quantidade'); }
                $revision=Contrato::integer($this->field('revisao'),1);
                if ($revision!==(int)$product['revisao']) { throw new DomainException('A quantidade ou a configuração mudou desde que você abriu o formulário. Confira os dados atuais e tente novamente.'); }
                if ($mode==='alertas') {
                    $model->configureStock((int)$product['id_pedido'],$id,$minimum==='' ? null : Contrato::integer($minimum,0),$maximum==='' ? null : Contrato::integer($maximum,1),$revision,(int)$_SESSION['usuario_id']);
                    $this->redirectWithFlash($back.'#produto-'.$id,'success','Alertas de estoque salvos.');
                }
                $amount=Contrato::integer($quantity,$mode==='iniciar' ? 0 : 1);
                $key=$this->field('chave');
                if (preg_match('/^[a-f0-9]{32}$/D',$key)!==1) { throw new DomainException('Formulário inválido. Confira a quantidade e tente novamente.'); }
                $balance=(int)$product['saldo'];
                $after=$mode==='iniciar' ? $amount : $balance+($mode==='consumir' ? -$amount : $amount);
                if ($after<0) { throw new DomainException('A quantidade utilizada não pode ser maior que a quantidade disponível.'); }
                if ($after>1000000000 || ($product['estoque_maximo']!==null && $after>(int)$product['estoque_maximo'])) { throw new DomainException('A quantidade ultrapassa o máximo permitido. Confira os alertas de estoque.'); }
                if ($this->field('etapa')==='confirmar') {
                    $expected=['item'=>$id,'mode'=>$mode,'quantity'=>$amount,'revision'=>$revision,'actor'=>(int)$_SESSION['usuario_id']];
                    $saved=$previews[$key] ?? null;
                    if (!is_array($saved) || ($saved['data'] ?? null)!==$expected) { throw new DomainException('Confira a quantidade antes de confirmar o registro.'); }
                    if ($mode==='iniciar') { $model->initializeStock((int)$product['id_pedido'],$id,$amount,$revision,(int)$_SESSION['usuario_id'],$key,Auth::isAdmin()); }
                    else { $model->move((int)$product['id_pedido'],$id,$mode==='consumir' ? 'saida' : 'entrada',$amount,$mode==='consumir' ? 'Consumo na escola' : 'Recebimento registrado no estoque',$key,(int)$_SESSION['usuario_id'],$revision); }
                    unset($_SESSION['stock_previews'][$key]);
                    $this->redirectWithFlash($back.'#produto-'.$id,'success',$mode==='iniciar' ? 'Quantidade inicial confirmada.' : ($mode==='consumir' ? 'Consumo registrado.' : 'Recebimento registrado.'));
                }
                if ($this->field('etapa')!=='editar') {
                    $preview=['quantity'=>$amount,'before'=>$balance,'after'=>$after];
                    $_SESSION['stock_previews'][$key]=['expires'=>time()+1800,'data'=>['item'=>$id,'mode'=>$mode,'quantity'=>$amount,'revision'=>$revision,'actor'=>(int)$_SESSION['usuario_id']]];
                    $_SESSION['stock_previews']=array_slice($_SESSION['stock_previews'],-10,null,true);
                } else { unset($_SESSION['stock_previews'][$key]); }
            } catch (DomainException $e) { $error=$e->getMessage(); }
            catch (PDOException $e) { TechnicalLogger::error('stock_database_error',['exception'=>$e::class]); $error='Não foi possível concluir. Confira os dados e tente novamente.'; }
        }
        if ($error!=='') {
            try { $product=$this->product($model,$id); }
            catch (DomainException) { render_http_error(404,'Produto indisponível','O produto solicitado não está mais disponível.','estoque'); }
            $blocked=$this->blocked($mode,$product);
        }
        if (preg_match('/^[a-f0-9]{32}$/D',$key)!==1 || $error!=='') { $key=bin2hex(random_bytes(16)); }
        $titles=['iniciar'=>'Quantidade inicial','receber'=>'Receber produtos','consumir'=>'Registrar consumo','alertas'=>'Alertas de estoque'];
        $this->view('estoque/form',['title'=>$titles[$mode],'mode'=>$mode,'product'=>$product,'back'=>$back,'suffix'=>$suffix,
            'error'=>$error,'quantity'=>$quantity,'preview'=>$preview,'key'=>$key,'minimum'=>$minimum,'maximum'=>$maximum,'blocked'=>$blocked]);
    }
}
