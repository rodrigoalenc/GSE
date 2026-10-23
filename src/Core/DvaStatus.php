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
    /** @var WeakMap<PDO,bool>|null */
    private static ?WeakMap $registeredConnections = null;

    public function __construct(?DateTimeImmutable $today = null, ?int $warningDays = null)
    {
        $this->today = ($today ?? self::configuredToday())->setTime(0, 0);
        $this->warningDays = $warningDays !== null && $warningDays >= 1 && $warningDays <= 365
            ? $warningDays
            : Config::int('DVA_WARNING_DAYS', 30, 1, 365);
    }

    public function classify(?string $expirationDate): string
    {
        return self::classifyDate($expirationDate, $this->today(), $this->warningLimit());
    }

    private static function classifyDate(?string $expirationDate, string $today, string $warningLimit): string
    {
        if ($expirationDate === null || !self::isValidDate($expirationDate)) {
            return self::SEM_DVA;
        }

        if ($expirationDate < $today) {
            return self::VENCIDA;
        }

        if ($expirationDate === $today) {
            return self::VENCE_HOJE;
        }

        return $expirationDate <= $warningLimit ? self::A_VENCER : self::VIGENTE;
    }

    public static function registerSqliteFunctions(PDO $pdo): void
    {
        self::$registeredConnections ??= new WeakMap();
        if (isset(self::$registeredConnections[$pdo])) {
            return;
        }

        $registered = $pdo->sqliteCreateFunction(
            'gse_dva_status',
            static fn (mixed $date, string $today, string $warningLimit): string => self::classifyDate(
                is_string($date) ? $date : null, $today, $warningLimit
            ),
            3,
            PDO::SQLITE_DETERMINISTIC
        );
        if (!$registered) {
            throw new RuntimeException('Não foi possível registrar a classificação DVA.');
        }
        self::$registeredConnections[$pdo] = true;
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

        $expression = $this->sqlExpression($dvaAlias);
        return ['sql' => $expression['sql'] . ' = :dva_status', 'params' => $expression['params'] + ['dva_status' => $status]];
    }

    /** @return array{sql:string, params:array<string,string>} */
    public function sqlExpression(string $dvaAlias = 'd'): array
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/i', $dvaAlias) !== 1) {
            throw new InvalidArgumentException('Alias DVA inválido.');
        }
        return [
            'sql' => "gse_dva_status({$dvaAlias}.data_vencimento, :dva_today, :dva_warning_limit)",
            'params' => ['dva_today' => $this->today(), 'dva_warning_limit' => $this->warningLimit()],
        ];
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

    private static function isValidDate(string $date): bool
    {
        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $date) !== 1) {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

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
