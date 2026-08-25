<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class PassivoCsvTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testBomPreviewAtomicConfirmationAndSingleUseToken(): void
    {
        $actor = $this->insertUsuario('Admin CSV');
        $file = $this->csv("\xEF\xBB\xBFNome;Data;N\u{00FA}mero;Caixa\nJos\u{00E9} CSV;01/02/2000;1;CX-CSV\nAna CSV;2001-03-04;;CX-CSV\n");
        $service = new \PassivoCsvService();
        $model = new \Passivo();
        $preview = $service->previewTrustedFile($file, $actor, $model);

        $this->assertIsArray($preview);
        $this->assertSame(2, $preview['valid']);
        $this->assertSame(0, $preview['invalid']);
        $storedPath = (string) $_SESSION['passivo_import_previews'][$preview['token']]['path'];
        $this->assertFileExists($storedPath);
        $this->assertSame(2, $service->confirm($preview['token'], $actor, $model));
        $this->assertFileDoesNotExist($storedPath);
        $this->assertSame(2, $model->paginate(['caixa' => 'cx-csv', 'ativo' => '1'])['total']);
        $this->assertFalse($service->confirm($preview['token'], $actor, $model));
        $this->assertSame('invalid_token', $service->lastErrorCode());
    }

    public function testPreviewReportsInvalidDuplicateAndLocationConflictRows(): void
    {
        $actor = $this->insertUsuario('Admin CSV Conflitos');
        $model = new \Passivo();
        $model->cadastrar(['nome_completo' => 'Existente', 'data_nascimento' => '2000-01-01', 'numero' => '5', 'caixa' => 'A'], $actor);
        $file = $this->csv("Nome;Data;Numero;Caixa\nExistente;2000-01-01;8;B\nNovo;2001-01-01;5;A\nData Ruim;31/02/2020;9;C\nLinha;Extra;Com;Cinco;Colunas\n\nValido;2002-02-02;1;D\n");
        $preview = (new \PassivoCsvService())->previewTrustedFile($file, $actor, $model);

        $this->assertIsArray($preview);
        $this->assertSame(1, $preview['valid']);
        $this->assertSame(3, $preview['invalid']);
        $this->assertSame(1, $preview['duplicate']);
        $this->assertSame(1, $preview['conflict']);
        $this->assertNotEmpty($preview['errors']);
    }

    public function testInvalidHeaderAndUtf8AreRejectedWithoutPreviewToken(): void
    {
        $actor = $this->insertUsuario('Admin CSV Invalido');
        $service = new \PassivoCsvService();
        $this->assertFalse($service->previewTrustedFile($this->csv("Nome,Data,Numero,Caixa\nA,2000-01-01,1,A\n"), $actor, new \Passivo()));
        $this->assertSame('invalid_header', $service->lastErrorCode());
        $this->assertFalse($service->previewTrustedFile($this->csv("Nome;Data;Numero;Caixa\nNome\xFF;2000-01-01;1;A\n"), $actor, new \Passivo()));
        $this->assertSame('invalid_utf8', $service->lastErrorCode());
    }

    private function csv(string $content): string
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'passivo_csv_' . bin2hex(random_bytes(8)) . '.csv';
        file_put_contents($file, $content);
        $this->files[] = $file;

        return $file;
    }
}
