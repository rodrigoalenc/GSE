<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class SessionIdRenewalTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSubmittedFormSurvivesRealSessionIdRotation(): void
    {
        $directory = sys_get_temp_dir() . '/gse-session-test-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        ini_set('session.save_path', $directory);
        try {
            \SessionManager::start(false);
            \SessionManager::authenticate(['id' => 1, 'nome' => 'Teste', 'tipo' => 'administrador'], 1000);
            $formToken = \csrf_token();
            $oldId = session_id();
            $this->assertTrue(\csrf_valido($formToken));
            $this->assertTrue(\SessionManager::touchAndRenew(2000));
            $this->assertNotSame($oldId, session_id());
            $this->assertTrue(\csrf_valido($formToken));
            \SessionManager::terminate();
            $this->assertFalse(\csrf_valido($formToken));
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
            foreach (glob($directory . '/*') ?: [] as $file) { @unlink($file); }
            @rmdir($directory);
        }
    }
}
