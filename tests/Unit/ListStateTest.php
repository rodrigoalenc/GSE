<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ListStateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = ['usuario_id' => 7];
        \ListState::resetRequest();
    }

    public function testSnapshotsAreImmutableScopedAndContainOnlyAllowedNormalizedFields(): void
    {
        $first = \ListState::create('passivo', [
            'q' => '  Pessoa fictícia  ',
            'caixa' => ' CX-4 ',
            'page' => '3',
            'url' => 'https://attacker.test',
            '_csrf_token' => 'secret',
        ]);
        $second = \ListState::create('passivo', ['q' => 'Outra pessoa', 'page' => '1']);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $first['token']);
        $this->assertNotSame($first['token'], $second['token']);
        $this->assertSame(['q' => 'Pessoa fictícia', 'caixa' => 'CX-4', 'page' => '3'], \ListState::restore('passivo', $first['token']));
        $this->assertNull(\ListState::restore('aluno', $first['token']));
        $this->assertStringNotContainsString('secret', json_encode($_SESSION, JSON_THROW_ON_ERROR));

        $_SESSION['usuario_id'] = 8;
        $this->assertNull(\ListState::restore('passivo', $first['token']));
    }

    public function testExpiredAndEvictedSnapshotsNeverFallBackToAnotherSearch(): void
    {
        $old = \ListState::create('aluno', ['q' => 'Primeira busca']);
        $_SESSION['list_states'][$old['token']]['accessed'] = time() - 1801;

        $this->assertNull(\ListState::restore('aluno', $old['token']));
        $this->assertTrue(\ListState::expired());

        for ($index = 1; $index <= 21; $index++) {
            \ListState::create('aluno', ['q' => 'Busca ' . $index]);
        }

        $routeStates = array_filter($_SESSION['list_states'], static fn (array $state): bool => $state['route'] === 'aluno');
        $this->assertCount(20, $routeStates);
    }

    public function testCertificateSubpagesRejectUnexpectedOrExcessiveArrays(): void
    {
        $valid = \ListState::create('certidao', ['documentos' => ['12' => '2', '30' => '4']]);
        $this->assertSame(['documentos' => [12 => 2, 30 => 4]], \ListState::restore('certidao', $valid['token']));

        $tooMany = [];
        for ($id = 1; $id <= 51; $id++) { $tooMany[(string) $id] = '1'; }
        $rejected = \ListState::create('certidao', ['documentos' => $tooMany]);
        $this->assertSame([], \ListState::restore('certidao', $rejected['token']));

        $invalid = \ListState::create('certidao', ['documentos' => ['0' => '2', '12' => '-1', 'x' => '3']]);
        $this->assertSame([], \ListState::restore('certidao', $invalid['token']));
    }

    public function testRedirectStateIsConsumedOnceAndKeepsSupportedTextOptions(): void
    {
        $state = \ListState::create('certidao/arquivadas', ['ano' => 'todos', 'busca' => 'Fornecedor']);
        \ListState::queueRedirect('certidao/arquivadas', $state['token']);

        $this->assertSame(
            ['ano' => 'todos', 'busca' => 'Fornecedor'],
            \ListState::consumeRedirect('certidao/arquivadas')
        );
        $this->assertNull(\ListState::consumeRedirect('certidao/arquivadas'));
    }
}
