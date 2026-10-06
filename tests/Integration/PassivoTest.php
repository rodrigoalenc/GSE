<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDOException;
use Tests\Support\DatabaseTestCase;

final class PassivoTest extends DatabaseTestCase
{
    public function testBoxListAndNavigationUseNaturalOrderForNumericAndAlphanumericLabels(): void
    {
        $actor = $this->insertUsuario('Admin Ordem Caixas');
        $model = new \Passivo();
        foreach (['CX-10', '20', 'A-10', '3', '10', 'CX-2', '1', 'A-2', '2', 'A-1'] as $box) {
            $this->assertIsInt($model->cadastrar([
                'nome_completo' => 'Registro ficticio caixa ' . $box,
                'caixa' => $box,
                'numero' => '1',
            ], $actor));
        }
        foreach (['30', '4'] as $box) {
            $id = $model->cadastrar(['nome_completo' => 'Registro inativo caixa ' . $box, 'caixa' => $box, 'numero' => '1'], $actor);
            $this->assertIsInt($id);
            $this->assertTrue($model->definirAtivo($id, false, $actor));
        }

        $expected = ['1', '2', '3', '10', '20', 'A-1', 'A-2', 'A-10', 'CX-2', 'CX-10'];
        $this->assertSame($expected, array_column($model->caixas(), 'caixa'));
        $this->assertSame(['4', '30'], array_column($model->caixas(false), 'caixa'));
        $this->assertSame(
            ['1', '2', '3', '4', '10', '20', '30', 'A-1', 'A-2', 'A-10', 'CX-2', 'CX-10'],
            array_column($model->caixas(null), 'caixa')
        );
        $this->assertSame([
            'anterior' => '1', 'proxima' => '3',
            'lista' => ['1', '2', '3', '10', '20', 'A-1', 'A-2'],
        ], $model->navegacaoCaixas('2'));
        $this->assertSame('CX-10', $model->navegacaoCaixas('cx-2')['proxima']);
        $this->assertSame('30', $model->navegacaoCaixas('4', false)['proxima']);
        $this->assertNull($model->navegacaoCaixas('1')['anterior']);
        $this->assertNull($model->navegacaoCaixas('CX-10')['proxima']);
        $this->assertSame($expected, array_column($model->paginate(['ordem' => 'caixa'])['items'], 'caixa'));
        $this->assertSame(['4', '30'], array_column($model->paginate(['ordem' => 'caixa', 'ativo' => '0'])['items'], 'caixa'));
    }

    public function testNaturalBoxOrderIsAppliedBeforePagination(): void
    {
        $actor = $this->insertUsuario('Admin Paginacao Caixas');
        $model = new \Passivo();
        foreach (range(25, 1) as $box) {
            $this->assertIsInt($model->cadastrar([
                'nome_completo' => 'Registro ficticio ' . (26 - $box),
                'caixa' => (string) $box,
                'numero' => '1',
            ], $actor));
        }

        $pages = [];
        foreach ([1 => range(1, 10), 2 => range(11, 20), 3 => range(21, 25)] as $number => $expected) {
            $page = $model->paginate(['ordem' => 'caixa'], $number, 10);
            $this->assertSame(25, $page['total']);
            $this->assertSame(3, $page['pages']);
            $this->assertSame($number, $page['page']);
            $this->assertSame(10, $page['per_page']);
            $this->assertSame(array_map('strval', $expected), array_column($page['items'], 'caixa'));
            $pages[] = $page['items'];
        }

        $all = array_merge(...$pages);
        $this->assertSame(array_map('strval', range(1, 25)), array_column($all, 'caixa'));
        $this->assertCount(25, array_unique(array_column($all, 'id')));
        $this->assertSame(array_column($model->caixas(), 'caixa'), array_column($all, 'caixa'));
        $this->assertSame(array_map('strval', range(21, 25)), array_column($model->paginate(['ordem' => 'caixa'], 99, 10)['items'], 'caixa'));
    }

