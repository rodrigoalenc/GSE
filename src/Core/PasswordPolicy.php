<?php

declare(strict_types=1);

final class PasswordHashException extends RuntimeException
{
}

class PasswordHashRuntime
{
    /** @return list<string> */
    public function algorithms(): array
    {
        return password_algos();
    }

    public function hash(#[SensitiveParameter] string $password, string $algorithm): string
    {
        return password_hash($password, $algorithm);
    }
}

final class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 128;

    // Tests replace this private native adapter by reflection; no environment switch.
    private static ?PasswordHashRuntime $runtime = null;

    private static function runtime(): PasswordHashRuntime
    {
        return self::$runtime ??= new PasswordHashRuntime();
    }

    private const COMMON = [
        '123456789012', '123456789123', 'administrator', 'administrador', 'password1234',
        'qwerty123456', 'letmein123456', 'iloveyou12345', 'senha123456', 'changeme1234',
        'welcome12345', 'bemvindo1234', 'secret123456', 'segredo123456', 'abcdef123456',
    ];

    /** @return list<string> */
    public static function validate(string $password, string $name = '', string $email = ''): array
    {
        if (!mb_check_encoding($password, 'UTF-8') || str_contains($password, "\0")) {
            return ['A senha deve conter texto UTF-8 válido, sem bytes nulos.'];
        }
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

    public static function argon2idAvailable(): bool
    {
        $algorithms = self::runtime()->algorithms();

        return in_array('argon2id', $algorithms, true);
    }

    public static function inputValid(#[SensitiveParameter] string $password): bool
    {
        // Legacy accounts can predate the current minimum length policy.
        return $password !== '' && mb_check_encoding($password, 'UTF-8')
            && !str_contains($password, "\0") && mb_strlen($password, 'UTF-8') <= self::MAX_LENGTH;
    }

    public static function verify(#[SensitiveParameter] string $password, string $hash): bool
    {
        if (!self::inputValid($password)
            || (preg_match('/^\$2[aby]\$/', $hash) === 1 && strlen($password) > 72)) {
            return false;
        }

        return password_verify($password, $hash);
    }

    public static function hash(#[SensitiveParameter] string $password): string
    {
        if (!self::inputValid($password)) {
            throw new InvalidArgumentException('Entrada de senha inválida.');
        }
        if (!self::argon2idAvailable()) {
            TechnicalLogger::error('password_hash_failed', ['cause' => 'argon2id_unavailable']);
            throw new PasswordHashException('Não foi possível proteger a senha.');
        }
        try {
            $hash = self::runtime()->hash($password, 'argon2id');
            if (password_get_info($hash)['algoName'] !== 'argon2id') {
                throw new RuntimeException('Unexpected hash algorithm.');
            }

            return $hash;
        } catch (Throwable $exception) {
            TechnicalLogger::error('password_hash_failed', ['cause' => 'argon2id_generation_failed', 'exception' => $exception::class]);
            throw new PasswordHashException('Não foi possível proteger a senha.');
        }
    }

    public static function needsRehash(string $hash): bool
    {
        return self::argon2idAvailable() && password_needs_rehash($hash, 'argon2id');
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
