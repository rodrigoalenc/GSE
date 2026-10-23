<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Tests\Support\DatabaseTestCase;

final class DvaClassificationConsistencyTest extends DatabaseTestCase
{
    public function testLegacyInvalidDatesMatchAcrossFiltersSummaryDashboardAndReports(): void
    {
        $status = new \DvaStatus(new DateTimeImmutable('2026-08-20'), 30);
        foreach (['', '2026-02-30', '2026-2-01', '9999-99-99', "2026-08-20\0", '2026-08-19', '2026-08-20', '2026-09-19', '2026-09-20'] as $i => $date) {
            $this->insertAlunoComDva('Aluno ' . $i, $date);
        }
        $student = new \Aluno();
        $panel = new \Painel();
        $report = new \RelatorioAlunos($status);
        $summary = $panel->resumo($status);
        $groups = $panel->alunosPorStatus($status);
        $columns = ['sem_dva', 'vencidas', 'vence_hoje', 'a_vencer', 'vigentes'];
        foreach (\DvaStatus::ALL as $i => $key) {
            $expected = $key === \DvaStatus::SEM_DVA ? 5 : 1;
            $this->assertSame($expected, $student->paginate(['dva' => $key, 'ativo' => '1'], 1, 20, $status)['total'], $key);
            $this->assertSame($expected, $summary[$columns[$i]], $key);
            $this->assertCount($expected, $groups[$key], $key);
            $this->assertSame($expected, $report->query(['dva' => $key])['total'], $key);
        }
        $this->assertCount(8, $panel->pendenciasPrioritarias(20, $status));
    }

    public function testReportInstancesKeepIndependentReferenceDatesOnSameConnection(): void
    {
        $this->insertAlunoComDva('Aluno Data', '2026-08-20');
        $this->insertAlunoComDva('Aluno Limite', '2026-08-29');
        $before = new \RelatorioAlunos(new \DvaStatus(new DateTimeImmutable('2026-08-19'), 30));
        $after = new \RelatorioAlunos(new \DvaStatus(new DateTimeImmutable('2026-08-21'), 3));
        $this->assertSame(2, $before->query(['dva' => 'a_vencer'])['total']);
        $this->assertSame(1, $after->query(['dva' => 'vencida'])['total']);
        $this->assertSame(1, $after->query(['dva' => 'vigente'])['total']);
    }

    public function testDashboardLimitsEveryGroupInSqlWhileCountsStayComplete(): void
    {
        $status = new \DvaStatus(new DateTimeImmutable('2026-08-20'), 30);
        foreach (['2026-02-30', '2026-08-19', '2026-08-20', '2026-09-19', '2026-09-20'] as $i => $date) {
            for ($n = 0; $n < 27; $n++) {
                $this->insertAlunoComDva(sprintf('Aluno %d %02d', $i, $n), $date);
            }
        }
        $panel = new \Painel();
        $groups = $panel->alunosPorStatus($status);
        foreach (\DvaStatus::ALL as $key) {
            $this->assertCount(10, $groups[$key]);
        }
        $this->assertSame(135, $panel->resumo($status)['alunos_ativos']);
        $this->assertSame(27, $panel->resumo($status)['sem_dva']);
    }
}
