<?php

declare(strict_types=1);

namespace Tests\Integration;

use src\Core\TextNormalizer;
use Tests\Support\DatabaseTestCase;

final class PassivoAutomaticoTest extends DatabaseTestCase
{
    public function testFirstAutomaticBoxAndStudentStartAtOneWithoutManualNumbers(): void
    {
        $actor = $this->insertUsuario('Admin Primeiro Passivo');
        $model = new \Passivo();

        $this->assertSame('1', $model->proximaCaixa());
        $this->assertSame('1', $model->validarCaixaCadastro('nova', ''));
        $id = $model->cadastrarAutomatico([
            'nome_completo' => 'Primeiro aluno', 'data_nascimento' => '2010-05-10',
            'tipo_caixa' => 'nova', 'caixa' => '999', 'numero' => '999',
        ], $actor);

        $this->assertIsInt($id);
        $record = $model->buscarPorId($id);
        $this->assertSame('1', $record['caixa']);
        $this->assertSame('1', $record['numero']);
        $this->assertSame('2010-05-10', $record['data_nascimento']);
        $this->assertSame(0, (int) $record['localizacao_pendente']);
        $this->assertSame('2', $model->proximaCaixa());
    }

    public function testInsertionInFirstBoxShiftsAllFollowingBoxesWithoutChangingTheirMembership(): void
    {
        $actor = $this->insertUsuario('Admin Sequencia Global');
        $before = [];
        foreach (['1' => 32, '2' => 28, '3' => 30] as $box => $total) {
            for ($index = 1; $index <= $total; $index++) {
                $number = count($before) + 1;
                $id = $this->seed('Maria ' . str_pad((string) $index, 3, '0', STR_PAD_LEFT), (string) $box, (string) $number);
                $before[$id] = $this->row($id);
            }
        }

        $model = new \Passivo();
        $id = $model->cadastrarAutomatico([
            'nome_completo' => 'Ana nova', 'tipo_caixa' => 'existente', 'caixa' => '1', 'numero' => '9000',
        ], $actor);
        $this->assertIsInt($id);
        $this->assertSame('1', $model->buscarPorId($id)['numero']);

        foreach (['1' => [1, 33], '2' => [34, 61], '3' => [62, 91]] as $box => [$start, $end]) {
            $statement = $this->pdo->prepare('SELECT numero FROM alunos_passivo WHERE caixa = ? ORDER BY nome_normalizado, id');
            $statement->execute([(string) $box]);
            $this->assertSame(array_map('strval', range($start, $end)), $statement->fetchAll(\PDO::FETCH_COLUMN));
        }
        foreach ($before as $oldId => $oldRecord) {
            $current = $this->row($oldId);
            foreach (['numero', 'numero_normalizado', 'atualizado_em', 'atualizado_por', 'revisao'] as $mutable) {
                unset($oldRecord[$mutable], $current[$mutable]);
            }
            $this->assertSame($oldRecord, $current, 'A reorganização preserva caixa, identidade, dados e histórico.');
        }
        $this->assertSame(91, (int) $this->pdo->query('SELECT COUNT(DISTINCT numero) FROM alunos_passivo')->fetchColumn());
        $this->assertSame('ok', $this->pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame([], $this->pdo->query('PRAGMA foreign_key_check')->fetchAll());
    }

    public function testAlphabeticalOrderIgnoresAccentsAndCaseAndUsesStableIdForTies(): void
    {
        $actor = $this->insertUsuario('Admin Ordem Alfabetica');
        $zeta = $this->seed('Zeta', '1', '1');
        $firstAlvaro = $this->seed('Álvaro', '1', '2');
        $secondAlvaro = $this->seed('alvaro', '1', '3');
        $beatriz = $this->seed('Beatriz', '1', '4');

        $model = new \Passivo();
        $thirdAlvaro = $model->cadastrarAutomatico([
            'nome_completo' => 'ÁLVARO', 'tipo_caixa' => 'existente', 'caixa' => '1',
        ], $actor);
        $this->assertIsInt($thirdAlvaro);
        $this->assertSame([$firstAlvaro, $secondAlvaro, $thirdAlvaro, $beatriz, $zeta],
            array_map('intval', $this->pdo->query('SELECT id FROM alunos_passivo ORDER BY CAST(numero AS INTEGER)')->fetchAll(\PDO::FETCH_COLUMN)));
        $this->assertSame(['1', '2', '3', '4', '5'],
            $this->pdo->query('SELECT numero FROM alunos_passivo ORDER BY CAST(numero AS INTEGER)')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testNewBoxUsesHighestExistingBoxIncludingInactiveRecordsAndContinuesGlobalNumbers(): void
    {
        $actor = $this->insertUsuario('Admin Proxima Caixa');
        $this->seed('Ana caixa um', '1', '1');
        $this->seed('Ana caixa dois', '2', '2');
        $this->seed('Ana caixa dez', '10', '3', false);
        $model = new \Passivo();
        $this->assertSame('11', $model->proximaCaixa());

        $id = $model->cadastrarAutomatico(['nome_completo' => 'Nova caixa', 'tipo_caixa' => 'nova'], $actor);
        $this->assertIsInt($id);
        $this->assertSame('11', $this->row($id)['caixa']);
        $this->assertSame('4', $this->row($id)['numero']);
    }

    public function testNextBoxRecognizesLegacyLabelsWithoutRenamingThem(): void
    {
        $actor = $this->insertUsuario('Admin Caixas Legadas');
        $ids = [
            $this->seed('Nome numérica', '2', '1'),
            $this->seed('Nome caixa', 'Caixa 12', '2'),
            $this->seed('Nome cx', 'CX-15', '3', false),
        ];
        $model = new \Passivo();
        $this->assertSame('16', $model->proximaCaixa());
        $id = $model->cadastrarAutomatico(['nome_completo' => 'Caixa seguinte', 'tipo_caixa' => 'nova'], $actor);
        $this->assertIsInt($id);
        $this->assertSame('16', $this->row($id)['caixa']);
        $this->assertSame(['2', 'Caixa 12', 'CX-15'], array_map(fn (int $oldId): string => (string) $this->row($oldId)['caixa'], $ids));
    }

    public function testInactiveNumbersRemainUniqueAndRecordsWithoutABoxAreNotMoved(): void
    {
        $actor = $this->insertUsuario('Admin Legado Sem Caixa');
        $inactive = $this->seed('Beatriz inativa', '1', '100', false);
        $active = $this->seed('Zeta ativa', '1', '100');
        $pending = $this->seed('Legado sem localização', null, null);
        $beforePending = $this->row($pending);
        $model = new \Passivo();
        $new = $model->cadastrarAutomatico([
            'nome_completo' => 'Ana nova', 'tipo_caixa' => 'existente', 'caixa' => '1',
        ], $actor);
        $this->assertIsInt($new);
        $this->assertSame('1', $this->row($new)['numero']);
        $this->assertSame('2', $this->row($inactive)['numero']);
        $this->assertSame('3', $this->row($active)['numero']);
        $this->assertSame(0, (int) $this->row($inactive)['ativo']);
        $this->assertSame($beforePending, $this->row($pending));
        $this->assertSame('1', $model->validarCaixaCadastro('existente', '1'));
    }

    public function testExistingBoxSelectionResolvesCanonicalLabelAndAcceptsInactiveOnlyBoxes(): void
    {
        $actor = $this->insertUsuario('Admin Selecionar Caixa');
        $this->seed('Registro inativo', 'CX-02', '1', false);
        $model = new \Passivo();
        $this->assertSame('CX-02', $model->validarCaixaCadastro('existente', 'cx-02'));
        $id = $model->cadastrarAutomatico([
            'nome_completo' => 'Novo aluno', 'tipo_caixa' => 'existente', 'caixa' => 'cx-02',
        ], $actor);
        $this->assertIsInt($id);
        $this->assertSame('CX-02', $this->row($id)['caixa']);
    }

    public function testMixedBoxLabelsUseTheSameOrderForNumbersListsAndNavigation(): void
    {
        $actor = $this->insertUsuario('Admin Ordem Caixas Mistas');
        $this->seed('Aluno caixa três', 'CX-3', '1');
        $this->seed('Aluno caixa um', '1', '2');
        $this->seed('Aluno caixa dois', 'Caixa 2', '3');
        $model = new \Passivo();
        $this->assertSame('4', $model->proximaCaixa());
        $id = $model->cadastrarAutomatico(['nome_completo' => 'Aluno caixa quatro', 'tipo_caixa' => 'nova'], $actor);
        $this->assertIsInt($id);
        $this->assertSame('4', $this->row($id)['caixa']);
        $expected = ['1', 'Caixa 2', 'CX-3', '4'];
        $this->assertSame($expected, array_column($model->caixas(), 'caixa'));
        $this->assertSame($expected, array_column($model->paginate(['ordem' => 'caixa'])['items'], 'caixa'));
        $this->assertSame($expected,
            $this->pdo->query('SELECT caixa FROM alunos_passivo ORDER BY CAST(numero AS INTEGER)')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame(['1', '2', '3', '4'],
            $this->pdo->query('SELECT numero FROM alunos_passivo ORDER BY caixa_normalizada COLLATE PASSIVO_CAIXA')->fetchAll(\PDO::FETCH_COLUMN));
        foreach ($expected as $position => $box) {
            $navigation = $model->navegacaoCaixas($box);
            $this->assertSame($expected[$position - 1] ?? null, $navigation['anterior']);
            $this->assertSame($expected[$position + 1] ?? null, $navigation['proxima']);
        }
    }

    public function testBoxOrderingKeepsAlphabeticalNumericOrderAcrossPaginationWithMoreThanTenStudents(): void
    {
        $actor = $this->insertUsuario('Admin Ordem Caixa Paginada');
        foreach (range(12, 1) as $index) {
            $this->seed('Aluno ' . str_pad((string) $index, 2, '0', STR_PAD_LEFT), '1', (string) (13 - $index));
        }
        $this->seed('Aluno caixa seguinte', '2', '13');
        $model = new \Passivo();
        $id = $model->cadastrarAutomatico([
            'nome_completo' => 'Aluno 00', 'tipo_caixa' => 'existente', 'caixa' => '1',
        ], $actor);
        $this->assertIsInt($id);
        $expectedNames = array_map(static fn (int $index): string => 'Aluno ' . str_pad((string) $index, 2, '0', STR_PAD_LEFT), range(0, 12));
        $expectedNumbers = array_map('strval', range(1, 13));

        foreach (['caixa', 'nome', 'numero'] as $order) {
            $first = $model->paginate(['caixa' => '1', 'ordem' => $order], 1, 10);
            $second = $model->paginate(['caixa' => '1', 'ordem' => $order], 2, 10);
            $this->assertSame(13, $first['total']);
            $this->assertSame(2, $first['pages']);
            $this->assertCount(10, $first['items']);
            $this->assertCount(3, $second['items']);
            $items = array_merge($first['items'], $second['items']);
            $this->assertSame($expectedNames, array_column($items, 'nome_completo'));
            $this->assertSame($expectedNumbers, array_column($items, 'numero'));
        }
        $wholeArchive = $model->paginate(['ordem' => 'caixa'], 1, 20)['items'];
        $this->assertSame([...$expectedNames, 'Aluno caixa seguinte'], array_column($wholeArchive, 'nome_completo'));
        $this->assertSame(array_map('strval', range(1, 14)), array_column($wholeArchive, 'numero'));
    }

    public function testInvalidBoxModeAndUnknownBoxNeverCreateRecords(): void
    {
        $actor = $this->insertUsuario('Admin Validacao Caixa');
        $model = new \Passivo();
        $this->assertFalse($model->validarCaixaCadastro('manual', '1'));
        $this->assertSame('invalid_box_mode', $model->lastErrorCode());
        $this->assertFalse($model->validarCaixaCadastro('existente', '1'));
        $this->assertSame('box_not_found', $model->lastErrorCode());
        foreach (['manual', 'existente', ''] as $mode) {
            $this->assertFalse($model->cadastrarAutomatico([
                'nome_completo' => 'Não cadastrar', 'tipo_caixa' => $mode, 'caixa' => '1',
            ], $actor));
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos_passivo')->fetchColumn());
    }

    public function testValidationFailureDoesNotCreateBoxOrRenumberExistingRecords(): void
    {
        $actor = $this->insertUsuario('Admin Validacao Automatica');
        $this->seed('Registro existente', '1', '10');
        $before = $this->rows();
        $model = new \Passivo();
        $this->assertFalse($model->cadastrarAutomatico([
            'nome_completo' => '', 'tipo_caixa' => 'nova',
        ], $actor));
        $this->assertFalse($model->cadastrarAutomatico([
            'nome_completo' => 'Data inválida', 'tipo_caixa' => 'nova', 'data_nascimento' => '2020-02-31',
        ], $actor));
        $this->assertSame($before, $this->rows());
        $this->assertSame('2', $model->proximaCaixa());
    }

    public function testRenumberingFailureRollsBackNewStudentExistingNumbersRevisionsAndReceipts(): void
    {
        $actor = $this->insertUsuario('Admin Falha Renumeracao');
        $this->seed('Zeta caixa um', '1', '1');
        $this->seed('Maria caixa dois', '2', '2');
        $before = $this->rows();
        $auditCount = (int) $this->pdo->query('SELECT COUNT(*) FROM security_audit')->fetchColumn();
        $this->pdo->exec("CREATE TRIGGER fail_automatic_number BEFORE UPDATE OF numero ON alunos_passivo
            WHEN NEW.numero = '3' BEGIN SELECT RAISE(ABORT, 'forced_automatic_number'); END");
        $model = new \Passivo();
        $this->assertFalse($model->cadastrarAutomatico([
            'nome_completo' => 'Ana caixa um', 'tipo_caixa' => 'existente', 'caixa' => '1',
        ], $actor, bin2hex(random_bytes(16))));
        $this->assertSame($before, $this->rows());
        $this->assertSame($auditCount, (int) $this->pdo->query('SELECT COUNT(*) FROM security_audit')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM request_receipts')->fetchColumn());
    }

    public function testRequiredAuditFailureRollsBackRenumberingAndAutomaticNewBox(): void
    {
        $actor = $this->insertUsuario('Admin Falha Auditoria Automatica');
        $this->seed('Zeta existente', '1', '99');
        $before = $this->rows();
        $this->pdo->exec("CREATE TRIGGER fail_automatic_audit BEFORE INSERT ON security_audit
            WHEN NEW.action = 'passive.created' BEGIN SELECT RAISE(ABORT, 'forced_automatic_audit'); END");
        $model = new \Passivo();
        $this->assertFalse($model->cadastrarAutomatico([
            'nome_completo' => 'Nova caixa com falha', 'tipo_caixa' => 'nova',
        ], $actor, bin2hex(random_bytes(16))));
        $this->assertSame($before, $this->rows());
        $this->assertSame('2', $model->proximaCaixa());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM request_receipts')->fetchColumn());
    }

    public function testReplayingAutomaticNewBoxUsesSameRecordWithoutNewBoxNumberOrAudit(): void
    {
        $actor = $this->insertUsuario('Admin Reenvio Automatico');
        $model = new \Passivo();
        $token = bin2hex(random_bytes(16));
        $data = ['nome_completo' => 'Aluno reenviado', 'tipo_caixa' => 'nova'];
        $id = $model->cadastrarAutomatico($data, $actor, $token);
        $this->assertIsInt($id);
        $before = $this->rows();
        $auditCount = (int) $this->pdo->query('SELECT COUNT(*) FROM security_audit')->fetchColumn();

        $this->assertSame($id, $model->cadastrarAutomatico($data, $actor, $token));
        $this->assertSame($before, $this->rows());
        $this->assertSame($auditCount, (int) $this->pdo->query('SELECT COUNT(*) FROM security_audit')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM request_receipts')->fetchColumn());
        $this->assertSame('2', $model->proximaCaixa());
        $this->assertFalse($model->cadastrarAutomatico([...$data, 'nome_completo' => 'Outro aluno'], $actor, $token));
        $this->assertSame($before, $this->rows());
    }

    public function testTwoNewBoxFormsResolveNextBoxAgainAtEachSave(): void
    {
        $actor = $this->insertUsuario('Admin Duas Abas');
        $this->seed('Aluno caixa um', '1', '1');
        $model = new \Passivo();
        $this->assertSame('2', $model->validarCaixaCadastro('nova', ''));
        $this->assertSame('2', $model->validarCaixaCadastro('nova', ''));
        $first = $model->cadastrarAutomatico([
            'nome_completo' => 'Primeira aba', 'tipo_caixa' => 'nova', 'caixa' => '2',
        ], $actor, bin2hex(random_bytes(16)));
        $second = $model->cadastrarAutomatico([
            'nome_completo' => 'Segunda aba', 'tipo_caixa' => 'nova', 'caixa' => '2',
        ], $actor, bin2hex(random_bytes(16)));
        $this->assertIsInt($first);
        $this->assertIsInt($second);
        $this->assertSame('2', $this->row($first)['caixa']);
        $this->assertSame('3', $this->row($second)['caixa']);
        $this->assertSame('2', $this->row($first)['numero']);
        $this->assertSame('3', $this->row($second)['numero']);
    }

    public function testNumberSwapsSucceedEvenWithAGlobalUniqueIndex(): void
    {
        $actor = $this->insertUsuario('Admin Posicoes Unicas');
        $zeta = $this->seed('Zeta', '1', '1');
        $ana = $this->seed('Ana', '1', '2');
        $nextBox = $this->seed('Aluno outra caixa', '2', '3');
        $this->pdo->exec('CREATE UNIQUE INDEX test_unique_global_number ON alunos_passivo(numero_normalizado)
            WHERE numero_normalizado IS NOT NULL');

        $model = new \Passivo();
        $new = $model->cadastrarAutomatico([
            'nome_completo' => 'Beatriz', 'tipo_caixa' => 'existente', 'caixa' => '1',
        ], $actor);
        $this->assertIsInt($new);
        $this->assertSame('1', $this->row($ana)['numero']);
        $this->assertSame('2', $this->row($new)['numero']);
        $this->assertSame('3', $this->row($zeta)['numero']);
        $this->assertSame('4', $this->row($nextBox)['numero']);
    }

    public function testNextBoxPreservesDigitsBeyondTheIntegerRange(): void
    {
        $actor = $this->insertUsuario('Admin Caixa Grande');
        $this->seed('Caixa grande', '999999999999999999999999999999', '1');
        $model = new \Passivo();
        $expected = '1000000000000000000000000000000';
        $this->assertSame($expected, $model->proximaCaixa());
        $id = $model->cadastrarAutomatico(['nome_completo' => 'Caixa seguinte', 'tipo_caixa' => 'nova'], $actor);
        $this->assertIsInt($id);
        $this->assertSame($expected, $this->row($id)['caixa']);
        $this->assertSame('2', $this->row($id)['numero']);
    }

    public function testBoxNumberOverflowFailsWithoutChangingTheArchive(): void
    {
        $actor = $this->insertUsuario('Admin Limite Caixa');
        $this->seed('Caixa limite', str_repeat('9', \Passivo::BOX_MAX_LENGTH), '1');
        $before = $this->rows();
        $model = new \Passivo();
        $this->assertFalse($model->proximaCaixa());
        $this->assertSame('box_range_overflow', $model->lastErrorCode());
        $this->assertFalse($model->cadastrarAutomatico([
            'nome_completo' => 'Não criar caixa fora do limite', 'tipo_caixa' => 'nova',
        ], $actor));
        $this->assertSame('box_range_overflow', $model->lastErrorCode());
        $this->assertSame($before, $this->rows());
    }

    public function testHistoricalBoxWithoutNumberIsNumberedInAlphabeticalOrder(): void
    {
        $actor = $this->insertUsuario('Admin Caixa Sem Posicao');
        $pending = $this->seed('Ana histórica', '1', null);
        $existing = $this->seed('Zeta existente', '1', '100');
        $model = new \Passivo();
        $new = $model->cadastrarAutomatico([
            'nome_completo' => 'Beatriz nova', 'tipo_caixa' => 'existente', 'caixa' => '1',
        ], $actor);
        $this->assertIsInt($new);
        $this->assertSame('1', $this->row($pending)['numero']);
        $this->assertSame('1', $this->row($pending)['caixa']);
        $this->assertSame(0, (int) $this->row($pending)['localizacao_pendente']);
        $this->assertSame('2', $this->row($new)['numero']);
        $this->assertSame('3', $this->row($existing)['numero']);
    }

    public function testNumberedHistoricalRecordWithoutBoxKeepsItsMissingBoxAndRemainsPending(): void
    {
        $actor = $this->insertUsuario('Admin Posicao Sem Caixa');
        $pending = $this->seed('Legado com número', null, '100');
        $existing = $this->seed('Aluno existente', '1', '2');
        $model = new \Passivo();
        $new = $model->cadastrarAutomatico(['nome_completo' => 'Nova caixa', 'tipo_caixa' => 'nova'], $actor);
        $this->assertIsInt($new);
        $this->assertSame('1', $this->row($pending)['numero']);
        $this->assertNull($this->row($pending)['caixa']);
        $this->assertNull($this->row($pending)['caixa_normalizada']);
        $this->assertSame(1, (int) $this->row($pending)['localizacao_pendente']);
        $this->assertSame('2', $this->row($existing)['numero']);
        $this->assertSame('3', $this->row($new)['numero']);
    }

    public function testOnlyChangedNumbersInvalidateOpenEditForms(): void
    {
        $actor = $this->insertUsuario('Admin Revisao Numeracao');
        $unchanged = $this->seed('Ana existente', '1', '1');
        $changed = $this->seed('Zeta existente', '1', '2');
        $unchangedBefore = $this->row($unchanged);
        $changedBefore = $this->row($changed);
        $model = new \Passivo();
        $this->assertIsInt($model->cadastrarAutomatico([
            'nome_completo' => 'Beatriz nova', 'tipo_caixa' => 'existente', 'caixa' => '1',
        ], $actor));
        $this->assertSame($unchangedBefore, $this->row($unchanged));
        $this->assertGreaterThan((int) $changedBefore['revisao'], (int) $this->row($changed)['revisao']);
        $this->assertFalse($model->atualizar($changed, $changedBefore, $actor, (int) $changedBefore['revisao']));
        $this->assertSame('stale_revision', $model->lastErrorCode());
        $this->assertTrue($model->atualizar($unchanged, $unchangedBefore, $actor, (int) $unchangedBefore['revisao']));
    }

    public function testConcurrentNewBoxSavesRecheckSequenceInsideTheWriteLock(): void
    {
        $actor = $this->insertUsuario('Admin Corrida Caixas');
        $this->seed('Aluno caixa inicial', '1', '1');
        $barrier = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gse-passivo-race-' . bin2hex(random_bytes(12));
        $workers = [];
        $readyFiles = [];
        try {
            foreach ([1, 2] as $worker) {
                $token = bin2hex(random_bytes(16));
                $readyFiles[] = $barrier . '.' . $token . '.ready';
                $pipes = [];
                $process = proc_open([PHP_BINARY, ROOT_PATH . '/tests/fixtures/passivo-create-race.php',
                    $barrier, (string) $actor, $token, (string) $worker],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            $ready = false;
            for ($attempt = 0; $attempt < 1000; $attempt++) {
                if (is_file($readyFiles[0]) && is_file($readyFiles[1])) {
                    $ready = true;
                    break;
                }
                usleep(10000);
            }
            $this->assertTrue($ready, 'Ambos os processos devem estar prontos antes de liberar as gravações.');
            file_put_contents($barrier, 'go');
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $errors . $output);
                $this->assertSame('', $errors);
                $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame('2', $result['preview']);
                $this->assertTrue($result['success'], $output);
                $results[] = $result;
            }
            $workers = [];
            $boxes = array_column($results, 'caixa');
            sort($boxes);
            $this->assertSame(['2', '3'], $boxes);
            $this->assertCount(2, array_unique(array_column($results, 'id')));
            $this->assertSame(['1', '2', '3'],
                $this->pdo->query('SELECT numero FROM alunos_passivo ORDER BY CAST(numero AS INTEGER)')->fetchAll(\PDO::FETCH_COLUMN));
            $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM request_receipts')->fetchColumn());
        } finally {
            foreach ($workers as [$process, $pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                }
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    proc_close($process);
                }
            }
            foreach ([$barrier, ...$readyFiles] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    private function seed(string $name, ?string $box, ?string $number, bool $active = true): int
    {
        $statement = $this->pdo->prepare('INSERT INTO alunos_passivo
            (nome_completo, nome_normalizado, data_nascimento, caixa, caixa_normalizada, numero, numero_normalizado, ativo, localizacao_pendente)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$name, TextNormalizer::searchKey($name), '2010-05-10', $box,
            $box === null ? null : TextNormalizer::searchKey($box), $number,
            $number === null ? null : TextNormalizer::searchKey($number), $active ? 1 : 0,
            $box === null || $number === null ? 1 : 0]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM alunos_passivo WHERE id = ?');
        $statement->execute([$id]);

        return $statement->fetch(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    private function rows(): array
    {
        return $this->pdo->query('SELECT * FROM alunos_passivo ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    }
}
