<?php

declare(strict_types=1);

final class SenhaController extends Controller
{
    public function alterar(): void
    {
        $usuario = Auth::user();

        if (!$usuario) {
            redirect('login');
        }

        $erros = [];

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $senhaAtual = (string) ($_POST['senha_atual'] ?? '');
            $novaSenha = (string) ($_POST['nova_senha'] ?? '');
            $confirmacao = (string) ($_POST['confirmar_senha'] ?? '');

            if ($senhaAtual === '') {
                $erros[] = 'Informe a senha atual.';
            }

            $erros = array_merge(
                $erros,
                PasswordPolicy::validate($novaSenha, (string) $usuario['nome'], (string) $usuario['email'])
            );

            if ($novaSenha !== $confirmacao) {
                $erros[] = 'A confirmação da nova senha não confere.';
            }

            $model = new Usuario();

            if ($erros === [] && $model->alterarSenha((int) $usuario['id'], $senhaAtual, $novaSenha)) {
                AuditLogger::record(
                    'password.changed',
                    AuditLogger::SUCCESS,
                    (int) $usuario['id'],
                    (int) $usuario['id'],
                    'Senha alterada pelo próprio usuário.'
                );
                SessionManager::terminate();
                SessionManager::startFreshForFlash();
                definir_flash('success', 'Senha alterada com segurança. Faça login novamente.');
                redirect('login');
            }

            if ($erros === []) {
                $erros[] = match ($model->lastErrorCode()) {
                    'current_password_invalid' => 'A senha atual não confere.',
                    'password_reused' => 'A nova senha deve ser diferente da senha atual.',
                    default => 'Não foi possível alterar a senha.',
                };
            }

            AuditLogger::record(
                'password.changed',
                AuditLogger::FAILURE,
                (int) $usuario['id'],
                (int) $usuario['id'],
                'Alteração de senha não concluída.'
            );
            http_response_code(422);
        }

        $this->view('senha/alterar', [
            'title' => 'Alterar Senha',
            'erros' => array_values(array_unique($erros)),
            'obrigatoria' => (int) ($usuario['deve_alterar_senha'] ?? 0) === 1,
        ]);
    }
}
