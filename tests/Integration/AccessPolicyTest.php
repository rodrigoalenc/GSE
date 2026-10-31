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

    public function testDefaultDeniesSensitivePermissionsToEmployeesAndRevalidatesRevocation(): void
    {
        $employee = $this->insertUsuario('Funcionario Privacidade', 'funcionario');
        \SessionManager::authenticate((new \Usuario())->buscarPorId($employee));
        foreach (['student.health','student.contacts','student.export','student.manage','unknown'] as $permission) {
            $this->assertFalse(\AccessPolicy::allows($permission));
        }
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
        $this->assertFalse(\AccessPolicy::allows('student.export'), 'Exportar DVA também exige acesso a saúde.');
        $_ENV['ACCESS_HEALTH_STAFF_IDS'] = (string) $employee;
        $this->assertTrue(\AccessPolicy::allows('student.health'));
        $this->assertTrue(\AccessPolicy::allows('student.export'));
        $this->assertFalse(\AccessPolicy::allows('student.contacts'));
        $_ENV['ACCESS_APPROVAL_VALID_UNTIL'] = '2000-01-01';
        $this->assertFalse(\AccessPolicy::allows('student.health'));
        $_ENV['ACCESS_APPROVAL_VALID_UNTIL'] = '2099-02-31';
        $this->assertFalse(\AccessPolicy::allows('student.health'));
        $_ENV['ACCESS_APPROVAL_VALID_UNTIL'] = gmdate('Y-m-d', time()+86400);
        unset($_ENV['ACCESS_APPROVAL_REFERENCE']);
        $this->assertFalse(\AccessPolicy::allows('student.health'));
    }

    public function testEmployeeProfileAndListContainOnlyBasicSchoolFields(): void
    {
        $employee = $this->insertUsuario('Funcionario Perfil Minimo', 'funcionario');
        $class = $this->insertTurma();
        $student = $this->insertAlunoComDva('Cadastro Minimo', '2037-12-31', $class);
        $this->pdo->prepare("UPDATE alunos SET telefone_aluno='65999991234',telefone_responsavel='65999994321' WHERE id=?")->execute([$student]);
        $this->pdo->prepare("UPDATE dvas SET observacao='OBSERVACAO_RESTRITA' WHERE id_aluno=?")->execute([$student]);
        \SessionManager::authenticate((new \Usuario())->buscarPorId($employee));
        $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/aluno/perfil/'.$student;
        ob_start(); (new \AlunoController())->perfil((string)$student); $profile=(string)ob_get_clean();
        $_SERVER['REQUEST_URI']='/aluno';
        ob_start(); (new \AlunoController())->index(); $list=(string)ob_get_clean();
        foreach ([$profile, $list] as $html) {
            $this->assertStringContainsString('Cadastro Minimo', $html);
            foreach (['OBSERVACAO_RESTRITA','65999991234','65999994321','2037-12-31','31/12/2037','name="dva"','dva-badge','Histórico de DVAs'] as $restricted) {
                $this->assertStringNotContainsString($restricted, $html);
            }
        }
        $this->assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action='student.profile_read'")->fetchColumn());
    }

    public function testHealthAndContactGrantsRemainIndependentInProfilesAndDrafts(): void
    {
        $employee = $this->insertUsuario('Funcionario Escopos Independentes', 'funcionario');
        $class = $this->insertTurma();
        $student = $this->insertAlunoComDva('Cadastro Com Escopos', '2037-12-31', $class);
        $this->pdo->prepare("UPDATE alunos SET telefone_aluno='65999991234' WHERE id=?")->execute([$student]);
        $this->pdo->prepare("UPDATE dvas SET observacao='OBSERVACAO_ESCOPO_RESTRITO' WHERE id_aluno=?")->execute([$student]);
        \SessionManager::authenticate((new \Usuario())->buscarPorId($employee));
        $_ENV['ACCESS_APPROVAL_REFERENCE'] = 'TESTE SINTETICO';
        $_ENV['ACCESS_APPROVAL_VALID_UNTIL'] = gmdate('Y-m-d', time()+86400);
        $_ENV['ACCESS_HEALTH_STAFF_IDS'] = (string)$employee;
        $_ENV['ACCESS_CONTACT_STAFF_IDS'] = (string)$employee;
        $target='aluno/dva/'.$student;
        $this->assertTrue(\FormDraft::save($employee,$target,['telefone_aluno'=>'65999991234','observacao'=>'OBSERVACAO_ESCOPO_RESTRITO']));
        $_ENV['ACCESS_CONTACT_STAFF_IDS']='';
        $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/aluno/perfil/'.$student;
        ob_start(); (new \AlunoController())->perfil((string)$student); $health=(string)ob_get_clean();
        $this->assertStringContainsString('OBSERVACAO_ESCOPO_RESTRITO',$health);
        $this->assertStringNotContainsString('65999991234',$health);
        $_SERVER['REQUEST_URI']='/aluno/dva/'.$student;
        ob_start(); (new \AlunoController())->dva((string)$student); $readonly=(string)ob_get_clean();
        $this->assertStringNotContainsString('action="/aluno/dva/'.$student.'"',$readonly);
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
        $_ENV['ACCESS_HEALTH_STAFF_IDS']=''; $_ENV['ACCESS_CONTACT_STAFF_IDS']=(string)$employee;
        $_GET=[];
        ob_start(); (new \AlunoController())->perfil((string)$student); $contacts=(string)ob_get_clean();
        $this->assertStringContainsString('65999991234',$contacts);
        $this->assertStringNotContainsString('OBSERVACAO_ESCOPO_RESTRITO',$contacts);
        $this->assertFalse(\AccessPolicy::allows('student.export'));
        $_ENV['ACCESS_MANAGE_STAFF_IDS']=(string)$employee;
        $editingTarget='aluno/editar/'.$student;
        // A draft created under an older health grant must not bypass its revocation.
        $this->assertTrue(\FormDraft::save($employee,$editingTarget,['telefone_aluno'=>'65999991234','observacao'=>'OBSERVACAO_ESCOPO_RESTRITO','data_vencimento'=>'2037-12-31']));
        $_GET=['form'=>$editingTarget];
        ob_start(); (new \FormDraftController())->read(); $contactsDraft=json_decode((string)ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
        $this->assertSame('65999991234',$contactsDraft['draft']['fields']['telefone_aluno']);
        $this->assertArrayNotHasKey('observacao',$contactsDraft['draft']['fields']);
        $this->assertArrayNotHasKey('data_vencimento',$contactsDraft['draft']['fields']);
        $basic=(new \Aluno())->paginate([],1,15,null,false,false)['items'][0];
        foreach (['telefone_aluno','telefone_responsavel','data_vencimento','dva_status','dva_observacao'] as $field) {
            $this->assertArrayNotHasKey($field,$basic);
        }
    }
}
