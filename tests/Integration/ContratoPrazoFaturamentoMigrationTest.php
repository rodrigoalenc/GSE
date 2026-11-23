<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use src\Core\DatabaseInitializer;
use Tests\Support\DatabaseTestCase;

final class ContratoPrazoFaturamentoMigrationTest extends DatabaseTestCase
{
    public function testVinteETresPreservaNotaJaFaturadaEAcrescentaPrazoNulo(): void
    {
        $old = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $old->exec('PRAGMA foreign_keys=ON');
        $schema = preg_replace('/^    prazo_faturamento TEXT NULL,\R/m', '', (string) file_get_contents(ROOT_PATH . '/database/schema.sql'));
        $migration = new \ReflectionMethod(DatabaseInitializer::class, 'runMigration');
        for ($version = 1; $version <= 22; $version++) {
            $migration->invoke(null, $old, $version, $schema);
        }

        $old->exec("INSERT INTO pedidos (id,titulo,qtd_paginas) VALUES (7,'Pedido histórico',2);
            INSERT INTO pedido_paginas (id,id_pedido,numero_pagina,observacao,data_faturamento,aditivo)
                VALUES (40,7,1,'Nota faturada','2026-10-15',1), (41,7,2,'Nota pendente',NULL,0)");
        $historicalNotes = $old->query('SELECT * FROM pedido_paginas ORDER BY id')->fetchAll();
        $this->assertArrayNotHasKey('prazo_faturamento', $historicalNotes[0]);

        DatabaseInitializer::initialize($old);
        DatabaseInitializer::initialize($old);

        $this->assertSame(24, (int) $old->query('PRAGMA user_version')->fetchColumn());
        $this->assertSame(1, (int) $old->query('SELECT COUNT(*) FROM schema_migrations WHERE version=23')->fetchColumn());
        $notes = $old->query('SELECT * FROM pedido_paginas ORDER BY id')->fetchAll();
        foreach ($notes as &$note) {
            $this->assertArrayHasKey('prazo_faturamento', $note);
            $this->assertNull($note['prazo_faturamento']);
            unset($note['prazo_faturamento']);
        }
        unset($note);
        $this->assertSame($historicalNotes, $notes);
        $this->assertSame([], $old->query('PRAGMA foreign_key_check')->fetchAll());

        $column = "SELECT name, type, [notnull], dflt_value FROM pragma_table_info('pedido_paginas') WHERE name='prazo_faturamento'";
        $this->assertSame([
            ['name' => 'prazo_faturamento', 'type' => 'TEXT', 'notnull' => 0, 'dflt_value' => null],
        ], $this->pdo->query($column)->fetchAll());
        $this->assertSame($this->pdo->query($column)->fetchAll(), $old->query($column)->fetchAll());
    }
}
