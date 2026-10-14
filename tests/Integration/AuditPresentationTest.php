<?php

declare(strict_types=1);

namespace Tests\Integration;

use DOMDocument;
use DOMXPath;
use Tests\Support\DatabaseTestCase;

final class AuditPresentationTest extends DatabaseTestCase
{
    /** @var array<string,mixed> */
    private array $previousSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['APP_TIMEZONE' => 'America/Campo_Grande', 'AUDIT_RETENTION_DAYS' => '365'] as $key => $value) {
            $this->previousSettings[$key] = $_ENV[$key] ?? null;
            $_ENV[$key] = $value;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousSettings as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        parent::tearDown();
    }

    public function testNamesAreJoinedWithoutChangingStoredAuditEvidenceOrHidingMissingUsers(): void
    {
        $actorId = $this->insertUsuario('Maria Administradora');
        $targetId = $this->insertUsuario('João Afetado', 'funcionario');
        $this->insertEvent([
            'action' => 'user.role_updated',
            'actor_user_id' => $actorId,
            'target_user_id' => $targetId,
            'resource_type' => 'user',
            'resource_id' => $targetId,
            'description' => 'Perfil anterior: funcionario; novo: administrador.',
        ]);
        $this->insertOrphanEvent(['actor_user_id' => 900001, 'target_user_id' => 900002]);
        $this->insertEvent();
        $before = $this->storedEvents();

        $model = new \Auditoria();
        $result = $model->paginate([], 1, 10);

        $this->assertSame(3, $result['total']);
        foreach ($result['items'] as $item) {
            $stored = $before[(int) $item['id']];
            foreach ($stored as $field => $value) {
                $this->assertSame($value, $item[$field], 'Audit evidence changed: ' . $field);
            }
            $this->assertSame(
                $item['actor_user_id'] === $actorId ? 'Maria Administradora' : null,
                $item['actor_name']
            );
            $this->assertSame(
                $item['target_user_id'] === $targetId ? 'João Afetado' : null,
                $item['target_name']
            );
        }
        $latest = $model->latest();
        $this->assertCount(3, $latest);
        $this->assertSame([null, null, 'Maria Administradora'], array_column($latest, 'actor_name'));
        $this->assertSame([null, null, 'João Afetado'], array_column($latest, 'target_name'));
        foreach ($latest as $item) {
            foreach ($before[(int) $item['id']] as $field => $value) {
                $this->assertSame($value, $item[$field], 'Audit evidence changed: ' . $field);
            }
        }
        $this->assertSame($before, $this->storedEvents());
    }

    public function testProtectedUserNamesAreReadableWithoutChangingEncryptedIdentitiesOrAuditEvidence(): void
    {
        $actorId = $this->insertUsuario('Administradora Protegida');
        $targetId = $this->insertUsuario('Pessoa Afetada', 'funcionario');
        $resourceId = $this->insertUsuario('Usuario do Recurso', 'funcionario');
        $usersBefore = $this->pdo->query('SELECT id, nome, email FROM usuarios ORDER BY id')->fetchAll();
        foreach ($usersBefore as $user) {
            $this->assertStringStartsWith('gse1:', $user['nome']);
            $this->assertStringStartsWith('gse1:', $user['email']);
        }
        $this->insertEvent([
            'action' => 'user.role_updated',
            'actor_user_id' => $actorId,
            'target_user_id' => $targetId,
            'resource_type' => 'user',
            'resource_id' => $resourceId,
        ]);
        $evidenceBefore = $this->storedEvents();
        $model = new \Auditoria();

        $latest = $model->latest();
        $paginated = $model->paginate(['resource_type' => 'user']);

        $this->assertCount(1, $latest);
        $this->assertSame(1, $paginated['total']);
        $this->assertCount(1, $paginated['items']);
        foreach ([$latest[0], $paginated['items'][0]] as $item) {
            $this->assertSame($actorId, $item['actor_user_id']);
            $this->assertSame($targetId, $item['target_user_id']);
            $this->assertSame($resourceId, $item['resource_id']);
            $this->assertSame('Administradora Protegida', $item['actor_name']);
            $this->assertSame('Pessoa Afetada', $item['target_name']);
            foreach ($evidenceBefore[(int) $item['id']] as $field => $value) {
                $this->assertSame($value, $item[$field], 'Audit evidence changed: ' . $field);
            }
        }
        $this->assertSame('Usuario do Recurso', $latest[0]['resource_name']);
        $this->assertSame($usersBefore, $this->pdo->query('SELECT id, nome, email FROM usuarios ORDER BY id')->fetchAll());
        $this->assertSame($evidenceBefore, $this->storedEvents());
    }

    public function testCanonicalCodeFiltersAndPaginationStillWorkWithUserJoins(): void
    {
        $actorId = $this->insertUsuario('Responsável por alunos');
        for ($index = 0; $index < 12; $index++) {
            $this->insertEvent(['actor_user_id' => $actorId, 'resource_id' => $index + 1]);
        }
        $this->insertEvent(['action' => 'student.updated']);
        $this->insertEvent(['result' => 'failure']);
        $this->insertEvent(['resource_type' => 'class']);
        $this->insertEvent(['occurred_at' => '2026-10-13 23:59:59']);
        $filters = [
            'action' => 'student.created', 'result' => 'success', 'resource_type' => 'student',
            'from' => '2026-10-14', 'to' => '2026-10-14',
        ];

        $result = (new \Auditoria())->paginate($filters, 2, 10);

        $this->assertSame(12, $result['total']);
        $this->assertSame(2, $result['page']);
        $this->assertSame(2, $result['pages']);
        $this->assertCount(2, $result['items']);
        $this->assertSame([2, 1], array_column($result['items'], 'resource_id'));
        $this->assertSame(['Responsável por alunos', 'Responsável por alunos'], array_column($result['items'], 'actor_name'));
    }

    public function testFilterOptionsIncludePortugueseCatalogAndHistoricalUnknownCodes(): void
    {
        $this->insertEvent(['action' => 'legacy.imported', 'resource_type' => 'legacy_document']);
        $this->insertEvent(['action' => 'legacy.imported', 'resource_type' => 'legacy_document']);
        $this->insertEvent(['resource_type' => null, 'resource_id' => null]);

        $options = (new \Auditoria())->filterOptions();

        $this->assertSame('Cadastrar Aluno', $options['actions']['student.created']);
        $this->assertSame('Aluno', $options['resources']['student']);
        $this->assertStringContainsString('Evento não identificado', $options['actions']['legacy.imported']);
        $this->assertStringContainsString('legacy.imported', $options['actions']['legacy.imported']);
        $this->assertStringContainsString('Registro', $options['resources']['legacy_document']);
        $this->assertStringContainsString('legacy_document', $options['resources']['legacy_document']);
        $this->assertArrayNotHasKey('', $options['resources']);
    }

    public function testViewShowsFourReadableColumnsWithSafeEscapingAndPreservesStoredEvidence(): void
    {
        $actorName = 'Maria <script>alert("nome")</script>';
        $targetName = 'João & <img src=x onerror=alert(1)>';
        $actorId = $this->insertUsuario($actorName);
        $targetId = $this->insertUsuario($targetName, 'funcionario');
        $description = 'Conferência "final" & <script>alert("descrição")</script>.';
        $this->insertEvent([
            'actor_user_id' => $actorId, 'target_user_id' => $targetId,
            'description' => $description,
        ]);
        $this->insertEvent([
            'action' => 'contrato.item_created', 'resource_type' => 'produto',
            'description' => 'Módulo 5: item_created',
        ]);
        $before = $this->storedEvents();
        $model = new \Auditoria();
        $html = $this->render($model->latest());
        $xpath = $this->xpath($html);
        $tableText = $xpath->evaluate('string(//table)');

        $this->assertSame(['Data/Hora', 'Usuário', 'Ação', 'Detalhes'], array_map(
            static fn (\DOMNode $node): string => trim($node->textContent),
            iterator_to_array($xpath->query('//table/thead/tr/th'))
        ));
        $this->assertStringContainsString('Cadastrar Aluno', $tableText);
        $this->assertStringContainsString('13/10/2026 22:03:04', $tableText);
        $this->assertStringContainsString($actorName, $tableText);
        $this->assertStringContainsString($description, $tableText);
        $this->assertStringContainsString('Contrato - Cadastrar Produto', $tableText);
        $this->assertSame(0, $xpath->query('//tbody//script | //tbody//img | //tbody//*[@onerror]')->length);
        $this->assertSame(0, $xpath->query('//tbody//details | //tbody//code')->length);
        foreach (['student.created', 'contrato.item_created', 'Módulo 5: item_created', '203.0.113.17', '0123456789abcdef0123456789abcdef'] as $value) {
            $this->assertStringNotContainsString($value, $tableText);
        }
        $this->assertSame(2, $xpath->query('//tbody[@id="corpoLogs"]/tr[contains(concat(" ", normalize-space(@class), " "), " item-filtrado ")]')->length);
        foreach (['col-data', 'col-user', 'col-acao', 'col-detalhe'] as $class) {
            $this->assertSame(2, $xpath->query('//tbody/tr/td[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]')->length);
        }
        $this->assertSame($before, $this->storedEvents());
    }

    public function testViewKeepsUnknownEventsAndDistinguishesSystemAndMissingUsers(): void
    {
        $this->insertEvent([
            'action' => 'legacy.custom', 'resource_type' => 'legacy_record',
            'description' => 'Detalhe legado preservado.',
        ]);
        $this->insertOrphanEvent([
            'action' => 'login.invalid', 'result' => 'failure', 'resource_type' => null,
            'resource_id' => null, 'actor_user_id' => 900001, 'target_user_id' => 900002,
        ]);
        $this->insertEvent(['action' => 'login.blocked', 'result' => 'blocked']);
        $model = new \Auditoria();
        $xpath = $this->xpath($this->render($model->latest()));
        $tableText = $xpath->evaluate('string(//table)');

        foreach (['Evento não identificado', 'Detalhe legado preservado.', 'Sistema', 'Usuário #900001', 'Falhou', 'Bloqueado', 'legacy.custom'] as $value) {
            $this->assertStringContainsString($value, $tableText);
        }
        $this->assertSame(3, $xpath->query('//tbody/tr[contains(concat(" ", normalize-space(@class), " "), " item-filtrado ")]')->length);
    }

    public function testViewShowsBackupDetailsWithoutChangingTheirStoredDescriptions(): void
    {
        $filename = 'backup_20261014_020304.sqlite';
        $this->insertEvent([
            'action' => 'backup.created', 'resource_type' => 'backup', 'resource_id' => null,
            'description' => 'Snapshot SQLite criado: ' . $filename,
        ]);
        $this->insertEvent([
            'action' => 'backup.downloaded', 'resource_type' => 'backup', 'resource_id' => null,
            'description' => 'Download de snapshot SQLite autorizado: ' . $filename,
        ]);
        $before = $this->storedEvents();

        $xpath = $this->xpath($this->render((new \Auditoria())->latest()));
        $details = $xpath->evaluate('string(//tbody[@id="corpoLogs"])');

        $this->assertSame(2, $xpath->query('//tbody/tr/td[contains(concat(" ", normalize-space(@class), " "), " col-acao ") and normalize-space(.)="Sistema"]')->length);
        $this->assertStringContainsString('Criou backup manual via painel.', $details);
        $this->assertStringContainsString('Download do backup: ' . $filename, $details);
        $this->assertStringNotContainsString('Snapshot SQLite criado:', $details);
        $this->assertStringNotContainsString('Download de snapshot SQLite autorizado:', $details);
        $this->assertSame($before, $this->storedEvents());
    }

    public function testViewProvidesSearchAndProtectedCleanupFormWithEmptyState(): void
    {
        $xpath = $this->xpath($this->render([]));

        $this->assertSame('search', $xpath->evaluate('string(//input[@id="filtroPainel"]/@type)'));
        $this->assertSame('Digite usuário, ação ou detalhe...', $xpath->evaluate('string(//input[@id="filtroPainel"]/@placeholder)'));
        $form = '//form[@method="POST" or @method="post"]';
        $this->assertSame(url('auditoria'), $xpath->evaluate('string(' . $form . '/@action)'));
        $this->assertSame('limpar', $xpath->evaluate('string(' . $form . '//input[@name="acao"]/@value)'));
        $this->assertNotSame('', $xpath->evaluate('string(' . $form . '//input[@name="_csrf_token"]/@value)'));
        $this->assertNotSame('', $xpath->evaluate('string(' . $form . '/@data-confirm-status)'));
        $this->assertStringContainsString('Limpar Antigos (> 1 ano)', $xpath->evaluate('string(' . $form . '//button)'));
        $this->assertSame('4', $xpath->evaluate('string(//tbody[@id="corpoLogs"]/tr/td/@colspan)'));
        $this->assertStringContainsString('Nenhum', $xpath->evaluate('string(//tbody)'));

        $configured = $this->xpath($this->render([], 730));
        $this->assertStringContainsString('Limpar Antigos (> 730 dias)', $configured->evaluate('string(' . $form . '//button)'));
        $this->assertStringContainsString('730 dias', $configured->evaluate('string(' . $form . '/@data-confirm-status)'));
    }

    public function testLatestBoundsResultCountAndUsesTimestampThenIdForOrder(): void
    {
        $this->pdo->beginTransaction();
        for ($index = 0; $index < 501; $index++) {
            $this->insertEvent();
        }
        $this->insertEvent(['occurred_at' => '2026-10-15 02:03:04']);
        $newestId = (int) $this->pdo->lastInsertId();
        $this->insertEvent(['occurred_at' => '2026-10-13 02:03:04']);
        $this->pdo->commit();
        $model = new \Auditoria();
        $expected = array_merge([$newestId], range(501, 3));

        $this->assertSame($expected, array_column($model->latest(), 'id'));
        $this->assertSame($expected, array_column($model->latest(1000), 'id'));
        $this->assertSame([$newestId, 501], array_column($model->latest(2), 'id'));
        $this->assertSame([$newestId], array_column($model->latest(0), 'id'));
    }

    public function testLatestAddsResourceNamesWithoutDroppingMissingResources(): void
    {
        $studentId = $this->insertAlunoComDva('Aluno Localizado', '2027-01-01');
        $classId = $this->insertTurma('Turma Localizada');
        $this->insertEvent(['resource_type' => 'student', 'resource_id' => $studentId]);
        $this->insertEvent(['action' => 'class.created', 'resource_type' => 'class', 'resource_id' => $classId]);
        $this->insertEvent(['resource_type' => 'student', 'resource_id' => 900001]);
        $before = $this->storedEvents();

        $items = (new \Auditoria())->latest();

        $this->assertCount(3, $items);
        $this->assertNull($items[0]['resource_name']);
        $this->assertSame('Turma Localizada', $items[1]['resource_name']);
        $this->assertSame('Aluno Localizado', $items[2]['resource_name']);
        $this->assertSame($before, $this->storedEvents());
    }

    public function testClearOldKeepsRecentAndBoundaryDayRecordsAndAuditsCleanup(): void
    {
        $actorId = $this->insertUsuario('Administradora da limpeza');
        $now = (new \DateTimeImmutable('2026-10-14 12:34:56', new \DateTimeZone('UTC')))->getTimestamp();
        $this->insertEvent(['occurred_at' => '2025-10-13 23:59:59']);
        $this->insertEvent(['occurred_at' => '2025-10-14 00:00:00']);
        $this->insertEvent(['occurred_at' => '2025-10-14 12:34:55']);
        $this->insertEvent();

        $this->assertSame(1, (new \Auditoria())->clearOld($actorId, $now));

        $remaining = $this->storedEvents();
        $this->assertCount(4, $remaining);
        $this->assertSame([2, 3, 4, 5], array_keys($remaining));
        $cleanup = $remaining[5];
        $this->assertSame('audit.cleaned', $cleanup['action']);
        $this->assertSame('success', $cleanup['result']);
        $this->assertSame($actorId, $cleanup['actor_user_id']);
        $this->assertSame('audit', $cleanup['resource_type']);
        $this->assertNull($cleanup['target_user_id']);
        $this->assertNull($cleanup['resource_id']);
        $this->assertStringContainsString('365 dias', $cleanup['description']);
    }

    public function testLatestProvidesContractSheetAndProductContextWithoutChangingEvidence(): void
    {
        $this->pdo->exec("INSERT INTO pedidos (titulo) VALUES ('Materiais escolares')");
        $contractId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO pedido_paginas (id_pedido, numero_pagina) VALUES (?, 3)')->execute([$contractId]);
        $sheetId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO pedido_produtos (id_pedido, numero_pagina, nome_produto, unidade, quantidade, valor_unitario, valor_total) VALUES (?, 3, 'Papel A4', 'pacote', 1, 10, 10)")->execute([$contractId]);
        $productId = (int) $this->pdo->lastInsertId();
        $this->insertEvent(['action' => 'contrato.item_created', 'resource_type' => 'produto', 'resource_id' => $productId]);
        $this->insertEvent(['action' => 'contrato.sheet_created', 'resource_type' => 'folha', 'resource_id' => $sheetId]);
        $this->insertEvent(['action' => 'contrato.created', 'resource_type' => 'contrato', 'resource_id' => $contractId]);
        $before = $this->storedEvents();

        $items = (new \Auditoria())->latest();

        $this->assertCount(3, $items);
        $this->assertSame('Materiais escolares', $items[0]['resource_name']);
        $this->assertSame($contractId, $items[1]['resource_parent_id']);
        $this->assertSame(3, $items[1]['resource_number']);
        $this->assertSame('Papel A4', $items[2]['resource_name']);
        $this->assertSame($contractId, $items[2]['resource_parent_id']);
        $this->assertSame(3, $items[2]['resource_number']);
        $this->assertSame($before, $this->storedEvents());
    }

    public function testSheetCreationAndDuplicationUseContractRecordedByRealOperations(): void
    {
        $actorId = $this->insertUsuario('Administradora de folhas');
        $title = 'Materiais de sala';
        $contract = new \Contrato();
        $contractId = $contract->create($title, '100,00', '', $actorId);
        $sheetId = $contract->addSheet($contractId, 'Conferir materiais', 1, $actorId);
        $contract->addSheet($contractId, '', 2, $actorId, $sheetId, str_repeat('b', 32));
        $before = $this->storedEvents();
        $items = array_column((new \Auditoria())->latest(), null, 'action');

        foreach ([
            'contrato.sheet_created' => 'Adicionou nova folha ao contrato #' . $contractId . '.',
            'contrato.sheet_duplicated' => 'Duplicou folha no contrato #' . $contractId . '.',
        ] as $action => $details) {
            $this->assertSame('contrato', $items[$action]['resource_type']);
            $this->assertSame($contractId, $items[$action]['resource_id']);
            $this->assertSame($title, $items[$action]['resource_name']);
            $entry = \AuditPresentation::logEntry($items[$action]);
            $this->assertSame($details, $entry['details']);
            $this->assertStringNotContainsString($title, $entry['details']);
        }
        $this->assertSame($before, $this->storedEvents());
    }

    public function testClearOldNeverShortensOneYearRetentionAndHonorsLongerConfiguration(): void
    {
        $actorId = $this->insertUsuario('Administradora da retenção');
        $now = (new \DateTimeImmutable('2026-10-14 12:34:56', new \DateTimeZone('UTC')))->getTimestamp();
        $_ENV['AUDIT_RETENTION_DAYS'] = '90';
        $this->insertEvent(['occurred_at' => '2025-10-13 23:59:59']);
        $this->insertEvent(['occurred_at' => '2026-01-01 00:00:00']);
        $model = new \Auditoria();
        $this->assertSame(1, $model->clearOld($actorId, $now));
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE occurred_at = '2026-01-01 00:00:00'")->fetchColumn());

        $_ENV['AUDIT_RETENTION_DAYS'] = '730';
        $this->insertEvent(['occurred_at' => '2024-10-13 23:59:59']);
        $this->insertEvent(['occurred_at' => '2024-10-14 00:00:00']);
        $this->insertEvent(['occurred_at' => '2025-01-01 00:00:00']);
        $this->assertSame(1, $model->clearOld($actorId, $now));
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE occurred_at = '2024-10-14 00:00:00'")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE occurred_at = '2025-01-01 00:00:00'")->fetchColumn());
        $this->assertStringContainsString('730 dias', (string) $this->pdo->query('SELECT description FROM security_audit ORDER BY id DESC LIMIT 1')->fetchColumn());
    }

    public function testClearOldRechecksActiveAdministratorAndRejectsOtherActorsWithoutMutation(): void
    {
        $employeeId = $this->insertUsuario('Funcionário sem acesso', 'funcionario');
        $inactiveId = $this->insertUsuario('Administrador inativo', 'administrador', false);
        $this->insertUsuario('Administrador de reserva');
        $changedId = $this->insertUsuario('Administrador alterado');
        $_SESSION['usuario_id'] = $changedId;
        $_SESSION['usuario_tipo'] = 'administrador';
        $this->pdo->prepare('UPDATE usuarios SET tipo = ? WHERE id = ?')->execute(['funcionario', $changedId]);
        $this->insertEvent(['occurred_at' => '2020-01-01 00:00:00']);
        $before = $this->storedEvents();
        $model = new \Auditoria();

        foreach ([$employeeId, $inactiveId, $changedId, 900001] as $actorId) {
            try {
                $model->clearOld($actorId);
                $this->fail('A limpeza exige um administrador ativo.');
            } catch (\DomainException $exception) {
                $this->assertStringContainsString('administradores ativos', $exception->getMessage());
            }
            $this->assertSame($before, $this->storedEvents());
        }
    }

    public function testClearOldRejectsStaleSessionVersionAndAcceptsCurrentVersion(): void
    {
        $actorId = $this->insertUsuario('Administradora com sessão renovada');
        $now = (new \DateTimeImmutable('2026-10-14 12:34:56', new \DateTimeZone('UTC')))->getTimestamp();
        $this->insertEvent(['occurred_at' => '2020-01-01 00:00:00']);
        $this->insertEvent();
        $staleVersion = (int) $this->pdo->query('SELECT session_version FROM usuarios WHERE id = ' . $actorId)->fetchColumn();
        $this->pdo->prepare('UPDATE usuarios SET session_version = session_version + 1 WHERE id = ?')->execute([$actorId]);
        $before = $this->storedEvents();
        $model = new \Auditoria();

        try {
            $model->clearOld($actorId, $now, $staleVersion);
            $this->fail('Uma sessão invalidada não pode limpar os logs.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('administradores ativos', $exception->getMessage());
        }
        $this->assertSame($before, $this->storedEvents());

        $currentVersion = (int) $this->pdo->query('SELECT session_version FROM usuarios WHERE id = ' . $actorId)->fetchColumn();
        $this->assertSame(1, $model->clearOld($actorId, $now, $currentVersion));
        $this->assertSame('audit.cleaned', $this->pdo->query('SELECT action FROM security_audit ORDER BY id DESC LIMIT 1')->fetchColumn());
    }

    public function testClearOldRollsBackDeletionWhenItsAuditCannotBeRecorded(): void
    {
        $actorId = $this->insertUsuario('Administradora da transação');
        $now = (new \DateTimeImmutable('2026-10-14 12:34:56', new \DateTimeZone('UTC')))->getTimestamp();
        $this->insertEvent(['occurred_at' => '2020-01-01 00:00:00']);
        $this->insertEvent();
        $before = $this->storedEvents();
        $this->pdo->exec("CREATE TRIGGER reject_cleanup_audit BEFORE INSERT ON security_audit WHEN NEW.action = 'audit.cleaned' BEGIN SELECT RAISE(ABORT, 'auditoria indisponível'); END");

        try {
            (new \Auditoria())->clearOld($actorId, $now);
            $this->fail('A limpeza deve falhar quando seu registro de auditoria falha.');
        } catch (\PDOException $exception) {
            $this->assertStringContainsString('auditoria indisponível', $exception->getMessage());
        }

        $this->assertSame($before, $this->storedEvents());
        $this->pdo->exec('DROP TRIGGER reject_cleanup_audit');
        $this->assertSame(1, (new \Auditoria())->clearOld($actorId, $now));
    }

    /** @param array<string,mixed> $values */
    private function insertEvent(array $values = []): void
    {
        $values = array_replace([
            'occurred_at' => '2026-10-14 02:03:04', 'action' => 'student.created', 'result' => 'success',
            'actor_user_id' => null, 'target_user_id' => null, 'resource_type' => 'student', 'resource_id' => 71,
            'ip_address' => '203.0.113.17', 'request_id' => '0123456789abcdef0123456789abcdef',
            'description' => 'Aluno cadastrado.',
        ], $values);
        $columns = implode(', ', array_keys($values));
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $this->pdo->prepare("INSERT INTO security_audit ({$columns}) VALUES ({$placeholders})")
            ->execute(array_values($values));
    }

    /** @param array<string,mixed> $values */
    private function insertOrphanEvent(array $values): void
    {
        // Reproduz um histórico legado importado sem os usuários correspondentes.
        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        try {
            $this->insertEvent($values);
        } finally {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function storedEvents(): array
    {
        $rows = $this->pdo->query('SELECT * FROM security_audit ORDER BY id')->fetchAll();
        return array_column($rows, null, 'id');
    }

    /** @param list<array<string,mixed>> $logs */
    private function render(array $logs, int $retentionDays = 365): string
    {
        ob_start();
        try {
            require ROOT_PATH . '/src/Views/auditoria/index.php';
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return new DOMXPath($document);
    }
}
