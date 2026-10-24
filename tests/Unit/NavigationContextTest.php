<?php
declare(strict_types=1);
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;

final class NavigationContextTest extends TestCase
{
    public function testReturnPreservesOnlyKnownFiltersInTheServerSession(): void
    {
        $_SESSION = ['usuario_id' => 7];
        $this->assertFileExists(ROOT_PATH . '/src/Core/NavigationContext.php');
        require_once ROOT_PATH . '/src/Core/NavigationContext.php';
        \NavigationContext::remember('aluno', ['q' => 'Pessoa fictícia', 'page' => '3', 'turma' => '12', 'url' => 'https://attacker.test', '_csrf_token' => 'secret']);
        $this->assertSame('aluno?q=Pessoa%20fict%C3%ADcia&page=3&turma=12', \NavigationContext::back('aluno'));
        \NavigationContext::remember('https://attacker.test', ['q' => 'x']);
        $this->assertSame('aluno?q=Pessoa%20fict%C3%ADcia&page=3&turma=12', \NavigationContext::back('aluno'));
        $this->assertSame('passivo', \NavigationContext::back('passivo'));
        $this->assertStringNotContainsString('secret', json_encode($_SESSION));
        $_SESSION['usuario_id'] = 8;
        $this->assertSame('aluno', \NavigationContext::back('aluno'));
    }

    public function testOrderListKeepsItsActualSearchAndPaginationNames(): void
    {
        $_SESSION = ['usuario_id' => 7];
        require_once ROOT_PATH . '/src/Core/NavigationContext.php';
        \NavigationContext::remember('contrato', ['busca' => 'Papelaria', 'pagina' => '3']);
        $this->assertSame('contrato?busca=Papelaria&pagina=3', \NavigationContext::back('contrato'));
    }
}
