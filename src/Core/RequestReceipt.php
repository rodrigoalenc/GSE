<?php

declare(strict_types=1);

require_once __DIR__ . '/DataProtection.php';

final class RequestReceipt
{
    /** Called only inside the same BEGIN IMMEDIATE as the business mutation.
     * @param array<mixed> $payload
     * @param callable():(int|false) $mutation
     */
    public static function run(PDO $pdo, int $user, string $operation, ?string $token, array $payload, callable $mutation): int|false
    {
        if ($token === null) { return $mutation(); } // Internal callers; HTTP requires a token.
        if (preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) { throw new DomainException('Chave de envio inválida.'); }
        $digest = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), DataProtection::keyring()['index']);
        $statement = $pdo->prepare('SELECT digest,result_id FROM request_receipts WHERE user_id=? AND operation=? AND token=?');
        $statement->execute([$user, $operation, $token]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            if (!hash_equals((string) $row['digest'], $digest)) { throw new DomainException('Chave já utilizada com outros dados. Atualize o formulário.'); }
            return (int) $row['result_id'];
        }
        $result = $mutation();
        if ($result === false) { return false; }
        $pdo->prepare('INSERT INTO request_receipts(user_id,operation,token,digest,result_id) VALUES(?,?,?,?,?)')
            ->execute([$user, $operation, $token, $digest, $result]);
        return $result;
    }
}
