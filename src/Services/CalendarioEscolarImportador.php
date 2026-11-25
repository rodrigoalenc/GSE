<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Core/TextNormalizer.php';
require_once ROOT_PATH . '/src/Core/UploadLimits.php';
require_once ROOT_PATH . '/src/Model/Agenda.php';
require_once __DIR__ . '/AlunoPdfParser.php';

use Smalot\PdfParser\Config as PdfConfig;
use src\Core\TextNormalizer;

/**
 * @phpstan-import-type Evento from Agenda
 * @phpstan-type Previa array{token:string,ano:int,itens:list<Evento>,avisos:list<string>}
 * @phpstan-type PreviaSessao array{token:string,ano:int,itens:list<Evento>,avisos:list<string>,ator_id:int,expira_em:int}
 */
final class CalendarioEscolarImportador
{
    public const ANO_MIN = 2020;
    public const ANO_MAX = 2100;
    public const MAX_PDF_BYTES = 10_485_760;
    public const MAX_TEXTO_BYTES = 262_144;

    private const SESSAO = 'agenda_calendario_previa';
    private const PREVIA_TTL = 900;
    private const MAX_EVENTOS = 500;
    private const MAX_PAGINAS = 100;
    private const MAX_PDF_TEXTO_BYTES = 524_288;

    private const TIPOS = [
        'feriado' => 'Feriado',
        'ponto facultativo' => 'Ponto facultativo',
        'recesso' => 'Recesso',
        'suspensao' => 'Suspensão',
        'nao letivo' => 'Dia não letivo',
        'conselho de classe' => 'Conselho de classe',
        'jornada pedagogica' => 'Jornada pedagógica',
        'planejamento' => 'Planejamento',
    ];

    private const MESES = [
        'janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'marco' => 3, 'abril' => 4, 'maio' => 5, 'junho' => 6,
        'julho' => 7, 'agosto' => 8, 'setembro' => 9, 'outubro' => 10, 'novembro' => 11, 'dezembro' => 12,
    ];

    private const DATA_NUMERICA = '(?<![0-9/.-])(?<dia>[0-9]{1,2})[\/.-](?<mes>[0-9]{1,2})(?:[\/.-](?<ano>[0-9]{4}|[0-9]{2}))?(?![0-9]|[\/.-][0-9])';
    private const DATA_ISO = '(?<![0-9])(?<ano>[0-9]{4})-(?<mes>[0-9]{2})-(?<dia>[0-9]{2})(?![0-9])';

