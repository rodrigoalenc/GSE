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
            || !in_array($config['encryption'], ['', 'none', 'tls', 'smtps'], true)
            || ($config['username'] !== '' && $config['password'] === '')) {
            throw new RuntimeException('A configuração de e-mail está incompleta ou inválida.');
        }

        return new self($config);
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

        if (in_array($this->config['encryption'], ['tls', 'smtps'], true)) {
            $mail->SMTPSecure = $this->config['encryption'];
        }

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
