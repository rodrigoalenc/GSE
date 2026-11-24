<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Model/RelatorioAlunos.php';
require_once ROOT_PATH . '/src/Core/AccessPolicy.php';

final class RelatorioController extends Controller
{
    /** @return array<string,mixed> */
    private function filters(): array
    {
        $source = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;
        return ['turma'=>$source['turma'] ?? '', 'dva'=>$source['dva'] ?? '', 'ativo'=>$source['ativo'] ?? '1'];
    }

    public function index(): void
    {
        AccessPolicy::require('student.export');
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
        AccessPolicy::require('student.export');
        $event = 'relatorio.' . $format . '_generated';
        $actor = (int) ($_SESSION['usuario_id'] ?? 0);
        try { $result=(new RelatorioAlunos())->query($this->filters(),1,true,$format==='pdf' ? 500 : 10000); }
        catch (DomainException $e) {
            AuditLogger::record($event, AuditLogger::BLOCKED, $actor, null, $e->getMessage(), 'relatorio');
            render_http_error(422,'Filtro inválido',$e->getMessage(),'relatorio');
        }
        if ($format==='pdf' && $result['total']>500) { render_http_error(422,'Relatório extenso','O PDF aceita até 500 alunos. Aplique filtros mais específicos.','relatorio'); }
        $now=new DateTimeImmutable('now',new DateTimeZone(Config::string('APP_TIMEZONE','America/Cuiaba')));
        $filename='relatorio-alunos-dva-'.$now->format('Ymd').'.'.$format;
        $source = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;
        $disposition = $format === 'pdf' && input_string($source['baixar'] ?? '') !== '1' ? 'inline' : 'attachment';
        $class = $result['filters']['turma'];
        $classId = $class === '' ? null : (int) $class;
        try {
            $content = $format === 'csv' ? $this->csvContent($result, $now) : $this->pdfContent($result);
        } catch (Throwable $exception) {
            AuditLogger::record($event, AuditLogger::FAILURE, $actor, null, 'Falha ao gerar o arquivo do relatório.', 'relatorio', $classId);
            throw $exception;
        }
        AuditLogger::recordRequired(Model::getConexao(), $event, AuditLogger::SUCCESS, $actor, null,
            'Filtros turma=' . $class . '; dva=' . $result['filters']['dva'] . '; ativo=' . $result['filters']['ativo']
            . '; total=' . count($result['items']) . '; entrega=' . $disposition,
            'relatorio', $classId);

        header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: '.$disposition.'; filename="'.$filename.'"');
        header('Content-Type: ' . ($format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/pdf'));
        echo $content;
    }

    /** @param array<string,mixed> $result */
    private function csvContent(array $result, DateTimeImmutable $now): string
    {
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) { throw new RuntimeException('Saída CSV indisponível.'); }
        try {
            fwrite($stream,"\xEF\xBB\xBF");
            fputcsv($stream,['Relatório de alunos e DVA','Gerado em '.$now->format('d/m/Y H:i')],';','"','');
            fputcsv($stream,['Turma',RelatorioAlunos::csvCell($result['class_filter_label']),'DVA',$result['filters']['dva'] ?: 'Todas','Alunos',$result['filters']['ativo']],';','"','');
            fputcsv($stream,['Total de resultados',$result['total']],';','"','');
            fputcsv($stream,[], ';','"','');
            fputcsv($stream,['Aluno','Turma','Nascimento','Vencimento DVA','Situação DVA','Situação aluno'],';','"','');
            foreach ($result['items'] as $row) {
                fputcsv($stream,[RelatorioAlunos::csvCell($row['nome_completo']),RelatorioAlunos::csvCell(RelatorioAlunos::classLabel($row['nome_turma'],$row['ano_letivo'])),RelatorioAlunos::displayDate($row['data_nascimento']),RelatorioAlunos::displayDate($row['data_vencimento']),DvaStatus::label($row['dva_status']),$row['ativo'] ? 'Ativo' : 'Inativo'],';','"','');
            }
            rewind($stream);
            $content = stream_get_contents($stream);
            if ($content === false) { throw new RuntimeException('Falha ao gerar CSV.'); }
            return $content;
        } finally {
            fclose($stream);
        }
    }

    /** @param array<string,mixed> $result */
    private function pdfContent(array $result): string
    {
        require_once ROOT_PATH . '/vendor/autoload.php';
        $options=new \Dompdf\Options();
        $options->setIsRemoteEnabled(false); $options->setIsPhpEnabled(false);
        $options->setChroot([ROOT_PATH.'/public/assets']);
        $options->setAllowedProtocols(['data://']);
        $options->setDefaultFont('DejaVu Sans');
        $pdf=new \Dompdf\Dompdf($options);
        $html=$this->pdfHtml($result);
        $pdf->loadHtml($html,'UTF-8'); $pdf->setPaper('A4','portrait'); $pdf->render();
        $content = $pdf->output();
        if ($content === '') { throw new RuntimeException('Falha ao gerar PDF.'); }
        return $content;
    }

    /** @param array<string,mixed> $result */
    private function pdfHtml(array $result): string
    {
        $escape=static fn(?string $text): string => htmlspecialchars((string)$text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $html='<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;color:#17324d;font-size:10px}h1{color:#155185;font-size:17px}.dva-expired-heading{margin:14px 0;padding:12px 14px;color:#991b1b;background:#fff1f2;border:1px solid #fecaca;border-left:5px solid #b91c1c;font-size:18px;font-weight:bold}.dva-expired{color:#991b1b;font-weight:bold}table{width:100%;border-collapse:collapse}th,td{border-bottom:1px solid #ccd5dd;text-align:left;padding:5px}th{background:#dceaf5}tr{page-break-inside:avoid}</style></head><body><h1>Relatório de alunos e DVA</h1>';
        if ($result['filters']['dva'] === DvaStatus::VENCIDA) {
            $html.='<div class="dva-expired-heading">DVA Vencida</div>';
        }
        $now=new DateTimeImmutable('now',new DateTimeZone(Config::string('APP_TIMEZONE','America/Cuiaba')));
        $html.='<p>Gerado em '.$now->format('d/m/Y H:i').' · '.$result['total'].' resultado(s). Dados do momento da geração.</p>';
        $html.='<p>Filtros: turma '.$escape($result['class_filter_label']).' · Situação da DVA: '.$escape($result['filters']['dva'] === '' ? 'Todas' : DvaStatus::label($result['filters']['dva'])).' · alunos '.$escape(match ($result['filters']['ativo']) { '1' => 'Ativos', '0' => 'Inativos', default => 'Todos' }).'</p>';
        $html.='<table><thead><tr><th>Aluno</th><th>Turma</th><th>Nascimento</th><th>Vencimento</th><th>DVA</th></tr></thead><tbody>';
        foreach ($result['items'] as $row) {
            $statusClass = $row['dva_status'] === DvaStatus::VENCIDA ? ' class="dva-expired"' : '';
            $html.='<tr><td>'.$escape($row['nome_completo']).'</td><td>'.$escape(RelatorioAlunos::classLabel($row['nome_turma'],$row['ano_letivo'])).'</td><td>'.$escape(RelatorioAlunos::displayDate($row['data_nascimento'])).'</td><td>'.$escape(RelatorioAlunos::displayDate($row['data_vencimento'])).'</td><td'.$statusClass.'>'.$escape(DvaStatus::label($row['dva_status'])).'</td></tr>';
        }
        return $html.'</tbody></table></body></html>';
    }
}
