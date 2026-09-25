<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Model/RelatorioAlunos.php';

final class RelatorioController extends Controller
{
    /** @return array<string,mixed> */
    private function filters(): array
    {
        return ['turma'=>$_GET['turma'] ?? '', 'dva'=>$_GET['dva'] ?? '', 'ativo'=>$_GET['ativo'] ?? '1'];
    }

    public function index(): void
    {
        try {
            $page=filter_var($_GET['pagina'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 1;
            $model=new RelatorioAlunos(); $result=$model->query($this->filters(),$page);
        } catch (DomainException $e) { render_http_error(422,'Filtro inválido',$e->getMessage(),'relatorio'); }
        $this->view('relatorios/index',['title'=>'Relatórios de alunos e DVA','result'=>$result,'classes'=>$model->classes()]);
    }

    public function csv(): void { $this->export('csv'); }
    public function pdf(): void { $this->export('pdf'); }

    private function export(string $format): void
    {
        try { $result=(new RelatorioAlunos())->query($this->filters(),1,true); }
        catch (DomainException $e) { render_http_error(422,'Filtro inválido',$e->getMessage(),'relatorio'); }
        if ($format==='pdf' && $result['total']>500) { render_http_error(422,'Relatório extenso','O PDF aceita até 500 alunos. Aplique filtros mais específicos.','relatorio'); }
        $actor=(int)($_SESSION['usuario_id'] ?? 0);
        AuditLogger::recordRequired(Model::getConexao(),'relatorio.'.$format.'_generated',AuditLogger::SUCCESS,$actor,null,
            'Filtros turma='.$result['filters']['turma'].'; dva='.$result['filters']['dva'].'; ativo='.$result['filters']['ativo'].'; total='.$result['total'],'relatorio',null);
        $now=new DateTimeImmutable('now',new DateTimeZone(Config::string('APP_TIMEZONE','America/Cuiaba')));
        $filename='relatorio-alunos-dva-'.$now->format('Ymd').'.'.$format;
        header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        if ($format==='csv') {
            header('Content-Type: text/csv; charset=UTF-8');
            $stream=fopen('php://output','wb');
            if ($stream===false) { throw new RuntimeException('Saída CSV indisponível.'); }
            fwrite($stream,"\xEF\xBB\xBF");
            fputcsv($stream,['Relatório de alunos e DVA','Gerado em '.$now->format('d/m/Y H:i')],';','"','');
            fputcsv($stream,['Turma',$result['filters']['turma'] ?: 'Todas','DVA',$result['filters']['dva'] ?: 'Todas','Alunos',$result['filters']['ativo']],';','"','');
            fputcsv($stream,['Total de resultados',$result['total']],';','"','');
            fputcsv($stream,[], ';','"','');
            fputcsv($stream,['Aluno','Turma','Nascimento','Vencimento DVA','Situação DVA','Situação aluno'],';','"','');
            foreach ($result['items'] as $row) {
                fputcsv($stream,[RelatorioAlunos::csvCell($row['nome_completo']),RelatorioAlunos::csvCell($row['nome_turma'] ?? ''),$row['data_nascimento'] ?? '',$row['data_vencimento'] ?? '',DvaStatus::label($row['dva_status']),$row['ativo'] ? 'Ativo' : 'Inativo'],';','"','');
            }
            fclose($stream); return;
        }
        require_once ROOT_PATH . '/vendor/autoload.php';
        $options=new \Dompdf\Options();
        $options->setIsRemoteEnabled(false); $options->setIsPhpEnabled(false);
        $options->setChroot([ROOT_PATH.'/public/assets']);
        $options->setAllowedProtocols(['data://']);
        $options->setDefaultFont('DejaVu Sans');
        $pdf=new \Dompdf\Dompdf($options);
        $html=$this->pdfHtml($result);
        $pdf->loadHtml($html,'UTF-8'); $pdf->setPaper('A4','portrait'); $pdf->render();
        header('Content-Type: application/pdf');
        echo $pdf->output();
    }

    /** @param array<string,mixed> $result */
    private function pdfHtml(array $result): string
    {
        $escape=static fn(?string $text): string => htmlspecialchars((string)$text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $html='<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;color:#17324d;font-size:10px}h1{color:#155185;font-size:17px}table{width:100%;border-collapse:collapse}th,td{border-bottom:1px solid #ccd5dd;text-align:left;padding:5px}th{background:#dceaf5}tr{page-break-inside:avoid}</style></head><body><h1>Relatório de alunos e DVA</h1>';
        $now=new DateTimeImmutable('now',new DateTimeZone(Config::string('APP_TIMEZONE','America/Cuiaba')));
        $html.='<p>Gerado em '.$now->format('d/m/Y H:i').' · '.$result['total'].' resultado(s). Dados do momento da geração.</p>';
        $html.='<p>Filtros: turma '.$escape($result['filters']['turma'] ?: 'todas').' · DVA '.$escape($result['filters']['dva'] ?: 'todas').' · aluno '.$escape($result['filters']['ativo']).'</p>';
        $html.='<table><thead><tr><th>Aluno</th><th>Turma</th><th>Nascimento</th><th>Vencimento</th><th>DVA</th></tr></thead><tbody>';
        foreach ($result['items'] as $row) {
            $html.='<tr><td>'.$escape($row['nome_completo']).'</td><td>'.$escape($row['nome_turma'] ?? 'Sem turma').'</td><td>'.$escape($row['data_nascimento'] ?? '').'</td><td>'.$escape($row['data_vencimento'] ?? '').'</td><td>'.$escape(DvaStatus::label($row['dva_status'])).'</td></tr>';
        }
        return $html.'</tbody></table></body></html>';
    }
}
