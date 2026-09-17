<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Model/Certidao.php';

final class CertidaoController extends Controller
{
    public function index(): void { $this->listing('corrente'); }
    public function arquivadas(): void { $this->listing('arquivada'); }
    public function excluidas(): void { $this->listing('excluida'); }

    private function listing(string $state): void
    {
        $model = new Certidao(); $filters = ['estado'=>$state];
        foreach (['fornecedor','tipo','validade','ano','busca','pendencias'] as $key) { $filters[$key] = self::input($_GET, $key); }
        $documentPages = is_array($_GET['documentos'] ?? null) ? $_GET['documentos'] : [];
        try { $result = $model->matrix($filters, (int)self::input($_GET,'page'), $documentPages); }
        catch (DomainException $e) { render_http_error(422, 'Filtro inválido', $e->getMessage(), 'certidao'); }
        $this->view('certidoes/index', ['title'=>$state === 'corrente' ? 'Matriz de Certidões' : ($state === 'arquivada' ? 'Certidões arquivadas' : 'Certidões excluídas'), 'filters'=>$filters, 'result'=>$result, 'summary'=>$model->summary(), 'fornecedores'=>$model->options('fornecedor'), 'tipos'=>$model->options('tipo')]);
    }

    public function cadastrar(): void { $this->form('cadastrar'); }
    public function editar(string $id): void { $this->form('editar', $id); }
    public function renovar(string $id): void { $this->form('renovar', $id); }

