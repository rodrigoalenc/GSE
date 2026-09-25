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
}
