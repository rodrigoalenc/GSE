<?php

declare(strict_types=1);

final class BackupCleanupException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Limpeza do snapshot pendente. Consulte o registro técnico privado.');
    }
}
