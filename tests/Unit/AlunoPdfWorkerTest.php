<?php

declare(strict_types=1);

namespace Tests\Unit;

use AlunoPdfWorker;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/AlunoPdfWorker.php';

final class AlunoPdfWorkerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/gse-pdf-isolation-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) { @unlink($file); }
        @rmdir($this->directory);
        putenv('GSE_PDF_TEST_SECRET');
    }

    public function testChildHasItsOwnMemoryLimitAndDoesNotInheritApplicationSecrets(): void
    {
        putenv('GSE_PDF_TEST_SECRET=must-not-reach-child');
        $result = $this->worker()->parse('inspect');
        self::assertSame(AlunoPdfWorker::MEMORY_LIMIT, $result['memory']);
        self::assertFalse($result['secret']);
        self::assertCount(0, glob($this->directory . '/*', GLOB_ONLYDIR) ?: []);
    }

    /** @return list<array{string,string}> */
    public static function failures(): array
    {
        return [['timeout', 'tempo'], ['crash', 'interrompido'], ['invalid', 'interrompido'], ['false-success', 'não concluiu'], ['memory', 'interrompido'], ['output', 'conteúdo excessivo']];
    }

    #[DataProvider('failures')]
    public function testTimeoutCrashesMalformedOutputAndMemoryFailuresNeverReturnPartialResults(string $mode, string $message): void
    {
        $started = microtime(true);
        try {
            $this->worker($mode === 'timeout' ? 0.1 : 3)->parse($mode);
            self::fail('An incomplete worker result was accepted.');
        } catch (DomainException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
        self::assertLessThan(4, microtime(true) - $started);
        self::assertCount(0, glob($this->directory . '/*', GLOB_ONLYDIR) ?: []);
        self::assertArrayHasKey('memory', $this->worker()->parse('inspect'), 'The slot must be released after failure.');
    }

    public function testConcurrencyIsBoundedWithoutWaitingForAnotherRequest(): void
    {
        $handles = [];
        try {
            for ($index = 0; $index < AlunoPdfWorker::MAX_CONCURRENT; $index++) {
                $handle = fopen($this->directory . '/slot-' . $index . '.lock', 'c');
                self::assertIsResource($handle);
                self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
                $handles[] = $handle;
            }
            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('ocupado');
            $this->worker()->parse('inspect');
        } finally {
            foreach ($handles as $handle) { flock($handle, LOCK_UN); fclose($handle); }
        }
    }

    public function testMismatchedPhpRuntimeIsRejectedBeforeAnyPdfFileIsRead(): void
    {
        $process = proc_open([PHP_BINARY, ROOT_PATH . '/bin/parse-students-pdf.php', $this->directory . '/does-not-exist.pdf', '0'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT_PATH);
        self::assertIsResource($process);
        $result = json_decode((string)stream_get_contents($pipes[1]), true);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(4, proc_close($process));
        self::assertSame('', $errors);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('mesma versão PHP', $result['message']);
    }

    private function worker(float $timeout = 3): AlunoPdfWorker
    {
        return new AlunoPdfWorker(ROOT_PATH . '/tests/fixtures/pdf-worker-failure.php', $timeout, $this->directory);
    }
}