    public function testNaturalBoxOrderPreservesLabelsLargerThanIntegerRange(): void
    {
        $actor = $this->insertUsuario('Admin Caixas Grandes');
        $model = new \Passivo();
        foreach (['100000000000000000000', '10', '2', '90000000000000000000', '1', '01', '00', '0'] as $box) {
            $this->assertIsInt($model->cadastrar(['nome_completo' => 'Registro ficticio ' . $box, 'caixa' => $box, 'numero' => '1'], $actor));
        }

        $expected = ['0', '00', '01', '1', '2', '10', '90000000000000000000', '100000000000000000000'];
        $this->assertSame($expected, array_column($model->caixas(), 'caixa'));
        $this->assertSame($expected, array_column($model->paginate(['ordem' => 'caixa'])['items'], 'caixa'));
        $this->assertSame('100000000000000000000', $model->navegacaoCaixas('90000000000000000000')['proxima']);
    }

    public function testLogicalDeletionScopesQueriesBoxesExportEnumerationAndConflicts(): void
    {
        $actor = $this->insertUsuario('Funcionario UC004', 'funcionario');
        $model = new \Passivo();
        $deleted = $model->cadastrar(['nome_completo' => 'Histórico', 'caixa' => 'A', 'numero' => '9'], $actor);
        $unnumbered = $this->legacyPending('Sem posição', 'B');
        $this->assertIsInt($deleted);
        $this->assertIsInt($unnumbered);
        $this->assertNull($model->buscarPorId($unnumbered)['numero']);
        $this->assertTrue($model->definirAtivo($deleted, false, $actor));
        $this->assertTrue($model->definirAtivo($unnumbered, false, $actor));
        $this->assertSame(0, $model->paginate([])['total']);
        $this->assertSame(2, $model->paginate(['ativo' => '0'])['total']);
        $this->assertSame(['caixas' => 0, 'registros' => 0, 'inativos' => 2, 'pendentes' => 0], $model->resumo());
        $this->assertSame([], $model->caixas());
        $this->assertSame(['A', 'B'], array_column($model->caixas(false), 'caixa'));
        $this->assertSame('B', $model->navegacaoCaixas('A', false)['proxima']);
        $this->assertFalse($model->listarParaTxt('A'));
        $this->assertSame('box_not_found', $model->lastErrorCode());
        $this->assertFalse($model->previewEnumeracao('B'));

        $replacement = $model->cadastrar(['nome_completo' => 'Atual', 'caixa' => 'A', 'numero' => '9'], $actor);
        $activeUnnumbered = $this->legacyPending('Numerar', 'B');
        $this->assertIsInt($replacement);
        $this->assertIsInt($activeUnnumbered);
        $this->assertSame([['numero' => '9', 'nome_completo' => 'Atual', 'localizacao_pendente' => 0]], $model->listarParaTxt('A'));
        $preview = $model->previewEnumeracao('B');
        $this->assertIsArray($preview);
        $this->assertSame([$activeUnnumbered], array_column($preview['assignments'], 'id'));
        $this->assertSame(1, $model->aplicarEnumeracao('B', $preview['assignments'], $actor));
        $this->assertNull($model->buscarPorId($unnumbered)['numero']);
        $this->assertTrue($model->atualizar($deleted, ['nome_completo' => 'Histórico corrigido', 'caixa' => 'A', 'numero' => '9'], $actor));
        $this->assertFalse($model->definirAtivo($deleted, true, $actor));
        $this->assertSame('location_conflict', $model->lastErrorCode());
        $this->assertSame(0, (int) $model->buscarPorId($deleted)['ativo']);
        $this->assertTrue($model->atualizar($deleted, ['nome_completo' => 'Histórico corrigido', 'caixa' => 'C', 'numero' => '9'], $actor));
        $this->assertTrue($model->definirAtivo($deleted, true, $actor));
        $this->assertSame(3, $model->resumo()['registros']);
        $this->assertSame(['A', 'B', 'C'], array_column($model->caixas(null), 'caixa'));
    }

