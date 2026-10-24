<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/bootstrap.php';
$record = null;
$suppliers = [['id'=>1, 'nome'=>'Fornecedor fictício', 'ativo'=>1]];
$draft = [];
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Novo pedido fictício</title><link href="/bootstrap.css" rel="stylesheet"><link href="/painel.css" rel="stylesheet"><link href="/modulo5.css" rel="stylesheet"><link href="/app.css" rel="stylesheet"><link href="/pedidos.css" rel="stylesheet"><script src="/pedidos.js" defer></script><script src="/app.js" defer></script></head><body><main><?php require ROOT_PATH . '/src/Views/contratos/form.php'; ?></main></body></html>
