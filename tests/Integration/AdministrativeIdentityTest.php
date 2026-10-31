<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class AdministrativeIdentityTest extends DatabaseTestCase
{
    public function testAdministrativeSelfEmailChangeRequiresPasswordAndInvalidatesBothOldSessions(): void
    {
        $id=$this->insertUsuario('Admin identidade');
        $model=new \Usuario();
        $before=$model->buscarPorId($id);
        $sessions=[['usuario_id'=>$id,'auth_version'=>1],['usuario_id'=>$id,'auth_version'=>1]];
        foreach ([null,'incorreta'] as $password) {
            $this->assertFalse($model->atualizar($id,$before['nome'],'novo@example.test','administrador',null,false,$id,$password,1));
            $this->assertSame('current_password_invalid',$model->lastErrorCode());
            $this->assertSame($before,$model->buscarPorId($id));
        }
        $this->assertTrue($model->atualizar($id,$before['nome'],'novo@example.test','administrador',null,false,$id,'Frase segura 2026',1));
        $after=$model->buscarPorId($id);
        $this->assertSame(2,(int)$after['session_version']);
        $this->assertSame($before['senha'],$after['senha']);
        foreach ($sessions as $session) { $_SESSION=$session; $this->assertFalse(\Auth::check()); }
        $this->assertFalse(\Auth::attempt($before['email'],'Frase segura 2026'));
        $this->assertTrue(\Auth::attempt('novo@example.test','Frase segura 2026'));
    }

    public function testReauthenticationBudgetCannotBeResetBySwitchingProfileAndAdministrativeRoutes(): void
    {
        $id=$this->insertUsuario('Admin limite');
        $model=new \Usuario();
        $before=$model->buscarPorId($id);
        for ($i=0;$i<10;$i++) {
            $result=$i%2===0
                ? $model->atualizar($id,$before['nome'],'novo@example.test','administrador',null,false,$id,'incorreta',1)
                : $model->atualizarPerfilProprio($id,1,$before['nome'],'novo@example.test','incorreta');
            $this->assertFalse($result);
        }
        try {
            $model->alterarSenha($id,'Frase segura 2026','Outra frase valida 2027');
            $this->fail('Switching to password route bypassed shared reauthentication budget.');
        } catch (\ActionLimitExceeded $exception) { $this->assertGreaterThan(0,$exception->retryAfter); }
        $this->assertSame($before,$model->buscarPorId($id));
    }

    public function testEmailChangeByAnotherAdministratorAlsoRevokesExistingSessions(): void
    {
        $actor=$this->insertUsuario('Admin autor');
        $id=$this->insertUsuario('Admin alvo');
        $model=new \Usuario();
        $before=$model->buscarPorId($id);
        $this->assertTrue($model->atualizar($id,$before['nome'],'alvo.novo@example.test','administrador',null,false,$actor));
        $_SESSION=['usuario_id'=>$id,'auth_version'=>1];
        $this->assertFalse(\Auth::check());
        $this->assertSame(2,(int)$model->buscarPorId($id)['session_version']);
    }

    public function testStaleVersionAndFailedAuditCannotCommitSelfIdentityChange(): void
    {
        $id=$this->insertUsuario('Admin concorrencia');
        $model=new \Usuario();
        $before=$model->buscarPorId($id);
        $this->assertFalse($model->atualizar($id,$before['nome'],'novo@example.test','administrador',null,false,$id,'Frase segura 2026',0));
        $this->assertSame('session_invalid',$model->lastErrorCode());
        $this->pdo->exec("CREATE TRIGGER reject_identity BEFORE INSERT ON security_audit WHEN NEW.action='user.identity_updated' BEGIN SELECT RAISE(ABORT,'forced'); END");
        $this->assertFalse($model->atualizar($id,$before['nome'],'novo@example.test','administrador',null,false,$id,'Frase segura 2026',1));
        $this->assertSame($before,$model->buscarPorId($id));
    }
}