    public function testAuditFailureRollsBackUpdateDeletionAndRestoration(): void
    {
        $actor = $this->insertUsuario('Funcionario Auditoria UC004', 'funcionario');
        $model = new \Passivo();
        $id = $model->cadastrar(['nome_completo' => 'Preservado', 'caixa' => 'A', 'numero' => '1'], $actor);
        $this->assertIsInt($id);
        foreach (['passive.updated', 'passive.deactivated', 'passive.reactivated'] as $action) {
            if ($action === 'passive.reactivated') {
                $this->assertTrue($model->definirAtivo($id, false, $actor));
            }
            $before = $model->buscarPorId($id);
            $auditCount = (int) $this->pdo->query('SELECT COUNT(*) FROM security_audit')->fetchColumn();
            $this->pdo->exec("CREATE TRIGGER fail_uc004 BEFORE INSERT ON security_audit WHEN NEW.action = '{$action}' BEGIN SELECT RAISE(ABORT, 'forced'); END");
            $result = $action === 'passive.updated'
                ? $model->atualizar($id, ['nome_completo' => 'Alterado', 'caixa' => 'B', 'numero' => '1'], $actor)
                : $model->definirAtivo($id, $action === 'passive.reactivated', $actor);
            $this->assertFalse($result);
            $this->assertSame($before, $model->buscarPorId($id));
            $this->assertSame($auditCount, (int) $this->pdo->query('SELECT COUNT(*) FROM security_audit')->fetchColumn());
            $this->pdo->exec('DROP TRIGGER fail_uc004');
        }
    }

    public function testCrudSearchFiltersPaginationAndLogicalLifecycle(): void
    {
        $actor = $this->insertUsuario('Admin Passivo');
        $model = new \Passivo();
        $id = $model->cadastrar([
            'nome_completo' => "Jos\u{00E9} da Silva",
            'data_nascimento' => '2000-01-01',
            'numero' => '7',
            'caixa' => 'CX-1',
        ], $actor);

        $this->assertIsInt($id);
        $this->assertSame(1, $model->paginate(['q' => 'Jose', 'ativo' => '1'])['total']);
        $this->assertSame(1, $model->paginate(['q' => "JOSE\u{0301}", 'ativo' => '1'])['total']);
        $this->assertSame(1, $model->paginate(['caixa' => 'cx-1', 'ativo' => '1'])['total']);
        $this->assertSame("Jos\u{00E9} da Silva", $model->buscarPorId($id)['nome_completo'] ?? null);

        $percentId = $model->cadastrar([
            'nome_completo' => 'Pessoa 100%', 'data_nascimento' => '', 'numero' => '1', 'caixa' => 'CX-2',
        ], $actor);
        $this->assertIsInt($percentId);
        $this->assertSame(1, $model->paginate(['q' => '%', 'ativo' => '1'])['total']);
        $this->assertSame(0, $model->paginate(['q' => '_', 'ativo' => '1'])['total']);

        $this->assertTrue($model->atualizar($id, [
            'nome_completo' => "Jos\u{00E9} Atualizado", 'data_nascimento' => '2000-01-01',
            'numero' => '8', 'caixa' => 'CX-1',
        ], $actor));
        $this->assertFalse($model->cadastrar([
            'nome_completo' => 'Conflito', 'data_nascimento' => '', 'numero' => '8', 'caixa' => 'cx-1',
        ], $actor));
        $this->assertSame('location_conflict', $model->lastErrorCode());

        $this->assertTrue($model->definirAtivo($id, false, $actor));
        $this->assertSame(0, (int) $model->buscarPorId($id)['ativo']);
        $this->assertTrue($model->definirAtivo($id, true, $actor));
        $this->assertSame(1, (int) $model->buscarPorId($id)['ativo']);
        $this->assertSame(2, $model->resumo()['registros']);

        $this->expectException(PDOException::class);
        $this->pdo->exec('DELETE FROM alunos_passivo WHERE id = ' . $id);
    }

    public function testArchiveInactiveStudentPreservesStudentAndDvaAndRejectsDuplicate(): void
    {
        $actor = $this->insertUsuario('Admin Integracao');
        $name = \src\Core\TextNormalizer::displayName('Aluno Inativo');
        $statement = $this->pdo->prepare(
            'INSERT INTO alunos (nome_completo, nome_normalizado, data_nascimento, ativo) VALUES (?, ?, ?, 0)'
        );
        $statement->execute([$name, \src\Core\TextNormalizer::comparisonKey($name), '2010-05-10']);
        $studentId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO dvas (id_aluno, data_vencimento) VALUES (?, ?)')
            ->execute([$studentId, '2027-01-01']);

        $model = new \Passivo();
        $passiveId = $model->arquivarAluno($studentId, ['caixa' => 'CX-9', 'numero' => '1'], $actor);

        $this->assertIsInt($passiveId);
        $this->assertSame($studentId, (int) $model->buscarPorId($passiveId)['aluno_origem_id']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos WHERE id = ' . $studentId)->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM dvas WHERE id_aluno = ' . $studentId)->fetchColumn());
        $this->assertFalse($model->arquivarAluno($studentId, ['caixa' => 'CX-10', 'numero' => '2'], $actor));
        $this->assertSame('origin_conflict', $model->lastErrorCode());

