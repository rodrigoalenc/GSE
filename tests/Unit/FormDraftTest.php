<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FormDraftTest extends TestCase
{
    protected function setUp(): void { $_SESSION = ['usuario_id' => 8]; }

    public function testDraftImplementationExists(): void
    {
        $this->assertFileExists(ROOT_PATH . '/src/Core/FormDraft.php');
    }

    public function testOnlyBusinessFieldsAreStoredAndTheUserIsIsolated(): void
    {
        require_once ROOT_PATH . '/src/Core/FormDraft.php';
        \FormDraft::save(8, 'aluno/editar/4', ['nome_completo' => 'Pessoa fictícia', 'senha' => 'secret', '_csrf_token' => 'secret', 'revisao' => '3', 'id_turma' => '2'], '3', 100);
        $draft = \FormDraft::read(8, 'aluno/editar/4', 101);
        $this->assertSame(['nome_completo' => 'Pessoa fictícia', 'id_turma' => '2'], $draft['fields']);
        $this->assertSame('3', $draft['revision']);
        $this->assertNull(\FormDraft::read(9, 'aluno/editar/4', 101));
        $this->assertStringNotContainsString('secret', json_encode($_SESSION, JSON_THROW_ON_ERROR));
    }

    public function testExpirationAndDiscardAndSessionTerminationRemoveDrafts(): void
    {
        require_once ROOT_PATH . '/src/Core/FormDraft.php';
        \FormDraft::save(8, 'aluno/criar', ['nome_completo' => 'Pessoa'], '', 100);
        $this->assertNull(\FormDraft::read(8, 'aluno/criar', 1901));
        \FormDraft::save(8, 'aluno/criar', ['nome_completo' => 'Pessoa']);
        \FormDraft::discard(8, 'aluno/criar');
        $this->assertNull(\FormDraft::read(8, 'aluno/criar'));
        \FormDraft::save(8, 'aluno/criar', ['nome_completo' => 'Pessoa']);
        \SessionManager::terminate();
        $this->assertNull(\FormDraft::read(8, 'aluno/criar'));
    }

    public function testUnknownTargetsInvalidUtf8NestedFieldsAndOversizedPayloadAreRejected(): void
    {
        require_once ROOT_PATH . '/src/Core/FormDraft.php';
        $this->assertFalse(\FormDraft::save(8, 'usuario/criar', ['nome' => 'Pessoa']));
        $this->assertFalse(\FormDraft::save(8, 'aluno/editar/0', ['nome_completo' => 'Pessoa']));
        \FormDraft::save(8, 'aluno/criar', ['nome_completo' => ['nested'], 'observacao' => "\xFF"]);
        $this->assertSame([], \FormDraft::read(8, 'aluno/criar')['fields']);
        $this->assertFalse(\FormDraft::save(8, 'contrato/criar', ['titulo' => str_repeat('a', 70000)]));
    }

    public function testDynamicProductNamesAreWhitelistedButCannotInjectControlFields(): void
    {
        require_once ROOT_PATH . '/src/Core/FormDraft.php';
        \FormDraft::save(8, 'contrato/criar', ['titulo' => 'Pedido fictício', 'folhas[0][produtos][1][nome]' => 'Papel', 'folhas[0][produtos][1][preco]' => '1.250,00', 'folhas[0][produtos][1][revisao]' => '99', 'folhas[9999][observacao]' => 'ignore']);
        $draft = \FormDraft::read(8, 'contrato/criar');
        $this->assertCount(3, $draft['fields']);
        $this->assertSame('1.250,00', $draft['fields']['folhas[0][produtos][1][preco]']);
    }

    public function testCountIsBoundedAndWritesCannotImpersonateAnotherSessionUser(): void
    {
        require_once ROOT_PATH . '/src/Core/FormDraft.php';
        $this->assertFalse(\FormDraft::save(9, 'aluno/criar', ['nome_completo' => 'Pessoa']));
        for ($i = 1; $i <= 15; $i++) { \FormDraft::save(8, 'aluno/editar/' . $i, ['nome_completo' => 'Pessoa']); }
        $this->assertCount(10, $_SESSION['form_drafts']);
        $this->assertNull(\FormDraft::read(8, 'aluno/editar/1'));
    }

    public function testSavedFormInvalidatesDraftRequestsAlreadyInFlight(): void
    {
        require_once ROOT_PATH . '/src/Core/FormDraft.php';
        $context = \FormDraft::open(8, 'aluno/criar');
        $this->assertTrue(\FormDraft::acceptsContext(8, 'aluno/criar', $context));
        $this->assertFalse(\FormDraft::acceptsContext(9, 'aluno/criar', $context));
        \FormDraft::close(8, 'aluno/criar');
        $this->assertFalse(\FormDraft::acceptsContext(8, 'aluno/criar', $context));
    }

    public function testPassiveDraftKeepsBirthDate(): void
    {
        require_once ROOT_PATH . '/src/Core/FormDraft.php';
        \FormDraft::save(8, 'passivo/criar', ['nome_completo' => 'Pessoa', 'data_nascimento' => '2010-05-10']);
        $this->assertSame('2010-05-10', \FormDraft::read(8, 'passivo/criar')['fields']['data_nascimento'] ?? null);
    }
}
