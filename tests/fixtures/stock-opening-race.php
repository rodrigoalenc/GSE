<?php

declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';
require dirname(__DIR__).'/bootstrap.php';

$barrier=$argv[1] ?? '';
$contract=(int)($argv[2] ?? 0);
$item=(int)($argv[3] ?? 0);
$actor=(int)($argv[4] ?? 0);
$key=$argv[5] ?? '';
for ($attempt=0;$attempt<200 && !is_file($barrier);$attempt++) { usleep(10000); }
if (!is_file($barrier)) { echo 'TIMEOUT'; exit(2); }
Model::getConexao();
try {
    (new Contrato())->configureStock($contract,$item,0,10,1,$actor,0,$key,true);
    echo 'OK';
} catch (DomainException|PDOException) {
    echo 'NO';
}
