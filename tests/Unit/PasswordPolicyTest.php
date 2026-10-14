<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function testAcceptsUnicodePassphrasesAndArgon2id(): void
    {
        $password = 'Café na escola às sete 2026';

        $this->assertSame([], \PasswordPolicy::validate($password, 'Ana Souza', 'ana@example.test'));
        $hash = \PasswordPolicy::hash($password);
        $this->assertSame('argon2id', password_get_info($hash)['algoName']);
        $this->assertTrue(password_verify($password, $hash));
        $this->assertFalse(\PasswordPolicy::needsRehash($hash));
    }

    public function testRejectsShortBlankCommonIdentityAndOversizedPasswords(): void
    {
        $this->assertNotEmpty(\PasswordPolicy::validate('curta'));
        $this->assertNotEmpty(\PasswordPolicy::validate(str_repeat(' ', 12)));
        $this->assertNotEmpty(\PasswordPolicy::validate('password1234'));
        $this->assertNotEmpty(\PasswordPolicy::validate('maria.silva', 'Maria Silva', 'maria@example.test'));
        $this->assertNotEmpty(\PasswordPolicy::validate(str_repeat('á', 129)));
    }

    public function testDoesNotRequireSymbolsOrCompositionClasses(): void
    {
        $this->assertSame([], \PasswordPolicy::validate('uma frase longa somente letras'));
    }

    public function testDistinctSuffixesBeyond72BytesAreFullyProtected(): void
    {
        foreach ([str_repeat('a', 72), str_repeat('á', 36)] as $prefix) {
            $first = $prefix . ' primeira';
            $second = $prefix . ' segunda';
            foreach ([[$first, $second], [$second, $first]] as [$password, $other]) {
                $hash = \PasswordPolicy::hash($password);
                $this->assertSame('argon2id', password_get_info($hash)['algoName']);
                $this->assertTrue(\PasswordPolicy::verify($password, $hash));
                $this->assertFalse(\PasswordPolicy::verify($other, $hash));
            }
        }
    }

    public function testCharacterBoundariesAndInvalidEncoding(): void
    {
        foreach ([15, 128] as $length) {
            $password = str_repeat('界', $length);
            $this->assertSame([], \PasswordPolicy::validate($password));
            $this->assertTrue(\PasswordPolicy::verify($password, \PasswordPolicy::hash($password)));
        }
        foreach ([str_repeat('界', 14), str_repeat('界', 129), "Invalid UTF8 \xff", "Frase com nulo\0"] as $password) {
            $this->assertNotEmpty(\PasswordPolicy::validate($password));
        }
        $password = '  Café com espaços 2026  ';
        $hash = \PasswordPolicy::hash($password);
        $this->assertTrue(\PasswordPolicy::verify($password, $hash));
        $this->assertFalse(\PasswordPolicy::verify(trim($password), $hash));
    }

    public function testUnavailableArgon2idNeverHashesOrDowngrades(): void
    {
        $hash = \PasswordPolicy::hash('Frase original segura');
        $runtime = \Tests\Support\PasswordRuntime::inject('unavailable');
        try {
            $this->assertFalse(\PasswordPolicy::argon2idAvailable());
            $this->assertFalse(\PasswordPolicy::needsRehash($hash));
            $this->assertFalse(\PasswordPolicy::needsRehash(password_hash('legado', PASSWORD_BCRYPT)));
            try {
                \PasswordPolicy::hash('Frase nova segura');
                $this->fail('Hash creation must fail closed.');
            } catch (\PasswordHashException $exception) {
                $this->assertSame('Não foi possível proteger a senha.', $exception->getMessage());
            }
            $this->assertSame([], $runtime->requestedAlgorithms);
        } finally {
            \Tests\Support\PasswordRuntime::inject(null);
        }
    }

    public function testHashGenerationFailureNeverFallsBack(): void
    {
        $runtime = \Tests\Support\PasswordRuntime::inject('failure');
        try {
            try {
                \PasswordPolicy::hash('Frase nova segura');
                $this->fail('Hash creation must fail closed.');
            } catch (\PasswordHashException $exception) {
                $this->assertSame('Não foi possível proteger a senha.', $exception->getMessage());
                $this->assertNull($exception->getPrevious());
            }
            $this->assertSame(['argon2id'], $runtime->requestedAlgorithms);
        } finally {
            \Tests\Support\PasswordRuntime::inject(null);
        }
    }
}
