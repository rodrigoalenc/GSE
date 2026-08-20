<?php

declare(strict_types=1);

final class UsuarioController extends Controller
{
    public function index(): void
    {
        $termo = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100, 'UTF-8');
        $usuarios = (new Usuario())->listar($termo);

        $this->view('usuarios/index', [
            'title' => 'Gerenciar Usuários',
            'usuarios' => $usuarios,
            'termo' => $termo,
        ]);
    }

    public function criar(): void
    {
        $dados = $this->dadosDoFormulario();
        $erros = [];

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $erros = $this->validar($dados, true);
            $model = new Usuario();

            if (!$erros && $model->cadastrar(
                $dados['nome'],
                $dados['email'],
                $dados['senha'],
                $dados['tipo'],
                true,
                $dados['recebe_alertas_dva']
            )) {
                $created = $model->buscarPorEmail($dados['email']);
                AuditLogger::record(
                    'user.created',
                    AuditLogger::SUCCESS,
                    (int) $_SESSION['usuario_id'],
                    is_array($created) ? (int) $created['id'] : null,
                    'Conta criada com senha temporária.'
                );
                $this->redirectWithFlash('usuario', 'success', 'Usuário cadastrado. A senha informada é temporária e deverá ser alterada no primeiro acesso.');
            }

            if (!$erros) {
                $erros[] = $model->lastErrorCode() === 'duplicate_email'
                    ? 'O e-mail informado já está em uso.'
                    : 'Não foi possível cadastrar o usuário. Revise os dados e tente novamente.';
                AuditLogger::record(
                    'user.created',
                    AuditLogger::FAILURE,
                    (int) $_SESSION['usuario_id'],
                    null,
                    'Criação de conta não concluída.'
                );
            }

            http_response_code(422);
        }

        $dados['senha'] = '';
        $dados['confirmar_senha'] = '';
        $this->view('usuarios/form', [
            'title' => 'Novo Usuário',
            'dados' => $dados,
            'erros' => $erros,
            'edicao' => false,
        ]);
    }

    public function editar(string $id): void
    {
        $usuarioId = filter_var($id, FILTER_VALIDATE_INT);
        $model = new Usuario();
        $usuario = $usuarioId ? $model->buscarPorId((int) $usuarioId) : false;

        if (!$usuario) {
            $this->redirectWithFlash('usuario', 'danger', 'Usuário não encontrado.');
        }

        $dados = [
            'nome' => (string) $usuario['nome'],
            'email' => (string) $usuario['email'],
            'tipo' => (string) $usuario['tipo'],
            'senha' => '',
            'confirmar_senha' => '',
            'recebe_alertas_dva' => (int) ($usuario['recebe_alertas_dva'] ?? 0) === 1,
        ];
        $erros = [];

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $dados = $this->dadosDoFormulario();
            $erros = $this->validar($dados, false);
            $novaSenha = $dados['senha'] !== '' ? $dados['senha'] : null;

            if (!$erros && $model->atualizar(
                (int) $usuarioId,
                $dados['nome'],
                $dados['email'],
                $dados['tipo'],
                $novaSenha,
                $dados['recebe_alertas_dva']
            )) {
                $actor = (int) $_SESSION['usuario_id'];
                $target = (int) $usuarioId;
                $securityChange = false;

                if ((string) $usuario['nome'] !== $dados['nome'] || (string) $usuario['email'] !== $dados['email']) {
                    AuditLogger::record('user.identity_updated', AuditLogger::SUCCESS, $actor, $target, 'Nome ou e-mail da conta alterado.');
                }

                if ((string) $usuario['tipo'] !== $dados['tipo']) {
                    AuditLogger::record('user.role_updated', AuditLogger::SUCCESS, $actor, $target, 'Perfil de acesso alterado.');
                    $securityChange = true;
                }

                if ($novaSenha !== null) {
                    AuditLogger::record('password.reset', AuditLogger::SUCCESS, $actor, $target, 'Senha temporária definida pelo administrador.');
                    $securityChange = true;
                }

                if ($actor === $target && $securityChange) {
                    SessionManager::terminate();
                    SessionManager::startFreshForFlash();
                    definir_flash('success', 'Alteração concluída. Faça login novamente.');
                    redirect('login');
                }

                if ($actor === $target) {
                    $_SESSION['usuario_nome'] = trim($dados['nome']);
                }

                $this->redirectWithFlash('usuario', 'success', 'Usuário atualizado com sucesso.');
            }

            if (!$erros) {
                $code = $model->lastErrorCode();
                $erros[] = match ($code) {
                    'duplicate_email' => 'O e-mail informado já está em uso.',
                    'last_active_admin' => 'A alteração foi bloqueada: o sistema deve manter um administrador ativo.',
                    default => 'Não foi possível atualizar o usuário.',
                };

                if ($code === 'last_active_admin') {
                    AuditLogger::record(
                        'admin.last_admin_change_blocked',
                        AuditLogger::BLOCKED,
                        (int) $_SESSION['usuario_id'],
                        (int) $usuarioId,
                        'Tentativa de rebaixar o último administrador ativo.'
                    );
                }
            }

            http_response_code(422);
        }

        $dados['senha'] = '';
        $dados['confirmar_senha'] = '';
        $this->view('usuarios/form', [
            'title' => 'Editar Usuário',
            'dados' => $dados,
            'erros' => $erros,
            'edicao' => true,
            'usuarioId' => (int) $usuarioId,
        ]);
    }

    public function status(string $id): void
    {
        $usuarioId = filter_var($id, FILTER_VALIDATE_INT);
        $ativoRaw = filter_var($_POST['ativo'] ?? null, FILTER_VALIDATE_INT);

        if (!$usuarioId || !in_array($ativoRaw, [0, 1], true)) {
            $this->redirectWithFlash('usuario', 'danger', 'Solicitação inválida.');
        }

        $model = new Usuario();
        $sucesso = $model->definirAtivo((int) $usuarioId, $ativoRaw === 1, (int) $_SESSION['usuario_id']);

        if (!$sucesso) {
            $code = $model->lastErrorCode();
            $action = match ($code) {
                'self_deactivation' => 'user.self_deactivation_blocked',
                'last_active_admin' => 'admin.last_admin_change_blocked',
                default => 'user.status_change_failed',
            };
            $description = match ($code) {
                'self_deactivation' => 'Tentativa de inativar a própria conta.',
                'last_active_admin' => 'Tentativa de inativar o último administrador ativo.',
                default => 'Alteração de situação da conta não concluída.',
            };
            AuditLogger::record(
                $action,
                in_array($code, ['self_deactivation', 'last_active_admin'], true)
                    ? AuditLogger::BLOCKED
                    : AuditLogger::FAILURE,
                (int) $_SESSION['usuario_id'],
                (int) $usuarioId,
                $description
            );
            $this->redirectWithFlash(
                'usuario',
                'danger',
                in_array($code, ['self_deactivation', 'last_active_admin'], true)
                    ? 'A alteração foi bloqueada. Não é permitido inativar a própria conta ou o último administrador ativo.'
                    : 'Não foi possível alterar a situação do usuário.'
            );
        }

        AuditLogger::record(
            $ativoRaw === 1 ? 'user.activated' : 'user.deactivated',
            AuditLogger::SUCCESS,
            (int) $_SESSION['usuario_id'],
            (int) $usuarioId,
            $ativoRaw === 1 ? 'Conta ativada.' : 'Conta inativada.'
        );
        $this->redirectWithFlash(
            'usuario',
            'success',
            $ativoRaw === 1 ? 'Usuário ativado com sucesso.' : 'Usuário inativado com sucesso.'
        );
    }

    private function dadosDoFormulario(): array
    {
        return [
            'nome' => mb_substr(trim((string) ($_POST['nome'] ?? '')), 0, 151, 'UTF-8'),
            'email' => mb_substr(Usuario::normalizarEmail((string) ($_POST['email'] ?? '')), 0, 255, 'UTF-8'),
            'tipo' => mb_substr((string) ($_POST['tipo'] ?? Usuario::PERFIL_FUNCIONARIO), 0, 30, 'UTF-8'),
            'senha' => (string) ($_POST['senha'] ?? ''),
            'confirmar_senha' => (string) ($_POST['confirmar_senha'] ?? ''),
            'recebe_alertas_dva' => isset($_POST['recebe_alertas_dva']),
        ];
    }

    private function validar(array $dados, bool $senhaObrigatoria): array
    {
        $erros = [];

        if ($dados['nome'] === '' || mb_strlen($dados['nome'], 'UTF-8') > 150) {
            $erros[] = 'Informe um nome com até 150 caracteres.';
        }

        if (filter_var($dados['email'], FILTER_VALIDATE_EMAIL) === false || mb_strlen($dados['email'], 'UTF-8') > 254) {
            $erros[] = 'Informe um e-mail válido com até 254 caracteres.';
        }

        if (!Usuario::perfilValido($dados['tipo'])) {
            $erros[] = 'Selecione um perfil de acesso válido.';
        }

        if ($senhaObrigatoria && $dados['senha'] === '') {
            $erros[] = 'Informe a senha temporária inicial.';
        }

        if ($dados['senha'] !== '') {
            $erros = array_merge($erros, PasswordPolicy::validate($dados['senha'], $dados['nome'], $dados['email']));
        }

        if ($dados['senha'] !== $dados['confirmar_senha']) {
            $erros[] = 'A confirmação da senha não confere.';
        }

        return array_values(array_unique($erros));
    }
}
