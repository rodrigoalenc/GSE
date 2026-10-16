<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Services/AlunoPdfParser.php';
require_once ROOT_PATH . '/src/Model/AlunoImportacao.php';

/**
 * A prévia fica apenas na sessão privada. O upload original não é conservado.
 * @phpstan-import-type SourceRow from AlunoImportacao
 * @phpstan-import-type PlanItem from AlunoImportacao
 * @phpstan-type Preview array{items:list<PlanItem>,counts:array{importar:int,duplicado:int,revisar:int},classes_new:list<string>,year:int,token:string,expires_at:int,actor_id:int,pages:int,total:int,eligible:int,ignored:int,statuses:array<string,int>}
 */
final class AlunoPdfImportService
{
    public const PREVIEW_TTL = 900;
    private const SESSION_KEY = 'aluno_pdf_previews';

    /**
     * @param array<string,mixed> $file
     * @return Preview
     */
    public function previewUpload(array $file, int $year, int $actorId): array
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new DomainException('O PDF ultrapassa o limite de envio do servidor. Use um arquivo de até 10 MiB.');
        }
        if ($error !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)
            || !is_uploaded_file($file['tmp_name'])) {
            throw new DomainException('Selecione um PDF enviado pelo formulário.');
        }

        return $this->previewFile($file['tmp_name'], $year, $actorId);
    }

    /** Usado também pela validação local, sem simular um upload HTTP.
     * @return Preview
     */
    public function previewFile(string $path, int $year, int $actorId): array
    {
        if ($actorId < 1) {
            throw new DomainException('Faça login novamente para importar.');
        }
        $report = (new AlunoPdfParser())->parseFile($path);
        $plan = (new AlunoImportacao())->preview($report['rows'], $year);
        $this->cleanup();
        // Evita acumular dados pessoais de relatórios anteriores na sessão.
        $_SESSION[self::SESSION_KEY] = [];
        $token = bin2hex(random_bytes(32));
        $preview = array_merge($plan, [
            'token' => $token,
            'expires_at' => time() + self::PREVIEW_TTL,
            'actor_id' => $actorId,
            'pages' => $report['pages'],
            'total' => $report['total'],
            'eligible' => $report['eligible'],
            'ignored' => $report['ignored'],
            'statuses' => $report['statuses'],
        ]);
        $_SESSION[self::SESSION_KEY][$token] = $preview;

        return $preview;
    }

    /** @return Preview|null */
    public function getPreview(string $token, int $actorId): ?array
    {
        $this->cleanup();
        if (preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            return null;
        }
        $preview = $_SESSION[self::SESSION_KEY][$token] ?? null;
        if (!is_array($preview) || (int) ($preview['actor_id'] ?? 0) !== $actorId) {
            return null;
        }

        /** @var Preview $preview */
        return $preview;
    }

    /** @return array{imported:int,classes_created:int,duplicates:int,review:int,ignored:int} */
    public function confirm(string $token, int $actorId): array
    {
        $preview = $this->getPreview($token, $actorId);
        if ($preview === null) {
            throw new DomainException('A prévia expirou ou já foi utilizada. Envie o PDF novamente.');
        }
        // Consome antes de executar: uma repetição nunca reaplica o mesmo lote.
        unset($_SESSION[self::SESSION_KEY][$token]);
        /** @var list<SourceRow> $rows */
        $rows = [];
        foreach ($preview['items'] as $item) {
            if ($item['action'] === 'importar') {
                $rows[] = [
                    'nome_completo' => $item['nome_completo'],
                    'data_nascimento' => $item['data_nascimento'],
                    'turma' => $item['turma'],
                    'codigo_sgde' => $item['codigo_sgde'],
                    'situacao' => $item['situacao'],
                    'pagina' => $item['pagina'],
                ];
            }
        }
        if ($rows === []) {
            throw new DomainException('Não há novos alunos aptos para importar nesta prévia.');
        }
        // O model confere novamente os cadastros sob o lock de escrita.
        $result = (new AlunoImportacao())->import($rows, (int) $preview['year'], $actorId);
        $result['duplicates'] += (int) $preview['counts']['duplicado'];
        $result['review'] += (int) $preview['counts']['revisar'];

        return array_merge($result, ['ignored' => (int) $preview['ignored']]);
    }

    private function cleanup(): void
    {
        $previews = $_SESSION[self::SESSION_KEY] ?? [];
        if (!is_array($previews)) {
            $_SESSION[self::SESSION_KEY] = [];
            return;
        }
        foreach ($previews as $token => $preview) {
            if (!is_array($preview) || (int) ($preview['expires_at'] ?? 0) <= time()) {
                unset($_SESSION[self::SESSION_KEY][$token]);
            }
        }
    }
}
