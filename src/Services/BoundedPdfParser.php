<?php

declare(strict_types=1);

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\RawData\RawDataParser;

/** Applies the document budget as streams are decoded, before retaining objects. */
final class BoundedPdfRawDataParser extends RawDataParser
{
    private int $decoded = 0;
    private int $objectsRead = 0;

    public function __construct(private Config $limits)
    {
        parent::__construct(['ignore_filter_decoding_errors' => false, 'ignore_missing_filter_decoders' => false], $limits);
    }

    /**
     * @param array<mixed> $xref
     * @param array<mixed> $sdic
     * @return array<mixed>
     */
    protected function decodeStream(string $pdfData, array $xref, array $sdic, string $stream): array
    {
        $available = min(AlunoPdfParser::MAX_STREAM_BYTES, AlunoPdfParser::MAX_DECODED_BYTES - $this->decoded);
        if ($available <= 0) { throw new DomainException('O PDF ultrapassa o limite de expansão de conteúdo. Exporte arquivos menores.'); }
        $this->limits->setDecodeMemoryLimit($available);
        try {
            $result = parent::decodeStream($pdfData, $xref, $sdic, $stream);
        } catch (Throwable $exception) {
            throw new DomainException('Não foi possível decodificar o PDF dentro do limite de expansão de conteúdo.', 0, $exception);
        }
        // Flate may return a prefix exactly as large as its limit. Never import it.
        $length = strlen($result[0]);
        if ($length >= $available || $result[1] !== []) {
            throw new DomainException('O PDF ultrapassa o limite de expansão de conteúdo ou usa um filtro não suportado.');
        }
        $this->decoded += $length;
        return $result;
    }

    /**
     * @param array<mixed> $xref
     * @return Generator<string,array<mixed>>
     */
    public function iterateIndirectObjects(string $pdfData, array $xref): Generator
    {
        if (count($xref['xref'] ?? []) > AlunoPdfParser::MAX_OBJECTS) {
            throw new DomainException('O PDF contém objetos em excesso. Exporte arquivos menores.');
        }
        foreach (parent::iterateIndirectObjects($pdfData, $xref) as $id => $object) {
            if (++$this->objectsRead > AlunoPdfParser::MAX_OBJECTS) {
                throw new DomainException('O PDF contém objetos em excesso. Exporte arquivos menores.');
            }
            yield $id => $object;
        }
    }
}

final class BoundedPdfParser extends Parser
{
    public function __construct(Config $config)
    {
        parent::__construct([], $config);
        $this->rawDataParser = new BoundedPdfRawDataParser($config);
    }
}