    private function form(string $mode, ?string $id = null): void
    {
        $model = new Certidao(); $record = $id === null ? null : $this->record($id);
        if ($record !== null && $record['estado'] !== 'corrente') { render_http_error(409, 'Registro indisponível', 'Somente certidões correntes podem ser editadas ou renovadas.', 'certidao'); }
        $path = 'certidao/' . $mode . ($id === null ? '' : '/' . $id);
        $state = $_SESSION['certidao_form'][$path] ?? null; unset($_SESSION['certidao_form'][$path]);
        $data = is_array($state) ? $state : ($record ?? []);
        if ($mode === 'renovar' && $state === null) { $data['data_emissao']=''; $data['data_vencimento']=''; $data['observacao']=''; }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $data = [];
            foreach (['id_fornecedor','id_tipo_certidao','data_emissao','data_vencimento','observacao','revisao'] as $key) { $data[$key] = self::input($_POST,$key); }
            try {
                if ($mode === 'editar') {
                    if (isset($_FILES['arquivo_pdf']) && ($_FILES['arquivo_pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) { throw new DomainException('Para enviar outro PDF, utilize a renovação.'); }
                    $model->updateMetadata(Certidao::id($id), $data, $this->actor()); $saved = Certidao::id($id);
                } else {
                    $upload = is_array($_FILES['arquivo_pdf'] ?? null) ? $_FILES['arquivo_pdf'] : [];
                    $saved = $model->create($data, $upload, $this->actor(), $mode === 'renovar' ? Certidao::id($id) : null);
                }
                $this->redirectWithFlash('certidao/detalhes/' . $saved, 'success', 'Certidão salva com sucesso.');
            } catch (Throwable $e) {
                $_SESSION['certidao_form'][$path] = $data;
                $this->redirectWithFlash($path, 'danger', $this->safeError($e));
            }
        }
        $this->view('certidoes/form', ['title'=>match($mode) {'editar'=>'Editar certidão', 'renovar'=>'Renovar certidão', default=>'Nova certidão'}, 'mode'=>$mode,'data'=>$data,'record'=>$record,'path'=>$path,'fornecedores'=>$model->options('fornecedor'),'tipos'=>$model->options('tipo')]);
    }

    public function detalhes(string $id): void
    {
        $record = $this->record($id);
        $this->view('certidoes/detalhes', ['title'=>'Certidão #' . $record['id'], 'record'=>$record]);
    }

    public function pdf(string $id): void
    {
        $record = $this->record($id);
        try {
            $path = (new CertidaoStorage())->path((string)($record['pdf_privado'] ?? ''));
            if (hash_file('sha256',$path) !== $record['pdf_sha256'] || filesize($path) !== (int)$record['pdf_bytes']) { throw new DomainException('Documento com integridade pendente.'); }
        } catch (Throwable) { render_http_error(404,'PDF indisponível','Documento pendente de revisão ou migração para armazenamento privado.','certidao/detalhes/'.$id); }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="certidao-' . (int)$record['id'] . '.pdf"');
        header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
        header('Content-Length: ' . (string)filesize($path));
        session_write_close(); readfile($path);
    }

    public function arquivar(string $id): void { $this->change($id,'archive'); }
    public function excluir(string $id): void { $this->change($id,'delete'); }
    private function change(string $id, string $action): void
    {
        $this->record($id);
        try {
            if (self::input($_POST,'confirmar') !== '1') { throw new DomainException('Confirme a operação antes de continuar.'); }
            (new Certidao())->transition(Certidao::id($id),$action,Certidao::id(self::input($_POST,'revisao')),$this->actor());
            $this->redirectWithFlash('certidao/detalhes/'.$id,'success',$action === 'delete' ? 'Certidão excluída logicamente. Documento e histórico preservados.' : 'Certidão arquivada.');
        } catch (Throwable $e) { $this->redirectWithFlash('certidao/detalhes/'.$id,'danger',$this->safeError($e)); }
    }

    public function configurar(): void
    {
        $model = new Certidao();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                $raw = self::input($_POST,'id');
                $active = self::input($_POST,'ativo');
                if (!in_array($active,['0','1'],true)) { throw new DomainException('Situação inválida.'); }
                $model->saveOption(self::input($_POST,'tipo'),$raw === '' ? null : Certidao::id($raw),self::input($_POST,'nome'),$active === '1',$this->actor(),$raw === '' ? null : Certidao::id(self::input($_POST,'revisao')));
                unset($_SESSION['certidao_option_draft']);
                $this->redirectWithFlash('certidao/configurar','success','Configuração salva. Os vínculos históricos foram preservados.');
            } catch (Throwable $e) {
                $_SESSION['certidao_option_draft'] = [];
                foreach (['tipo','id','nome','ativo','revisao'] as $key) { $_SESSION['certidao_option_draft'][$key] = mb_substr(self::input($_POST,$key),0,150); }
                $this->redirectWithFlash('certidao/configurar','danger',$this->safeError($e));
            }
        }
        $search = mb_substr(self::input($_GET,'busca'),0,150);
        $draft = $_SESSION['certidao_option_draft'] ?? null;
        $this->view('certidoes/configurar',['title'=>'Fornecedores e tipos','search'=>$search,'draft'=>$draft,'fornecedores'=>$model->options('fornecedor',$search),'tipos'=>$model->options('tipo',$search)]);
    }

    /** @return array<string,mixed> */
    private function record(string $id): array
    {
        try { $record = (new Certidao())->buscarPorId(Certidao::id($id)); }
        catch (DomainException) { $record = false; }
        if (!$record) { render_http_error(404,'Certidão não encontrada','O registro solicitado não existe.','certidao'); }
        return $record;
    }
    private function actor(): int { return (int)($_SESSION['usuario_id'] ?? 0); }
    /** @param array<string,mixed> $source */
    private static function input(array $source,string $key): string { return is_string($source[$key] ?? null) ? $source[$key] : ''; }
    private function safeError(Throwable $e): string
    {
        if ($e instanceof DomainException) { return $e->getMessage(); }
        TechnicalLogger::error('certidao_operation_failed',['exception'=>$e::class]);
        return 'Não foi possível concluir. Nenhuma alteração foi confirmada. Tente novamente ou contate o administrador.';
    }
}
