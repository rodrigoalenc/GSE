<?php

declare(strict_types=1);

final class CertidaoStatus
{
    public const LABELS = ['vigente' => 'Vigente', 'a_vencer' => 'A vencer', 'vence_hoje' => 'Vence hoje', 'vencida' => 'Vencida', 'pendente' => 'Data pendente'];
    private readonly DateTimeImmutable $date;
    private readonly int $days;

    public function __construct(?DateTimeImmutable $now = null, ?int $days = null)
    {
        $zone = new DateTimeZone(Config::string('APP_TIMEZONE', 'America/Cuiaba'));
        $this->date = ($now ?? new DateTimeImmutable('now', $zone))->setTimezone($zone)->setTime(0, 0);
        $raw = $days ?? Config::string('CERTIDAO_WARNING_DAYS', '15');
        $parsed = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
        if ($parsed === false) {
            throw new RuntimeException('CERTIDAO_WARNING_DAYS deve estar entre 1 e 365.');
        }
        $this->days = $parsed;
    }

    public static function validDate(string $value): bool
    {
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) !== 1 || substr($value, 0, 4) === '0000') {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    public function today(): string { return $this->date->format('Y-m-d'); }
    public function limit(): string { return $this->date->modify('+' . $this->days . ' days')->format('Y-m-d'); }

    public function deadline(?string $value): string
    {
        if ($value === null || !self::validDate($value)) { return 'Prazo pendente de revisão'; }
        $days = (int)$this->date->diff(new DateTimeImmutable($value, $this->date->getTimezone()))->format('%r%a');
        if ($days === 0) { return 'Vence hoje'; }
        $unit = abs($days) === 1 ? ' dia' : ' dias';
        return $days < 0 ? 'Vencida há ' . abs($days) . $unit : 'Vence em ' . $days . $unit;
    }

    public function classify(?string $value): string
    {
        if ($value === null || !self::validDate($value)) { return 'pendente'; }
        if ($value < $this->today()) { return 'vencida'; }
        if ($value === $this->today()) { return 'vence_hoje'; }
        return $value <= $this->limit() ? 'a_vencer' : 'vigente';
    }
}
