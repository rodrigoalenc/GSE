<?php

declare(strict_types=1);

namespace Tests\Integration;

use Agenda;
use CalendarioEscolarImportador;
use DomainException;
use Dompdf\Dompdf;
use PDOException;
use Tests\Support\DatabaseTestCase;

final class AgendaTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT_PATH . '/src/Model/Agenda.php';
        require_once ROOT_PATH . '/src/Services/CalendarioEscolarImportador.php';
    }

    public function testNoticesAreListedByDateAndOnlyOwnerOrAdminCanChangeThem(): void
    {
        $owner = $this->insertUsuario('Autor Agenda', 'funcionario');
        $other = $this->insertUsuario('Outro Agenda', 'funcionario');
        $admin = $this->insertUsuario('Admin Agenda');
        $agenda = new Agenda();
        $id = $agenda->cadastrar($owner, '2026-12-31', 'Aviso da turma', 'Descrição');
        $this->assertIsInt($id);
        $this->assertNull($agenda->cadastrar($owner, '2026-12-31', 'Aviso da turma', 'Descrição'));
        $this->assertIsInt($agenda->cadastrar($owner, '2027-01-01', 'Aviso fora do período', ''));

        $rows = $agenda->listar('2026-12-01', '2026-12-31');
        $this->assertCount(1, $rows);
        $this->assertSame('Autor Agenda', $rows[0]['autor_nome']);
        $this->assertSame(1, $rows[0]['revisao']);
        $this->assertDomainStatus(403, fn () => $agenda->buscarParaEdicao($other, $id));
        $this->assertDomainStatus(403, fn () => $agenda->editar($other, $id, 1, '2026-12-31', 'Mudança indevida', ''));
        $this->assertDomainStatus(403, fn () => $agenda->excluir($other, $id, 1));

        $this->assertFalse($agenda->editar($owner, $id, 1, '2026-12-31', 'Aviso da turma', 'Descrição'));
        $this->assertTrue($agenda->editar($owner, $id, 1, '2026-12-30', 'Aviso revisto', 'Descrição'));
        $this->assertDomainStatus(409, fn () => $agenda->excluir($admin, $id, 1));
        $this->assertSame(2, $agenda->buscarParaEdicao($admin, $id)['revisao']);
        $this->assertTrue($agenda->editar($admin, $id, 2, '2026-12-30', 'Aviso revisto pela equipe', ''));
        $agenda->excluir($admin, $id, 3);
        $this->assertDomainStatus(404, fn () => $agenda->excluir($admin, $id, 3));
        $this->assertSame([], $agenda->listar('2026-12-01', '2026-12-31'));
        $this->assertSame(4, (int) $this->pdo->query("SELECT revisao FROM agenda_avisos WHERE id = {$id} AND excluido_em IS NOT NULL")->fetchColumn());
        $this->assertSame(2, $this->auditCount('agenda.notice_created'));
        $this->assertSame(2, $this->auditCount('agenda.notice_updated'));
        $this->assertSame(1, $this->auditCount('agenda.notice_deleted'));
    }

    public function testInvalidDatesAndMultibyteFieldLimitsAreCheckedWithoutSaving(): void
    {
        $actor = $this->insertUsuario('Autor Validação Agenda', 'funcionario');
        $agenda = new Agenda();
        $this->assertDomainStatus(422, fn () => $agenda->cadastrar($actor, '2026-02-29', 'Inválido', ''));
        $this->assertDomainStatus(422, fn () => $agenda->cadastrar($actor, '2028-02-29', str_repeat('á', 121), ''));
        $this->assertDomainStatus(422, fn () => $agenda->cadastrar($actor, '2028-02-29', "\u{00a0}", ''));
        $this->assertDomainStatus(422, fn () => $agenda->cadastrar($actor, '2028-02-29', 'Teste', str_repeat('ó', 1001)));
        $this->assertIsInt($agenda->cadastrar($actor, '2028-02-29', str_repeat('á', 120), str_repeat('ó', 1000)));
        $this->assertCount(1, $agenda->listar('2028-02-01', '2028-02-29'));
    }

    public function testImportPreviewAppliesOnlySelectedItemsAndDeduplicatesReimports(): void
    {
        $actor = $this->insertUsuario('Admin Importação Agenda');
        $other = $this->insertUsuario('Outro Admin Agenda');
        $employee = $this->insertUsuario('Funcionário Importação Agenda', 'funcionario');
        $service = new CalendarioEscolarImportador();
        $agenda = new Agenda();
        $text = "07/09 - Feriado da Independência\n19 a 20 de Outubro — Recesso\n31/02 - Feriado inválido";
        $preview = $service->previsualizar([], 2026, $text, $actor);
        $this->assertSame(['2026-09-07', '2026-10-19', '2026-10-20'], array_column($preview['itens'], 'data_aviso'));
        $this->assertSame([], $agenda->listar('2026-01-01', '2026-12-31'));
        $this->assertDomainStatus(422, fn () => $service->confirmar($preview['token'], ['0'], $other));
        $this->assertDomainStatus(422, fn () => $service->confirmar($preview['token'], ['3'], $actor));
        $this->assertSame(['salvos' => 1, 'ignorados' => 0], $service->confirmar($preview['token'], ['1', '1'], $actor));
        $this->assertDomainStatus(422, fn () => $service->confirmar($preview['token'], ['2'], $actor));
        $this->assertDomainStatus(403, fn () => $agenda->importar($employee, $preview['itens']));

        $second = $service->previsualizar([], 2026, $text, $other);
        $this->assertSame(['salvos' => 2, 'ignorados' => 1], $service->confirmar($second['token'], ['0', '1', '2'], $other));
        $rows = $agenda->listar('2026-01-01', '2026-12-31');
        $this->assertSame('[Calendário] Feriado da Independência', $rows[0]['titulo']);
        $this->assertSame('Feriado', $rows[0]['tipo']);
        $this->assertSame($actor, $rows[1]['usuario_id']);
        $this->assertSame(2, $this->auditCount('agenda.calendar_imported'));
    }

    public function testExpiredPreviewCannotBeAppliedAndDoesNotKeepSourceText(): void
    {
        $actor = $this->insertUsuario('Admin Expiração Agenda');
        $service = new CalendarioEscolarImportador();
        $preview = $service->previsualizar([], 2026, "Linha que não é um evento\n02/02 - Jornada pedagógica", $actor);
        $this->assertStringNotContainsString('Linha que não é um evento', serialize($_SESSION));
        $_SESSION['agenda_calendario_previa']['expira_em'] = time();

        $this->assertDomainStatus(422, fn () => $service->confirmar($preview['token'], ['0'], $actor));
        $this->assertArrayNotHasKey('agenda_calendario_previa', $_SESSION);
        $this->assertSame([], (new Agenda())->listar('2026-01-01', '2026-12-31'));
    }

    public function testAuditFailureRollsBackNoticeAndCalendarBatch(): void
    {
        $actor = $this->insertUsuario('Admin Auditoria Agenda');
        $agenda = new Agenda();
        $this->pdo->exec("CREATE TRIGGER abort_agenda_audit BEFORE INSERT ON security_audit
            WHEN NEW.action LIKE 'agenda.%' BEGIN SELECT RAISE(ABORT, 'teste auditoria'); END");

        try {
            $agenda->cadastrar($actor, '2026-10-09', 'Aviso', '');
            self::fail('Cadastrou aviso sem auditoria.');
        } catch (PDOException) {
            $this->assertSame([], $agenda->listar('2026-10-01', '2026-10-31'));
        }

        try {
            $agenda->importar($actor, [
                ['data_aviso' => '2026-10-10', 'titulo' => 'Dia não letivo', 'descricao' => '', 'tipo' => 'Dia não letivo'],
                ['data_aviso' => '2026-10-11', 'titulo' => 'Feriado', 'descricao' => '', 'tipo' => 'Feriado'],
            ]);
            self::fail('Importou calendário sem auditoria.');
        } catch (PDOException) {
            $this->assertSame([], $agenda->listar('2026-10-01', '2026-10-31'));
        }
    }

    public function testCalendarTextCanBeExtractedFromARealPdf(): void
    {
        $actor = $this->insertUsuario('Admin PDF Agenda');
        $path = tempnam(sys_get_temp_dir(), 'gse-agenda-pdf-');
        $this->assertIsString($path);
        try {
            $pdf = new Dompdf();
            $pdf->loadHtml('<p>21/04/2026 - Feriado Tiradentes</p><p>12 a 15 de outubro - Recesso escolar</p>');
            $pdf->render();
            file_put_contents($path, $pdf->output());
            $service = new CalendarioEscolarImportador();
            $text = $service->extrairTextoPdf($path);
            $preview = $service->previsualizar([], 2026, $text, $actor);
            $this->assertSame(['2026-04-21', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15'], array_column($preview['itens'], 'data_aviso'));
            $this->assertStringNotContainsString($path, serialize($_SESSION));
        } finally {
            unlink($path);
        }
    }

    private function assertDomainStatus(int $expected, callable $operation): void
    {
        try {
            $operation();
            self::fail('A operação deveria lançar uma exceção de domínio.');
        } catch (DomainException $exception) {
            $this->assertSame($expected, $exception->getCode(), $exception->getMessage());
        }
    }

    private function auditCount(string $action): int
    {
        $query = $this->pdo->prepare('SELECT COUNT(*) FROM security_audit WHERE action = ? AND result = ?');
        $query->execute([$action, 'success']);

        return (int) $query->fetchColumn();
    }
}
