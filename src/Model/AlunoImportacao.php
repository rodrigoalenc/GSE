<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Core/Model.php';
require_once ROOT_PATH . '/src/Core/SqliteTransaction.php';
require_once ROOT_PATH . '/src/Core/TextNormalizer.php';
require_once ROOT_PATH . '/src/Core/AuditLogger.php';
require_once ROOT_PATH . '/src/Core/DvaStatus.php';
require_once ROOT_PATH . '/src/Model/Aluno.php';

use src\Core\SqliteTransaction;
use src\Core\TextNormalizer;

/**
 * @phpstan-type SourceRow array{nome_completo:string,data_nascimento:string,turma:string,codigo_sgde:string,situacao:string,pagina:int}
 * @phpstan-type PlanItem array{nome_completo:string,data_nascimento:string,turma:string,codigo_sgde:string,situacao:string,pagina:int,action:'importar'|'duplicado'|'revisar',reason:string}
 * @phpstan-type ImportPlan array{items:list<PlanItem>,counts:array{importar:int,duplicado:int,revisar:int},classes_new:list<string>,year:int}
 */
final class AlunoImportacao extends Model
{
    /**
     * @param list<SourceRow> $rows
     * @return ImportPlan
     */
    public function preview(array $rows, int $year): array
    {
        return $this->buildPlan($this->getConexao(), $this->validateRows($rows, $year), $year);
    }

    /**
     * @param list<SourceRow> $rows
     * @return array{imported:int,classes_created:int,duplicates:int,review:int}
     */
    public function import(array $rows, int $year, int $actorId): array
    {
        // Validate the whole eligible report before opening a write transaction.
        $rows = $this->validateRows($rows, $year);

        return SqliteTransaction::immediate($this->getConexao(), function (PDO $pdo) use ($rows, $year, $actorId): array {
            $actor = $pdo->prepare("SELECT 1 FROM usuarios WHERE id = :id AND ativo = 1 AND tipo = 'administrador'");
            $actor->execute(['id' => $actorId]);

            if ($actorId < 1 || $actor->fetchColumn() === false) {
                throw new DomainException('Somente um administrador ativo pode importar alunos.');
            }

            // Recheck duplicates and class status under the write lock.
            $plan = $this->buildPlan($pdo, $rows, $year);
            $now = gmdate('Y-m-d H:i:s');
            $classesCreated = 0;
            $imported = 0;
            /** @var array<string,int> $classIds */
            $classIds = [];
            $findClass = $pdo->prepare('SELECT id FROM turmas WHERE nome_normalizado = :name AND ano_letivo = :year AND ativo = 1');
            $createClass = $pdo->prepare(
                'INSERT INTO turmas (nome_turma,nome_normalizado,ano_letivo,ativo,criado_em,atualizado_em)
                 VALUES (:name,:normalized_name,:year,1,:now,:now)'
            );
            $createStudent = $pdo->prepare(
                'INSERT INTO alunos (nome_completo,nome_normalizado,data_nascimento,id_turma,telefone_aluno,telefone_responsavel,ativo,criado_em,atualizado_em)
                 VALUES (:name,:normalized_name,:birth,:class,NULL,NULL,1,:now,:now)'
            );

            foreach ($plan['items'] as $item) {
                if ($item['action'] !== 'importar') {
                    continue;
                }

                $classKey = TextNormalizer::comparisonKey($item['turma']);

                if (!isset($classIds[$classKey])) {
                    $findClass->execute(['name' => $classKey, 'year' => $year]);
                    $classId = $findClass->fetchColumn();

                    if ($classId === false) {
                        $createClass->execute([
                            'name' => $item['turma'], 'normalized_name' => $classKey,
                            'year' => $year, 'now' => $now,
                        ]);
                        $classId = (int) $pdo->lastInsertId();
                        ++$classesCreated;
                        AuditLogger::recordRequired(
                            $pdo, 'class.created', AuditLogger::SUCCESS, $actorId, null,
                            'Turma cadastrada pela importação de alunos.', 'class', $classId
                        );
                    }

                    $classIds[$classKey] = (int) $classId;
                }

                $createStudent->execute([
                    'name' => $item['nome_completo'],
                    'normalized_name' => TextNormalizer::comparisonKey($item['nome_completo']),
                    'birth' => $item['data_nascimento'], 'class' => $classIds[$classKey], 'now' => $now,
                ]);
                $studentId = (int) $pdo->lastInsertId();
                ++$imported;
                AuditLogger::recordRequired(
                    $pdo, 'student.created', AuditLogger::SUCCESS, $actorId, null,
                    'Cadastro de aluno concluído pela importação.', 'student', $studentId
                );
            }

            AuditLogger::recordRequired(
                $pdo, 'student.import_completed', AuditLogger::SUCCESS, $actorId, null,
                sprintf(
                    'Importação concluída: %d alunos, %d turmas criadas, %d duplicados e %d para revisão; ano letivo %d.',
                    $imported, $classesCreated, $plan['counts']['duplicado'], $plan['counts']['revisar'], $year
                ),
                'student_import'
            );

            return [
                'imported' => $imported, 'classes_created' => $classesCreated,
                'duplicates' => $plan['counts']['duplicado'], 'review' => $plan['counts']['revisar'],
            ];
        });
    }

