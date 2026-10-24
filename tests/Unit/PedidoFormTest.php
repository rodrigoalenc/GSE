<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PedidoFormTest extends TestCase
{
    public function testCreateRemainsOneCompleteSubmittableFormWithoutJavascript(): void
    {
        $record = null;
        $suppliers = [];
        $draft = ['titulo' => 'Pedido <teste>', 'valor' => '1.250,00'];
        ob_start();
        require ROOT_PATH . '/src/Views/contratos/form.php';
        $html = (string) ob_get_clean();
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($document);
        self::assertSame(3, $xpath->query('//form/fieldset[@data-pedido-step]')->length);
        self::assertSame(0, $xpath->query('//form/fieldset[@data-pedido-step][@hidden or @disabled]')->length);
        self::assertSame(1, $xpath->query('//form//button[@type="submit"]')->length);
        self::assertSame('Pedido <teste>', $xpath->query('//input[@name="titulo"]')->item(0)->getAttribute('value'));
        self::assertSame('1.250,00', $xpath->query('//input[@name="valor"]')->item(0)->getAttribute('value'));
        self::assertSame(0, $xpath->query('//script')->length);
    }
}
