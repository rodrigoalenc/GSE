<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class PassivoCsvTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        $previews = $_SESSION['passivo_import_previews'] ?? [];

        if (is_array($previews)) {
            foreach ($previews as $preview) {
                $path = is_array($preview) ? ($preview['path'] ?? null) : null;

                if (is_string($path) && is_file($path)) {
                    @unlink($path);
                }
            }
        }

        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testBomPreviewAtomicConfirmationAndSingleUseToken(): void
    {
        $actor = $this->insertUsuario('Admin CSV');
        $file = $this->csv("\xEF\xBB\xBFNome;Data;N\u{00FA}mero;Caixa\nJos\u{00E9} CSV;01/02/2000;1;CX-CSV\nAna CSV;2001-03-04;;CX-CSV\n");
        $service = new \PassivoCsvService();
        $model = new \Passivo();
        $preview = $service->previewTrustedFile($file, $actor, $model);

        $this->assertIsArray($preview);
        $this->assertSame(2, $preview['valid']);
        $this->assertSame(0, $preview['invalid']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $preview['token']);
        $this->assertGreaterThanOrEqual(time() + \PassivoCsvService::PREVIEW_TTL - 2, $preview['expires_at']);
        $storedPath = (string) $_SESSION['passivo_import_previews'][$preview['token']]['path'];
        $this->assertFileExists($storedPath);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{48}\.csv$/', basename($storedPath));
        $this->assertStringNotContainsString(
            str_replace('\\', '/', (string) realpath(ROOT_PATH . '/public')),
            str_replace('\\', '/', (string) realpath($storedPath))
        );
        $this->assertSame(hash_file('sha256', $storedPath), $_SESSION['passivo_import_previews'][$preview['token']]['sha256']);
        $this->assertSame(2, $service->confirm($preview['token'], $actor, $model));
        $this->assertFileDoesNotExist($storedPath);
        $this->assertArrayNotHasKey($preview['token'], $_SESSION['passivo_import_previews'] ?? []);
        $this->assertSame(2, $model->paginate(['caixa' => 'cx-csv', 'ativo' => '1'])['total']);
        $auditDescription = (string) $this->pdo->query(
            "SELECT description FROM security_audit WHERE action = 'passive.import_completed' ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        $this->assertStringContainsString('2 registros', $auditDescription);
        $this->assertStringNotContainsString('José CSV', $auditDescription);
        $this->assertStringNotContainsString('Ana CSV', $auditDescription);
        $this->assertFalse($service->confirm($preview['token'], $actor, $model));
        $this->assertSame('invalid_token', $service->lastErrorCode());
    }

    public function testPreviewReportsInvalidDuplicateAndLocationConflictRows(): void
    {
        $actor = $this->insertUsuario('Admin CSV Conflitos');
        $model = new \Passivo();
        $model->cadastrar(['nome_completo' => 'Existente', 'data_nascimento' => '2000-01-01', 'numero' => '5', 'caixa' => 'A'], $actor);
        $file = $this->csv("Nome;Data;Numero;Caixa\nExistente;2000-01-01;8;B\nNovo;2001-01-01;5;A\nData Ruim;31/02/2020;9;C\nLinha;Extra;Com;Cinco;Colunas\n\nValido;2002-02-02;1;D\n");
        $preview = (new \PassivoCsvService())->previewTrustedFile($file, $actor, $model);

        $this->assertIsArray($preview);
        $this->assertSame(1, $preview['valid']);
        $this->assertSame(3, $preview['invalid']);
        $this->assertSame(1, $preview['duplicate']);
        $this->assertSame(1, $preview['conflict']);
        $this->assertNotEmpty($preview['errors']);
        $this->assertTrue((bool) array_filter(
            $preview['errors'],
            static fn (array $error): bool => str_contains($error['message'], 'exatamente quatro colunas')
        ));
        $this->assertSame(1, $model->paginate(['q' => 'Existente', 'ativo' => '1'])['total']);
    }

    public function testInvalidHeaderAndUtf8AreRejectedWithoutPreviewToken(): void
    {
        $actor = $this->insertUsuario('Admin CSV Inválido');
        $service = new \PassivoCsvService();
        $this->assertFalse($service->previewTrustedFile($this->csv("Nome,Data,Numero,Caixa\nA,2000-01-01,1,A\n"), $actor, new \Passivo()));
        $this->assertSame('invalid_header', $service->lastErrorCode());
        $this->assertFalse($service->previewTrustedFile($this->csv("Nome;Nascimento;Numero;Caixa\nA;2000-01-01;1;A\n"), $actor, new \Passivo()));
        $this->assertSame('invalid_header', $service->lastErrorCode());
        $this->assertFalse($service->previewTrustedFile($this->csv("Nome;Data;Numero;Caixa\nNome\xFF;2000-01-01;1;A\n"), $actor, new \Passivo()));
        $this->assertSame('invalid_utf8', $service->lastErrorCode());
    }

    public function testFileSizeRowCountAndMimeLimitsAreRejected(): void
    {
        $actor = $this->insertUsuario('Admin CSV Limites');
        $service = new \PassivoCsvService();
        $model = new \Passivo();

        $oversized = $this->csv(str_repeat('A', \PassivoCsvService::MAX_FILE_SIZE + 1));
        $this->assertFalse($service->previewTrustedFile($oversized, $actor, $model));
        $this->assertSame('file_too_large', $service->lastErrorCode());

        $tooManyRows = $this->csv(
            "Nome;Data;Numero;Caixa\n"
            . str_repeat("Pessoa;2000-01-01;;CX-LIMITE\n", \PassivoCsvService::MAX_ROWS + 1)
        );
        $this->assertFalse($service->previewTrustedFile($tooManyRows, $actor, $model));
        $this->assertSame('too_many_rows', $service->lastErrorCode());

        $invalidMime = $this->csv("\x89PNG\r\n\x1A\n" . str_repeat("\0", 64));
        $this->assertFalse($service->previewTrustedFile($invalidMime, $actor, $model));
        $this->assertSame('invalid_mime', $service->lastErrorCode());
    }

    public function testPreviewDisplaysAtMostFiftyErrors(): void
    {
        $actor = $this->insertUsuario('Admin CSV Erros');
        $service = new \PassivoCsvService();
        $rows = '';

        for ($index = 1; $index <= 60; $index++) {
            $rows .= "Pessoa {$index};31/02/2020;{$index};ERROS\n";
        }

        $preview = $service->previewTrustedFile(
            $this->csv("Nome;Data;Numero;Caixa\n" . $rows),
            $actor,
            new \Passivo()
        );

        $this->assertIsArray($preview);
        $this->assertSame(60, $preview['invalid']);
        $this->assertCount(\PassivoCsvService::MAX_ERRORS, $preview['errors']);
    }

    public function testExpiredCrossUserAndInvalidTokensAreRejectedAndCleaned(): void
    {
        $actor = $this->insertUsuario('Admin CSV Token');
        $otherActor = $this->insertUsuario('Outro Admin CSV');
        $service = new \PassivoCsvService();
        $model = new \Passivo();

        $expired = $service->previewTrustedFile(
            $this->csv("Nome;Data;Numero;Caixa\nExpirado;2000-01-01;1;TOKEN\n"),
            $actor,
            $model
        );
        $this->assertIsArray($expired);
        $expiredPath = (string) $_SESSION['passivo_import_previews'][$expired['token']]['path'];
        $_SESSION['passivo_import_previews'][$expired['token']]['expires_at'] = time() - 1;

        $this->assertFalse($service->confirm($expired['token'], $actor, $model));
        $this->assertSame('invalid_token', $service->lastErrorCode());
        $this->assertArrayNotHasKey($expired['token'], $_SESSION['passivo_import_previews'] ?? []);
        $this->assertFileDoesNotExist($expiredPath);

        $crossUser = $service->previewTrustedFile(
            $this->csv("Nome;Data;Numero;Caixa\nOutro usuario;2000-01-01;2;TOKEN\n"),
            $actor,
            $model
        );
        $this->assertIsArray($crossUser);
        $crossUserPath = (string) $_SESSION['passivo_import_previews'][$crossUser['token']]['path'];

        $this->assertFalse($service->confirm($crossUser['token'], $otherActor, $model));
        $this->assertSame('invalid_token', $service->lastErrorCode());
        $this->assertArrayNotHasKey($crossUser['token'], $_SESSION['passivo_import_previews'] ?? []);
        $this->assertFileDoesNotExist($crossUserPath);

        $this->assertFalse($service->confirm('token-invalido', $actor, $model));
        $this->assertSame('invalid_token', $service->lastErrorCode());
        $this->assertSame(0, $model->paginate(['ativo' => '1'])['total']);
    }

    public function testChangedPreviewFileIsRejectedAndTemporaryFileIsRemoved(): void
    {
        $actor = $this->insertUsuario('Admin CSV Arquivo Alterado');
        $service = new \PassivoCsvService();
        $model = new \Passivo();
        $preview = $service->previewTrustedFile(
            $this->csv("Nome;Data;Numero;Caixa\nOriginal;2000-01-01;1;ALTERADO\n"),
            $actor,
            $model
        );

        $this->assertIsArray($preview);
        $storedPath = (string) $_SESSION['passivo_import_previews'][$preview['token']]['path'];
        file_put_contents($storedPath, "Injetado;2001-01-01;2;ALTERADO\n", FILE_APPEND);

        $this->assertFalse($service->confirm($preview['token'], $actor, $model));
        $this->assertSame('preview_changed', $service->lastErrorCode());
        $this->assertArrayNotHasKey($preview['token'], $_SESSION['passivo_import_previews'] ?? []);
        $this->assertFileDoesNotExist($storedPath);
        $this->assertSame(0, $model->paginate(['ativo' => '1'])['total']);
    }

    public function testTokenCannotBeUsedFromAnotherSessionEvenByTheSameUser(): void
    {
        $actor = $this->insertUsuario('Admin CSV Sessão');
        $service = new \PassivoCsvService();
        $model = new \Passivo();
        $preview = $service->previewTrustedFile(
            $this->csv("Nome;Data;Numero;Caixa\nSessao original;2000-01-01;1;SESSAO\n"),
            $actor,
            $model
        );

        $this->assertIsArray($preview);
        $ownerSession = $_SESSION;
        $storedPath = (string) $ownerSession['passivo_import_previews'][$preview['token']]['path'];
        $_SESSION = [];

        $this->assertFalse($service->confirm($preview['token'], $actor, $model));
        $this->assertSame('invalid_token', $service->lastErrorCode());
        $this->assertFileExists($storedPath);

        $_SESSION = $ownerSession;
        $this->assertSame(1, $service->confirm($preview['token'], $actor, $model));
        $this->assertFileDoesNotExist($storedPath);
        $this->assertSame(1, $model->paginate(['caixa' => 'sessao', 'ativo' => '1'])['total']);
    }

    public function testDatabaseChangesAfterPreviewRejectTheWholeImport(): void
    {
        $actor = $this->insertUsuario('Admin CSV Banco Alterado');
        $service = new \PassivoCsvService();
        $model = new \Passivo();
        $preview = $service->previewTrustedFile(
            $this->csv("Nome;Data;Numero;Caixa\nMudou;2000-01-01;1;BANCO\nContinua válida;2001-01-01;2;BANCO\n"),
            $actor,
            $model
        );

        $this->assertIsArray($preview);
        $storedPath = (string) $_SESSION['passivo_import_previews'][$preview['token']]['path'];
        $this->assertIsInt($model->cadastrar([
            'nome_completo' => 'Mudou',
            'data_nascimento' => '2000-01-01',
            'numero' => '9',
            'caixa' => 'OUTRA',
        ], $actor));

        $this->assertFalse($service->confirm($preview['token'], $actor, $model));
        $this->assertSame('duplicate_changed', $service->lastErrorCode());
        $this->assertFileDoesNotExist($storedPath);
        $this->assertSame(1, $model->paginate(['ativo' => '1'])['total']);
        $this->assertSame(0, $model->paginate(['q' => 'Continua válida', 'ativo' => '1'])['total']);
    }

    public function testInsertionFailureRollsBackTheEntireBatch(): void
    {
        $actor = $this->insertUsuario('Admin CSV Rollback');
        $service = new \PassivoCsvService();
        $model = new \Passivo();
        $preview = $service->previewTrustedFile(
            $this->csv("Nome;Data;Numero;Caixa\nPrimeiro;2000-01-01;1;ROLLBACK\nFalha Lote;2001-01-01;2;ROLLBACK\n"),
            $actor,
            $model
        );

        $this->assertIsArray($preview);
        $storedPath = (string) $_SESSION['passivo_import_previews'][$preview['token']]['path'];
        $this->pdo->exec(
            "CREATE TRIGGER fail_passive_batch BEFORE INSERT ON alunos_passivo
             WHEN NEW.nome_completo = 'Falha Lote'
             BEGIN SELECT RAISE(ABORT, 'forced_passive_batch_failure'); END"
        );

        $this->assertFalse($service->confirm($preview['token'], $actor, $model));
        $this->assertSame('database_error', $service->lastErrorCode());
        $this->assertFileDoesNotExist($storedPath);
        $this->assertArrayNotHasKey($preview['token'], $_SESSION['passivo_import_previews'] ?? []);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos_passivo')->fetchColumn());
    }

    public function testRequiredAuditFailureRollsBackTheEntireImport(): void
    {
        $actor = $this->insertUsuario('Admin CSV Auditoria');
        $service = new \PassivoCsvService();
        $model = new \Passivo();
        $preview = $service->previewTrustedFile(
            $this->csv("Nome;Data;Numero;Caixa\nAuditoria Um;2000-01-01;1;AUDIT\nAuditoria Dois;2001-01-01;2;AUDIT\n"),
            $actor,
            $model
        );

        $this->assertIsArray($preview);
        $storedPath = (string) $_SESSION['passivo_import_previews'][$preview['token']]['path'];
        $this->pdo->exec(
            "CREATE TRIGGER fail_import_audit BEFORE INSERT ON security_audit
             WHEN NEW.action = 'passive.import_completed'
             BEGIN SELECT RAISE(ABORT, 'forced_import_audit_failure'); END"
        );

        $this->assertFalse($service->confirm($preview['token'], $actor, $model));
        $this->assertSame('database_error', $service->lastErrorCode());
        $this->assertFileDoesNotExist($storedPath);
        $this->assertArrayNotHasKey($preview['token'], $_SESSION['passivo_import_previews'] ?? []);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos_passivo')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action = 'passive.import_completed'")->fetchColumn());
    }

    private function csv(string $content): string
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'passivo_csv_' . bin2hex(random_bytes(8)) . '.csv';
        file_put_contents($file, $content);
        $this->files[] = $file;

        return $file;
    }
}
