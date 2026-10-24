<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AuditPresentationTest extends TestCase
{
    private mixed $previousTimezoneSetting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTimezoneSetting = $_ENV['APP_TIMEZONE'] ?? null;
        $_ENV['APP_TIMEZONE'] = 'America/Campo_Grande';
    }

    protected function tearDown(): void
    {
        if ($this->previousTimezoneSetting === null) {
            unset($_ENV['APP_TIMEZONE']);
        } else {
            $_ENV['APP_TIMEZONE'] = $this->previousTimezoneSetting;
        }
        parent::tearDown();
    }

    public function testKnownEventsResourcesAndResultsHavePortugueseLabels(): void
    {
        $this->assertSame('Cadastrar Aluno', \AuditPresentation::actionLabel('student.created'));
        $this->assertSame('Editar Aluno', \AuditPresentation::actionLabel('student.updated'));
        $this->assertSame('Transferir Aluno', \AuditPresentation::actionLabel('student.transferred'));
        $this->assertSame('Retornar Aluno à Escola', \AuditPresentation::actionLabel('student.returned_from_passive'));
        $this->assertSame('Passivo - Retorno à Escola', \AuditPresentation::actionLabel('passive.student_returned'));
        $this->assertSame('Login', \AuditPresentation::actionLabel('login.success'));
        $this->assertSame('Usuario - Criar', \AuditPresentation::actionLabel('user.created'));
        $this->assertSame('Pedido - Cadastrar Produto', \AuditPresentation::actionLabel('contrato.item_created'));
        $this->assertSame('Aluno', \AuditPresentation::resourceLabel('student'));
        $this->assertSame('Concluído', \AuditPresentation::resultLabel('success'));
        $this->assertSame('Falhou', \AuditPresentation::resultLabel('failure'));
        $this->assertSame('Bloqueado', \AuditPresentation::resultLabel('blocked'));
    }

    public function testUnknownCodesHaveReadableFallbackLabels(): void
    {
        $this->assertSame('Evento não identificado', \AuditPresentation::actionLabel('future.custom_event'));
        $this->assertSame('Registro', \AuditPresentation::resourceLabel('future_resource'));
        $this->assertSame('Resultado não identificado', \AuditPresentation::resultLabel('future_result'));
    }

    public function testLegacyTechnicalDescriptionIsTranslatedAtPresentationTime(): void
    {
        $this->assertSame(
            'Item cadastrado no pedido.',
            \AuditPresentation::description('contrato.item_created', 'Módulo 5: item_created')
        );
        $this->assertSame(
            'Pedido cadastrado.',
            \AuditPresentation::description('contrato.created', 'Módulo 5: created')
        );
    }

    public function testCustomDescriptionsKeepBusinessDetailsAndUnknownEventsRemainIntact(): void
    {
        $description = 'Anterior: 01/10/2026; nova: 14/10/2026; motivo: Correção de lançamento & conferência.';
        $this->assertSame($description, \AuditPresentation::description('contrato.sheet_billed', $description));
        $this->assertSame($description, \AuditPresentation::description('future.custom_event', $description));
        $this->assertSame(
            'Módulo 5: detalhe legado desconhecido',
            \AuditPresentation::description('future.custom_event', 'Módulo 5: detalhe legado desconhecido')
        );
    }

    public function testLegacyContractDescriptionsStillProduceContextualPedidoPresentation(): void
    {
        $legacy = [
            'action'=>'contrato.created', 'result'=>'success', 'description'=>'Contrato cadastrado.',
            'resource_type'=>'contrato', 'resource_id'=>42, 'resource_name'=>'Contrato da escola',
        ];
        $entry = \AuditPresentation::logEntry($legacy);
        $this->assertSame('Pedido - Cadastrar', $entry['action']);
        $this->assertSame("Novo pedido 'Contrato da escola' cadastrado.", $entry['details']);
        $this->assertSame('contrato.created', $legacy['action']);
        $this->assertSame('Contrato cadastrado.', $legacy['description']);
        $this->assertSame('Pedido', \AuditPresentation::resourceLabel('contrato'));
        $this->assertSame('Nota do pedido', \AuditPresentation::resourceLabel('folha'));
        $this->assertSame('Item do pedido', \AuditPresentation::resourceLabel('produto'));
        $this->assertSame('Pedido cadastrado.', \AuditPresentation::description('contrato.created', 'Contrato cadastrado.'));
        $this->assertSame('Nota criada no pedido.', \AuditPresentation::description('contrato.sheet_created', 'Folha criada no contrato.'));
    }

    public function testSheetAndProductHistoryUsesNotaAndPedidoWithoutChangingCustomEvidence(): void
    {
        $sheet = \AuditPresentation::logEntry([
            'action'=>'contrato.deleted', 'result'=>'success', 'description'=>'Módulo 5: deleted',
            'resource_type'=>'folha', 'resource_id'=>7, 'resource_number'=>2, 'resource_parent_id'=>42,
        ]);
        $this->assertSame('Pedido - Excluir Nota', $sheet['action']);
        $this->assertSame('Excluiu a nota 2 do pedido #42.', $sheet['details']);
        $product = \AuditPresentation::logEntry([
            'action'=>'contrato.item_created', 'result'=>'success', 'description'=>'Item cadastrado no contrato.',
            'resource_type'=>'produto', 'resource_id'=>8, 'resource_name'=>'Papel', 'resource_number'=>2, 'resource_parent_id'=>42,
        ]);
        $this->assertSame("Adicionou produto 'Papel' na nota 2 do pedido #42.", $product['details']);
        $custom = 'Contrato assinado; folha do contrato anexada conforme documento antigo.';
        $this->assertSame($custom, \AuditPresentation::description('contrato.updated', $custom));
        $this->assertSame('Contrato cadastrado.', \AuditPresentation::description('future.event', 'Contrato cadastrado.'));
    }

    public function testReportDescriptionTranslatesFiltersWithoutLosingClassOrResultCount(): void
    {
        $this->assertSame(
            'Filtros: turma #37; DVA a vencer; alunos inativos; total de resultados: 109.',
            \AuditPresentation::description(
                'relatorio.pdf_generated',
                'Filtros turma=37; dva=a_vencer; ativo=0; total=109'
            )
        );
        $this->assertSame(
            'Filtros: turma todas; DVA sem DVA; alunos ativos; total de resultados: 0.',
            \AuditPresentation::description(
                'relatorio.csv_generated',
                'Filtros turma=; dva=sem_dva; ativo=1; total=0'
            )
        );
        $unknownFormat = 'Filtros turma=37; dva=novo_estado; ativo=0; total=109; observação: conferir.';
        $this->assertSame($unknownFormat, \AuditPresentation::description('relatorio.pdf_generated', $unknownFormat));
    }

    public function testBackupDescriptionsKeepTheirOriginalFileIdentifier(): void
    {
        $filename = 'backup_20261014_020304.sqlite';
        $this->assertSame(
            'Cópia de segurança do banco de dados criada: ' . $filename,
            \AuditPresentation::description('backup.created', 'Snapshot SQLite criado: ' . $filename)
        );
        $this->assertSame(
            'Transferência da cópia de segurança do banco de dados autorizada: ' . $filename,
            \AuditPresentation::description('backup.downloaded', 'Download de snapshot SQLite autorizado: ' . $filename)
        );

        $created = \AuditPresentation::logEntry([
            'action' => 'backup.created', 'result' => 'success',
            'description' => 'Snapshot SQLite criado: ' . $filename,
        ]);
        $downloaded = \AuditPresentation::logEntry([
            'action' => 'backup.downloaded', 'result' => 'success',
            'description' => 'Download de snapshot SQLite autorizado: ' . $filename,
        ]);
        $this->assertSame('Sistema', $created['action']);
        $this->assertSame('Criou backup manual via painel.', $created['details']);
        $this->assertSame('Sistema', $downloaded['action']);
        $this->assertSame('Download do backup: ' . $filename, $downloaded['details']);
    }

    public function testDateTimeConvertsUtcToConfiguredTimezoneWithoutApplyingServerTimezone(): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('America/Santiago');

        try {
            $this->assertSame('13/10/2026 22:03:04', \AuditPresentation::dateTime('2026-10-14 02:03:04'));
            $this->assertSame('29/02/2024 19:59:59', \AuditPresentation::dateTime('2024-02-29 23:59:59'));
            $_ENV['APP_TIMEZONE'] = 'America/Sao_Paulo';
            $this->assertSame('13/10/2026 23:03:04', \AuditPresentation::dateTime('2026-10-14 02:03:04'));
            $_ENV['APP_TIMEZONE'] = 'timezone-inexistente';
            $this->assertSame('13/10/2026 22:03:04', \AuditPresentation::dateTime('2026-10-14 02:03:04'));
            $this->assertSame('2026-02-30 02:03:04', \AuditPresentation::dateTime('2026-02-30 02:03:04'));
            $this->assertSame('data legada inválida', \AuditPresentation::dateTime('data legada inválida'));
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    public function testLogEntryShowsUserAndPreservesDescriptionForEscapingByView(): void
    {
        $name = 'Maria <script>alert("nome")</script>';
        $description = 'Conferência "final" & <img src=x onerror=alert(1)>.';
        $entry = \AuditPresentation::logEntry([
            'action' => 'student.updated', 'result' => 'success', 'actor_user_id' => 7,
            'actor_name' => $name, 'occurred_at' => '2026-10-14 02:03:04',
            'description' => $description,
        ]);

        $this->assertSame('Editar Aluno', $entry['action']);
        $this->assertSame($name, $entry['user']);
        $this->assertStringContainsString($description, $entry['details']);
        $this->assertSame('13/10/2026 22:03:04', $entry['date']);
        $this->assertSame('acao-editar', $entry['action_class']);
    }

    public function testLogEntryDistinguishesMissingUserFromSystemActor(): void
    {
        $entry = ['action' => 'login.success', 'actor_user_id' => 900001, 'actor_name' => null];
        $this->assertSame('Usuário #900001', \AuditPresentation::logEntry($entry)['user']);
        $entry['actor_user_id'] = null;
        $this->assertSame('Sistema', \AuditPresentation::logEntry($entry)['user']);
        $entry['action'] = 'user.initial_admin_created';
        $this->assertSame('Sistema', \AuditPresentation::logEntry($entry)['user']);
    }

    public function testLogEntryUsesAvailableNamesInBusinessDetails(): void
    {
        $student = \AuditPresentation::logEntry([
            'action' => 'student.created', 'result' => 'success',
            'description' => 'Aluno cadastrado.', 'resource_name' => 'Aluno Localizado', 'resource_id' => 42,
        ]);
        $this->assertSame('Novo aluno: Aluno Localizado (ID: 42)', $student['details']);

        $user = \AuditPresentation::logEntry([
            'action' => 'user.created', 'result' => 'success', 'description' => 'Conta criada.',
            'target_name' => 'Conta Localizada', 'target_user_id' => 18,
        ]);
        $this->assertSame('Criou: Conta Localizada', $user['details']);
    }

    public function testLogEntryKeepsUnknownEventAndFailureEvidence(): void
    {
        $unknown = \AuditPresentation::logEntry([
            'action' => 'legacy.custom', 'result' => 'success',
            'description' => 'Detalhe legado preservado.',
        ]);
        $this->assertStringContainsString('Detalhe legado preservado.', $unknown['details']);
        $this->assertStringContainsString('legacy.custom', $unknown['details']);

        foreach (['failure' => 'Falhou', 'blocked' => 'Bloqueado'] as $result => $label) {
            $entry = \AuditPresentation::logEntry([
                'action' => 'student.created', 'result' => $result,
                'description' => 'Motivo: dados incompletos.', 'resource_name' => 'Aluno Teste',
            ]);
            $this->assertStringContainsString('Motivo: dados incompletos.', $entry['details']);
            $this->assertStringContainsString($label, $entry['details']);
            $this->assertStringNotContainsString('Concluído', $entry['details']);
            $this->assertStringNotContainsString('Cadastrou o aluno', $entry['details']);
        }

        $failedContract = \AuditPresentation::logEntry([
            'action' => 'contrato.created', 'result' => 'failure',
            'description' => 'Módulo 5: created', 'resource_type' => 'contrato', 'resource_id' => 42,
        ]);
        $this->assertStringContainsString('Módulo 5: created', $failedContract['details']);
        $this->assertStringContainsString('Resultado: Falhou.', $failedContract['details']);
        $this->assertStringNotContainsString('Pedido cadastrado.', $failedContract['details']);
    }

    public function testActionClassesAreSelectedByActionLabel(): void
    {
        $this->assertSame('acao-cadastrar', \AuditPresentation::actionClass('Cadastrar Aluno'));
        $this->assertSame('acao-editar', \AuditPresentation::actionClass('Editar Aluno'));
        $this->assertSame('acao-apagar', \AuditPresentation::actionClass('Apagar Aluno'));
        $this->assertSame('acao-login', \AuditPresentation::actionClass('Login'));
        $this->assertSame('', \AuditPresentation::actionClass('Evento não identificado'));
    }
}
