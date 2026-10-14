<?php

declare(strict_types=1);

final class DvaStatus
{
    public const SEM_DVA = 'sem_dva';
    public const VENCIDA = 'vencida';
    public const VENCE_HOJE = 'vence_hoje';
    public const A_VENCER = 'a_vencer';
    public const VIGENTE = 'vigente';

    /** @var list<string> */
    public const ALL = [self::SEM_DVA, self::VENCIDA, self::VENCE_HOJE, self::A_VENCER, self::VIGENTE];

    private DateTimeImmutable $today;
    private int $warningDays;

    public function __construct(?DateTimeImmutable $today = null, ?int $warningDays = null)
    {
        $this->today = ($today ?? self::configuredToday())->setTime(0, 0);
        $this->warningDays = $warningDays !== null && $warningDays >= 1 && $warningDays <= 365
            ? $warningDays
            : Config::int('DVA_WARNING_DAYS', 30, 1, 365);
    }

    public function classify(?string $expirationDate): string
    {
        if ($expirationDate === null || !$this->isValidDate($expirationDate)) {
            return self::SEM_DVA;
        }

        if ($expirationDate < $this->today()) {
            return self::VENCIDA;
        }

        if ($expirationDate === $this->today()) {
            return self::VENCE_HOJE;
        }

        return $expirationDate <= $this->warningLimit() ? self::A_VENCER : self::VIGENTE;
    }

    public function daysRemaining(?string $expirationDate): ?int
    {
        if ($expirationDate === null || !$this->isValidDate($expirationDate)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expirationDate, $this->today->getTimezone());

        return $date === false ? null : (int) $this->today->diff($date)->format('%r%a');
    }

    public function today(): string
    {
        return $this->today->format('Y-m-d');
    }

    public function warningLimit(): string
    {
        return $this->today->modify('+' . $this->warningDays . ' days')->format('Y-m-d');
    }

    public function emailWarningLimit(): string
    {
        $days = Config::int('DVA_EMAIL_WARNING_DAYS', 15, 1, 365);

        return $this->today->modify('+' . $days . ' days')->format('Y-m-d');
    }

    /** @return array{sql:string, params:array<string,string>} */
    public function filter(string $status, string $dvaAlias = 'd'): array
    {
        if (!in_array($status, self::ALL, true) || preg_match('/^[a-z][a-z0-9_]*$/i', $dvaAlias) !== 1) {
            return ['sql' => '', 'params' => []];
        }

        $date = $dvaAlias . '.data_vencimento';
        $id = $dvaAlias . '.id';

        return match ($status) {
            self::SEM_DVA => ['sql' => "{$id} IS NULL", 'params' => []],
            self::VENCIDA => ['sql' => "{$id} IS NOT NULL AND {$date} < :dva_today", 'params' => ['dva_today' => $this->today()]],
            self::VENCE_HOJE => ['sql' => "{$id} IS NOT NULL AND {$date} = :dva_today", 'params' => ['dva_today' => $this->today()]],
            self::A_VENCER => [
                'sql' => "{$id} IS NOT NULL AND {$date} > :dva_today AND {$date} <= :dva_warning_limit",
                'params' => ['dva_today' => $this->today(), 'dva_warning_limit' => $this->warningLimit()],
            ],
            self::VIGENTE => ['sql' => "{$id} IS NOT NULL AND {$date} > :dva_warning_limit", 'params' => ['dva_warning_limit' => $this->warningLimit()]],
        };
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::VENCIDA => 'Vencida',
            self::VENCE_HOJE => 'Vence hoje',
            self::A_VENCER => 'A vencer',
            self::VIGENTE => 'Vigente',
            default => 'Sem DVA',
        };
    }

    private function isValidDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->today->getTimezone());

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private static function configuredToday(): DateTimeImmutable
    {
        $timezoneName = Config::string('APP_TIMEZONE', 'America/Cuiaba');

        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (Throwable) {
            $timezone = new DateTimeZone('America/Cuiaba');
        }

        return new DateTimeImmutable('today', $timezone);
    }
}
