<?php

declare(strict_types=1);

namespace Tests\Integration;

use DOMDocument;
use DOMXPath;
use Tests\Support\DatabaseTestCase;

require_once ROOT_PATH . '/src/Controllers/DashboardController.php';

final class DashboardUsabilidadeTest extends DatabaseTestCase
{
    /** @var array<string,mixed> */
    private array $previousGet;
    /** @var array<string,mixed> */
    private array $previousServer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousGet = $_GET;
        $this->previousServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_GET = $this->previousGet;
        $_SERVER = $this->previousServer;
        parent::tearDown();
    }

    public function testBuscaEncontraAlunoForaDaAmostraComNomeSemAcento(): void
    {
        $this->authenticate();
        $class = $this->insertTurma();
        for ($number = 1; $number <= 12; $number++) {
            $this->student('Aluno Amostra ' . $number, $class);
        }
        $target = $this->student('Zélia Fora da Amostra', $class);
        $sample = (new \Painel())->alunosPorStatus();
        $this->assertNotContains($target, array_column($sample[\DvaStatus::SEM_DVA], 'id'));

        $xpath = $this->dashboard(['q' => 'zelia']);
        $this->assertSame(1, $xpath->query('//*[@id="dashboard-search-results"]//tbody/tr')->length);
        $this->assertSame('Zélia Fora da Amostra', $xpath->evaluate('string(//*[@id="dashboard-search-results"]//tbody/tr/td[1]/a)'));
    }

    public function testListaEPainelEncontramMesmosAlunosSemAcentoEPreservamFiltros(): void
    {
        $class = $this->insertTurma();
        $otherClass = $this->insertTurma('Outra turma');
        $target = $this->student('José Álvares', $class);
        $this->student('José Álvares', $class, '2010-01-01', false);
        $this->student('José Álvares', $otherClass);
        $this->student('José Álvares vigente', $class, '2010-01-01', true, '2099-12-31');
        $literal = $this->student('João % _ literal', $class);
        $model = new \Aluno();
        $dashboard = new \Painel();
        foreach (['jose alvares' => [$target], "JOSE\u{0301} A\u{0301}LVARES" => [$target], '%' => [$literal], '_' => [$literal], "' OR 1=1 --" => []] as $query => $expected) {
            $filters = ['q' => $query, 'ativo' => '1', 'turma' => (string) $class, 'dva' => 'sem_dva'];
            $list = $model->paginate($filters, 1, 15);
            $panel = $dashboard->buscarAlunos($filters, 1, 15);
            $this->assertSame($expected, array_column($list['items'], 'id'), 'A lista deve aceitar nome sem acento e filtros combinados.');
            $this->assertSame($expected, array_column($panel['items'], 'id'));
            $this->assertSame(count($expected), $list['total']);
            $this->assertSame($list['total'], $panel['total']);
        }
        $this->assertSame('josé álvares', $this->pdo->query('SELECT nome_normalizado FROM alunos WHERE id=' . $target)->fetchColumn());
    }

    public function testBuscaSemAcentoPaginaAntesDeRetornarDadosEmAmbasAsListas(): void
    {
        $class = $this->insertTurma();
        $expected = [];
        for ($number = 1; $number <= 18; $number++) {
            $id = $this->student(sprintf('José Álvares %02d', $number), $class);
            if ($number > 15) { $expected[] = $id; }
        }
        $filters = ['q' => 'jose alvares', 'ativo' => '1'];
        $list = (new \Aluno())->paginate($filters, 2, 15);
        $dashboard = (new \Painel())->buscarAlunos($filters, 2, 15);
        $this->assertSame(18, $list['total']);
        $this->assertSame(2, $list['pages']);
        $this->assertSame($expected, array_column($list['items'], 'id'));
        $this->assertSame($expected, array_column($dashboard['items'], 'id'));
        $this->assertArrayNotHasKey('telefone_aluno', $dashboard['items'][0]);
        $this->assertArrayNotHasKey('telefone_responsavel', $dashboard['items'][0]);
    }

    public function testFuncaoDeBuscaDeterministicaPermaneceNaConexaoSemRedefinirCursorAtivo(): void
    {
        $class = $this->insertTurma();
        $target = $this->student('João registrado', $class);
        $this->student('João segundo', $class);
        $model = new \Aluno();
        $this->assertSame(2, $model->paginate(['q' => 'joao'])['total']);
        $flags = $this->pdo->query("SELECT flags FROM pragma_function_list WHERE name='gse_student_search' AND narg=1")->fetchColumn();
        $this->assertNotFalse($flags);
        $this->assertSame(0x800, ((int) $flags) & 0x800, 'A normalização deve estar registrada como função determinística.');
        $cursor = $this->pdo->query('SELECT gse_student_search(nome_normalizado) FROM alunos ORDER BY id');
        $this->assertSame('joao registrado', $cursor->fetchColumn());
        $this->assertSame(2, $model->contar(['q' => 'joao']));
        $this->assertSame(2, (new \Painel())->buscarAlunos(['q' => 'joao'])['total']);
        $this->assertSame('joao segundo', $cursor->fetchColumn());
        $cursor->closeCursor();

        $original = $this->pdo;
        $second = new \PDO('sqlite:' . $_ENV['DB_PATH'], null, null, [\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        try {
            \Model::setConexao($second);
            $this->assertSame([$target], array_column((new \Aluno())->paginate(['q' => 'joao registrado'])['items'], 'id'));
        } finally {
            \Model::setConexao($original);
        }
    }

    public function testHomonimosExibemNascimentoTurmaESituacaoInclusiveInativo(): void
    {
        $this->authenticate();
        $classA = $this->insertTurma('7 Ano A');
        $classB = $this->insertTurma('7 Ano B');
        $this->student('José Silva', $classA, '2010-02-03');
        $this->student('José Silva', $classB, '2009-04-05', false);

        $xpath = $this->dashboard(['q' => 'jose silva']);
        $rows = $xpath->query('//*[@id="dashboard-search-results"]//tbody/tr');
        $this->assertCount(2, $rows);
        $this->assertStringContainsString('7 Ano A', $rows->item(0)->textContent);
        $this->assertStringContainsString('03/02/2010', $rows->item(0)->textContent);
        $this->assertStringContainsString('Ativo', $rows->item(0)->textContent);
        $this->assertStringContainsString('7 Ano B', $rows->item(1)->textContent);
        $this->assertStringContainsString('05/04/2009', $rows->item(1)->textContent);
        $this->assertStringContainsString('Inativo', $rows->item(1)->textContent);
    }

    public function testFiltrosSaoAplicadosAntesDaPaginacaoEPreservadosNosLinks(): void
    {
        $this->authenticate();
        $class = $this->insertTurma('Filtro A');
        $otherClass = $this->insertTurma('Filtro B');
        for ($number = 1; $number <= 17; $number++) {
            $this->student(sprintf('Aluno Painel %02d', $number), $class);
        }
        $this->student('Aluno Painel outra turma', $otherClass);
        $this->student('Aluno Painel inativo', $class, '2010-01-01', false);
        $this->student('Aluno Painel vigente', $class, '2010-01-01', true, '2099-12-31');
        $filters = ['q' => 'Aluno Painel', 'ativo' => '1', 'turma' => (string) $class, 'dva' => 'sem_dva'];

        $xpath = $this->dashboard($filters);
        $this->assertSame(15, $xpath->query('//*[@id="dashboard-search-results"]//tbody/tr')->length);
        $this->assertStringContainsString('17 aluno(s)', $xpath->evaluate('string(//*[@id="dashboard-search-results"])'));
        $next = $xpath->evaluate('string(//*[@id="dashboard-search-results"]//a[@rel="next"]/@href)');
        parse_str((string) parse_url($next, PHP_URL_QUERY), $query);
        $this->assertSame($filters + ['page' => '2'], $query);

        $second = $this->dashboard($query);
        $this->assertSame(2, $second->query('//*[@id="dashboard-search-results"]//tbody/tr')->length);
        $this->assertSame('Aluno Painel 16', $second->evaluate('string(//*[@id="dashboard-search-results"]//tbody/tr[1]/td[1]/a)'));
        $last = $this->dashboard($filters + ['page' => '999999999']);
        $this->assertSame(2, $last->query('//*[@id="dashboard-search-results"]//tbody/tr')->length);
    }

    public function testPesquisaTrataCoringasEInjecaoComoTexto(): void
    {
        $this->authenticate();
        $class = $this->insertTurma();
        $this->student('Aluno com % literal', $class);
        $this->student('Aluno com _ literal', $class);
        $this->student('Outro aluno', $class);

        foreach (['%' => 'Aluno com % literal', '_' => 'Aluno com _ literal'] as $query => $name) {
            $xpath = $this->dashboard(['q' => $query]);
            $this->assertSame(1, $xpath->query('//*[@id="dashboard-search-results"]//tbody/tr')->length);
            $this->assertSame($name, $xpath->evaluate('string(//*[@id="dashboard-search-results"]//tbody/tr/td[1]/a)'));
        }
        $injected = $this->dashboard(['q' => "' OR 1=1 --", 'turma' => '1 OR 1=1', 'page' => '1; DROP TABLE alunos']);
        $this->assertSame(0, $injected->query('//*[@id="dashboard-search-results"]//tbody/tr')->length);
        $this->assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM alunos')->fetchColumn());
    }

    public function testBuscaFuncionaPorGetParaAmbosPerfisSemDadosDeContato(): void
    {
        $class = $this->insertTurma();
        $id = $this->student('Aluno perfil permitido', $class);
        $this->pdo->prepare('UPDATE alunos SET telefone_aluno=?, telefone_responsavel=? WHERE id=?')->execute(['65988887777', '65999998888', $id]);
        foreach (['administrador', 'funcionario'] as $role) {
            $this->authenticate($role);
            $xpath = $this->dashboard(['q' => 'perfil permitido']);
            $this->assertSame('get', $xpath->evaluate('string(//form[@id="dashboard-search-form"]/@method)'));
            $this->assertSame('q', $xpath->evaluate('string(//form[@id="dashboard-search-form"]//input[@type="search"]/@name)'));
            $this->assertSame('Buscar alunos', $xpath->evaluate('string(//form[@id="dashboard-search-form"]//button[@type="submit"])'));
            $this->assertSame(1, $xpath->query('//*[@id="dashboard-search-results"]//tbody/tr')->length);
            $content = $xpath->evaluate('string(//*[@id="dashboard-search-results"])');
            $this->assertStringNotContainsString('65988887777', $content);
            $this->assertStringNotContainsString('65999998888', $content);
        }
    }

    public function testCamposMalformadosNaoQuebramPesquisaNemRenderizamHtml(): void
    {
        $this->authenticate();
        $this->student('Aluno seguro', $this->insertTurma());
        $xpath = $this->dashboard(['q' => ['segredo'], 'ativo' => ['1'], 'turma' => ['1'], 'dva' => ['sem_dva'], 'page' => ['2']]);
        $this->assertSame(1, $xpath->query('//*[@id="dashboard-search-results"]//tbody/tr')->length);
        $xss = $this->dashboard(['q' => '"><img src=x onerror=alert(1)>']);
        $this->assertSame(0, $xss->query('//*[@id="dashboard-search-form"]//img')->length);
        $this->assertSame('"><img src=x onerror=alert(1)>', $xss->evaluate('string(//*[@id="dashboard-search-input"]/@value)'));
    }

    public function testAlertaDeCertidoesDirecionaAoFiltroDeVencimento(): void
    {
        $actor = $this->authenticate();
        $certificates = new \Certidao();
        $certificates->saveOption('fornecedor', null, 'Fornecedor fictício', true, $actor);
        $certificates->saveOption('tipo', null, 'Documento fictício', true, $actor);
        $supplier = (int) $certificates->options('fornecedor')[0]['id'];
        $type = (int) $certificates->options('tipo')[0]['id'];
        foreach ([[0, 1], [1, 0]] as [$archived, $active]) {
            $this->pdo->prepare('INSERT INTO certidoes(id_fornecedor,id_tipo_certidao,data_emissao,data_vencimento,arquivado,status) VALUES(?,?,?,?,?,?)')
                ->execute([$supplier, $type, '1999-01-01', '2000-01-01', $archived, $active]);
        }
        $today = (new \CertidaoStatus())->today();
        $this->pdo->prepare('INSERT INTO certidoes(id_fornecedor,id_tipo_certidao,data_emissao,data_vencimento,arquivado,status) VALUES(?,?,?,?,0,1)')
            ->execute([$supplier, $type, '1999-01-01', $today]);
        $xpath = $this->dashboard(['q' => '']);
        $this->assertSame(\url('certidao?validade=vence_hoje'), $xpath->evaluate('string(//*[contains(@class,"dashboard-alert-content")]//a/@href)'));
    }

    public function testCardDvaAVencerAbreFiltroCorrespondenteASuaContagem(): void
    {
        $this->authenticate();
        $class = $this->insertTurma();
        $today = new \DateTimeImmutable((new \DvaStatus())->today());
        $this->student('Vence hoje', $class, '2010-01-01', true, $today->format('Y-m-d'));
        $this->student('Vence amanhã', $class, '2010-01-01', true, $today->modify('+1 day')->format('Y-m-d'));
        $xpath = $this->dashboard(['q' => '']);
        $link = $xpath->query('//a[contains(@class,"dashboard-number-card") and contains(@class,"due")]')->item(0);
        $this->assertSame(\url('dva?dva=a_vencer'), $xpath->evaluate('string(@href)', $link));
        $this->assertSame('1', $xpath->evaluate('string(strong)', $link));
    }

    public function testLimitesDaBuscaLimitamLeituraDaPaginaEMantemTotalCompleto(): void
    {
        $class = $this->insertTurma();
        for ($number = 1; $number <= 55; $number++) {
            $this->student(sprintf('Limite aluno %02d', $number), $class);
        }
        $first = (new \Painel())->buscarAlunos(['q' => 'limite'], 0, PHP_INT_MAX);
        $last = (new \Painel())->buscarAlunos(['q' => 'limite'], PHP_INT_MAX, PHP_INT_MAX);
        $this->assertSame(55, $first['total']);
        $this->assertCount(50, $first['items']);
        $this->assertSame(1, $first['page']);
        $this->assertSame(2, $last['page']);
        $this->assertCount(5, $last['items']);
        $this->assertSame('Limite aluno 51', $last['items'][0]['nome_completo']);
    }

    /** @param array<string,mixed> $query */
    private function dashboard(array $query): DOMXPath
    {
        $_GET = $query;
        $_SERVER['REQUEST_URI'] = '/dashboard';
        ob_start();
        try {
            (new \DashboardController())->index();
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        $this->assertSame(1, $xpath->query('//*[@id="dashboard-search-results"]')->length, 'A busca deve renderizar resultados completos no painel.');
        return $xpath;
    }

    private function authenticate(string $role = 'administrador'): int
    {
        $id = $this->insertUsuario('Painel ' . $role, $role);
        $_SESSION = ['usuario_id' => $id, 'auth_version' => 1];
        return $id;
    }

    private function student(string $name, int $class, string $birth = '2010-01-01', bool $active = true, ?string $expiration = null): int
    {
        $this->pdo->prepare('INSERT INTO alunos(nome_completo,nome_normalizado,data_nascimento,id_turma,ativo) VALUES(?,?,?,?,?)')
            ->execute([$name, \src\Core\TextNormalizer::comparisonKey($name), $birth, $class, $active ? 1 : 0]);
        $id = (int) $this->pdo->lastInsertId();
        if ($expiration !== null) {
            $this->pdo->prepare('INSERT INTO dvas(id_aluno,data_vencimento) VALUES(?,?)')->execute([$id, $expiration]);
        }
        return $id;
    }
}
