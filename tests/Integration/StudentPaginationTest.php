<?php

declare(strict_types=1);

namespace Tests\Integration;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatabaseTestCase;

final class StudentPaginationTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
        parent::tearDown();
    }

    /** @return array<string,array{string,int,list<int>}> */
    public static function studentSituations(): array
    {
        return [
            'todos' => ['todos', 40, [16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30]],
            'ativos' => ['1', 20, [31, 33, 35, 37, 39]],
            'inativos' => ['0', 20, [32, 34, 36, 38, 40]],
        ];
    }

    /** @param list<int> $secondPageIds */
    #[DataProvider('studentSituations')]
    public function testFollowingTheRenderedNextLinkPreservesSituationAndCombinedFilters(
        string $situation,
        int $total,
        array $secondPageIds
    ): void {
        $actor = $this->insertUsuario();
        \SessionManager::authenticate((new \Usuario())->buscarPorId($actor));
        $class = $this->insertTurma('Turma da paginação');
        $otherClass = $this->insertTurma('Outra turma');
        $insert = $this->pdo->prepare('INSERT INTO alunos
            (nome_completo,nome_normalizado,data_nascimento,id_turma,ativo) VALUES(?,?,?,?,?)');
        for ($number = 1; $number <= 40; $number++) {
            $name = sprintf('Pessoa Paginacao %02d', $number);
            $insert->execute([$name, strtolower($name), '2010-01-01', $class, $number % 2]);
        }
        // Each extra record violates exactly one of the combined filters.
        $insert->execute(['Pessoa Paginacao Outra Turma', 'pessoa paginacao outra turma', '2010-01-01', $otherClass, 1]);
        $insert->execute(['Outro nome', 'outro nome', '2010-01-01', $class, 1]);
        $withDva = $this->insertAlunoComDva('Pessoa Paginacao Com DVA', '2099-12-31', $class);
        $this->pdo->prepare('UPDATE dvas SET ativo=1 WHERE id_aluno=?')->execute([$withDva]);

        $first = $this->renderList(['ativo' => $situation, 'q' => 'Pessoa Paginacao', 'turma' => (string) $class, 'dva' => 'sem_dva']);
        $next = $first->query('//nav[@aria-label="Páginas de alunos"]/a[@rel="next"]')->item(0);
        self::assertNotNull($next, 'A primeira página deve oferecer um link para a próxima.');
        $query = [];
        parse_str((string) parse_url($next->getAttribute('href'), PHP_URL_QUERY), $query);
        $second = $this->renderList($query);

        self::assertSame((string) $total, trim($second->evaluate('string(//div[@class="hero-stats"]/span[1]/strong)')));
        self::assertSame('2', trim($second->evaluate('string(//div[@class="hero-stats"]/span[2]/strong)')));
        self::assertSame($situation, $second->evaluate('string(//select[@id="ativo"]/option[@selected]/@value)'));
        self::assertSame('Pessoa Paginacao', $query['q'] ?? null);
        self::assertSame((string) $class, $query['turma'] ?? null);
        self::assertSame('sem_dva', $query['dva'] ?? null);
        $ids = [];
        foreach ($second->query('//input[@data-student-selection-id]') as $checkbox) {
            $ids[] = (int) $checkbox->getAttribute('value');
        }
        self::assertSame($secondPageIds, $ids, 'A segunda página deve manter os registros da situação consultada.');
    }

    /** @param array<string,mixed> $query */
    private function renderList(array $query): DOMXPath
    {
        $_GET = $query;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/aluno?' . http_build_query($query);
        ob_start();
        try {
            (new \Router())->dispatch('aluno', 'GET');
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return new DOMXPath($document);
    }
}
