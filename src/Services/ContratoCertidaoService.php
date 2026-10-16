<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Model/Certidao.php';

/** @phpstan-type ExpiredCertificate array{id:int,id_fornecedor:int,fornecedor:string,tipo_certidao:string,data_vencimento:string} */
final class ContratoCertidaoService
{
    public function __construct(private readonly PDO $pdo, private readonly ?CertidaoStatus $dates = null) {}

    /** @return list<ExpiredCertificate> */
    public function expired(?int $supplierId = null): array
    {
        $dates = $this->dates ?? new CertidaoStatus();
        $sql = 'SELECT c.id, c.id_fornecedor, f.nome AS fornecedor, t.nome AS tipo_certidao, c.data_vencimento
                FROM certidoes c
                JOIN lista_fornecedores f ON f.id = c.id_fornecedor
                JOIN lista_tipos_certidao t ON t.id = c.id_tipo_certidao
                WHERE ' . Certidao::STATE_SQL . " = 'corrente' AND c.data_vencimento < ?";
        $params = [$dates->today()];
        if ($supplierId !== null) {
            $sql .= ' AND c.id_fornecedor = ?';
            $params[] = $supplierId;
        }
        $query = $this->pdo->prepare($sql . ' ORDER BY c.data_vencimento, f.nome, t.nome, c.id');
        $query->execute($params);
        $expired = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // Datas legadas inválidas são pendências, não certidões vencidas.
            if ($dates->classify($row['data_vencimento']) !== 'vencida') { continue; }
            $expired[] = [
                'id' => (int) $row['id'], 'id_fornecedor' => (int) $row['id_fornecedor'],
                'fornecedor' => (string) $row['fornecedor'], 'tipo_certidao' => (string) $row['tipo_certidao'],
                'data_vencimento' => (string) $row['data_vencimento'],
            ];
        }
        return $expired;
    }

    public function assertCanCreate(?int $supplierId): void
    {
        if ($supplierId === null) { return; }
        $expired = $this->expired($supplierId);
        if ($expired === []) { return; }
        $descriptions = array_map(static fn (array $certificate): string => $certificate['tipo_certidao']
            . ' — ' . $certificate['fornecedor'] . ' (vencida em '
            . (new DateTimeImmutable($certificate['data_vencimento']))->format('d/m/Y') . ')', $expired);
        throw new DomainException('Não é possível criar pedido ou nota: o fornecedor possui certidões vencidas. '
            . implode('; ', $descriptions) . '. Regularize as certidões para continuar.');
    }
}
