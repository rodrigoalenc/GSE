<?php

declare(strict_types=1);

namespace Tests\Unit;

use AlunoPdfParser;
use DomainException;
use PHPUnit\Framework\TestCase;
use Tests\Support\AlunoPdfFixture;

require_once ROOT_PATH . '/src/Services/AlunoPdfParser.php';
require_once ROOT_PATH . '/tests/Support/AlunoPdfFixture.php';

final class AlunoPdfParserTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gse-student-pdf-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->directory);
    }

    public function testActualPdfBytesReadAccentsClassAfterTableAndAllStatuses(): void
    {
        $path = AlunoPdfFixture::write($this->directory . '/report.pdf');
        $report = (new AlunoPdfParser())->parseFile($path);

        self::assertSame(1, $report['pages']);
        self::assertSame(3, $report['total']);
        self::assertSame(2, $report['eligible']);
        self::assertSame(1, $report['ignored']);
        self::assertSame(['Em curso' => 2, 'Transferido' => 1], $report['statuses']);
        self::assertSame('ALUNA FICTÍCIA ALFA', $report['rows'][0]['nome_completo']);
        self::assertSame('2019-03-10', $report['rows'][0]['data_nascimento']);
        self::assertSame('1º Ano A - EF - TURMA A', $report['rows'][0]['turma']);
        self::assertSame('9000001', $report['rows'][0]['codigo_sgde']);
        self::assertSame('Transferido', $report['rows'][2]['situacao']);
    }

    public function testActualMultiplePagesKeepEachClassAndVariableStudentCounts(): void
    {
        $row = ['codigo_sgde' => '9000101', 'nome_completo' => 'ALUNA FICTÍCIA DELTA', 'data_nascimento' => '29/02/2020', 'situacao' => 'Em curso'];
        $path = AlunoPdfFixture::writePages($this->directory . '/multiple.pdf', [
            ['turma' => '1º Ano A - EF - TURMA A', 'rows' => [$row]],
            ['turma' => '2º Ano B - EF - TURMA B', 'rows' => [$row, array_replace($row, ['codigo_sgde' => '9000102', 'situacao' => 'Cancelado'])]],
        ]);
        $report = (new AlunoPdfParser())->parseFile($path);

        self::assertSame(2, $report['pages']);
        self::assertSame(3, $report['total']);
        self::assertSame(2, $report['eligible']);
        self::assertSame('2020-02-29', $report['rows'][0]['data_nascimento']);
        self::assertSame('1º Ano A - EF - TURMA A', $report['rows'][0]['turma']);
        self::assertSame('2º Ano B - EF - TURMA B', $report['rows'][1]['turma']);
        self::assertSame(2, $report['rows'][1]['pagina']);
        self::assertSame(['Em curso' => 2, 'Cancelado' => 1], $report['statuses']);
    }

    public function testWrappedNamesStatusesAndRepeatedHeadersAreReadByPosition(): void
    {
        $page = $this->page();
        $page = array_values(array_filter($page, static fn (array $cell): bool => !($cell['x'] === 94.0 && $cell['y'] === 623.0)));
        $page[] = $this->cell(94, 628, "  ÁLVARO\u{00A0}FICTÍCIO ");
        $page[] = $this->cell(94, 618, 'DA SILVA');
        $page = array_values(array_filter($page, static fn (array $cell): bool => !($cell['x'] === 460.0 && $cell['y'] === 623.0)));
        $page[] = $this->cell(460, 628, 'Em');
        $page[] = $this->cell(460, 618, 'curso');
        array_push($page, ...$this->headers(400));
        $page[] = $this->cell(530, 404, 'Nº');
        array_push($page, ...$this->row(380, '9000202', 'ALUNA FICTÍCIA ÉPSILON', 'Transferido'));
        // Simula a ordem de streams do SSRS: os metadados não precedem as linhas.
        $page = array_reverse($page);
        $report = (new AlunoPdfParser())->parsePages([$page]);

        self::assertSame(2, $report['total']);
        self::assertSame(1, $report['eligible']);
        self::assertSame('ÁLVARO FICTÍCIO DA SILVA', $report['rows'][0]['nome_completo']);
        self::assertSame('Em curso', $report['rows'][0]['situacao']);
    }

    public function testOnlyExactNormalizedEmCursoIsEligible(): void
    {
        $page = $this->page();
        $page = array_values(array_filter($page, static fn (array $cell): bool => !($cell['x'] === 460.0 && $cell['y'] === 623.0)));
        $page[] = $this->cell(460, 623, "  EM\u{00A0} CURSO  ");
        array_push($page, ...$this->row(600, '9000202', 'ALUNA FICTÍCIA ZETA', 'Em curso parcial'));
        array_push($page, ...$this->row(580, '9000203', 'ALUNO FICTÍCIO ETA', 'Transferido'));
        $report = (new AlunoPdfParser())->parsePages([$page]);

        self::assertSame(1, $report['eligible']);
        self::assertSame(2, $report['ignored']);
    }

    public function testMissingBirthdateRejectsWholeReportInsteadOfSkippingStudent(): void
    {
        $page = array_values(array_filter($this->page(), static fn (array $cell): bool => $cell['text'] !== '10/03/2019'));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('data de nascimento');
        (new AlunoPdfParser())->parsePages([$page]);
    }

    public function testMissingStudentCodeDoesNotSilentlyDropEligibleRow(): void
    {
        $page = $this->page();
        $orphan = array_values(array_filter($this->row(600, '9000202', 'ALUNA FICTÍCIA TETA', 'Em curso'), static fn (array $cell): bool => $cell['text'] !== '9000202'));
        array_push($page, ...$orphan);
        $this->expectException(DomainException::class);
        (new AlunoPdfParser())->parsePages([$page]);
    }

    public function testMissingSituationRejectsWholeReport(): void
    {
        $page = array_values(array_filter($this->page(), static fn (array $cell): bool => $cell['text'] !== 'Em curso'));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('situação');
        (new AlunoPdfParser())->parsePages([$page]);
    }

    public function testMissingRequiredColumnRejectsWholeReport(): void
    {
        $page = array_values(array_filter($this->page(), static fn (array $cell): bool => $cell['text'] !== 'Sexo'));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('colunas');
        (new AlunoPdfParser())->parsePages([$page]);
    }

    public function testSingleClassContinuationMayOmitRepeatedClassHeader(): void
    {
        $page = array_values(array_filter($this->page(), static fn (array $cell): bool => !str_starts_with($cell['text'], 'Turma:')));
        $report = (new AlunoPdfParser())->parsePages([$this->page(), $page]);
        self::assertSame(2, $report['total']);
        self::assertSame('1º Ano A - EF - TURMA A', $report['rows'][1]['turma']);
        self::assertSame(2, $report['rows'][1]['pagina']);
    }

    public function testMissingClassOnMultipleClassReportNeverGuessesPreviousClass(): void
    {
        $page = array_values(array_filter($this->page(), static fn (array $cell): bool => !str_starts_with($cell['text'], 'Turma:')));
        $otherClass = $this->page();
        $otherClass[count($otherClass) - 1]['text'] = 'Turma: 2º Ano B - EF - TURMA B';
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('turma na página 2');
        (new AlunoPdfParser())->parsePages([$this->page(), $page, $otherClass]);
    }

    public function testThreeLineNameAlignedAtTopNeverAttachesTailToNextStudent(): void
    {
        $page = array_values(array_filter($this->page(), static fn (array $cell): bool => !($cell['x'] === 94.0 && $cell['y'] === 623.0)));
        $page[] = $this->cell(94, 623, 'ALUNA FICTÍCIA COM NOME');
        $page[] = $this->cell(94, 612, 'LONGO PARA TESTAR');
        $page[] = $this->cell(94, 601, 'QUEBRA EM TRÊS LINHAS');
        array_push($page, ...$this->row(580, '9000202', 'ALUNA FICTÍCIA SEGUINTE', 'Em curso'));
        $report = (new AlunoPdfParser())->parsePages([$page]);

        self::assertSame('ALUNA FICTÍCIA COM NOME LONGO PARA TESTAR QUEBRA EM TRÊS LINHAS', $report['rows'][0]['nome_completo']);
        self::assertSame('ALUNA FICTÍCIA SEGUINTE', $report['rows'][1]['nome_completo']);
    }

    public function testInvalidDateRejectsWholeReport(): void
    {
        $page = $this->page();
        foreach ($page as &$cell) {
            if ($cell['text'] === '10/03/2019') {
                $cell['text'] = '31/02/2019';
            }
        }
        unset($cell);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('data de nascimento inválida');
        (new AlunoPdfParser())->parsePages([$page]);
    }

    public function testMoreThanTwoHundredPagesIsRejected(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('200 páginas');
        (new AlunoPdfParser())->parsePages(array_fill(0, 201, $this->page()));
    }

    public function testMoreThanFiveThousandRowsIsRejected(): void
    {
        $page = $this->page();
        for ($i = 1; $i < 26; $i++) {
            array_push($page, ...$this->row(623 - $i * 18, (string) (9000201 + $i), 'ALUNA FICTÍCIA LIMITE', 'Em curso'));
        }
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('5.000 alunos');
        (new AlunoPdfParser())->parsePages(array_fill(0, 193, $page));
    }

    public function testOversizedFileIsRejectedBeforeParsing(): void
    {
        $path = $this->directory . '/oversized.pdf';
        $handle = fopen($path, 'wb');
        self::assertIsResource($handle);
        fwrite($handle, '%PDF-1.7');
        fseek($handle, AlunoPdfParser::MAX_FILE_SIZE);
        fwrite($handle, 'x');
        fclose($handle);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('10 MiB');
        (new AlunoPdfParser())->parseFile($path);
    }

    public function testRenamingPlainTextToPdfDoesNotPassContentValidation(): void
    {
        $path = $this->directory . '/fake.pdf';
        file_put_contents($path, 'Este arquivo contém somente texto.');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('conteúdo');
        (new AlunoPdfParser())->parseFile($path);
    }

    public function testProtectedPdfHasUnderstandableError(): void
    {
        $path = AlunoPdfFixture::write($this->directory . '/protected.pdf');
        $pdf = (string) file_get_contents($path);
        file_put_contents($path, str_replace('trailer << /Size', 'trailer << /Encrypt 3 0 R /Size', $pdf));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('protegido por senha');
        (new AlunoPdfParser())->parseFile($path);
    }

    public function testCompressedContentIsNeverSilentlyTruncatedIntoPartialImport(): void
    {
        $base = AlunoPdfFixture::write($this->directory . '/base.pdf');
        self::assertSame(1, preg_match('/stream\n(.*?)endstream/s', (string) file_get_contents($base), $match));
        // O início contém linhas válidas; aceitar o prefixo truncado importaria um lote parcial.
        $path = AlunoPdfFixture::writeCompressedContent($this->directory . '/expansion.pdf', $match[1] . str_repeat(' ', 9 * 1024 * 1024));
        self::assertLessThan(50_000, filesize($path));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('expansão');
        (new AlunoPdfParser())->parseFile($path);
    }

    public function testImageOnlyPdfHasUnderstandableError(): void
    {
        $path = AlunoPdfFixture::write($this->directory . '/no-text.pdf');
        $pdf = (string) file_get_contents($path);
        $withoutText = preg_replace_callback('/BT .*? ET/s', static fn (array $match): string => str_repeat(' ', strlen($match[0])), $pdf);
        self::assertIsString($withoutText);
        file_put_contents($path, $withoutText);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('digitalizados');
        (new AlunoPdfParser())->parseFile($path);
    }

    public function testManySmallCompressedStreamsShareOneAggregateBudget(): void
    {
        $path = AlunoPdfFixture::writeCompressedStreams($this->directory . '/aggregate.pdf', 5, 7 * 1024 * 1024);
        self::assertLessThan(100_000, filesize($path));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('expansão');
        (new AlunoPdfParser())->parseFile($path);
    }

    public function testDirectParsingCannotBypassIsolatedWorker(): void
    {
        $path = AlunoPdfFixture::write($this->directory . '/direct.pdf');
        $this->expectException(\LogicException::class);
        (new AlunoPdfParser())->parseFileInWorker($path);
    }

    public function testSyntheticFiveThousandStudentReportFitsTheWorkerBudget(): void
    {
        $pages = [];
        for ($page = 0; $page < 200; $page++) {
            $rows = [];
            for ($row = 0; $row < 25; $row++) {
                $rows[] = ['codigo_sgde' => (string)(9_000_000 + $page * 25 + $row), 'nome_completo' => 'ALUNO FICTICIO VOLUME',
                    'data_nascimento' => '10/03/2019', 'situacao' => 'Em curso'];
            }
            $pages[] = ['turma' => '1º Ano A - EF - TURMA A', 'rows' => $rows];
        }
        $path = AlunoPdfFixture::writePages($this->directory . '/maximum.pdf', $pages);
        $report = (new AlunoPdfParser())->parseFile($path);
        self::assertSame(200, $report['pages']);
        self::assertSame(5_000, $report['total']);
        self::assertSame(5_000, $report['eligible']);
        self::assertSame('9004999', $report['rows'][4_999]['codigo_sgde']);
    }

    /** @return list<array{x:float,y:float,text:string}> */
    private function page(): array
    {
        return [
            $this->cell(99, 752, 'RELATÓRIO DE ESTUDANTES ALOCADOS'),
            ...$this->headers(641),
            ...$this->row(623, '9000201', 'ALUNA FICTÍCIA IOTA', 'Em curso'),
            $this->cell(30, 711, 'Turma: 1º Ano A - EF - TURMA A'),
        ];
    }

    /** @return list<array{x:float,y:float,text:string}> */
    private function headers(int $y): array
    {
        return [$this->cell(38, $y, 'Cod. SGDE'), $this->cell(183, $y, 'Nome do Estudante'),
            $this->cell(366, $y, 'Dt. Nasc'), $this->cell(418, $y, 'Sexo'),
            $this->cell(460, $y, 'Situação'), $this->cell(518, $y, 'Chamada')];
    }

    /** @return list<array{x:float,y:float,text:string}> */
    private function row(int $y, string $code, string $name, string $status): array
    {
        return [$this->cell(44, $y, $code), $this->cell(94, $y, $name), $this->cell(362, $y, '10/03/2019'),
            $this->cell(425, $y, 'F'), $this->cell(460, $y, $status), $this->cell(534, $y, '1')];
    }

    /** @return array{x:float,y:float,text:string} */
    private function cell(int $x, int $y, string $text): array
    {
        return ['x' => (float) $x, 'y' => (float) $y, 'text' => $text];
    }
}
