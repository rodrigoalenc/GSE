<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/src/Core/Helpers.php';
require_once ROOT_PATH . '/src/Core/Model.php';
require_once ROOT_PATH . '/src/Core/Router.php';
require_once ROOT_PATH . '/src/Model/Usuario.php';
require_once ROOT_PATH . '/src/Model/Aluno.php';
require_once ROOT_PATH . '/src/Model/Painel.php';
require_once ROOT_PATH . '/src/Model/Pedido.php';
require_once ROOT_PATH . '/src/Model/Certidao.php';
require_once ROOT_PATH . '/src/Model/Passivo.php';
require_once ROOT_PATH . '/src/Model/Relatorio.php';
require_once ROOT_PATH . '/src/Model/Sistema.php';
require_once ROOT_PATH . '/tests/Support/DatabaseTestCase.php';

if (!isset($_SESSION)) {
    $_SESSION = [];
}
