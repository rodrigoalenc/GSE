<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Services/AlunoPdfImportService.php';

final class AlunoImportacaoController extends Controller
{
    public function index(): void
    {
        $token = input_string($_GET['preview'] ?? '');
        $preview = (new AlunoPdfImportService())->getPreview($token, $this->actorId());
        if ($token !== '' && $preview === null) {
            definir_flash('warning', 'A prévia expirou ou já foi utilizada. Envie o PDF novamente.');
        }
        $this->view('alunos/importar', [
            'title' => 'Importar alunos matriculados',
            'preview' => $preview,
            'year' => $preview === null ? (int) gmdate('Y') : (int) $preview['year'],
        ]);
    }

    public function preview(): void
    {
        try {
            $year = filter_var($_POST['ano_letivo'] ?? null, FILTER_VALIDATE_INT);
            if ($year === false || $year < 2000 || $year > (int) gmdate('Y') + 5) {
                throw new DomainException('Informe um ano letivo válido.');
            }
            $preview = (new AlunoPdfImportService())->previewUpload(
                is_array($_FILES['arquivo_pdf'] ?? null) ? $_FILES['arquivo_pdf'] : [],
                $year,
                $this->actorId()
            );
            AuditLogger::record('student.import_previewed', AuditLogger::SUCCESS, $this->actorId(), null,
                sprintf('Prévia: %d em curso e %d ignorados por situação.', $preview['eligible'], $preview['ignored']), 'student_import');
            redirect('aluno/importar?preview=' . $preview['token']);
        } catch (DomainException $exception) {
            $this->redirectWithFlash('aluno/importar', 'danger', $exception->getMessage());
        } catch (Throwable $exception) {
            $this->failed($exception);
        }
    }

    public function confirm(): void
    {
        try {
            $result = (new AlunoPdfImportService())->confirm(input_string($_POST['preview_token'] ?? ''), $this->actorId());
            $this->redirectWithFlash('aluno', $result['review'] > 0 ? 'warning' : 'success', sprintf(
                'Importação concluída: %d aluno(s) importado(s), %d turma(s) criada(s), %d já cadastrado(s) ou repetido(s), %d para revisão e %d ignorado(s) por situação.',
                $result['imported'], $result['classes_created'], $result['duplicates'], $result['review'], $result['ignored']
            ));
        } catch (DomainException $exception) {
            $this->redirectWithFlash('aluno/importar', 'danger', $exception->getMessage());
        } catch (Throwable $exception) {
            $this->failed($exception);
        }
    }

    private function actorId(): int
    {
        return (int) ($_SESSION['usuario_id'] ?? 0);
    }

    private function failed(Throwable $exception): never
    {
        TechnicalLogger::error('student_pdf_import_failed', ['exception' => $exception::class]);
        AuditLogger::record('student.import_failed', AuditLogger::FAILURE, $this->actorId(), null,
            'Importação de alunos não concluída.', 'student_import');
        $this->redirectWithFlash('aluno/importar', 'danger', 'Não foi possível concluir a importação. Nenhum cadastro foi gravado. Envie o PDF novamente.');
    }
}