        $this->assertTrue($model->definirAtivo($passiveId, false, $actor));
        $replacement = $model->arquivarAluno($studentId, ['caixa' => 'CX-10', 'numero' => '2'], $actor);
        $this->assertIsInt($replacement);
        $this->assertFalse($model->definirAtivo($passiveId, true, $actor));
        $this->assertSame('origin_conflict', $model->lastErrorCode());
        $this->assertSame($studentId, (int) $model->buscarPorId($passiveId)['aluno_origem_id']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM dvas WHERE id_aluno = ' . $studentId)->fetchColumn());

        $this->pdo->exec('UPDATE alunos SET ativo = 1 WHERE id = ' . $studentId);
        $this->assertTrue($model->definirAtivo($passiveId, false, $actor));
        $this->assertFalse($model->arquivarAluno($studentId, ['caixa' => 'CX-10', 'numero' => '2'], $actor));
        $this->assertSame('active_student', $model->lastErrorCode());
    }

    public function testEnumerationUsesOnlySelectedBoxAndPreservesExistingNumbers(): void
    {
        $actor = $this->insertUsuario('Admin Enumeracao');
        $model = new \Passivo();
        $model->cadastrar(['nome_completo' => 'Zeta', 'data_nascimento' => '', 'numero' => '7', 'caixa' => 'A'], $actor);
        $ana = $this->legacyPending('Ana', 'A');
        $bia = $this->legacyPending('Bia', 'A');
        $other = $this->legacyPending('Outra', 'B');

        $preview = $model->previewEnumeracao('a');
        $this->assertIsArray($preview);
        $this->assertSame([$ana, $bia], array_column($preview['assignments'], 'id'));
        $this->assertSame(['8', '9'], array_column($preview['assignments'], 'numero'));
        $this->assertSame(2, $model->aplicarEnumeracao('A', $preview['assignments'], $actor));
        $this->assertSame('8', $model->buscarPorId($ana)['numero']);
        $this->assertSame('9', $model->buscarPorId($bia)['numero']);
        $this->assertNull($model->buscarPorId($other)['numero']);
        $this->assertCount(3, $model->listarParaTxt('A'));
    }

    public function testValidationRejectsFutureDateInvalidUtf8AndMalformedLocation(): void
    {
        $model = new \Passivo();

        $this->assertFalse($model->validarDados(['nome_completo' => '', 'caixa' => 'A']));
        $this->assertSame('invalid_name', $model->lastErrorCode());
        $this->assertFalse($model->validarDados(['nome_completo' => 'Nome', 'caixa' => '../A']));
        $this->assertSame('invalid_box', $model->lastErrorCode());
        $this->assertFalse($model->validarDados(['nome_completo' => 'Nome', 'caixa' => 'A', 'numero' => '1', 'data_nascimento' => '2999-01-01']));
        $this->assertSame('future_birth_date', $model->lastErrorCode());
        $this->assertFalse($model->validarDados(['nome_completo' => "Nome\xFF", 'caixa' => 'A']));
        $this->assertSame('invalid_utf8', $model->lastErrorCode());
    }

    public function testRequiredAuditFailureRollsBackSensitiveOperation(): void
    {
        $actor = $this->insertUsuario('Admin Auditoria Passivo');
        $this->pdo->exec(
            "CREATE TRIGGER fail_passive_audit BEFORE INSERT ON security_audit
             WHEN NEW.action = 'passive.created'
             BEGIN SELECT RAISE(ABORT, 'forced_passive_audit_failure'); END"
        );
        $model = new \Passivo();

        $this->assertFalse($model->cadastrar([
            'nome_completo' => 'Rollback Auditoria', 'data_nascimento' => '', 'numero' => '1', 'caixa' => 'AUDIT',
        ], $actor));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos_passivo')->fetchColumn());
    }

