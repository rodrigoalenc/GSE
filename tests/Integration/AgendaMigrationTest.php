<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use src\Core\DatabaseInitializer;

final class AgendaMigrationTest extends TestCase
{
    public function testAgendaCanBeAddedToVersion24WithExistingReminders(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys=ON');
        $migration = new \ReflectionMethod(DatabaseInitializer::class, 'runMigration');
        $schema = (string) file_get_contents(ROOT_PATH . '/database/schema.sql');
        foreach (range(1, 24) as $version) {
            $migration->invoke(null, $pdo, $version, $schema);
        }
        $pdo->exec("PRAGMA user_version=24;
            CREATE TABLE agenda_avisos (id INTEGER PRIMARY KEY AUTOINCREMENT, usuario_id INTEGER,
                data_aviso TEXT NOT NULL, titulo TEXT NOT NULL, descricao TEXT, criado_em TEXT DEFAULT CURRENT_TIMESTAMP);
            INSERT INTO agenda_avisos (id, data_aviso, titulo) VALUES (7, '2026-11-25', 'Aviso existente')");

        DatabaseInitializer::initialize($pdo);
        DatabaseInitializer::initialize($pdo);

        $this->assertSame(25, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
        $this->assertSame(['Aviso existente', 1, null, null], $pdo->query(
            'SELECT titulo,revisao,tipo,excluido_em FROM agenda_avisos WHERE id=7'
        )->fetch(PDO::FETCH_NUM));
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations WHERE version=25')->fetchColumn());
        $this->assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
    }

    public function testHolidaysUseSchoolDatesAndIncludeTheNextYearInDashboardRange(): void
    {
        require_once ROOT_PATH . '/src/Services/FeriadosAgenda.php';
        $service = new \FeriadosAgenda();
        $year = array_column($service->listarPorAno(2026), 'data', 'titulo');

        $this->assertSame('2026-06-20', $year['Aniversário de Vicentina']);
        $this->assertSame('2026-04-03', $year['Paixão de Cristo']);
        $this->assertSame('2026-06-04', $year['Corpus Christi']);
        $this->assertSame(['2026-12-25', '2027-01-01'], array_column(
            $service->listarProximos(new \DateTimeImmutable('2026-12-25'), 7), 'data'
        ));
    }
}
