<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!extension_loaded('curl')) {
    fwrite(STDERR, "A extensão cURL do PHP é necessária para o teste HTTP.\n");
    exit(1);
}

$root = dirname(__DIR__);
$options = getopt('', ['port::']);
$port = filter_var($options['port'] ?? null, FILTER_VALIDATE_INT) ?: random_int(18000, 28000);
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gse-http-' . bin2hex(random_bytes(6));
mkdir($tempRoot, 0700, true);
$database = $tempRoot . DIRECTORY_SEPARATOR . 'http.sqlite';
$cookieAdmin = $tempRoot . DIRECTORY_SEPARATOR . 'admin.cookies';
$cookieEmployee = $tempRoot . DIRECTORY_SEPARATOR . 'employee.cookies';
$cookieProduction = $tempRoot . DIRECTORY_SEPARATOR . 'production.cookies';
$cookieGuest = $tempRoot . DIRECTORY_SEPARATOR . 'guest.cookies';
$serverOut = $tempRoot . DIRECTORY_SEPARATOR . 'server.out.log';
$serverError = $tempRoot . DIRECTORY_SEPARATOR . 'server.error.log';
$process = null;
$failure = null;
$checks = 0;
$temporaryPassword = 'Temporária HTTP segura 2026';
$permanentPassword = 'Frase definitiva HTTP 2027';

/** @param array<string, string> $environment */
function runCli(array $arguments, array $environment): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($arguments, $descriptors, $pipes, dirname(__DIR__), $environment);

    if (!is_resource($process)) {
        throw new RuntimeException('Não foi possível iniciar o comando de teste.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    return [$exit, $stdout, $stderr];
}

/** @param array<string, string> $environment */
function startServer(int $port, array $environment, string $stdout, string $stderr): mixed
{
    $descriptors = [
        1 => ['file', $stdout, 'ab'],
        2 => ['file', $stderr, 'ab'],
    ];

    return proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', 'public', 'public/index.php'],
        $descriptors,
        $pipes,
        dirname(__DIR__),
        $environment
    );
}

function stopServer(mixed &$process): void
{
    if (is_resource($process)) {
        $status = proc_get_status($process);

        if (($status['running'] ?? false) && PHP_OS_FAMILY === 'Windows') {
            $pid = (int) ($status['pid'] ?? 0);

            if ($pid > 0) {
                exec('taskkill /PID ' . $pid . ' /T /F >NUL 2>&1');
            }
        } elseif ($status['running'] ?? false) {
            proc_terminate($process, 15);
        }

        proc_close($process);
        $process = null;
    }
}

/** @return array{status:int,headers:array<string,string>,body:string} */
function request(string $method, string $url, string $cookie, array $data = [], array $headers = []): array
{
    $responseHeaders = [];
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            $length = strlen($line);

            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $key = strtolower(trim($name));
                $responseHeaders[$key] = isset($responseHeaders[$key])
                    ? $responseHeaders[$key] . ', ' . trim($value)
                    : trim($value);
            }

            return $length;
        },
    ]);

    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($handle, CURLOPT_HTTPHEADER, array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers));
    }

    $body = curl_exec($handle);

    if ($body === false) {
        throw new RuntimeException('Falha HTTP: ' . curl_error($handle));
    }

    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $body];
}

function csrf(string $html): string
{
    if (preg_match('/name="_csrf_token" value="([a-f0-9]{64})"/', $html, $match) !== 1) {
        throw new RuntimeException('Token CSRF não encontrado na resposta.');
    }

    return $match[1];
}