    public function testCompleteLocationIsRequiredAcrossCreationImportAndIndividualArchive(): void
    {
        $actor = $this->insertUsuario('Admin RF004');
        $model = new \Passivo();
        $student = $this->insertAlunoComDva('Aluno RF004', '2027-06-01');
        $this->pdo->exec('UPDATE alunos SET ativo = 0 WHERE id = ' . $student);
        foreach ([null, '', '  ', "\u{00A0}\u{2003}", '../1', str_repeat('1', 41)] as $number) {
            $data = ['nome_completo' => 'Novo RF004', 'caixa' => 'A', 'numero' => $number];
            $this->assertFalse($model->cadastrar($data, $actor));
            $this->assertSame('invalid_number', $model->lastErrorCode());
            $this->assertFalse($model->arquivarAluno($student, $data, $actor));
            $this->assertSame('invalid_number', $model->lastErrorCode());
            // Uma chamada direta ao modelo não pode confiar em normalização fornecida pelo cliente.
            $this->assertFalse($model->importarLote([$data + ['numero_normalizado' => '9', 'caixa_normalizada' => 'a']], $actor));
            $this->assertSame('invalid_number', $model->lastErrorCode());
        }
        $this->assertFalse($model->cadastrar(['nome_completo' => 'Sem caixa', 'caixa' => '', 'numero' => '1'], $actor));
        $this->assertSame('invalid_box', $model->lastErrorCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos_passivo')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM dvas WHERE id_aluno = ' . $student)->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM security_audit')->fetchColumn());
    }

    public function testHistoricalMissingFieldsRemainPendingUntilExplicitConflictFreeCompletion(): void
    {
        $actor = $this->insertUsuario('Funcionario RF004', 'funcionario');
        $model = new \Passivo();
        $emptyBox = $this->legacyPending('Sem caixa histórica', null, '3');
        $emptyNumber = $this->legacyPending('Sem posição histórica', 'B');
        foreach ([$emptyBox => ['caixa' => '', 'numero' => '3'], $emptyNumber => ['caixa' => 'B', 'numero' => '']] as $id => $location) {
            $before = $model->buscarPorId($id);
            $this->assertTrue($model->atualizar($id, ['nome_completo' => 'Dados históricos corrigidos ' . $id] + $location, $actor));
            $after = $model->buscarPorId($id);
            $this->assertSame($before['caixa'], $after['caixa']);
            $this->assertSame($before['numero'], $after['numero']);
            $this->assertSame(1, (int) $after['localizacao_pendente']);
            $this->assertSame($actor, (int) $after['atualizado_por']);
            $this->assertSame($before['criado_por'], $after['criado_por']);
        }
        $this->assertSame(2, $model->resumo()['pendentes']);
        $this->assertSame(2, $model->paginate(['localizacao' => 'pendente'])['total']);
        $this->assertSame(0, $model->paginate(['localizacao' => 'completa'])['total']);
        $occupied = $model->cadastrar(['nome_completo' => 'Pasta ocupante', 'caixa' => 'B', 'numero' => '3'], $actor);
        $this->assertIsInt($occupied);
        $before = $model->buscarPorId($emptyNumber);
        $this->assertFalse($model->atualizar($emptyNumber, ['nome_completo' => 'Completar conflito', 'caixa' => 'B', 'numero' => '3'], $actor));
        $this->assertSame('location_conflict', $model->lastErrorCode());
        $this->assertSame($before, $model->buscarPorId($emptyNumber));
        $this->assertTrue($model->atualizar($emptyNumber, ['nome_completo' => 'Localizado', 'caixa' => 'B', 'numero' => '4'], $actor));
        $this->assertSame(0, (int) $model->buscarPorId($emptyNumber)['localizacao_pendente']);
        $this->assertSame(1, $model->resumo()['pendentes']);
        $this->assertSame(2, $model->paginate(['localizacao' => 'completa'])['total']);
        $this->assertFalse($model->atualizar($emptyNumber, ['nome_completo' => 'Apagar posição', 'caixa' => 'B', 'numero' => ''], $actor));
        $this->assertSame('4', $model->buscarPorId($emptyNumber)['numero']);
        $this->assertFalse($model->atualizar($emptyBox, ['nome_completo' => 'Apagar posição conhecida', 'caixa' => '', 'numero' => ''], $actor));
        $this->assertSame('3', $model->buscarPorId($emptyBox)['numero']);
        $this->assertSame($actor, (int) $this->pdo->query("SELECT actor_user_id FROM security_audit WHERE action = 'passive.updated' ORDER BY id DESC LIMIT 1")->fetchColumn());
    }

