<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Model/Certidao.php';

use src\Core\SqliteTransaction;

final class CertidaoNotificationService
{
    public function __construct(private readonly PDO $pdo, private readonly MailTransport $transport, private readonly ?CertidaoStatus $dates = null) {}

    /** @return array{warnings:int,recipients:int,sent:int,skipped:int,failed:int} */
    public function notify(): array
    {
        $dates = $this->dates ?? new CertidaoStatus();
        $warnings = [];
        $q = $this->pdo->query('SELECT c.id,c.data_vencimento,f.nome AS fornecedor,t.nome AS tipo FROM certidoes c JOIN lista_fornecedores f ON f.id=c.id_fornecedor JOIN lista_tipos_certidao t ON t.id=c.id_tipo_certidao WHERE ' . Certidao::STATE_SQL . " = 'corrente' ORDER BY c.data_vencimento,c.id");
        foreach ($q->fetchAll() as $row) {
            if (in_array($dates->classify((string)$row['data_vencimento']), ['vencida','vence_hoje','a_vencer'], true)) { $warnings[] = $row; }
        }
        $recipients = array_values(array_filter($this->pdo->query("SELECT id,nome,email FROM usuarios WHERE ativo=1 AND tipo='administrador' ORDER BY id")->fetchAll(), static fn (array $row): bool => filter_var($row['email'], FILTER_VALIDATE_EMAIL) !== false));
        $result = ['warnings'=>count($warnings),'recipients'=>count($recipients),'sent'=>0,'skipped'=>0,'failed'=>0];
        if ($warnings === []) { return $result; }
        if ($recipients === []) { $result['failed'] = 1; return $result; }
        $lines = [];
        foreach ($warnings as $row) {
            $lines[] = '#' . $row['id'] . ' | ' . $row['fornecedor'] . ' | ' . $row['tipo'] . ' | ' . $row['data_vencimento'] . ' | ' . CertidaoStatus::LABELS[$dates->classify((string)$row['data_vencimento'])];
        }
        $text = "Certidões que exigem atenção em " . $dates->today() . "\n\n" . implode("\n", $lines);
        $html = '<h1>Certidões que exigem atenção</h1><pre>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
        foreach ($recipients as $recipient) {
            try {
                // Hold the write reservation until transport succeeds: other workers cannot claim this recipient.
                // A crash after SMTP acceptance but before COMMIT may still cause a duplicate retry.
                $sent = SqliteTransaction::immediate($this->pdo, function (PDO $pdo) use ($dates, $recipient, $text, $html): bool {
                    $q = $pdo->prepare('SELECT 1 FROM certidao_notification_deliveries WHERE notification_date=? AND user_id=?');
                    $q->execute([$dates->today(), $recipient['id']]);
                    if ($q->fetchColumn() !== false) { return false; }
                    $active = $pdo->prepare("SELECT 1 FROM usuarios WHERE id=? AND ativo=1 AND tipo='administrador' AND email=?");
                    $active->execute([$recipient['id'],$recipient['email']]);
                    if ($active->fetchColumn() === false) { return false; }
                    $this->transport->send((string)$recipient['email'], (string)$recipient['nome'], 'GSE: relatório diário de certidões', $html, $text);
                    $q = $pdo->prepare('INSERT INTO certidao_notification_deliveries(notification_date,user_id,sent_at) VALUES (?,?,?)');
                    $q->execute([$dates->today(),$recipient['id'],gmdate('Y-m-d H:i:s')]);
                    return true;
                });
                $result[$sent ? 'sent' : 'skipped']++;
            } catch (Throwable $exception) {
                $result['failed']++;
                TechnicalLogger::error('certidao_notification_failed', ['exception'=>$exception::class]);
            }
        }
        return $result;
    }
}
