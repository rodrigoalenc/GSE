<?php

declare(strict_types=1);

final class DashboardController extends Controller
{
    public function index(): void
    {
        $usuario = Auth::user();

        if (!$usuario) {
            redirect('login');
        }

        $estatisticas = (new Usuario())->estatisticas();

        $this->view('dashboard/index', [
            'title' => 'Painel de Controle',
            'usuario' => $usuario,
            'estatisticas' => $estatisticas,
        ]);
    }
}
