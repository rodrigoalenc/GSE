<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

final class PhpMailerTransport implements MailTransport
{
    /** @param array{host:string,port:int,username:string,password:string,encryption:string,from_address:string,from_name:string} $config */
    public function __construct(private readonly array $config)
    {
    }

    public static function fromConfig(): self
    {
        $config = [
            'host' => Config::string('MAIL_HOST'),
            'port' => Config::int('MAIL_PORT', 587, 1, 65535),
            'username' => Config::string('MAIL_USERNAME'),
            'password' => Config::string('MAIL_PASSWORD'),
            'encryption' => strtolower(Config::string('MAIL_ENCRYPTION', 'tls')),
            'from_address' => Config::string('MAIL_FROM_ADDRESS'),
            'from_name' => Config::string('MAIL_FROM_NAME', 'GSE'),
        ];

        if ($config['host'] === ''
            || filter_var($config['from_address'], FILTER_VALIDATE_EMAIL) === false
            || ($config['username'] !== '' && $config['password'] === '')) {
            throw new RuntimeException('A configuração de e-mail está incompleta ou inválida.');
        }

        self::securitySettings($config['encryption'], $config['port'], Config::isProduction());

        return new self($config);
    }

    /** @return array{secure:string,auto_tls:bool} */
    public static function securitySettings(string $encryption, int $port, bool $production): array
    {
        $encryption = strtolower(trim($encryption));
        $encryption = $encryption === '' ? 'none' : $encryption;

        if (!in_array($encryption, ['none', 'tls', 'smtps'], true)) {
            throw new RuntimeException('MAIL_ENCRYPTION possui um valor inválido.');
        }

        if ($production && $encryption === 'none') {
            throw new RuntimeException('E-mail sem criptografia não é permitido em produção.');
        }

        if (($encryption === 'tls' && $port === 465) || ($encryption === 'smtps' && $port === 587)) {
            throw new RuntimeException('MAIL_PORT não é coerente com MAIL_ENCRYPTION.');
        }

        return match ($encryption) {
            'tls' => ['secure' => PHPMailer::ENCRYPTION_STARTTLS, 'auto_tls' => true],
            'smtps' => ['secure' => PHPMailer::ENCRYPTION_SMTPS, 'auto_tls' => false],
            default => ['secure' => '', 'auto_tls' => false],
        };
    }

    public function send(string $recipientAddress, string $recipientName, string $subject, string $html, string $text): void
    {
        if (filter_var($recipientAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Destinatário inválido.');
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->config['host'];
        $mail->Port = $this->config['port'];
        $mail->SMTPAuth = $this->config['username'] !== '';
        $mail->Username = $this->config['username'];
        $mail->Password = $this->config['password'];

        $security = self::securitySettings($this->config['encryption'], $this->config['port'], Config::isProduction());
        $mail->SMTPSecure = $security['secure'];
        $mail->SMTPAutoTLS = $security['auto_tls'];

        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom($this->config['from_address'], $this->config['from_name']);
        $mail->addAddress($recipientAddress, $recipientName);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = $text;
        $mail->send();
    }
}