function sessionCookieValue(string $cookieFile): string
{
    if (!is_file($cookieFile)) {
        return '';
    }

    foreach (array_reverse(file($cookieFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
        if ((!str_starts_with($line, '#') || str_starts_with($line, '#HttpOnly_'))
            && str_contains($line, "\tgse_session\t")) {
            $parts = explode("\t", $line);

            return (string) end($parts);
        }
    }

    return '';
}

function checkHttp(bool $condition, string $name, string $detail = ''): void
{
    global $checks;
    $checks++;

    if (!$condition) {
        throw new RuntimeException("FALHOU: {$name}" . ($detail !== '' ? " ({$detail})" : ''));
    }

    fwrite(STDOUT, "PASSOU: {$name}\n");
}

function waitForServer(string $url, string $cookie, array $headers = []): void
{
    for ($attempt = 0; $attempt < 50; $attempt++) {
        try {
            $response = request('GET', $url, $cookie, [], $headers);

            if ($response['status'] > 0) {
                return;
            }
        } catch (Throwable) {
        }

        usleep(100_000);
    }

    throw new RuntimeException('O servidor HTTP não ficou pronto.');
}

$baseEnvironment = array_merge(getenv(), [
    'APP_ENV' => 'testing',
    'APP_URL' => '',
    'APP_ALLOWED_HOSTS' => '',
    'DB_PATH' => $database,
    'LOG_PATH' => $tempRoot . DIRECTORY_SEPARATOR . 'technical.log',
    'FORCE_HTTPS' => 'false',
    'TRUSTED_PROXIES' => '',
    'LOGIN_DELAY_BASE_MS' => '0',
    'LOGIN_DELAY_MAX_MS' => '0',
    'GSE_ADMIN_PASSWORD' => $temporaryPassword,
]);

try {
    [$exit, $stdout, $stderr] = runCli([PHP_BINARY, 'bin/init-db.php'], $baseEnvironment);
    checkHttp($exit === 0, 'Inicialização limpa do SQLite', trim($stderr));
    [$exit, $stdout, $stderr] = runCli(
        [PHP_BINARY, 'bin/create-admin.php', '--name=Administrador HTTP', '--email=admin@example.test'],
        $baseEnvironment
    );
    checkHttp($exit === 0 && str_contains($stdout, 'sucesso'), 'Criação do primeiro administrador temporário', trim($stderr));

    unset($baseEnvironment['GSE_ADMIN_PASSWORD']);
    $process = startServer($port, $baseEnvironment, $serverOut, $serverError);
    $baseUrl = "http://127.0.0.1:{$port}";
    waitForServer($baseUrl . '/login', $cookieAdmin);

    $guestDashboard = request('GET', $baseUrl . '/dashboard', $cookieGuest);
    checkHttp($guestDashboard['status'] === 302 && str_contains($guestDashboard['headers']['location'] ?? '', '/login'), 'Acesso sem autenticação redireciona ao login');

    $login = request('GET', $baseUrl . '/login', $cookieAdmin);
    $preLoginSession = sessionCookieValue($cookieAdmin);
    checkHttp($login['status'] === 200, 'GET /login', 'HTTP ' . $login['status'] . ' ' . mb_substr(strip_tags($login['body']), 0, 180));
    checkHttp(isset($login['headers']['x-request-id']), 'Identificador de requisição');
    checkHttp(!str_contains($login['headers']['content-security-policy'] ?? '', 'unsafe-inline'), 'CSP sem unsafe-inline');
    checkHttp(!isset($login['headers']['strict-transport-security']), 'HSTS ausente em HTTP direto');

    $invalid = request('POST', $baseUrl . '/login/entrar', $cookieAdmin, [
        '_csrf_token' => csrf($login['body']),
        'email' => 'admin@example.test',
        'senha' => 'valor de teste incorreto',
    ]);
    checkHttp($invalid['status'] === 422 && str_contains($invalid['body'], 'E-mail ou senha inválidos'), 'Login inválido genérico');

    $login = request('GET', $baseUrl . '/login', $cookieAdmin);
    $valid = request('POST', $baseUrl . '/login/entrar', $cookieAdmin, [
        '_csrf_token' => csrf($login['body']),
        'email' => 'ADMIN@example.test',
        'senha' => $temporaryPassword,
    ]);
    checkHttp($valid['status'] === 302 && str_contains($valid['headers']['location'] ?? '', '/senha/alterar'), 'Login temporário exige troca');
    checkHttp($preLoginSession !== '' && sessionCookieValue($cookieAdmin) !== $preLoginSession, 'Identificador de sessão regenerado no login');

    $blockedDashboard = request('GET', $baseUrl . '/dashboard', $cookieAdmin);
    checkHttp($blockedDashboard['status'] === 302 && str_contains($blockedDashboard['headers']['location'] ?? '', '/senha/alterar'), 'Dashboard bloqueado até troca');

    $passwordPage = request('GET', $baseUrl . '/senha/alterar', $cookieAdmin);
    $changed = request('POST', $baseUrl . '/senha/alterar', $cookieAdmin, [
        '_csrf_token' => csrf($passwordPage['body']),
        'senha_atual' => $temporaryPassword,
        'nova_senha' => $permanentPassword,
        'confirmar_senha' => $permanentPassword,
    ]);
    checkHttp($changed['status'] === 302 && str_contains($changed['headers']['location'] ?? '', '/login'), 'Troca obrigatória força novo login');

    $login = request('GET', $baseUrl . '/login', $cookieAdmin);
    $valid = request('POST', $baseUrl . '/login/entrar', $cookieAdmin, [
        '_csrf_token' => csrf($login['body']),
        'email' => 'admin@example.test',
        'senha' => $permanentPassword,
    ]);
    checkHttp($valid['status'] === 302 && str_contains($valid['headers']['location'] ?? '', '/dashboard'), 'Login com senha definitiva');

    $dashboard = request('GET', $baseUrl . '/dashboard', $cookieAdmin);
    checkHttp(
        $dashboard['status'] === 200
        && str_contains($dashboard['body'], 'Alunos ativos')
        && str_contains($dashboard['body'], 'Segurança e controle de acesso'),
        'Dashboard integra os Módulos 1 e 2'
    );

    $createPage = request('GET', $baseUrl . '/usuario/criar', $cookieAdmin);
    $employeeTemporaryPassword = 'Inicial funcionário HTTP 2026';
    $createdUser = request('POST', $baseUrl . '/usuario/criar', $cookieAdmin, [
        '_csrf_token' => csrf($createPage['body']),
        'nome' => 'Funcionário HTTP',
        'email' => 'employee@example.test',
        'tipo' => 'funcionario',
        'senha' => $employeeTemporaryPassword,
        'confirmar_senha' => $employeeTemporaryPassword,
    ]);
    checkHttp($createdUser['status'] === 302 && str_contains($createdUser['headers']['location'] ?? '', '/usuario'), 'Criação administrativa de usuário');

    $editPage = request('GET', $baseUrl . '/usuario/editar/2', $cookieAdmin);
    $editedUser = request('POST', $baseUrl . '/usuario/editar/2', $cookieAdmin, [
        '_csrf_token' => csrf($editPage['body']),
        'nome' => 'Funcionário HTTP Editado',
        'email' => 'employee@example.test',
        'tipo' => 'funcionario',
        'senha' => '',
        'confirmar_senha' => '',
    ]);
    checkHttp($editedUser['status'] === 302, 'Edição administrativa de usuário');

    $classPage = request('GET', $baseUrl . '/turma/criar', $cookieAdmin);
    $createdClass = request('POST', $baseUrl . '/turma/criar', $cookieAdmin, [
        '_csrf_token' => csrf($classPage['body']),
        'nome_turma' => 'Turma HTTP A',
        'ano_letivo' => date('Y'),
    ]);
    checkHttp($createdClass['status'] === 302 && str_contains($createdClass['headers']['location'] ?? '', '/turma'), 'Criação administrativa de turma');

    $studentPage = request('GET', $baseUrl . '/aluno/criar', $cookieAdmin);
    $initialExpiration = date('Y-m-d', strtotime('+10 days'));
    $createdStudent = request('POST', $baseUrl . '/aluno/criar', $cookieAdmin, [
        '_csrf_token' => csrf($studentPage['body']),
        'nome_completo' => 'Aluno HTTP <Seguro>',
        'data_nascimento' => '2011-05-10',
        'id_turma' => '1',
        'telefone_aluno' => '(65) 99999-0000',
        'telefone_responsavel' => '(65) 3333-0000',
        'data_vencimento' => $initialExpiration,
        'observacao' => 'DVA inicial HTTP',
    ]);
    checkHttp($createdStudent['status'] === 302 && str_contains($createdStudent['headers']['location'] ?? '', '/aluno/perfil/1'), 'Cadastro de aluno com DVA inicial');

    $profile = request('GET', $baseUrl . '/aluno/perfil/1', $cookieAdmin);
    checkHttp(
        $profile['status'] === 200
        && str_contains($profile['body'], 'Aluno HTTP &lt;Seguro&gt;')
        && str_contains($profile['body'], 'DVA inicial HTTP'),
        'Perfil do aluno escapa HTML e exibe DVA'
    );

    $studentEdit = request('GET', $baseUrl . '/aluno/editar/1', $cookieAdmin);
    $editedStudent = request('POST', $baseUrl . '/aluno/editar/1', $cookieAdmin, [
        '_csrf_token' => csrf($studentEdit['body']),
        'nome_completo' => 'Aluno HTTP Editado',
        'data_nascimento' => '2011-05-10',
        'id_turma' => '1',
        'telefone_aluno' => '65999990000',
        'telefone_responsavel' => '6533330000',
    ]);
    checkHttp($editedStudent['status'] === 302, 'Edição do aluno');

    $dvaPage = request('GET', $baseUrl . '/aluno/dva/1', $cookieAdmin);
    $renewedExpiration = date('Y-m-d', strtotime('+20 days'));
    $renewedDva = request('POST', $baseUrl . '/aluno/dva/1', $cookieAdmin, [
        '_csrf_token' => csrf($dvaPage['body']),
        'data_vencimento' => $renewedExpiration,
        'observacao' => 'Renovação HTTP',
    ]);
    checkHttp($renewedDva['status'] === 302, 'Renovação de DVA');
    $profile = request('GET', $baseUrl . '/aluno/perfil/1', $cookieAdmin);
    checkHttp(str_contains($profile['body'], 'Arquivada') && str_contains($profile['body'], 'Renovação HTTP'), 'Histórico da DVA preservado');

    $dvaFilter = request('GET', $baseUrl . '/dva?dva=a_vencer', $cookieAdmin);
    checkHttp($dvaFilter['status'] === 200 && str_contains($dvaFilter['body'], 'Aluno HTTP Editado'), 'Semáforo e filtro de DVA integrados');

    $invalidStudentCsrf = request('POST', $baseUrl . '/aluno/status/1', $cookieAdmin, ['_csrf_token' => 'invalid', 'ativo' => '0']);
    checkHttp($invalidStudentCsrf['status'] === 419, 'CSRF protege alteração de aluno');

    $profile = request('GET', $baseUrl . '/aluno/perfil/1', $cookieAdmin);
    $deactivatedStudent = request('POST', $baseUrl . '/aluno/status/1', $cookieAdmin, [
        '_csrf_token' => csrf($profile['body']),
        'ativo' => '0',
    ]);
    checkHttp($deactivatedStudent['status'] === 302, 'Administrador inativa aluno sem exclusão');

    $wrongMethod = request('POST', $baseUrl . '/dashboard', $cookieAdmin, ['_csrf_token' => csrf($dashboard['body'])]);
    checkHttp($wrongMethod['status'] === 405 && ($wrongMethod['headers']['allow'] ?? '') === 'GET', 'Método não permitido retorna 405 e Allow');
    checkHttp(request('GET', $baseUrl . '/rota-inexistente', $cookieAdmin)['status'] === 404, 'Rota inexistente retorna 404');
    $spoofed = request('GET', $baseUrl . '/login', $cookieEmployee, [], ['X-Forwarded-Proto: https', 'X-Forwarded-For: 203.0.113.8']);
    checkHttp(!isset($spoofed['headers']['strict-transport-security']), 'Proxy não confiável é ignorado');

    $employeeLogin = request('GET', $baseUrl . '/login', $cookieEmployee);
    $employeeAuth = request('POST', $baseUrl . '/login/entrar', $cookieEmployee, [
        '_csrf_token' => csrf($employeeLogin['body']),
        'email' => 'employee@example.test',
        'senha' => $employeeTemporaryPassword,
    ]);
    checkHttp($employeeAuth['status'] === 302 && str_contains($employeeAuth['headers']['location'] ?? '', '/senha/alterar'), 'Funcionário recebe troca obrigatória');
    $employeePasswordPage = request('GET', $baseUrl . '/senha/alterar', $cookieEmployee);
    $employeePassword = 'Frase funcionário definitiva 2027';
    $employeeChanged = request('POST', $baseUrl . '/senha/alterar', $cookieEmployee, [
        '_csrf_token' => csrf($employeePasswordPage['body']),
        'senha_atual' => $employeeTemporaryPassword,
        'nova_senha' => $employeePassword,
        'confirmar_senha' => $employeePassword,
    ]);
    checkHttp($employeeChanged['status'] === 302, 'Funcionário altera senha temporária');
    $employeeLogin = request('GET', $baseUrl . '/login', $cookieEmployee);
    $employeeAuth = request('POST', $baseUrl . '/login/entrar', $cookieEmployee, [
        '_csrf_token' => csrf($employeeLogin['body']),
        'email' => 'employee@example.test',
        'senha' => $employeePassword,
    ]);
    checkHttp($employeeAuth['status'] === 302 && str_contains($employeeAuth['headers']['location'] ?? '', '/dashboard'), 'Login de funcionário com senha definitiva');
    checkHttp(request('GET', $baseUrl . '/usuario', $cookieEmployee)['status'] === 403, 'Funcionário recebe 403 em rota administrativa');
    checkHttp(request('GET', $baseUrl . '/aluno', $cookieEmployee)['status'] === 200, 'Funcionário autenticado consulta alunos');
    checkHttp(
        request('POST', $baseUrl . '/aluno/status/1', $cookieEmployee, ['_csrf_token' => 'irrelevante', 'ativo' => '1'])['status'] === 403,
        'Funcionário recebe 403 ao tentar reativar aluno'
    );
    checkHttp(request('GET', $baseUrl . '/turma', $cookieEmployee)['status'] === 403, 'Funcionário não gerencia turmas');

    $audit = request('GET', $baseUrl . '/auditoria', $cookieAdmin);
    checkHttp(
        $audit['status'] === 200
        && str_contains($audit['body'], 'login.success')
        && str_contains($audit['body'], 'student.created'),
        'Auditoria administrativa inclui recursos do Módulo 2'
    );
    $invalidCsrf = request('POST', $baseUrl . '/login/sair', $cookieAdmin, ['_csrf_token' => 'invalid']);
    checkHttp($invalidCsrf['status'] === 419, 'CSRF inválido retorna 419');
    $adminDashboard = request('GET', $baseUrl . '/dashboard', $cookieAdmin);
    $logout = request('POST', $baseUrl . '/login/sair', $cookieAdmin, ['_csrf_token' => csrf($adminDashboard['body'])]);
    checkHttp($logout['status'] === 302 && str_contains($logout['headers']['location'] ?? '', '/login'), 'Logout válido encerra a sessão');
    $afterLogout = request('GET', $baseUrl . '/dashboard', $cookieAdmin);
    checkHttp($afterLogout['status'] === 302 && str_contains($afterLogout['headers']['location'] ?? '', '/login'), 'Sessão anterior não é reutilizável após logout');

    stopServer($process);
    $productionPort = $port + 1;
    $productionEnvironment = array_merge($baseEnvironment, [
        'APP_ENV' => 'production',
        'APP_URL' => 'https://gse.example.test',
        'APP_ALLOWED_HOSTS' => 'gse.example.test',
        'FORCE_HTTPS' => 'true',
        'TRUSTED_PROXIES' => '127.0.0.1',
    ]);
    $process = startServer($productionPort, $productionEnvironment, $serverOut, $serverError);
    $productionUrl = "http://127.0.0.1:{$productionPort}/login";
    $proxyHeaders = ['Host: gse.example.test', 'X-Forwarded-Proto: https'];
    waitForServer($productionUrl, $cookieProduction, $proxyHeaders);
    $proxiedHttps = request('GET', $productionUrl, $cookieProduction, [], $proxyHeaders);
    checkHttp($proxiedHttps['status'] === 200, 'HTTPS reconhecido atrás de proxy confiável sem loop');
    checkHttp(isset($proxiedHttps['headers']['strict-transport-security']), 'HSTS em HTTPS reconhecido');
    checkHttp(str_contains(strtolower($proxiedHttps['headers']['set-cookie'] ?? ''), 'secure'), 'Cookie Secure em HTTPS');

    $redirect = request('GET', $productionUrl, $cookieProduction, [], ['Host: gse.example.test']);
    checkHttp($redirect['status'] === 308 && str_starts_with($redirect['headers']['location'] ?? '', 'https://gse.example.test'), 'Redirecionamento HTTPS usa APP_URL segura');
    checkHttp(request('GET', $productionUrl, $cookieProduction, [], ['Host: attacker.example.test', 'X-Forwarded-Proto: https'])['status'] === 400, 'Host inválido é recusado');

    stopServer($process);
    $brokenDatabase = $tempRoot . DIRECTORY_SEPARATOR . 'broken.sqlite';
    file_put_contents($brokenDatabase, 'conteudo de teste que nao e sqlite');
    $errorEnvironment = array_merge($productionEnvironment, ['DB_PATH' => $brokenDatabase]);
    $errorPort = $port + 2;
    $process = startServer($errorPort, $errorEnvironment, $serverOut, $serverError);
    $errorUrl = "http://127.0.0.1:{$errorPort}/login";
    waitForServer($errorUrl, $cookieProduction, $proxyHeaders);
    $productionError = request('GET', $errorUrl, $cookieProduction, [], $proxyHeaders);
    checkHttp(
        $productionError['status'] === 500
        && str_contains($productionError['body'], 'temporariamente indisponível')
        && !preg_match('/PDO|SQLite|Stack trace|Fatal error/i', $productionError['body']),
        'Erro de produção usa página 500 genérica sem detalhe técnico'
    );

    fwrite(STDOUT, "HTTP OK ({$checks} verificações).\n");
} catch (Throwable $exception) {
    $failure = $exception;
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    $serverLog = is_file($serverError) ? file_get_contents($serverError) : '';

    if ($serverLog !== '') {
        fwrite(STDERR, "Log do servidor:\n{$serverLog}\n");
    }

    $technicalLogPath = $tempRoot . DIRECTORY_SEPARATOR . 'technical.log';
    $technicalLog = is_file($technicalLogPath) ? file_get_contents($technicalLogPath) : '';

    if ($technicalLog !== '') {
        fwrite(STDERR, "Log técnico:\n{$technicalLog}\n");
    }

} finally {
    stopServer($process);
    gc_collect_cycles();

    if (is_dir($tempRoot)) {
        for ($attempt = 0; $attempt < 10 && is_dir($tempRoot); $attempt++) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tempRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }

            @rmdir($tempRoot);

            if (is_dir($tempRoot)) {
                usleep(100_000);
            }
        }

        if (is_dir($tempRoot)) {
            fwrite(STDERR, "Aviso: não foi possível remover o diretório temporário {$tempRoot}.\n");
        }
    }
}

if ($failure !== null) {
    exit(1);
}
