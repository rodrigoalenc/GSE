<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once ROOT_PATH . '/src/Services/AlunoPdfImportService.php';
require_once ROOT_PATH . '/tests/Support/AlunoPdfFixture.php';

use DomainException;
use PDOException;
use Tests\Support\AlunoPdfFixture;
use Tests\Support\DatabaseTestCase;

final class AlunoPdfImportServiceTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testRealPdfPreviewKeepsOnlyParsedSessionDataAndLeavesOriginalFileUnchanged(): void
    {
        $actor = $this->insertUsuario('Admin PDF original');
        $file = $this->temporaryFile();
        AlunoPdfFixture::write($file);
        $original = hash_file('sha256', $file);
        $service = new \AlunoPdfImportService();

        $preview = $service->previewFile($file, 2026, $actor);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $preview['token']);
        $this->assertSame($actor, $preview['actor_id']);
        $this->assertSame(3, $preview['total']);
        $this->assertSame(2, $preview['eligible']);
        $this->assertSame(1, $preview['ignored']);
        $this->assertSame(2, $preview['counts']['importar']);
        $this->assertGreaterThanOrEqual(time() + \AlunoPdfImportService::PREVIEW_TTL - 2, $preview['expires_at']);
        $this->assertSame($preview, $service->getPreview($preview['token'], $actor));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM turmas')->fetchColumn());
        $this->assertFileExists($file);
        $this->assertSame($original, hash_file('sha256', $file));
        $this->assertStringNotContainsString($file, serialize($_SESSION['aluno_pdf_previews']));
        $this->assertArrayNotHasKey('path', $preview);
        $this->assertArrayNotHasKey('file', $preview);
        $this->assertArrayNotHasKey('content', $preview);

        // Confirmation depends exclusively on the reviewed, server-held plan.
        unlink($file);
        $this->assertSame(
            ['imported' => 2, 'classes_created' => 1, 'duplicates' => 0, 'review' => 0, 'ignored' => 1],
            $service->confirm($preview['token'], $actor)
        );
        $this->assertNull($service->getPreview($preview['token'], $actor));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM dvas')->fetchColumn());
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos')->fetchColumn());
        $this->expectException(DomainException::class);
        $service->confirm($preview['token'], $actor);
    }

    public function testNewPreviewReplacesPreviousTokenAndPersonalData(): void
    {
        $actor = $this->insertUsuario('Admin PDF substituir');
        $oldName = 'ALUNO FICTICIO PREVIA ANTIGA';
        $old = $this->seedPreview([$this->row($oldName, 'Turma antiga')], $actor);
        $file = $this->temporaryFile();
        AlunoPdfFixture::write($file, [[
            'codigo_sgde' => '900001', 'nome_completo' => 'ALUNO FICTICIO PREVIA NOVA',
            'data_nascimento' => '10/05/2010', 'situacao' => 'Em curso',
        ]]);
        $service = new \AlunoPdfImportService();

        $preview = $service->previewFile($file, 2026, $actor);
        $this->assertNotSame($old['token'], $preview['token']);
        $this->assertNull($service->getPreview($old['token'], $actor));
        $this->assertCount(1, $_SESSION['aluno_pdf_previews']);
        $this->assertStringNotContainsString($oldName, serialize($_SESSION['aluno_pdf_previews']));
        $this->assertSame('ALUNO FICTICIO PREVIA NOVA', $preview['items'][0]['nome_completo']);
    }

    public function testAnotherActorCannotReadOrConsumePreviewAndOwnerCanStillConfirm(): void
    {
        $owner = $this->insertUsuario('Admin PDF dono');
        $other = $this->insertUsuario('Admin PDF outro');
        $preview = $this->seedPreview([$this->row('Aluno fictício dono', 'Turma')], $owner);
        $service = new \AlunoPdfImportService();

        $this->assertNull($service->getPreview($preview['token'], $other));

        try {
            $service->confirm($preview['token'], $other);
            $this->fail('Outro ator confirmou a prévia.');
        } catch (DomainException) {
            $this->assertArrayHasKey($preview['token'], $_SESSION['aluno_pdf_previews']);
        }

        $this->assertSame(1, $service->confirm($preview['token'], $owner)['imported']);
        $this->assertArrayNotHasKey($preview['token'], $_SESSION['aluno_pdf_previews']);
    }

    public function testExpiredPreviewIsRemovedWithItsPersonalDataAndCannotImport(): void
    {
        $actor = $this->insertUsuario('Admin PDF expiracao');
        $name = 'Aluno fictício expirado';
        $preview = $this->seedPreview([$this->row($name, 'Turma')], $actor);
        $_SESSION['aluno_pdf_previews'][$preview['token']]['expires_at'] = time();
        $service = new \AlunoPdfImportService();

        $this->assertNull($service->getPreview($preview['token'], $actor));
        $this->assertStringNotContainsString($name, serialize($_SESSION['aluno_pdf_previews']));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos')->fetchColumn());
        $this->expectException(DomainException::class);
        $service->confirm($preview['token'], $actor);
    }

    public function testMalformedTokensDoNotReadOrConsumeValidPreview(): void
    {
        $actor = $this->insertUsuario('Admin PDF token');
        $preview = $this->seedPreview([$this->row('Aluno fictício token', 'Turma')], $actor);
        $service = new \AlunoPdfImportService();

        foreach (['', '../preview', str_repeat('a', 63), str_repeat('A', 64), $preview['token'] . "\n"] as $token) {
            $this->assertNull($service->getPreview($token, $actor));

            try {
                $service->confirm($token, $actor);
                $this->fail('Token inválido foi aceito.');
            } catch (DomainException) {
                $this->assertArrayHasKey($preview['token'], $_SESSION['aluno_pdf_previews']);
            }
        }

        $this->assertSame(1, $service->confirm($preview['token'], $actor)['imported']);
    }

    public function testRowsSkippedInPreviewNeverBecomeAdditionsAfterDatabaseChanges(): void
    {
        $actor = $this->insertUsuario('Admin PDF revisao fixa');
        $existing = $this->insertAlunoComDva('Aluno duplicado fictício', '2027-01-01');
        $classId = $this->insertTurma('Turma inativa');
        $this->pdo->prepare('UPDATE turmas SET ano_letivo = 2026,ativo = 0 WHERE id = ?')->execute([$classId]);
        $preview = $this->seedPreview([
            $this->row('Aluno duplicado fictício', 'Turma descartada'),
            $this->row('Aluno para revisão fictício', 'Turma inativa'),
            $this->row('Aluno aprovado fictício', 'Turma nova'),
        ], $actor);
        $this->assertSame(['importar' => 1, 'duplicado' => 1, 'revisar' => 1], $preview['counts']);
        $this->pdo->prepare('UPDATE alunos SET nome_completo = ?,nome_normalizado = ? WHERE id = ?')
            ->execute(['Aluno renomeado fictício', 'aluno renomeado fictício', $existing]);
        $this->pdo->prepare('UPDATE turmas SET ativo = 1 WHERE id = ?')->execute([$classId]);

        $result = (new \AlunoPdfImportService())->confirm($preview['token'], $actor);
        $this->assertSame(['imported' => 1, 'classes_created' => 1, 'duplicates' => 1, 'review' => 1, 'ignored' => 0], $result);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM alunos WHERE nome_completo IN ('Aluno duplicado fictício','Aluno para revisão fictício')")->fetchColumn());
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM turmas WHERE nome_turma = 'Turma descartada'")->fetchColumn());
    }

    public function testStudentAddedAfterPreviewIsSkippedOnConfirmationWithoutUnusedClass(): void
    {
        $actor = $this->insertUsuario('Admin PDF concorrencia');
        $preview = $this->seedPreview([$this->row('Aluno concorrente fictício', 'Turma não criar')], $actor);
        $this->insertAlunoComDva('ALUNO CONCORRENTE FICTÍCIO', '2027-01-01');

        $result = (new \AlunoPdfImportService())->confirm($preview['token'], $actor);
        $this->assertSame(['imported' => 0, 'classes_created' => 0, 'duplicates' => 1, 'review' => 0, 'ignored' => 0], $result);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM turmas')->fetchColumn());
    }

    public function testFailedAuditRollsBackAndConsumesPreview(): void
    {
        $actor = $this->insertUsuario('Admin PDF falha audit');
        $preview = $this->seedPreview([$this->row('Aluno fictício rollback', 'Turma rollback')], $actor);
        $this->pdo->exec("CREATE TRIGGER reject_pdf_import_audit BEFORE INSERT ON security_audit WHEN NEW.action = 'student.import_completed' BEGIN SELECT RAISE(ABORT, 'audit_rejected'); END");
        $service = new \AlunoPdfImportService();

        try {
            $service->confirm($preview['token'], $actor);
            $this->fail('Falha de auditoria não interrompeu a importação.');
        } catch (PDOException) {
            $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos')->fetchColumn());
            $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM turmas')->fetchColumn());
            $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action IN ('student.created','class.created','student.import_completed')")->fetchColumn());
            $this->assertNull($service->getPreview($preview['token'], $actor));
        }

        $this->expectException(DomainException::class);
        $service->confirm($preview['token'], $actor);
    }

    public function testPreviewWithOnlySkippedRowsCannotImportAndIsConsumed(): void
    {
        $actor = $this->insertUsuario('Admin PDF sem novos');
        $this->insertAlunoComDva('Aluno existente fictício', '2027-01-01');
        $preview = $this->seedPreview([$this->row('Aluno existente fictício', 'Turma não criar')], $actor);
        $service = new \AlunoPdfImportService();

        try {
            $service->confirm($preview['token'], $actor);
            $this->fail('Prévia sem novos alunos foi confirmada.');
        } catch (DomainException) {
            $this->assertNull($service->getPreview($preview['token'], $actor));
            $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos')->fetchColumn());
            $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM turmas')->fetchColumn());
        }
    }

    public function testUploadErrorsAndOversizedFilesNeverProduceSuccessfulPreview(): void
    {
        $actor = $this->insertUsuario('Admin PDF upload');
        $service = new \AlunoPdfImportService();

        foreach ([UPLOAD_ERR_NO_FILE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE] as $error) {
            try {
                $service->previewUpload(['error' => $error, 'tmp_name' => ''], 2026, $actor);
                $this->fail('Upload inválido produziu prévia.');
            } catch (DomainException) {
                $this->assertSame([], $_SESSION['aluno_pdf_previews'] ?? []);
            }
        }

        $file = $this->temporaryFile();
        $handle = fopen($file, 'wb');
        $this->assertIsResource($handle);
        fwrite($handle, "%PDF-1.7\n");
        ftruncate($handle, \AlunoPdfParser::MAX_FILE_SIZE + 1);
        fclose($handle);
        clearstatcache(true, $file);

        try {
            $service->previewFile($file, 2026, $actor);
            $this->fail('Arquivo acima do limite produziu prévia.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('10 MiB', $exception->getMessage());
            $this->assertSame([], $_SESSION['aluno_pdf_previews'] ?? []);
            $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos')->fetchColumn());
            $this->assertFileExists($file);
        }

        try {
            $service->previewUpload(['error' => UPLOAD_ERR_OK, 'tmp_name' => $file], 2026, $actor);
            $this->fail('Arquivo local comum foi aceito como upload HTTP.');
        } catch (DomainException) {
            $this->assertSame([], $_SESSION['aluno_pdf_previews'] ?? []);
        }
    }

    /**
     * @param list<array{nome_completo:string,data_nascimento:string,turma:string,codigo_sgde:string,situacao:string,pagina:int}> $rows
     * @return array<string,mixed>
     */
    private function seedPreview(array $rows, int $actorId): array
    {
        $plan = (new \AlunoImportacao())->preview($rows, 2026);
        $token = bin2hex(random_bytes(32));
        $preview = $plan + [
            'token' => $token, 'actor_id' => $actorId,
            'expires_at' => time() + \AlunoPdfImportService::PREVIEW_TTL,
            'pages' => 1, 'total' => count($rows), 'eligible' => count($plan['items']),
            'ignored' => count($rows) - count($plan['items']), 'statuses' => ['Em curso' => count($plan['items'])],
        ];
        $_SESSION['aluno_pdf_previews'][$token] = $preview;

        return $preview;
    }

    /** @return array{nome_completo:string,data_nascimento:string,turma:string,codigo_sgde:string,situacao:string,pagina:int} */
    private function row(string $name, string $class): array
    {
        return [
            'nome_completo' => $name, 'data_nascimento' => '2010-05-10', 'turma' => $class,
            'codigo_sgde' => '900001', 'situacao' => 'Em curso', 'pagina' => 1,
        ];
    }

    private function temporaryFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'gse-pdf-service-');
        $this->assertIsString($file);
        $this->files[] = $file;

        return $file;
    }
}