    public function testAuditFailureRollsBackHistoricalLocationCompletionAndEnumeration(): void
    {
        $actor = $this->insertUsuario('Admin Pendencia Auditoria');
        $model = new \Passivo();
        $id = $this->legacyPending('Histórico com pendência', 'A');
        $before = $model->buscarPorId($id);
        foreach (['passive.updated', 'passive.enumerated'] as $action) {
            $this->pdo->exec("CREATE TRIGGER fail_completion BEFORE INSERT ON security_audit WHEN NEW.action = '{$action}' BEGIN SELECT RAISE(ABORT, 'forced'); END");
            if ($action === 'passive.updated') {
                $result = $model->atualizar($id, ['nome_completo' => 'Histórico localizado', 'caixa' => 'A', 'numero' => '1'], $actor);
            } else {
                $preview = $model->previewEnumeracao('A');
                $this->assertIsArray($preview);
                $result = $model->aplicarEnumeracao('A', $preview['assignments'], $actor);
            }
            $this->assertFalse($result);
            $this->assertSame($before, $model->buscarPorId($id));
            $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM security_audit')->fetchColumn());
            $this->pdo->exec('DROP TRIGGER fail_completion');
        }
    }

    public function testEnumerationContinuesAfterHistoricalMaximumBeyondIntegerRangeAndRejectsStalePreview(): void
    {
        $actor = $this->insertUsuario('Admin Historico Numeracao');
        $model = new \Passivo();
        $history = $model->cadastrar(['nome_completo' => 'Histórico de posição grande', 'caixa' => 'A', 'numero' => '000999999999999999999999'], $actor);
        $this->assertIsInt($history);
        $this->assertTrue($model->definirAtivo($history, false, $actor));
        $id = $this->legacyPending('Pasta a numerar', 'A');
        $preview = $model->previewEnumeracao('A');
        $this->assertIsArray($preview);
        $this->assertSame('1000000000000000000000', $preview['assignments'][0]['numero']);
        $another = $model->cadastrar(['nome_completo' => 'Novo histórico concorrente', 'caixa' => 'A', 'numero' => '1000000000000000000001'], $actor);
        $this->assertIsInt($another);
        $this->assertTrue($model->definirAtivo($another, false, $actor));
        $this->assertFalse($model->aplicarEnumeracao('A', $preview['assignments'], $actor));
        $this->assertSame('preview_changed', $model->lastErrorCode());
        $this->assertNull($model->buscarPorId($id)['numero']);
        $preview = $model->previewEnumeracao('A');
        $this->assertIsArray($preview);
        $this->assertSame('1000000000000000000002', $preview['assignments'][0]['numero']);
        $this->assertSame(1, $model->aplicarEnumeracao('A', $preview['assignments'], $actor));
        $this->assertSame('000999999999999999999999', $model->buscarPorId($history)['numero']);
        $this->assertSame(0, (int) $model->buscarPorId($id)['localizacao_pendente']);
        $this->assertSame($actor, (int) $model->buscarPorId($id)['atualizado_por']);
    }

    public function testEnumerationDetectsFortyDigitOverflowAndPreservesUnnumberedRecord(): void
    {
        $actor = $this->insertUsuario('Admin Limite Enumeracao');
        $model = new \Passivo();
        $history = $model->cadastrar(['nome_completo' => 'Histórico no limite', 'caixa' => 'A', 'numero' => str_repeat('9', 40)], $actor);
        $this->assertIsInt($history);
        $this->assertTrue($model->definirAtivo($history, false, $actor));
        $id = $this->legacyPending('Pasta sem posição', 'A');
        $this->assertFalse($model->previewEnumeracao('A'));
        $this->assertSame('number_range_overflow', $model->lastErrorCode());
        $this->assertNull($model->buscarPorId($id)['numero']);
        $this->assertSame(1, (int) $model->buscarPorId($id)['localizacao_pendente']);
    }

