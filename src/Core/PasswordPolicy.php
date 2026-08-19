<?php

declare(strict_types=1);

final class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 128;

    private const COMMON = [
        '123456789012', '123456789123', 'administrator', 'administrador', 'password1234',
        'qwerty123456', 'letmein123456', 'iloveyou12345', 'senha123456', 'changeme1234',
        'welcome12345', 'bemvindo1234', 'secret123456', 'segredo123456', 'abcdef123456',
    ];

    /** @return list<string> */
    public static function validate(string $password, string $name = '', string $email = ''): array
    {
        $errors = [];
        $length = mb_strlen($password, 'UTF-8');

        if ($length < self::MIN_LENGTH) {
            $errors[] = 'Use uma senha com pelo menos 12 caracteres.';
        }

        if ($length > self::MAX_LENGTH) {
            $errors[] = 'A senha pode ter no máximo 128 caracteres.';
        }

        if (trim($password) === '') {
            $errors[] = 'A senha não pode estar vazia nem conter apenas espaços.';
        }

        $normalized = self::compact($password);

        if (in_array($normalized, self::COMMON, true)) {
            $errors[] = 'Escolha uma senha menos comum.';
        }

        if (self::resemblesIdentity($normalized, $name, $email)) {
            $errors[] = 'A senha não pode ser igual ou muito semelhante ao nome ou e-mail.';
        }

        return array_values(array_unique($errors));
    }

    public static function hash(string $password): string
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algorithm);

        if ($hash === false && $algorithm !== PASSWORD_DEFAULT) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
        }

        if ($hash === false) {
            throw new RuntimeException('Não foi possível proteger a senha.');
        }

        return $hash;
    }

    public static function needsRehash(string $hash): bool
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;

        return password_needs_rehash($hash, $algorithm);
    }

    private static function resemblesIdentity(string $password, string $name, string $email): bool
    {
        if ($password === '') {
            return false;
        }

        $identities = [self::compact($name), self::compact(strstr($email, '@', true) ?: $email)];

        foreach ($identities as $identity) {
            if ($identity === '' || mb_strlen($identity) < 4) {
                continue;
            }

            if ($password === $identity) {
                return true;
            }

            if (strlen($password) <= 255 && strlen($identity) <= 255) {
                $distance = levenshtein($password, $identity);
                $threshold = max(1, (int) floor(max(strlen($password), strlen($identity)) * 0.15));

                if ($distance <= $threshold) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function compact(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');

        return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';
    }
}
