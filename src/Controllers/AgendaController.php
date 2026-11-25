<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Model/Agenda.php';
require_once ROOT_PATH . '/src/Services/FeriadosAgenda.php';

/**
 * @phpstan-import-type Aviso from Agenda
 * @phpstan-type Navegacao array{data:DateTimeImmutable,hoje:DateTimeImmutable,visao:'mes'|'semana'}
 * @phpstan-type Dados array{titulo:string,descricao:string,data_aviso:string}
 */
final class AgendaController extends Controller
{
    public function index(): void
    {
        $navegacao = $this->navegacao($_GET);
        $edicao = null;
        if (isset($_GET['editar'])) {
            $edicao = $this->avisoParaEdicao($this->idValido($_GET['editar'], $navegacao), $navegacao);
        }
        $this->mostrarAgenda($navegacao, $edicao);
    }

    public function cadastrar(): void
    {
        $navegacao = $this->navegacao($_POST);
        $dados = $this->dadosFormulario();
        try {
            $id = (new Agenda())->cadastrar($this->atorId(), $dados['data_aviso'], $dados['titulo'], $dados['descricao']);
        } catch (DomainException $exception) {
            if ($exception->getCode() !== 422) {
                $this->erroDominio($exception, $this->urlAgenda($navegacao));
            }
            http_response_code(422);
            $this->mostrarAgenda($navegacao, null, [$exception->getMessage()], $dados);
            return;
        }
        $this->redirectWithFlash($this->urlAgenda($navegacao, $dados['data_aviso']), $id !== null ? 'success' : 'warning', $id !== null
            ? 'Aviso cadastrado na agenda.' : 'Um aviso igual foi cadastrado há pouco.');
    }

    public function editar(string $id): void
    {
        $navegacao = $this->navegacao($_POST);
        $id = $this->idValido($id, $navegacao);
        $dados = $this->dadosFormulario();
        $revisao = $this->revisaoEnviada();
        try {
            $alterado = (new Agenda())->editar($this->atorId(), $id, $revisao, $dados['data_aviso'], $dados['titulo'], $dados['descricao']);
        } catch (DomainException $exception) {
            if ($exception->getCode() !== 422) {
                $this->erroDominio($exception, $this->urlAgenda($navegacao));
            }
            $edicao = $this->avisoParaEdicao($id, $navegacao);
            // Mantém a revisão enviada mesmo se outro usuário salvou desde a validação.
            $edicao['revisao'] = $revisao;
            http_response_code(422);
            $this->mostrarAgenda($navegacao, $edicao, [$exception->getMessage()], $dados);
            return;
        }
        $this->redirectWithFlash($this->urlAgenda($navegacao, $dados['data_aviso']), $alterado ? 'success' : 'warning', $alterado
            ? 'Aviso atualizado.' : 'O aviso já está com esses dados.');
    }

    public function excluir(string $id): void
    {
        $navegacao = $this->navegacao($_POST);
        try {
            (new Agenda())->excluir($this->atorId(), $this->idValido($id, $navegacao), $this->revisaoEnviada());
        } catch (DomainException $exception) {
            $this->erroDominio($exception, $this->urlAgenda($navegacao));
        }
        $this->redirectWithFlash($this->urlAgenda($navegacao), 'success', 'Aviso excluído da agenda.');
    }

    public function importar(): void
    {
        require_once ROOT_PATH . '/src/Services/CalendarioEscolarImportador.php';

        $ano = (int) UiPresentation::localNow()->format('Y');
        $texto = '';
        $preview = null;
        $errors = [];
        $avisos = [];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $importador = new CalendarioEscolarImportador();
            try {
                $acao = input_string($_POST['acao'] ?? '');
                if ($acao === 'confirmar') {
                    $token = input_string($_POST['token'] ?? '');
                    $preview = $importador->buscarPrevia($token, $this->atorId());
                    $ano = $preview['ano'] ?? $ano;
                    $avisos = $preview['avisos'] ?? [];
                    $resultado = $importador->confirmar($token, $_POST['selecionados'] ?? null, $this->atorId());
                    $mensagem = sprintf('Itens salvos: %d. Itens já cadastrados: %d.', $resultado['salvos'], $resultado['ignorados']);
                    $this->redirectWithFlash('agenda', $resultado['salvos'] > 0 ? 'success' : 'warning', $mensagem);
                }
                if ($acao !== 'previsualizar') {
                    throw new DomainException('Escolha uma ação válida para a importação.', 422);
                }
                $anoSelecionado = input_string($_POST['ano'] ?? (string) $ano);
                $ano = preg_match('/\A[0-9]{4}\z/', $anoSelecionado) === 1 ? (int) $anoSelecionado : 0;
                $texto = input_string($_POST['texto'] ?? '');
                $preview = $importador->previsualizar($_FILES['arquivo'] ?? [], $ano, $texto, $this->atorId());
                $avisos = $preview['avisos'];
            } catch (DomainException $exception) {
                if ($exception->getCode() !== 422) {
                    $this->erroDominio($exception, 'agenda/importar');
                }
                http_response_code(422);
                $errors[] = $exception->getMessage();
            }
        }

