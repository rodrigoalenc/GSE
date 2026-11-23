<?php
declare(strict_types=1);
namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;
require_once ROOT_PATH . '/src/Core/AccessPolicy.php';
require_once ROOT_PATH . '/src/Controllers/AlunoController.php';
require_once ROOT_PATH . '/src/Controllers/FormDraftController.php';

final class AccessPolicyTest extends DatabaseTestCase
{
    private const KEYS = ['ACCESS_HEALTH_STAFF_IDS','ACCESS_CONTACT_STAFF_IDS','ACCESS_EXPORT_STAFF_IDS',
        'ACCESS_MANAGE_STAFF_IDS','ACCESS_APPROVAL_REFERENCE','ACCESS_APPROVAL_VALID_UNTIL'];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::KEYS as $key) { $_ENV[$key] = ''; }
    }

    protected function tearDown(): void
    {
        foreach (self::KEYS as $key) { unset($_ENV[$key]); putenv($key); }
        $_GET = []; $_POST = []; unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
        parent::tearDown();
    }

    public function testDvaAccessAppliesToActiveEmployeesAndAdministrators(): void
    {
        $this->assertFalse(\AccessPolicy::allows('student.health'));
        $employee = $this->insertUsuario('Funcionario Privacidade', 'funcionario');
        \SessionManager::authenticate((new \Usuario())->buscarPorId($employee));
        $this->assertTrue(\AccessPolicy::allows('student.health'));
        foreach (['student.contacts','student.export','student.manage','unknown'] as $permission) {
            $this->assertFalse(\AccessPolicy::allows($permission));
        }
        $this->pdo->prepare('UPDATE usuarios SET ativo=0 WHERE id=?')->execute([$employee]);
        $this->assertFalse(\AccessPolicy::allows('student.health'));
        $admin = $this->insertUsuario('Admin Privacidade');
        $this->insertUsuario('Admin Guardiao Privacidade');
        \SessionManager::authenticate((new \Usuario())->buscarPorId($admin));
        $this->assertTrue(\AccessPolicy::allows('student.health'));
        $this->pdo->prepare('UPDATE usuarios SET ativo=0 WHERE id=?')->execute([$admin]);
        $this->assertFalse(\AccessPolicy::allows('student.health'));
    }

    public function testNamedGrantsRequireCurrentDocumentedApprovalAndSeparatePermissions(): void
    {
        $employee = $this->insertUsuario('Funcionario Nomeado', 'funcionario');
        \SessionManager::authenticate((new \Usuario())->buscarPorId($employee));
        $_ENV['ACCESS_EXPORT_STAFF_IDS'] = (string) $employee;
        $_ENV['ACCESS_APPROVAL_REFERENCE'] = 'TESTE SINTETICO';
        $_ENV['ACCESS_APPROVAL_VALID_UNTIL'] = gmdate('Y-m-d', time()+86400);
        $this->assertTrue(\AccessPolicy::allows('student.health'));
        $this->assertFalse(\AccessPolicy::allows('student.export'), 'Exportar DVA exige concessão nominal de saúde.');
        $_ENV['ACCESS_HEALTH_STAFF_IDS'] = (string) $employee;
        $this->assertTrue(\AccessPolicy::allows('student.export'));
        $this->assertFalse(\AccessPolicy::allows('student.contacts'));
        $_ENV['ACCESS_APPROVAL_VALID_UNTIL'] = '2000-01-01';
        $this->assertFalse(\AccessPolicy::allows('student.export'));
        $this->assertTrue(\AccessPolicy::allows('student.health'));
        $_ENV['ACCESS_APPROVAL_VALID_UNTIL'] = '2099-02-31';
        $this->assertFalse(\AccessPolicy::allows('student.export'));
        $_ENV['ACCESS_APPROVAL_VALID_UNTIL'] = gmdate('Y-m-d', time()+86400);
        unset($_ENV['ACCESS_APPROVAL_REFERENCE']);
        $this->assertFalse(\AccessPolicy::allows('student.export'));
    }

    public function testSavedPermissionsCanBeGrantedAndRevokedWithoutFallingBackToLegacy(): void
    {
        $admin = $this->insertUsuario('Admin Concessoes');
        $employee = $this->insertUsuario('Funcionario Concessoes', 'funcionario');
        $model = new \Usuario();
        $approval = ['reference' => 'Autorização 01', 'until' => gmdate('Y-m-d', time() + 86400)];
        $_ENV['ACCESS_CONTACT_STAFF_IDS'] = (string) $employee;
        $_ENV['ACCESS_APPROVAL_REFERENCE'] = $approval['reference'];
        $_ENV['ACCESS_APPROVAL_VALID_UNTIL'] = $approval['until'];
        $user = $model->buscarPorId($employee);
        \SessionManager::authenticate($user);
        $this->assertTrue(\AccessPolicy::allows('student.contacts'));
        $this->assertTrue($model->atualizar($employee, $user['nome'], $user['email'], 'funcionario', actorId: $admin,
            grants: ['student.export' => $approval], grantsRevision: \AccessPolicy::grantsRevision($user)));
        $this->assertTrue(\AccessPolicy::allows('student.export'));
        $this->assertFalse(\AccessPolicy::allows('student.contacts'));
        $stored = $model->buscarPorId($employee);
        $this->assertTrue($model->atualizar($employee, $user['nome'], $user['email'], 'funcionario', actorId: $admin,
            grants: [], grantsRevision: \AccessPolicy::grantsRevision($stored)));
        $this->assertFalse(\AccessPolicy::allows('student.export'));
        $this->assertFalse(\AccessPolicy::allows('student.contacts'));
        $this->assertTrue(\AccessPolicy::allows('student.health'));
        $this->assertFalse($model->atualizar($employee, $user['nome'], $user['email'], 'funcionario', actorId: $admin,
            grants: ['student.export' => $approval], grantsRevision: \AccessPolicy::grantsRevision($stored)));
        $this->assertSame('stale_grants', $model->lastErrorCode());
        $audit = $this->pdo->query("SELECT actor_user_id, target_user_id FROM security_audit WHERE action='user.staff_permissions_updated' ORDER BY id")->fetchAll();
        $this->assertSame(array_fill(0, 2, ['actor_user_id' => $admin, 'target_user_id' => $employee]), $audit);
    }

    public function testSavingPermissionsRequiresAnAdministratorAndRollsBackOnAuditFailure(): void
    {
        $admin = $this->insertUsuario('Admin Auditoria');
        $employee = $this->insertUsuario('Funcionario Auditoria', 'funcionario');
        $target = $this->insertUsuario('Conta Alvo', 'funcionario');
        $model = new \Usuario();
        $user = $model->buscarPorId($target);
        $approval = ['reference' => 'Autorização 02', 'until' => gmdate('Y-m-d', time() + 86400)];
        $grants = ['student.manage' => $approval];
        $revision = \AccessPolicy::grantsRevision($user);
        $this->assertFalse($model->atualizar($target, $user['nome'], $user['email'], 'funcionario', actorId: $employee,
            grants: $grants, grantsRevision: $revision));
        $this->pdo->exec("CREATE TRIGGER block_grants_audit BEFORE INSERT ON security_audit
            WHEN NEW.action='user.staff_permissions_updated' BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
        $this->assertFalse($model->atualizar($target, 'Nome alterado', $user['email'], 'funcionario', actorId: $admin,
            grants: $grants, grantsRevision: $revision));
        $this->assertSame($user, $model->buscarPorId($target));
    }

    public function testStudentManagementIncludesContactAccessAndExpiresSeparatelyFromExport(): void
    {
        $admin = $this->insertUsuario('Admin Validades');
        $model = new \Usuario();
        $approval = ['reference' => 'Autorização 03', 'until' => gmdate('Y-m-d', time() + 86400)];
        $name = 'Novo Auxiliar';
        $email = 'auxiliar.novo@example.test';
        $password = 'Caminho lunar sobre jardim 783!';
        $this->assertFalse($model->cadastrar($name, $email, $password, 'funcionario', actorId: $admin,
            grants: ['student.manage' => ['reference' => 'Autorização antiga', 'until' => '2000-01-01']]));
        $this->assertTrue($model->cadastrar($name, $email, $password, 'funcionario', actorId: $admin,
            grants: ['student.manage' => $approval, 'student.export' => $approval]));
        $user = $model->buscarPorEmail($email);
        \SessionManager::authenticate($user);
        $this->assertTrue(\AccessPolicy::allows('student.manage'));
        $this->assertTrue(\AccessPolicy::allows('student.contacts'));
        $grants = \AccessPolicy::staffGrants($user);
        $grants['student.manage']['until'] = '2000-01-01';
        $this->pdo->prepare('UPDATE usuarios SET staff_grants=? WHERE id=?')->execute([json_encode($grants), $user['id']]);
        $this->assertFalse(\AccessPolicy::allows('student.contacts'));
        $this->assertFalse(\AccessPolicy::allows('student.manage'));
        $this->assertTrue(\AccessPolicy::allows('student.export'));
    }

    public function testVersion24MigrationPreservesExistingEmployeeAccounts(): void
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        \src\Core\DatabaseInitializer::initialize($pdo);
        $pdo->exec('ALTER TABLE usuarios DROP COLUMN staff_grants;
            DELETE FROM schema_migrations WHERE version = 24; PRAGMA user_version = 23');
        $id = \test_insert_user($pdo, [
            'nome' => 'Conta Legada',
            'email' => 'legado@example.test',
            'senha' => 'hash-ficticio',
            'tipo' => 'funcionario',
        ]);
        $before = $pdo->query("SELECT * FROM usuarios WHERE id=$id")->fetch();

        \src\Core\DatabaseInitializer::initialize($pdo);
        \src\Core\DatabaseInitializer::initialize($pdo);
        \src\Core\DatabaseInitializer::assertCurrent($pdo);

        $current = $pdo->query("SELECT * FROM usuarios WHERE id=$id")->fetch();
        $this->assertArrayHasKey('staff_grants', $current);
        $this->assertNull($current['staff_grants']);
        unset($current['staff_grants']);
        $this->assertSame($before, $current);
        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
    }

    public function testEmployeeProfileAndListShowDvaWithContactsProtected(): void
    {
        $employee = $this->insertUsuario('Funcionario Perfil DVA', 'funcionario');
        $class = $this->insertTurma();
        $student = $this->insertAlunoComDva('Cadastro Minimo', '2037-12-31', $class);
        $this->pdo->prepare("UPDATE alunos SET telefone_aluno='65999991234',telefone_responsavel='65999994321' WHERE id=?")->execute([$student]);
        $this->pdo->prepare("UPDATE dvas SET observacao='OBSERVACAO_DVA' WHERE id_aluno=?")->execute([$student]);
        \SessionManager::authenticate((new \Usuario())->buscarPorId($employee));
        $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/aluno/perfil/'.$student;
        ob_start(); (new \AlunoController())->perfil((string)$student); $profile=(string)ob_get_clean();
        $_SERVER['REQUEST_URI']='/aluno';
        ob_start(); (new \AlunoController())->index(); $list=(string)ob_get_clean();
        foreach ([$profile, $list] as $html) {
            $this->assertStringContainsString('Cadastro Minimo', $html);
            foreach (['65999991234','65999994321','Contatos Registrados','Editar Dados'] as $restricted) {
                $this->assertStringNotContainsString($restricted, $html);
            }
        }
        $this->assertStringContainsString('OBSERVACAO_DVA', $profile);
        $this->assertStringContainsString('Histórico de DVAs', $profile);
        $this->assertStringContainsString('name="dva"', $list);
        $this->assertStringContainsString('dva-badge', $list);
        $this->assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action='student.profile_read'")->fetchColumn());
    }

    public function testDvaAccessIsIndependentOfContactGrantsInProfilesAndDrafts(): void
    {
        $employee = $this->insertUsuario('Funcionario Escopos Independentes', 'funcionario');
        $class = $this->insertTurma();
        $student = $this->insertAlunoComDva('Cadastro Com Escopos', '2037-12-31', $class);
        $this->pdo->prepare("UPDATE alunos SET telefone_aluno='65999991234' WHERE id=?")->execute([$student]);
        $this->pdo->prepare("UPDATE dvas SET observacao='OBSERVACAO_ESCOPO_RESTRITO' WHERE id_aluno=?")->execute([$student]);
        \SessionManager::authenticate((new \Usuario())->buscarPorId($employee));
        $_ENV['ACCESS_APPROVAL_REFERENCE'] = 'TESTE SINTETICO';
        $_ENV['ACCESS_APPROVAL_VALID_UNTIL'] = gmdate('Y-m-d', time()+86400);
        $target='aluno/dva/'.$student;
        $this->assertTrue(\FormDraft::save($employee,$target,['telefone_aluno'=>'65999991234','observacao'=>'OBSERVACAO_ESCOPO_RESTRITO']));
        $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/aluno/perfil/'.$student;
        ob_start(); (new \AlunoController())->perfil((string)$student); $health=(string)ob_get_clean();
        $this->assertStringContainsString('OBSERVACAO_ESCOPO_RESTRITO',$health);
        $this->assertStringNotContainsString('65999991234',$health);
        $_SERVER['REQUEST_URI']='/aluno/dva/'.$student;
        ob_start(); (new \AlunoController())->dva((string)$student); $dvaForm=(string)ob_get_clean();
        $this->assertStringContainsString('action="/aluno/dva/'.$student.'"',$dvaForm);
        $_SERVER['REQUEST_URI']='/dva';
        ob_start(); (new \AlunoController())->painelDva(); ob_end_clean();
        $reads=$this->pdo->query("SELECT actor_user_id,resource_type,resource_id,result,description FROM security_audit WHERE action='student.sensitive_read' ORDER BY id")->fetchAll();
        $this->assertCount(2,$reads);
        $this->assertSame($employee,(int)$reads[0]['actor_user_id']);
        $this->assertSame($student,(int)$reads[0]['resource_id']);
        foreach ($reads as $read) {
            $this->assertSame('student',$read['resource_type']);
            $this->assertSame('success',$read['result']);
            $this->assertStringContainsString('quantidade=1',$read['description']);
            $this->assertStringNotContainsString('OBSERVACAO_ESCOPO_RESTRITO',$read['description']);
            $this->assertStringNotContainsString('Cadastro Com Escopos',$read['description']);
        }
        $_GET=['form'=>$target];
        ob_start(); (new \FormDraftController())->read(); $draft=json_decode((string)ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('telefone_aluno',$draft['draft']['fields']);
        $this->assertSame('OBSERVACAO_ESCOPO_RESTRITO',$draft['draft']['fields']['observacao']);
        $_ENV['ACCESS_CONTACT_STAFF_IDS']=(string)$employee;
        $_GET=[];
        ob_start(); (new \AlunoController())->perfil((string)$student); $contacts=(string)ob_get_clean();
        $this->assertStringContainsString('65999991234',$contacts);
        $this->assertStringContainsString('OBSERVACAO_ESCOPO_RESTRITO',$contacts);
        $this->assertFalse(\AccessPolicy::allows('student.export'));
        $basic=(new \Aluno())->paginate([],1,15,null,false,false)['items'][0];
        foreach (['telefone_aluno','telefone_responsavel','data_vencimento','dva_status','dva_observacao'] as $field) {
            $this->assertArrayNotHasKey($field,$basic);
        }
    }
}
