<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function testAcceptsUnicodePassphrasesAndArgonOrSafeFallback(): void
    {
        $password = 'Café na escola às sete 2026';

        $this->assertSame([], \PasswordPolicy::validate($password, 'Ana Souza', 'ana@example.test'));
        $hash = \PasswordPolicy::hash($password);
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
}
