<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class RelatorioTest extends DatabaseTestCase
{
    public function testPreviaDistingueTurmasHomonimasPorAnoESemVinculo(): void
    {
        $classes = $this->reportClasses();
        $model = new \RelatorioAlunos();
        $result = $model->query(['ativo' => 'todos']);
        $html = $this->renderReportPreview($result, $model->classes());

        foreach (['Turma &lt;A&gt; — 2026', 'Turma &lt;A&gt; — 2027', 'Legada — Ano não informado'] as $label) {
            $this->assertStringContainsString($label . '</option>', $html);
            $this->assertStringContainsString('<td>' . $label . '</td>', $html);
        }
        $this->assertStringContainsString('<td>Sem turma</td>', $html);
        foreach ([2026, 2027] as $year) {
            $filtered = $model->query(['turma' => (string) $classes[$year]], 1, true);
            $this->assertSame(1, $filtered['total']);
            $this->assertSame('Aluno ' . $year, $filtered['items'][0]['nome_completo']);
            $selected = $this->renderReportPreview($filtered, $model->classes());
            $this->assertStringContainsString('value="' . $classes[$year] . '" selected', $selected);
        }
    }

    public function testPdfDescreveTurmaDoFiltroMesmoSemAlunosEHtmlEscapaRotulos(): void
    {
        $classes = $this->reportClasses();
        require_once ROOT_PATH . '/src/Controllers/RelatorioController.php';
        $render = new \ReflectionMethod(\RelatorioController::class, 'pdfHtml');
        $model = new \RelatorioAlunos();
        $all = $render->invoke(new \RelatorioController(), $model->query(['ativo' => 'todos'], 1, true));
        foreach (['Turma &lt;A&gt; — 2026', 'Turma &lt;A&gt; — 2027', 'Legada — Ano não informado', 'Sem turma'] as $label) {
            $this->assertStringContainsString('<td>' . $label . '</td>', $all);
        }
        $empty = $model->query(['turma' => (string) $classes[2027], 'ativo' => '0'], 1, true);
        $this->assertSame(0, $empty['total']);
        $pdfHtml = $render->invoke(new \RelatorioController(), $empty);
        $this->assertStringContainsString('Filtros: turma Turma &lt;A&gt; — 2027', $pdfHtml);
        $this->assertStringNotContainsString('<A>', $pdfHtml);
    }

    /** @return array<int,int> */
    private function reportClasses(): array
    {
        $classes = [];
        $insert = $this->pdo->prepare('INSERT INTO turmas(nome_turma,nome_normalizado,ano_letivo) VALUES(?,?,?)');
        foreach ([2026, 2027] as $year) {
            $insert->execute(['Turma <A>', 'turma <a>', $year]);
            $classes[$year] = (int) $this->pdo->lastInsertId();
            $this->insertAlunoComDva('Aluno ' . $year, '2028-01-01', $classes[$year]);
        }
        $legacy = $this->insertTurma('Legada');
        $this->insertAlunoComDva('Aluno legado', '2028-01-01', $legacy);
        $this->insertAlunoComDva('Aluno sem turma', '2028-01-01');
        return $classes;
    }

    /** @param array<string,mixed> $result
     *  @param list<array<string,mixed>> $classes */
    private function renderReportPreview(array $result, array $classes): string
    {
        ob_start();
        require ROOT_PATH . '/src/Views/relatorios/index.php';
        return (string) ob_get_clean();
    }

    public function testFiltrosDoRelatorio(): void
    {
        $idTurma = $this->insertTurma('Turma Relatorio');
        $this->insertAlunoComDva('Aluno Vencido', date('Y-m-d', strtotime('-2 days')), $idTurma);
        $this->insertAlunoComDva('Aluno A Vencer', date('Y-m-d', strtotime('+5 days')), $idTurma);
        $this->insertAlunoComDva('Aluno Vigente', date('Y-m-d', strtotime('+60 days')), $idTurma);
        $this->pdo->prepare(
            'INSERT INTO alunos (nome_completo, nome_normalizado, data_nascimento, id_turma) VALUES (?, ?, ?, ?)'
        )->execute(['Aluno Sem DVA', 'aluno sem dva', '2010-01-01', $idTurma]);

        $relatorio = new \Relatorio();

        $this->assertCount(1, $relatorio->getTurmas());
        $this->assertCount(4, $relatorio->buscarDados($idTurma, ''));
        $this->assertCount(1, $relatorio->buscarDados($idTurma, 'sem_dva'));
        $this->assertCount(1, $relatorio->buscarDados($idTurma, 'vencida'));
        $this->assertCount(1, $relatorio->buscarDados($idTurma, 'avencer'));
        $this->assertCount(1, $relatorio->buscarDados($idTurma, 'vigente'));
    }
}
