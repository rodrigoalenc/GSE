<?php

declare(strict_types=1);

use src\Core\SqliteTransaction;

final class DvaNotificationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MailTransport $transport,
        private readonly ?DvaStatus $statusService = null
    ) {
    }

    /** @return array{warnings:int,recipients:int,sent:int,skipped:int,failed:int} */
    public function notify(): array
    {
        $status = $this->statusService ?? new DvaStatus();
        $warnings = $this->warnings($status);
        $recipients = $this->recipients();
        $result = ['warnings' => $warnings, 'recipients' => count($recipients), 'sent' => 0, 'skipped' => 0, 'failed' => 0];

        if ($warnings === 0 || $recipients === []) {
            TechnicalLogger::info('dva_notification_nothing_to_send', [
                'warning_count' => $warnings,
                'recipient_count' => count($recipients),
            ]);

            return $result;
        }

        [$subject, $html, $text] = $this->message($warnings);

        foreach ($recipients as $recipient) {
            $userId = (int) $recipient['id'];

            if (!$this->claim($status->today(), $userId, (string) $recipient['email'])) {
                $result['skipped']++;
                continue;
            }

            TechnicalLogger::info('dva_notification_attempt', ['recipient_user_id' => $userId]);

            try {
                $this->transport->send(
                    (string) $recipient['email'],
                    (string) $recipient['nome'],
                    $subject,
                    $html,
                    $text
                );
                $this->mark($status->today(), $userId, 'sent', null);
                $result['sent']++;
                TechnicalLogger::info('dva_notification_sent', ['recipient_user_id' => $userId]);
            } catch (Throwable $exception) {
                $this->mark($status->today(), $userId, 'failed', $exception::class);
                $result['failed']++;
                TechnicalLogger::error('dva_notification_failed', [
                    'recipient_user_id' => $userId,
                    'exception' => $exception::class,
                ]);
            }
        }

        return $result;
    }

    private function warnings(DvaStatus $status): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM dvas d
             JOIN alunos a ON a.id = d.id_aluno AND a.ativo = 1
             WHERE d.ativo = 1 AND d.data_vencimento <= :limit'
        );
        $statement->execute(['limit' => $status->emailWarningLimit()]);
        return (int) $statement->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    private function recipients(): array
    {
        $recipients = $this->pdo->query(
            "SELECT id, nome, email FROM usuarios
             WHERE ativo = 1 AND tipo = 'administrador' AND recebe_alertas_dva = 1
             ORDER BY id"
        )->fetchAll();

        return array_values(array_filter(
            array_map([DataProtection::class, 'user'], $recipients),
            static fn (array $recipient): bool => filter_var($recipient['email'], FILTER_VALIDATE_EMAIL) !== false
        ));
    }

    private function claim(string $date, int $userId, string $email): bool
    {
        return SqliteTransaction::immediate($this->pdo, function (PDO $pdo) use ($date, $userId, $email): bool {
            $active = $pdo->prepare(
                "SELECT 1 FROM usuarios WHERE id = :user AND ativo = 1 AND tipo = 'administrador'
                 AND recebe_alertas_dva = 1 AND email_lookup = :email"
            );
            $active->execute(['user' => $userId, 'email' => DataProtection::emailIndex($email)]);
            if ($active->fetchColumn() === false) {
                return false;
            }

            $existing = $pdo->prepare(
                'SELECT status, sent_at FROM dva_notification_deliveries WHERE notification_date = :date AND user_id = :user'
            );
            $existing->execute(['date' => $date, 'user' => $userId]);
            $delivery = $existing->fetch();

            if (is_array($delivery) && $delivery['status'] === 'sent') {
                return false;
            }

            $staleBefore = gmdate('Y-m-d H:i:s', time() - 3600);

            if (is_array($delivery)
                && $delivery['status'] === 'processing'
                && (string) ($delivery['sent_at'] ?? '') >= $staleBefore) {
                return false;
            }

            $claim = $pdo->prepare(
                "INSERT INTO dva_notification_deliveries
                    (notification_date, user_id, sent_at, status, last_error_code)
                 VALUES (:date, :user, :claimed_at, 'processing', NULL)
                 ON CONFLICT(notification_date, user_id) DO UPDATE SET
                    status = 'processing', sent_at = :claimed_at, last_error_code = NULL
                 WHERE dva_notification_deliveries.status = 'failed'
                    OR (dva_notification_deliveries.status = 'processing'
                        AND dva_notification_deliveries.sent_at < :stale_before)"
            );
            $claim->execute([
                'date' => $date,
                'user' => $userId,
                'claimed_at' => gmdate('Y-m-d H:i:s'),
                'stale_before' => $staleBefore,
            ]);

            return $claim->rowCount() === 1;
        });
    }

    private function mark(string $date, int $userId, string $status, ?string $errorCode): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE dva_notification_deliveries
             SET status = :status, sent_at = :sent_at, last_error_code = :error
             WHERE notification_date = :date AND user_id = :user'
        );
        $statement->execute([
            'status' => $status,
            'sent_at' => $status === 'sent' ? gmdate('Y-m-d H:i:s') : null,
            'error' => $errorCode === null ? null : mb_substr($errorCode, 0, 120),
            'date' => $date,
            'user' => $userId,
        ]);
    }

    /** @return array{0:string,1:string,2:string} */
    private function message(int $warnings): array
    {
        $subject = 'GSE: registros para revisar';
        $summary = sprintf('Há %d registro(s) que precisam de revisão no GSE.', $warnings);
        $base = Config::appUrl();
        $scheme = strtolower((string) parse_url($base, PHP_URL_SCHEME));
        $link = filter_var($base, FILTER_VALIDATE_URL) !== false && in_array($scheme, ['http', 'https'], true)
            ? $base . '/dva' : '';
        $html = '<!doctype html><html lang="pt-BR"><body><h1>Revisão de registros</h1><p>' . $summary . '</p>';
        $text = $summary . "\n\nEntre no GSE com sua conta autorizada para consultar os registros.";
        if ($link !== '') {
            $html .= '<p><a href="' . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Entrar no GSE</a></p>';
            $text .= "\n" . $link;
        } else {
            $html .= '<p>Entre no endereço institucional do GSE com sua conta autorizada.</p>';
        }
        return [$subject, $html . '</body></html>', $text];
    }
}