        $this->view('agenda/importar', [
            'title' => 'Importar calendário escolar',
            'ano' => $ano,
            'texto' => mb_strcut($texto, 0, CalendarioEscolarImportador::MAX_TEXTO_BYTES, 'UTF-8'),
            'preview' => $preview,
            'errors' => $errors,
            'avisos' => $avisos,
        ]);
    }

    /**
     * @param Navegacao $navegacao
     * @param Aviso|null $edicao
     * @param list<string> $errors
     * @param Dados|null $dados
     */
    private function mostrarAgenda(array $navegacao, ?array $edicao, array $errors = [], ?array $dados = null): void
    {
        $data = $navegacao['data'];
        $semanal = $navegacao['visao'] === 'semana';
        $inicio = $data->modify($semanal ? 'monday this week' : 'first day of this month');
        $fim = $inicio->modify($semanal ? '+6 days' : 'last day of this month');
        $fim = min($fim, new DateTimeImmutable('9999-12-31', $data->getTimezone()));
        if ($dados === null) {
            $dados = [
                'titulo' => $edicao['titulo'] ?? '',
                'descricao' => $edicao['descricao'] ?? '',
                'data_aviso' => $edicao['data_aviso'] ?? $data->format('Y-m-d'),
            ];
        } else {
            $dados['titulo'] = mb_substr($dados['titulo'], 0, Agenda::TITULO_MAX + 1, 'UTF-8');
            $dados['descricao'] = mb_substr($dados['descricao'], 0, Agenda::DESCRICAO_MAX + 1, 'UTF-8');
            $dados['data_aviso'] = mb_substr($dados['data_aviso'], 0, 11, 'UTF-8');
        }
        $this->view('agenda/index', $navegacao + [
            'title' => 'Agenda escolar',
            'avisos' => (new Agenda())->listar($inicio->format('Y-m-d'), $fim->format('Y-m-d')),
            'feriados' => (new FeriadosAgenda())->listarProximos($inicio, (int) $inicio->diff($fim)->format('%a')),
            'edicao' => $edicao,
            'errors' => $errors,
            'dados' => $dados,
        ]);
    }

    /**
     * @param array<mixed> $input
     * @return Navegacao
     */
    private function navegacao(array $input): array
    {
        $hoje = UiPresentation::localNow()->setTime(0, 0);
        $data = input_string($input['data'] ?? '');

        return [
            'data' => Agenda::dataValida($data) ? new DateTimeImmutable($data, $hoje->getTimezone()) : $hoje,
            'hoje' => $hoje,
            'visao' => ($input['visao'] ?? null) === 'semana' ? 'semana' : 'mes',
        ];
    }

    /** @param Navegacao $navegacao */
    private function urlAgenda(array $navegacao, ?string $dia = null): string
    {
        return 'agenda?' . http_build_query(['data' => $dia ?? $navegacao['data']->format('Y-m-d'), 'visao' => $navegacao['visao']]);
    }

    /** @return Dados */
    private function dadosFormulario(): array
    {
        return [
            'titulo' => input_string($_POST['titulo'] ?? ''),
            'descricao' => input_string($_POST['descricao'] ?? ''),
            'data_aviso' => input_string($_POST['data_aviso'] ?? ''),
        ];
    }

    /** @param Navegacao $navegacao */
    private function idValido(mixed $id, array $navegacao): int
    {
        $valor = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($valor === false) {
            render_http_error(404, 'Aviso não encontrado', 'O aviso solicitado não existe na agenda.', $this->urlAgenda($navegacao));
        }

        return $valor;
    }

    private function atorId(): int
    {
        return (int) ($_SESSION['usuario_id'] ?? 0);
    }

    private function revisaoEnviada(): int
    {
        return (int) filter_var($_POST['revisao'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }

    /**
     * @param Navegacao $navegacao
     * @return Aviso
     */
    private function avisoParaEdicao(int $id, array $navegacao): array
    {
        try {
            return (new Agenda())->buscarParaEdicao($this->atorId(), $id);
        } catch (DomainException $exception) {
            $this->erroDominio($exception, $this->urlAgenda($navegacao));
        }
    }

    private function erroDominio(DomainException $exception, string $voltar): never
    {
        $status = $exception->getCode() === 0 ? 403 : $exception->getCode();
        $titulo = match ($status) {
            403 => 'Acesso não autorizado',
            404 => 'Aviso não encontrado',
            409 => 'Aviso desatualizado',
            default => 'Dados inválidos',
        };
        render_http_error($status, $titulo, $exception->getMessage(), $voltar);
    }
}
