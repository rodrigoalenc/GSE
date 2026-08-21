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
        $result = ['warnings' => count($warnings), 'recipients' => count($recipients), 'sent' => 0, 'skipped' => 0, 'failed' => 0];

        if ($warnings === [] || $recipients === []) {
            TechnicalLogger::info('dva_notification_nothing_to_send', [
                'warning_count' => count($warnings),
                'recipient_count' => count($recipients),
            ]);

            return $result;
        }

        [$subject, $html, $text] = $this->message($warnings, $status);

        foreach ($recipients as $recipient) {
            $userId = (int) $recipient['id'];

            if (!$this->claim($status->today(), $userId)) {
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

    /** @return list<array<string,mixed>> */
    private function warnings(DvaStatus $status): array
    {
        $statement = $this->pdo->prepare(
            'SELECT a.id, a.nome_completo, t.nome_turma, d.data_vencimento
             FROM dvas d
             JOIN alunos a ON a.id = d.id_aluno AND a.ativo = 1
             LEFT JOIN turmas t ON t.id = a.id_turma
             WHERE d.ativo = 1 AND d.data_vencimento <= :limit
             ORDER BY d.data_vencimento, a.nome_completo COLLATE NOCASE'
        );
        $statement->execute(['limit' => $status->emailWarningLimit()]);

        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    private function recipients(): array
    {
        return $this->pdo->query(
            "SELECT id, nome, email FROM usuarios
             WHERE ativo = 1 AND tipo = 'administrador' AND recebe_alertas_dva = 1
             ORDER BY id"
        )->fetchAll();
    }

    private function claim(string $date, int $userId): bool
    {
        return SqliteTransaction::immediate($this->pdo, function (PDO $pdo) use ($date, $userId): bool {
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

    /**
     * @param list<array<string,mixed>> $warnings
     * @return array{0:string,1:string,2:string}
     */
    private function message(array $warnings, DvaStatus $status): array
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rows = '';
        $textRows = [];

        foreach ($warnings as $item) {
            $label = DvaStatus::label($status->classify((string) $item['data_vencimento']));
            $rows .= '<tr><td>' . $escape((string) $item['nome_completo']) . '</td><td>'
                . $escape((string) ($item['nome_turma'] ?: 'Sem turma')) . '</td><td>'
                . $escape((string) $item['data_vencimento']) . '</td><td>' . $escape($label) . '</td></tr>';
            $textRows[] = sprintf(
                '- %s | %s | %s | %s',
                (string) $item['nome_completo'],
                (string) ($item['nome_turma'] ?: 'Sem turma'),
                (string) $item['data_vencimento'],
                $label
            );
        }

        $subject = 'GSE: resumo de DVAs que exigem atenção';
        $html = '<!doctype html><html lang="pt-BR"><body><h1>Resumo de DVAs</h1>'
            . '<p>Foram encontrados ' . count($warnings) . ' registro(s) vencido(s) ou próximo(s) do vencimento.</p>'
            . '<table><thead><tr><th>Aluno</th><th>Turma</th><th>Vencimento</th><th>Situação</th></tr></thead><tbody>'
            . $rows . '</tbody></table><p>Acesse o GSE para revisar os dados.</p></body></html>';
        $text = "Resumo de DVAs\n\n" . implode("\n", $textRows) . "\n\nAcesse o GSE para revisar os dados.";

        return [$subject, $html, $text];
    }
}
