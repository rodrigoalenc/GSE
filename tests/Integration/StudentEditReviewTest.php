<?php
declare(strict_types=1);
namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;
require_once ROOT_PATH . '/src/Controllers/AlunoController.php';

final class StudentEditReviewTest extends DatabaseTestCase
{
    protected function tearDown(): void { $_POST=[]; $_GET=[]; unset($_SERVER['REQUEST_METHOD'],$_SERVER['REQUEST_URI']); parent::tearDown(); }

    public function testStaleEditShowsBothVersionsAndRequiresReviewBeforeReusingTheCurrentRevision(): void
    {
        $admin=$this->insertUsuario();
        \SessionManager::authenticate((new \Usuario())->buscarPorId($admin));
        $class=$this->insertTurma();
        $id=$this->insertAlunoComDva('Pessoa Inicial','2027-12-31',$class);
        $revision=(int)$this->pdo->query('SELECT revisao FROM alunos WHERE id='.$id)->fetchColumn();
        $this->pdo->exec("UPDATE alunos SET nome_completo='Pessoa Atual',nome_normalizado='pessoa atual' WHERE id=".$id);
        $_SERVER['REQUEST_METHOD']='POST'; $_SERVER['REQUEST_URI']='/aluno/editar/'.$id;
        $_POST=['revisao'=>(string)$revision,'nome_completo'=>'Pessoa Tentativa','data_nascimento'=>'2010-05-10','id_turma'=>(string)$class,'telefone_aluno'=>'65999990000','telefone_responsavel'=>''];
        ob_start(); (new \AlunoController())->editar((string)$id); $html=ob_get_clean();
        $this->assertStringContainsString('data-conflict-review',$html);
        $this->assertStringContainsString('Pessoa Atual',$html);
        $this->assertStringContainsString('Pessoa Tentativa',$html);
        $this->assertStringContainsString('name="confirmar_revisao"',$html);
        $this->assertSame('Pessoa Atual',$this->pdo->query('SELECT nome_completo FROM alunos WHERE id='.$id)->fetchColumn());
        preg_match('/name="_conflict_token" value="([a-f0-9]{32})"/',$html,$token);
        $this->assertNotEmpty($token[1] ?? '');
        $_POST['_conflict_token']=$token[1];
        $_POST['revisao']=(string)$this->pdo->query('SELECT revisao FROM alunos WHERE id='.$id)->fetchColumn();
        ob_start(); (new \AlunoController())->editar((string)$id); $rejected=ob_get_clean();
        $this->assertStringContainsString('Confira a comparação e marque',$rejected);
        $this->assertSame('Pessoa Atual',$this->pdo->query('SELECT nome_completo FROM alunos WHERE id='.$id)->fetchColumn());
    }

    public function testRestoringAnOldDraftCannotOverwriteCurrentDataWithoutComparison(): void
    {
        $admin=$this->insertUsuario(); \SessionManager::authenticate((new \Usuario())->buscarPorId($admin));
        $class=$this->insertTurma(); $id=$this->insertAlunoComDva('Pessoa Atual','2027-12-31',$class);
        $revision=(int)$this->pdo->query('SELECT revisao FROM alunos WHERE id='.$id)->fetchColumn();
        $this->pdo->exec("UPDATE alunos SET telefone_aluno='65999991111' WHERE id=".$id);
        $_SERVER['REQUEST_METHOD']='POST'; $_SERVER['REQUEST_URI']='/aluno/editar/'.$id;
        $_POST=['revisao'=>(string)($revision+1),'_draft_source_revision'=>(string)$revision,'nome_completo'=>'Pessoa Tentativa','data_nascimento'=>'2010-05-10','id_turma'=>(string)$class,'telefone_aluno'=>'65999990000','telefone_responsavel'=>''];
        ob_start(); (new \AlunoController())->editar((string)$id); $html=ob_get_clean();
        $this->assertStringContainsString('data-conflict-review',$html);
        $this->assertStringContainsString('65999991111',$html);
        $this->assertStringContainsString('65999990000',$html);
        $this->assertSame('65999991111',$this->pdo->query('SELECT telefone_aluno FROM alunos WHERE id='.$id)->fetchColumn());
    }

    public function testReviewLimitPreservesTheStudentIdsOfTheRemainingEntries(): void
    {
        $admin=$this->insertUsuario(); \SessionManager::authenticate((new \Usuario())->buscarPorId($admin));
        $class=$this->insertTurma();
        for($i=0;$i<11;$i++) {
            $id=$this->insertAlunoComDva('Pessoa '.$i,'2027-12-31',$class);
            $this->pdo->exec("UPDATE alunos SET telefone_aluno='65999991111' WHERE id=".$id);
            $_SERVER['REQUEST_METHOD']='POST'; $_SERVER['REQUEST_URI']='/aluno/editar/'.$id;
            $_POST=['revisao'=>'1','nome_completo'=>'Pessoa Tentativa '.$i,'data_nascimento'=>'2010-05-10','id_turma'=>(string)$class,'telefone_aluno'=>'65999990000','telefone_responsavel'=>''];
            ob_start(); (new \AlunoController())->editar((string)$id); ob_end_clean();
        }
        $this->assertCount(10,$_SESSION['student_edit_reviews']);
        $this->assertArrayHasKey($id,$_SESSION['student_edit_reviews']);
        $this->assertArrayNotHasKey(0,$_SESSION['student_edit_reviews']);
    }
}
