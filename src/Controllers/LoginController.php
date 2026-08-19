<?php

declare(strict_types=1);

final class LoginController extends Controller
{
    public function index(): void
    {
        if (Auth::check()) {
            redirect(Auth::mustChangePassword() ? 'senha/alterar' : 'dashboard');
        }

        $this->view('login', [
            'title' => 'Entrar',
            'flash' => consumir_flash(),
        ], false);
    }

    public function entrar(): void
    {
        $email = mb_substr(Usuario::normalizarEmail((string) ($_POST['email'] ?? '')), 0, 254);
        $senha = (string) ($_POST['senha'] ?? '');

        if (!Auth::attempt($email, $senha)) {
            http_response_code(422);
            $this->view('login', [
                'title' => 'Entrar',
                'erro' => 'E-mail ou senha inválidos.',
                'email' => $email,
                'flash' => null,
            ], false);

            return;
        }

        if (Auth::mustChangePassword()) {
            definir_flash('warning', 'Altere a senha temporária antes de continuar.');
            redirect('senha/alterar');
        }

        definir_flash('success', 'Login realizado com sucesso.');
        redirect('dashboard');
    }

    public function sair(): void
    {
        Auth::logout();
        SessionManager::startFreshForFlash();
        definir_flash('success', 'Você saiu do sistema com segurança.');
        redirect('login');
    }
}