    /**
     * @param list<SourceRow> $rows
     * @return list<SourceRow>
     */
    private function validateRows(array $rows, int $year): array
    {
        if ($year < 2000 || $year > (int) gmdate('Y') + 5) {
            throw new DomainException('Informe um ano letivo entre 2000 e ' . ((int) gmdate('Y') + 5) . '.');
        }

        $valid = [];
        $today = (new DvaStatus())->today();

        foreach ($rows as $row) {
            try {
                $status = TextNormalizer::comparisonKey($row['situacao']);

                if ($status !== 'em curso') {
                    continue;
                }

                $name = TextNormalizer::displayName($row['nome_completo']);
                $class = TextNormalizer::displayName($row['turma']);
            } catch (RuntimeException $exception) {
                throw new DomainException('O relatório contém texto inválido.', 0, $exception);
            }

            if ($name === '' || mb_strlen($name, 'UTF-8') > Aluno::NAME_MAX_LENGTH
                || preg_match('/[\x00-\x1f\x7f]/u', $name) === 1) {
                throw new DomainException('Nome de aluno inválido no relatório.');
            }

            if ($class === '' || mb_strlen($class, 'UTF-8') > 100
                || preg_match('/[\x00-\x1f\x7f]/u', $class) === 1) {
                throw new DomainException('Turma inválida no relatório.');
            }

            $birth = trim($row['data_nascimento']);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $birth, new DateTimeZone('UTC'));

            if ($date === false || $date->format('Y-m-d') !== $birth || $birth > $today || $birth < '0001-01-01') {
                throw new DomainException('Data de nascimento inválida no relatório.');
            }

            $valid[] = [
                'nome_completo' => $name, 'data_nascimento' => $birth, 'turma' => $class,
                'codigo_sgde' => $row['codigo_sgde'], 'situacao' => 'Em curso', 'pagina' => $row['pagina'],
            ];
        }

        return $valid;
    }

    /**
     * @param list<SourceRow> $rows
     * @return ImportPlan
     */
    private function buildPlan(PDO $pdo, array $rows, int $year): array
    {
        $findStudent = $pdo->prepare('SELECT id,ativo FROM alunos WHERE nome_normalizado = :name AND data_nascimento = :birth ORDER BY id');
        $findClass = $pdo->prepare(
            'SELECT id,ativo,ano_letivo FROM turmas
             WHERE nome_normalizado = :name AND (ano_letivo = :year OR ano_letivo IS NULL)'
        );
        /** @var list<PlanItem> $items */
        $items = [];
        $counts = ['importar' => 0, 'duplicado' => 0, 'revisar' => 0];
        /** @var array<string,true> $seen */
        $seen = [];
        /** @var array<string,string> $classesNew */
        $classesNew = [];
        /** @var array<string,array{action:'importar'|'revisar',reason:string,new:bool}> $classes */
        $classes = [];

        foreach ($rows as $row) {
            $nameKey = TextNormalizer::comparisonKey($row['nome_completo']);
            $identity = $nameKey . "\0" . $row['data_nascimento'];
            $action = 'importar';
            $reason = 'Novo aluno.';

            if (isset($seen[$identity])) {
                $action = 'duplicado';
                $reason = 'Mesmo nome e nascimento repetidos no relatório.';
            } else {
                $seen[$identity] = true;
                $findStudent->execute(['name' => $nameKey, 'birth' => $row['data_nascimento']]);
                $students = $findStudent->fetchAll(PDO::FETCH_ASSOC);

                if ($students !== []) {
                    $action = 'duplicado';
                    $reason = count($students) > 1
                        ? 'Mais de um cadastro com o mesmo nome e nascimento; confira os cadastros existentes.'
                        : ((int) $students[0]['ativo'] === 1
                            ? 'Aluno já cadastrado com o mesmo nome e nascimento.'
                            : 'Aluno já cadastrado e inativo; confira o cadastro antes de reativá-lo.');
                } else {
                    $classKey = TextNormalizer::comparisonKey($row['turma']);

                    if (!isset($classes[$classKey])) {
                        $findClass->execute(['name' => $classKey, 'year' => $year]);
                        $matches = $findClass->fetchAll(PDO::FETCH_ASSOC);
                        $exact = null;
                        $legacy = false;

                        foreach ($matches as $match) {
                            if ($match['ano_letivo'] === null) {
                                $legacy = true;
                            } else {
                                $exact = $match;
                            }
                        }

                        $classes[$classKey] = ['action' => 'importar', 'reason' => 'Novo aluno.', 'new' => $exact === null];

                        if ($exact !== null && (int) $exact['ativo'] !== 1) {
                            $classes[$classKey] = [
                                'action' => 'revisar', 'reason' => 'A turma deste ano letivo está inativa; confira a turma antes de reativá-la.', 'new' => false,
                            ];
                        } elseif ($exact === null && $legacy) {
                            $classes[$classKey] = [
                                'action' => 'revisar', 'reason' => 'Existe turma com este nome sem ano letivo; confira o cadastro da turma.', 'new' => false,
                            ];
                        }
                    }

                    $action = $classes[$classKey]['action'];
                    $reason = $classes[$classKey]['reason'];

                    if ($action === 'importar' && $classes[$classKey]['new']) {
                        $classesNew[$classKey] = $row['turma'];
                    }
                }
            }

            $items[] = $row + ['action' => $action, 'reason' => $reason];
            ++$counts[$action];
        }

        return ['items' => $items, 'counts' => $counts, 'classes_new' => array_values($classesNew), 'year' => $year];
    }
}
