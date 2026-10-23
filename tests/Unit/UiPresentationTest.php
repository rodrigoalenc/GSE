<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UiPresentationTest extends TestCase
{
    public function testCivilDatesDoNotDependOnIntegerTimestamps(): void
    {
        require_once ROOT_PATH . '/src/Core/UiPresentation.php';
        self::assertSame('01/01/1900', UiPresentation::civilDate('1900-01-01'));
        self::assertSame('31/12/2099', UiPresentation::civilDate('2099-12-31'));
        self::assertSame('Data inválida', UiPresentation::civilDate('2026-02-31'));
        self::assertSame('—', UiPresentation::civilDate(null));
    }

    public function testFieldErrorsOnlyLinkKnownFieldsAndEscapeAttributes(): void
    {
        require_once ROOT_PATH . '/src/Core/UiPresentation.php';
        $errors = UiPresentation::fieldErrors(['Informe um e-mail válido.', 'Este cadastro foi atualizado por outra pessoa.'], ['email' => ['e-mail']]);
        self::assertSame(['email' => ['Informe um e-mail válido.']], $errors);
        self::assertSame(' aria-invalid="true" aria-describedby="email-error"', UiPresentation::fieldAttributes($errors, 'email'));
        self::assertSame('', UiPresentation::fieldAttributes($errors, 'nome'));
    }

    public function testCivilDateStaysUnchangedAndUtcEventsUseTheSchoolTimezone(): void
    {
        $previous = $_ENV['APP_TIMEZONE'] ?? null;
        $_ENV['APP_TIMEZONE'] = 'America/Cuiaba';
        try {
            self::assertSame('22/10/2026', UiPresentation::civilDate('2026-10-22'));
            self::assertSame('21/10/2026 21:00:00', UiPresentation::localDateTime('2026-10-22 01:00:00'));
            self::assertSame('America/Cuiaba', UiPresentation::localNow()->getTimezone()->getName());
        } finally {
            if ($previous === null) { unset($_ENV['APP_TIMEZONE']); } else { $_ENV['APP_TIMEZONE'] = $previous; }
        }
    }

    public function testStudentFormLinksServerErrorsWithoutJavascriptAndEscapesMessages(): void
    {
        $editing = false;
        $possibleDuplicate = false;
        $turmas = [];
        $errors = ['Informe um nome com até 150 caracteres. <script>alert(1)</script>'];
        $data = array_fill_keys(['nome_completo', 'observacao', 'data_nascimento', 'data_vencimento', 'id_turma', 'telefone_aluno', 'telefone_responsavel'], '');
        ob_start();
        require ROOT_PATH . '/src/Views/alunos/form.php';
        $html = (string) ob_get_clean();
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($document);
        self::assertSame(1, $xpath->query('//input[@id="nome_completo"][@aria-invalid="true"][@aria-describedby="nome_completo-error"]')->length);
        self::assertSame(1, $xpath->query('//div[@role="alert"]//a[@href="#nome_completo"]')->length);
        self::assertSame(1, $xpath->query('//*[@id="nome_completo-error"]')->length);
        self::assertSame(0, $xpath->query('//script')->length);
    }

    public function testPaginationKeepsFiltersAndLimitsLinksForTenThousandPages(): void
    {
        $renderPagination = require ROOT_PATH . '/src/Views/partials/pagination.php';
        ob_start();
        $renderPagination(5000, 10000, static fn (int $page): string => '/aluno?turma=3&page=' . $page, 'Páginas de alunos');
        $html = (string) ob_get_clean();
        $document = new DOMDocument();
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        self::assertLessThanOrEqual(9, $xpath->query('//nav/a')->length);
        self::assertSame(1, $xpath->query('//a[@aria-current="page"]')->length);
        self::assertSame('5000', $xpath->query('//a[@aria-current="page"]')->item(0)->textContent);
        foreach ($xpath->query('//nav/a') as $link) {
            self::assertStringContainsString('turma=3&', $link->getAttribute('href'));
        }
        self::assertStringContainsString('page=1"', $html);
        self::assertStringContainsString('page=10000"', $html);
    }

    public function testUserPasswordErrorsHaveOneMessageInEitherFormBranch(): void
    {
        foreach ([false, true] as $edicao) {
            $usuarioId = 1;
            $editingSelf = false;
            $dados = ['nome' => '', 'email' => '', 'tipo' => 'funcionario', 'recebe_alertas_dva' => false];
            $erros = ['Use uma senha com pelo menos 15 caracteres.', 'A confirmação da senha não confere.'];
            ob_start();
            require ROOT_PATH . '/src/Views/usuarios/form.php';
            $html = (string) ob_get_clean();
            $document = new DOMDocument();
            @$document->loadHTML($html);
            $xpath = new DOMXPath($document);
            foreach (['senha', 'confirmar_senha'] as $field) {
                self::assertSame(1, $xpath->query('//*[@id="' . $field . '-error"]')->length);
                self::assertSame(1, $xpath->query('//input[@id="' . $field . '"][@aria-invalid="true"]')->length);
            }
        }
    }
}
