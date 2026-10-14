<?php

declare(strict_types=1);

final class PerfilController extends Controller
{
    public function editar(): void
    {
        $usuario = Auth::user();
        if (!$usuario) {
            redirect('login');
        }
        $dados = ['nome' => (string) $usuario['nome'], 'email' => (string) $usuario['email']];
        $erros = [];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $dados = [
                'nome' => mb_substr(trim(self::input('nome')), 0, 151, 'UTF-8'),
                'email' => mb_substr(Usuario::normalizarEmail(self::input('email')), 0, 255, 'UTF-8'),
            ];
            $model = new Usuario();
            $result = $model->atualizarPerfilProprio(
                (int) $usuario['id'], (int) ($_SESSION['auth_version'] ?? 0),
                $dados['nome'], $dados['email'], self::input('senha_atual')
            );
            if ($result !== false) {
                if ($result['email_alterado']) {
                    SessionManager::terminate();
                    SessionManager::startFreshForFlash();
                    definir_flash('success', 'Perfil atualizado. Faça login novamente com o novo e-mail.');
                    redirect('login');
                }
                $_SESSION['usuario_nome'] = $result['nome'];
                $this->redirectWithFlash('usuario/perfil', 'success', 'Perfil atualizado com sucesso.');
            }
            $erros[] = match ($model->lastErrorCode()) {
                'current_password_invalid' => 'A senha atual não confere.',
                'duplicate_email' => 'O e-mail informado já está em uso.',
                'invalid_data' => 'Informe um nome com até 150 caracteres e um e-mail válido com até 254 caracteres.',
                'session_invalid' => 'Sua conta foi alterada. Faça login novamente antes de atualizar o perfil.',
                default => 'Não foi possível atualizar o perfil. Tente novamente.',
            };
            http_response_code(422);
        }
        $this->view('usuarios/perfil', ['title' => '👤 Meu Perfil', 'dados' => $dados, 'erros' => $erros]);
    }

    private static function input(string $key): string
    {
        return is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
    }
}
