<?php

declare(strict_types=1);

// Synthetic keys only. This helper is never loaded by application code.
function test_keyring(string $directory): string
{
    if (!is_dir($directory)) { mkdir($directory, 0700, true); }
    chmod($directory, 0700);
    $path = $directory . DIRECTORY_SEPARATOR . 'test-keys.json';
    file_put_contents($path, json_encode(['version'=>1, 'active'=>'test1',
        'keys'=>['test1'=>base64_encode(random_bytes(32))], 'index_key'=>base64_encode(random_bytes(32))], JSON_THROW_ON_ERROR));
    chmod($path, 0600);
    return $path;
}

/** @param array<string,mixed> $values */
function test_insert_user(PDO $pdo, array $values): int
{
    $id = $values['id'] ?? (int) $pdo->query("SELECT MAX(COALESCE((SELECT seq FROM sqlite_sequence WHERE name='usuarios'),0),COALESCE((SELECT MAX(id) FROM usuarios),0))+1")->fetchColumn();
    $values['id'] = $id;
    $values['email_lookup'] = DataProtection::emailIndex((string) $values['email']);
    $values['nome'] = DataProtection::encrypt((string) $values['nome'], 'usuarios.nome', (int) $id);
    $values['email'] = DataProtection::encrypt((string) $values['email'], 'usuarios.email', (int) $id);
    $pdo->prepare('INSERT INTO usuarios (' . implode(',', array_keys($values)) . ') VALUES (' . implode(',', array_fill(0,count($values),'?')) . ')')
        ->execute(array_values($values));
    return (int) $id;
}

function test_update_email(PDO $pdo, int $id, string $email): void
{
    $pdo->prepare('UPDATE usuarios SET email=?,email_lookup=? WHERE id=?')->execute([
        DataProtection::encrypt($email, 'usuarios.email', $id), DataProtection::emailIndex($email), $id]);
}
