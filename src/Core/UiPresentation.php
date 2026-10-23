<?php
declare(strict_types=1);

final class UiPresentation
{
    public static function localNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(Config::string('APP_TIMEZONE', 'America/Cuiaba')));
    }

    public static function civilDate(?string $value): string
    {
        if ($value === null || $value === '') { return '—'; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) { return 'Data inválida'; }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $date->format('d/m/Y') : 'Data inválida';
    }

    public static function localDateTime(?string $value): string
    {
        if ($value === null || $value === '') { return '—'; }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone(Config::string('APP_TIMEZONE', 'America/Cuiaba')))->format('d/m/Y H:i:s');
        } catch (Exception) { return 'Data inválida'; }
    }

    /**
     * @param array<string> $errors
     * @param array<string, array<string>> $terms
     * @return array<string, array<string>>
     */
    public static function fieldErrors(array $errors, array $terms): array
    {
        $fields = [];
        foreach ($errors as $error) {
            foreach ($terms as $field => $needles) {
                foreach ($needles as $needle) {
                    if (mb_stripos($error, $needle) !== false) { $fields[$field][] = $error; break; }
                }
            }
        }
        return $fields;
    }

    /** @param array<string, array<string>> $errors */
    public static function fieldAttributes(array $errors, string $field): string
    {
        return isset($errors[$field]) ? ' aria-invalid="true" aria-describedby="' . htmlspecialchars($field . '-error', ENT_QUOTES, 'UTF-8') . '"' : '';
    }
}
