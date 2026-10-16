<?php

declare(strict_types=1);

namespace Tests\Integration;

use CertidaoStatus;
use Contrato;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Tests\Support\DatabaseTestCase;

final class ContratoCertidaoTest extends DatabaseTestCase
{
    private function model(string $day = '2026-10-16'): Contrato
    {
        return new Contrato(new CertidaoStatus(new DateTimeImmutable($day . ' 12:00:00', new DateTimeZone('America/Cuiaba'))));
    }

    private function supplier(string $name = 'Fornecedor fictício'): int
    {
        $this->pdo->prepare('INSERT INTO lista_fornecedores(nome,ativo) VALUES(?,1)')->execute([$name]);
        return (int) $this->pdo->lastInsertId();
    }

    private function certificate(int $supplier, string $expiration, string $type = 'Certidão Fiscal', string $state = 'corrente'): int
    {
        $this->pdo->prepare('INSERT OR IGNORE INTO lista_tipos_certidao(nome,ativo) VALUES(?,1)')->execute([$type]);
        $query = $this->pdo->prepare('SELECT id FROM lista_tipos_certidao WHERE nome=?');
        $query->execute([$type]);
        $this->pdo->prepare('INSERT INTO certidoes(id_fornecedor,id_tipo_certidao,data_emissao,data_vencimento,arquivado,status,excluido_em) VALUES(?,?,?, ?,?,?,?)')
            ->execute([$supplier, $query->fetchColumn(), '2026-01-01', $expiration,
                $state === 'arquivada' ? 1 : 0, $state === 'status_inativo' ? 0 : 1,
                $state === 'excluida' ? '2026-10-01 12:00:00' : null]);
        return (int) $this->pdo->lastInsertId();
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['pedidos', 'pedido_paginas', 'pedido_produtos', 'modulo5_operacoes', 'request_receipts', 'security_audit'] as $table) {
            $snapshot[$table] = $this->pdo->query('SELECT * FROM ' . $table . ' ORDER BY rowid')->fetchAll();
        }
        return $snapshot;
    }

    private function blocked(callable $operation): string
    {
        $before = $this->snapshot();
        try { $operation(); $this->fail('Criação aceita com certidão vencida do fornecedor.'); }
        catch (DomainException $exception) {
            $this->assertStringContainsString('certidões vencidas', $exception->getMessage());
            $this->assertSame($before, $this->snapshot(), 'Bloqueio preserva pedidos, notas, produtos, revisões, recibos e auditoria.');
            return $exception->getMessage();
        }
    }

    public function testSimpleOrderIsBlockedAndMessageIdentifiesEveryExpiredCertificate(): void
    {
        $actor = $this->insertUsuario();
        $supplier = $this->supplier();
        $this->certificate($supplier, '2026-10-14', 'Fiscal Estadual');
        $this->certificate($supplier, '2026-10-15', 'Fiscal Municipal');
        $model = $this->model();
        $message = $this->blocked(fn () => $model->create('Pedido bloqueado', '100,00', (string) $supplier, $actor, str_repeat('a', 32)));
        $this->assertStringContainsString('Fiscal Estadual', $message);
        $this->assertStringContainsString('Fiscal Municipal', $message);
        $this->assertStringContainsString('Fornecedor fictício', $message);
        $this->assertStringContainsString('14/10/2026', $message);
        $this->assertStringContainsString('15/10/2026', $message);
    }

    public function testDetailedOrderCannotPartiallyCreateNotesOrProducts(): void
    {
        $actor = $this->insertUsuario();
        $supplier = $this->supplier();
        $this->certificate($supplier, '2026-10-15');
        $this->blocked(fn () => $this->model()->createDetailed('Pedido completo bloqueado', '100,00', (string) $supplier,
            [['observacao' => 'Nota fictícia', 'produtos' => [['nome' => 'Papel', 'marca' => '', 'unidade' => 'UN', 'quantidade' => '2', 'preco' => '10,00']]]],
            $actor, str_repeat('b', 32)));
    }

    public function testLaterExpiryWarnsExistingOrderAndBlocksNewNotesAndCopies(): void
    {
        $actor = $this->insertUsuario();
        $supplier = $this->supplier();
        $certificate = $this->certificate($supplier, '2026-10-15');
        $valid = $this->model('2026-10-15');
        $contract = $valid->create('Pedido anterior ao vencimento', '100,00', (string) $supplier, $actor);
        $sheet = (int) $valid->sheets($contract)[0]['id'];
        $before = $this->snapshot();
        $expired = $this->model('2026-10-16');
        $this->assertSame([], $valid->expiredCertificates($supplier));
        $this->assertSame([$certificate], array_column($expired->expiredCertificates($supplier), 'id'));
        $this->assertSame($before, $this->snapshot(), 'Consultar alertas após vencer não altera registros existentes.');
        $this->blocked(fn () => $expired->addSheet($contract, 'Nova nota', 1, $actor));
        $this->blocked(fn () => $expired->addSheet($contract, '', 1, $actor, $sheet, str_repeat('c', 32)));
        $this->assertSame('Pedido anterior ao vencimento', $expired->find($contract)['titulo']);
        $this->assertCount(1, $expired->sheets($contract));
    }

    public function testOnlySelectedSupplierBlocksCreationIncludingWhenOtherSuppliersHaveExpiredDocuments(): void
    {
        $actor = $this->insertUsuario();
        $bad = $this->supplier('Fornecedor vencido');
        $good = $this->supplier('Fornecedor regular');
        $this->certificate($bad, '2026-10-15');
        $this->certificate($good, '2026-10-16', 'Vence hoje');
        $this->certificate($good, '2026-10-17', 'Vence amanhã');
        $model = $this->model();
        $this->assertSame([], $model->expiredCertificates($good));
        $id = $model->create('Pedido de outro fornecedor', '100,00', (string) $good, $actor);
        $this->assertGreaterThan(0, $model->addSheet($id, 'Nota permitida', 1, $actor));
        $this->blocked(fn () => $model->create('Pedido do fornecedor vencido', '100,00', (string) $bad, $actor));
        $this->assertArrayHasKey($bad, $model->expiredCertificateGroups());
        $this->assertArrayNotHasKey($good, $model->expiredCertificateGroups());
    }

    public function testArchivedDeletedInactiveStatusAndInvalidDatesDoNotCountAsExpired(): void
    {
        $actor = $this->insertUsuario();
        $supplier = $this->supplier();
        $this->certificate($supplier, '2026-10-01', 'Arquivo', 'arquivada');
        $this->certificate($supplier, '2026-10-01', 'Excluída', 'excluida');
        $this->certificate($supplier, '2026-10-01', 'Status antigo', 'status_inativo');
        $this->certificate($supplier, '2026-02-31', 'Data inválida');
        $this->certificate($supplier, '', 'Data pendente');
        $model = $this->model();
        $this->assertSame([], $model->expiredCertificates($supplier));
        $this->assertGreaterThan(0, $model->create('Pedido permitido', '100,00', (string) $supplier, $actor));
    }

    public function testRenewalRemovesWarningAndAllowsNotesForExistingOrder(): void
    {
        $actor = $this->insertUsuario();
        $supplier = $this->supplier();
        $model = $this->model();
        $id = $model->create('Pedido existente', '100,00', (string) $supplier, $actor);
        $old = $this->certificate($supplier, '2026-10-15');
        $this->blocked(fn () => $model->addSheet($id, '', 1, $actor));
        $this->pdo->exec('UPDATE certidoes SET arquivado=1 WHERE id=' . $old);
        $this->certificate($supplier, '2026-12-01');
        $this->assertSame([], $model->expiredCertificates($supplier));
        $this->assertGreaterThan(0, $model->addSheet($id, 'Após regularização', 1, $actor));
    }

    public function testNewerValidDocumentDoesNotHideAnotherCurrentExpiredCertificate(): void
    {
        $actor = $this->insertUsuario();
        $supplier = $this->supplier();
        $this->certificate($supplier, '2026-10-01');
        $this->certificate($supplier, '2026-12-01');
        $this->blocked(fn () => $this->model()->create('Pedido bloqueado', '100,00', (string) $supplier, $actor));
    }

    public function testSubmitRechecksCertificateChangedAfterFormWasOpened(): void
    {
        $actor = $this->insertUsuario();
        $supplier = $this->supplier();
        $certificate = $this->certificate($supplier, '2026-12-01');
        $model = $this->model();
        $this->assertSame([], $model->expiredCertificates($supplier));
        $this->pdo->exec("UPDATE certidoes SET data_vencimento='2026-10-15' WHERE id=" . $certificate);
        $this->blocked(fn () => $model->create('Formulário antigo', '100,00', (string) $supplier, $actor));
    }

    public function testExistingOrderCanStillBeEditedWhileNewCreationIsBlocked(): void
    {
        $actor = $this->insertUsuario();
        $supplier = $this->supplier();
        $model = $this->model();
        $id = $model->create('Pedido existente', '100,00', (string) $supplier, $actor);
        $this->certificate($supplier, '2026-10-15');
        $model->update($id, 'Pedido existente corrigido', '100,00', (string) $supplier, 1, $actor);
        $this->assertSame('Pedido existente corrigido', $model->find($id)['titulo']);
        $this->assertCount(1, $model->expiredCertificates($supplier));
    }

    public function testRequestReceiptReplayAfterExpiryReturnsExistingOrderWithoutCreatingAnything(): void
    {
        $actor = $this->insertUsuario();
        $supplier = $this->supplier();
        $model = $this->model();
        $token = str_repeat('d', 32);
        $id = $model->create('Pedido idempotente', '100,00', (string) $supplier, $actor, $token);
        $this->certificate($supplier, '2026-10-15');
        $before = $this->snapshot();
        $this->assertSame($id, $model->create('Pedido idempotente', '100,00', (string) $supplier, $actor, $token));
        $this->assertSame($before, $this->snapshot());
    }
}
