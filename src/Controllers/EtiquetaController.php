<?php

declare(strict_types=1);

final class EtiquetaController extends Controller
{
    public function index(): void
    {
        $this->view('etiquetas/index', ['title' => 'Modelos de etiquetas']);
    }

    public function pasta(): void
    {
        $this->view('etiquetas/pasta', ['title' => 'Pasta de aluno']);
    }

    public function caixa(): void
    {
        $this->view('etiquetas/caixa', ['title' => 'Etiquetas do arquivo passivo']);
    }
}