    public function testUnicodeBlankHistoricalPositionIsPreservedDuringEditAndCanBeEnumerated(): void
    {
        $actor = $this->insertUsuario('Admin Espacos Legados');
        $model = new \Passivo();
        $blank = "\u{00A0}\u{2003}";
        $id = $this->legacyPending('Legado em branco', ' A ', $blank);
        $this->assertTrue($model->atualizar($id, ['nome_completo' => 'Legado corrigido', 'caixa' => 'A', 'numero' => ''], $actor));
        $this->assertSame(' A ', $model->buscarPorId($id)['caixa']);
        $this->assertSame($blank, $model->buscarPorId($id)['numero']);
        $this->assertSame(1, (int) $model->buscarPorId($id)['localizacao_pendente']);
        $preview = $model->previewEnumeracao('A');
        $this->assertIsArray($preview);
        $this->assertSame('1', $preview['assignments'][0]['numero']);
        $this->assertSame(1, $model->aplicarEnumeracao('A', $preview['assignments'], $actor));
        $this->assertSame(0, (int) $model->buscarPorId($id)['localizacao_pendente']);
    }

    public function testIndividualArchiveAuditFailurePreservesStudentDvaAndAllLinks(): void
    {
        $actor = $this->insertUsuario('Admin Auditoria Arquivamento');
        $model = new \Passivo();
        $id = $this->insertAlunoComDva('Aluno rollback passivo', '2027-06-01');
        $this->pdo->exec('UPDATE alunos SET ativo = 0 WHERE id = ' . $id);
        $students = $this->pdo->query('SELECT * FROM alunos')->fetchAll();
        $dvas = $this->pdo->query('SELECT * FROM dvas')->fetchAll();
        $this->pdo->exec("CREATE TRIGGER fail_archive_audit BEFORE INSERT ON security_audit WHEN NEW.action = 'passive.student_archived' BEGIN SELECT RAISE(ABORT, 'forced'); END");
        $this->assertFalse($model->arquivarAluno($id, ['caixa' => 'A', 'numero' => '1'], $actor));
        $this->assertSame($students, $this->pdo->query('SELECT * FROM alunos')->fetchAll());
        $this->assertSame($dvas, $this->pdo->query('SELECT * FROM dvas')->fetchAll());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos_passivo')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM security_audit')->fetchColumn());
    }

    public function testDirectImportRejectsDuplicatesBeforeWritingAnything(): void
    {
        $actor = $this->insertUsuario('Admin Duplicidade Importacao');
        $model = new \Passivo();
        $first = ['nome_completo' => 'Pasta primeira', 'caixa' => 'A', 'numero' => '1'];
        $second = ['nome_completo' => 'Pasta segunda', 'caixa' => 'a', 'numero' => '1'];
        $this->assertFalse($model->importarLote([$first, $second], $actor));
        $this->assertSame('location_conflict', $model->lastErrorCode());
        $this->assertFalse($model->importarLote([$first, ['nome_completo' => 'Pasta primeira', 'caixa' => 'B', 'numero' => '2']], $actor));
        $this->assertSame('duplicate_changed', $model->lastErrorCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos_passivo')->fetchColumn());
    }

    private function legacyPending(string $name, ?string $box, ?string $number = null): int
    {
        $statement = $this->pdo->prepare('INSERT INTO alunos_passivo (nome_completo, nome_normalizado, caixa, caixa_normalizada, numero, numero_normalizado, localizacao_pendente) VALUES (?, ?, ?, ?, ?, ?, 1)');
        $statement->execute([$name, \src\Core\TextNormalizer::searchKey($name), $box,
            $box === null ? null : \src\Core\TextNormalizer::searchKey($box), $number,
            $number === null ? null : \src\Core\TextNormalizer::searchKey($number)]);
        return (int) $this->pdo->lastInsertId();
    }
}