    /**
     * @param array<mixed> $arquivo
     * @return Previa
     */
    public function previsualizar(array $arquivo, int $ano, string $texto, int $atorId): array
    {
        unset($_SESSION[self::SESSAO]);
        if ($ano < self::ANO_MIN || $ano > self::ANO_MAX) {
            throw new DomainException(sprintf('Informe um ano entre %d e %d.', self::ANO_MIN, self::ANO_MAX), 422);
        }
        if (strlen($texto) > self::MAX_TEXTO_BYTES || !mb_check_encoding($texto, 'UTF-8')) {
            throw new DomainException('Cole um texto válido de até 256 KiB.', 422);
        }

        $avisos = [];
        $erro = $arquivo['error'] ?? UPLOAD_ERR_NO_FILE;
        if (in_array($erro, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new DomainException(UploadLimits::pdfSizeError(self::MAX_PDF_BYTES), 422);
        }
        if ($erro !== UPLOAD_ERR_NO_FILE) {
            $caminho = $arquivo['tmp_name'] ?? null;
            if ($erro !== UPLOAD_ERR_OK || !is_string($caminho) || !is_uploaded_file($caminho)) {
                throw new DomainException('Não foi possível receber o PDF. Selecione o arquivo e tente novamente.', 422);
            }
            $textoPdf = $this->extrairTextoPdf($caminho);
            if (trim($textoPdf) === '') {
                $avisos[] = 'O PDF não contém texto legível. A prévia foi gerada com o texto colado.';
            }
            $texto .= "\n" . $textoPdf;
        }
        if (trim($texto) === '') {
            throw new DomainException('Envie um PDF com texto selecionável ou cole o texto do calendário.', 422);
        }

        $itens = $this->identificarEventos($texto, $ano);
        if ($itens === []) {
            throw new DomainException('Nenhum evento com data e tipo reconhecido foi encontrado. Confira o PDF ou o texto colado.', 422);
        }
        $previa = ['token' => bin2hex(random_bytes(32)), 'ano' => $ano, 'itens' => $itens, 'avisos' => $avisos];
        $_SESSION[self::SESSAO] = $previa + ['ator_id' => $atorId, 'expira_em' => time() + self::PREVIA_TTL];

        return $previa;
    }

    /** @return Previa|null */
    public function buscarPrevia(string $token, int $atorId): ?array
    {
        /** @var PreviaSessao|null $previa */
        $previa = $_SESSION[self::SESSAO] ?? null;
        if ($previa === null || $previa['expira_em'] <= time()) {
            unset($_SESSION[self::SESSAO]);
            return null;
        }
        if ($previa['ator_id'] !== $atorId || !hash_equals($previa['token'], $token)) {
            return null;
        }
        unset($previa['ator_id'], $previa['expira_em']);

        return $previa;
    }

    /** @return array{salvos:int,ignorados:int} */
    public function confirmar(string $token, mixed $selecionados, int $atorId): array
    {
        $previa = $this->buscarPrevia($token, $atorId);
        if ($previa === null) {
            throw new DomainException('A prévia expirou ou já foi utilizada. Analise o calendário novamente.', 422);
        }
        if (!is_array($selecionados) || $selecionados === []) {
            throw new DomainException('Selecione pelo menos um item da prévia.', 422);
        }

        $itens = [];
        foreach ($selecionados as $valor) {
            $indice = is_string($valor) ? filter_var($valor, FILTER_VALIDATE_INT) : false;
            if ($indice === false || !isset($previa['itens'][$indice])) {
                throw new DomainException('A seleção contém um item fora da prévia. Confira a lista e tente novamente.', 422);
            }
            $itens[$indice] = $previa['itens'][$indice];
        }

        unset($_SESSION[self::SESSAO]);

        return (new Agenda())->importar($atorId, array_values($itens));
    }

    /** Lê o arquivo temporário verificado pelo formulário de importação. */
    public function extrairTextoPdf(string $caminho): string
    {
        if (!is_file($caminho) || is_link($caminho) || !is_readable($caminho)) {
            throw new DomainException('Não foi possível ler o PDF enviado.', 422);
        }
        $tamanho = filesize($caminho);
        if ($tamanho !== false && $tamanho > self::MAX_PDF_BYTES) {
            throw new DomainException(UploadLimits::pdfSizeError(self::MAX_PDF_BYTES), 422);
        }
        $cabecalho = file_get_contents($caminho, false, null, 0, 8);
        if (!is_string($cabecalho) || preg_match('/\A%PDF-[0-9]\.[0-9]/', $cabecalho) !== 1
            || (new finfo(FILEINFO_MIME_TYPE))->file($caminho) !== 'application/pdf') {
            throw new DomainException('O conteúdo enviado não é um arquivo PDF válido.', 422);
        }

        $config = new PdfConfig();
        $config->setRetainImageContent(false);
        $config->setMaxNestingDepth(128);
        try {
            $documento = (new BoundedPdfParser($config))->parseFile($caminho);
            $paginas = $documento->getPages();
            if (count($paginas) > self::MAX_PAGINAS) {
                throw new DomainException('O PDF ultrapassa o limite de 100 páginas.', 422);
            }
            $texto = '';
            foreach ($paginas as $pagina) {
                $texto .= $pagina->getText() . "\n";
                if (strlen($texto) > self::MAX_PDF_TEXTO_BYTES) {
                    throw new DomainException('O PDF contém texto em excesso. Envie um trecho menor do calendário.', 422);
                }
            }

            return $texto;
        } catch (Throwable $exception) {
            if ($exception instanceof DomainException && $exception->getCode() === 422) {
                throw $exception;
            }
            throw new DomainException('Não foi possível ler o PDF. Para gerar a prévia, remova o arquivo e cole o texto do calendário.', 422, $exception);
        }
    }

    /** @return list<Evento> */
    private function identificarEventos(string $texto, int $ano): array
    {
        $meses = implode('|', array_keys(self::MESES));
        $dataPorExtenso = '(?<![0-9])(?:(?<inicio>[0-9]{1,2})\s*(?:a|até|[-–—])\s*)?'
            . '(?<dia>[0-9]{1,2})\s*(?:de\s+)?(?<mes>' . $meses . ')(?:\s+de\s+(?<ano>[0-9]{4}))?(?!\p{L})';
        $eventos = [];
        foreach (preg_split('/\R/u', $texto) ?: [] as $linha) {
            $linha = TextNormalizer::displayName(preg_replace('/\p{Cc}/u', ' ', $linha) ?? '');
            if ($linha === '') {
                continue;
            }
            $tipo = $this->identificarTipo($linha);
            if ($tipo === null) {
                continue;
            }
            $datas = $this->extrairDatas($linha, $ano, $dataPorExtenso);
            $titulo = $this->montarTitulo($linha, $tipo, $dataPorExtenso);
            foreach ($datas as $data) {
                $eventos[$data . '|' . $titulo] = [
                    'data_aviso' => $data,
                    'titulo' => $titulo,
                    'descricao' => mb_substr($linha, 0, Agenda::DESCRICAO_MAX, 'UTF-8'),
                    'tipo' => $tipo,
                ];
                if (count($eventos) > self::MAX_EVENTOS) {
                    throw new DomainException('A prévia ultrapassa 500 eventos. Analise o calendário em partes menores.', 422);
                }
            }
        }
        ksort($eventos, SORT_STRING);

        return array_values($eventos);
    }

    private function identificarTipo(string $linha): ?string
    {
        $normalizada = TextNormalizer::searchKey($linha);
        foreach (self::TIPOS as $termo => $tipo) {
            if (str_contains($normalizada, $termo)) {
                return $tipo;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function extrairDatas(string $linha, int $anoPadrao, string $dataPorExtenso): array
    {
        $datas = [];
        foreach ([self::DATA_NUMERICA, self::DATA_ISO, $dataPorExtenso] as $padrao) {
            preg_match_all('~' . $padrao . '~iu', $linha, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
            foreach ($matches as $match) {
                $ano = isset($match['ano']) ? (int) $match['ano'] : $anoPadrao;
                if (isset($match['ano']) && strlen($match['ano']) === 2) {
                    $ano += 2000;
                }
                $mes = self::MESES[mb_strtolower($match['mes'], 'UTF-8')] ?? (int) $match['mes'];
                $fim = (int) $match['dia'];
                $inicio = isset($match['inicio']) ? (int) $match['inicio'] : $fim;
                if ($inicio > $fim || !checkdate($mes, $inicio, $ano) || !checkdate($mes, $fim, $ano)) {
                    continue;
                }
                for ($dia = $inicio; $dia <= $fim; $dia++) {
                    $datas[] = sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
                }
            }
        }

        return array_values(array_unique($datas));
    }

    private function montarTitulo(string $linha, string $tipo, string $dataPorExtenso): string
    {
        foreach ([self::DATA_ISO, self::DATA_NUMERICA, $dataPorExtenso] as $padrao) {
            $linha = preg_replace('~\A' . $padrao . '[\s:,.–—-]*~iu', '', $linha) ?? $linha;
        }
        $limite = Agenda::TITULO_MAX - mb_strlen(Agenda::PREFIXO_IMPORTACAO, 'UTF-8');

        return mb_strimwidth($linha === '' ? $tipo : $linha, 0, $limite, '…', 'UTF-8');
    }
}
