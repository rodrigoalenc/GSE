<?php

declare(strict_types=1);

// Bounded synthetic volume check, not a load test against a server or school.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gse-audit-volume-' . bin2hex(random_bytes(8)) . '.sqlite';
$_ENV['DB_PATH'] = $temporary; putenv('DB_PATH=' . $temporary);
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/bootstrap.php';
$pdo = Model::getConexao();
try {
    \src\Core\DatabaseInitializer::initialize($pdo);
    $pdo->beginTransaction();
    $insert = $pdo->prepare('INSERT INTO alunos(nome_completo,nome_normalizado,data_nascimento) VALUES(?,?,?)');
    for ($i = 0; $i < 10000; $i++) {
        $name = sprintf('Aluno sintético %05d', $i);
        $insert->execute([$name, $name, '2010-10-15']);
    }
    $pdo->commit();
    memory_reset_peak_usage(); $before = memory_get_usage(true); $started = hrtime(true);
    $model = new Aluno(); $panel = new Painel();
    $summary = $panel->resumo(); $groups = $panel->alunosPorStatus();
    $birthdays = $model->aniversariantesDoMesCompleto(new DateTimeImmutable('2026-10-30'));
    $birthdayTotal = $model->contarAniversariantesDoMes(new DateTimeImmutable('2026-10-30'));
    $search = $panel->buscarAlunos(['q' => 'sintético'], 1, 15);
    $pdfRejected = false;
    try { (new RelatorioAlunos())->query([], 1, true, 500); } catch (DomainException) { $pdfRejected = true; }
    $elapsed = (hrtime(true) - $started) / 1000000;
    $extraMemory = max(0, memory_get_peak_usage(true) - $before);
    $ok = $summary['alunos_ativos'] === 10000 && count($groups[DvaStatus::SEM_DVA]) === 10
        && count($birthdays) === 20 && $birthdayTotal === 10000 && count($search['items']) === 15
        && $search['total'] === 10000 && $pdfRejected && $extraMemory < 32 * 1024 * 1024;
    echo json_encode(['ok' => $ok, 'synthetic_students' => 10000, 'elapsed_ms' => round($elapsed, 2),
        'additional_peak_bytes' => $extraMemory, 'birthday_rows' => count($birthdays), 'search_rows' => count($search['items']),
        'dva_rows' => count($groups[DvaStatus::SEM_DVA]), 'pdf_rejected' => $pdfRejected,
        'scope' => 'Consultas locais sequenciais; não mede capacidade de produção.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} finally {
    $insert = null; $model = null; $panel = null;
    Model::setConexao(null); $pdo = null; gc_collect_cycles();
    foreach ([$temporary, $temporary . '-wal', $temporary . '-shm', $temporary . '-journal'] as $file) { if (is_file($file)) { unlink($file); } }
}
exit($ok ? 0 : 1);
