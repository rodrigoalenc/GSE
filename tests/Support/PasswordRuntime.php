<?php

declare(strict_types=1);

namespace Tests\Support;

final class PasswordRuntime extends \PasswordHashRuntime
{
    /** @var list<string> */
    public array $requestedAlgorithms = [];

    public function __construct(private readonly string $mode)
    {
    }

    public function algorithms(): array
    {
        return $this->mode === 'unavailable' ? ['2y'] : parent::algorithms();
    }

    public function hash(#[\SensitiveParameter] string $password, string $algorithm): string
    {
        $this->requestedAlgorithms[] = $algorithm;
        throw new \ValueError('Artificial private detail: ' . $password);
    }

    public static function inject(?string $mode): ?self
    {
        $runtime = $mode !== null ? new self($mode) : null;
        (new \ReflectionProperty(\PasswordPolicy::class, 'runtime'))->setValue(null, $runtime);
        return $runtime;
    }
}
