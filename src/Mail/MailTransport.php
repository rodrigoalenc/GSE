<?php

declare(strict_types=1);

interface MailTransport
{
    public function send(string $recipientAddress, string $recipientName, string $subject, string $html, string $text): void;
}
