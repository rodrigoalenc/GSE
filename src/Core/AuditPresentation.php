<?php

declare(strict_types=1);

/** Textos de apresentação; os códigos e as descrições gravados permanecem intactos. */
final class AuditPresentation
{
    /** @var array<string,string> */
    private const ACTION_LABELS = [
        'admin.last_admin_change_blocked' => 'Usuario - Alteração Bloqueada',
        'audit.cleaned' => 'Sistema',
        'authorization.admin_denied' => 'Sistema - Acesso Negado',
        'backup.created' => 'Sistema',
        'backup.downloaded' => 'Sistema',
        'certidao.archived' => 'Certidao - Arquivar',
        'certidao.archived_by_renewal' => 'Certidao - Arquivar',
        'certidao.created' => 'Certidao - Cadastrar',
        'certidao.deleted' => 'Certidao - Apagar',
        'certidao.pdf_migrated' => 'Certidao - Migrar PDF',
        'certidao.renewed' => 'Certidao - Renovar',
        'certidao.restored' => 'Certidao - Desarquivar',
        'certidao.updated' => 'Certidao - Editar',
        'class.created' => 'Cadastrar Turma',
        'class.deactivated' => 'Inativar Turma',
        'class.reactivated' => 'Reativar Turma',
        'class.series_configured' => 'Turma - Configurar Série',
        'class.status_change_blocked' => 'Alterar Situação da Turma',
        'class.updated' => 'Editar Turma',
        'contrato.additive_sheet_created' => 'Contrato - Cadastrar Aditivo',
        'contrato.created' => 'Contrato - Cadastrar',
        'contrato.deleted' => 'Contrato - Excluir',
        'contrato.item_created' => 'Contrato - Cadastrar Produto',
        'contrato.item_updated' => 'Contrato - Editar Produto',
        'contrato.legacy_contract_reconciled' => 'Contrato - Conciliar Valor',
        'contrato.legacy_item_reconciled' => 'Contrato - Conciliar Produto',
        'contrato.old_opening_recovered' => 'Estoque - Conferir Abertura',
        'contrato.old_zero_opening_confirmed' => 'Estoque - Confirmar Abertura Zerada',
        'contrato.sheet_billed' => 'Contrato - Editar Folha',
        'contrato.sheet_created' => 'Contrato - Cadastrar Folha',
        'contrato.sheet_duplicated' => 'Contrato - Cadastrar Folha',
        'contrato.sheet_updated' => 'Contrato - Editar Folha',
        'contrato.stock_configured' => 'Estoque - Configurar Alertas',
        'contrato.stock_entrada' => 'Estoque - Recebimento',
        'contrato.stock_estorno' => 'Estoque - Estornar',
        'contrato.stock_opened' => 'Estoque - Confirmar Quantidade Inicial',
        'contrato.stock_saida' => 'Estoque - Consumo',
        'contrato.sheet_received' => 'Estoque - Recebimento da Nota',
        'contrato.updated' => 'Contrato - Editar',
        'dva.created' => 'Cadastrar DVA',
        'dva.renewed' => 'Renovar DVA',
        'fornecedor.created' => 'Certidao - Cadastrar Opcao',
        'fornecedor.deactivated' => 'Certidao - Excluir Opcao',
        'fornecedor.updated' => 'Certidao - Editar Opcao',
        'login.blocked' => 'Login - Bloqueado',
        'login.invalid' => 'Login - Falha',
        'login.success' => 'Login',
        'logout' => 'Logout',
        'passive.batch_archived' => 'Passivo - Arquivar Alunos',
        'passive.conflict' => 'Passivo - Conflito',
        'passive.created' => 'Passivo - Cadastrar',
        'passive.deactivated' => 'Passivo - Excluir',
        'passive.enumerated' => 'Passivo - Numerar',
        'passive.enumeration_previewed' => 'Passivo - Conferir Numeração',
        'passive.exported' => 'Passivo - Exportar',
        'passive.import_completed' => 'Passivo - Importar CSV',
        'passive.import_failed' => 'Passivo - Falha na Importação',
        'passive.import_previewed' => 'Passivo - Conferir Importação',
        'passive.reactivated' => 'Passivo - Restaurar',
        'passive.student_archived' => 'Passivo - Arquivar Aluno',
        'passive.updated' => 'Passivo - Editar',
        'password.changed' => 'Usuario - Alterar Senha',
        'password.rehashed' => 'Usuario - Atualizar Proteção da Senha',
        'password.reset' => 'Usuario - Redefinir Senha',
        'relatorio.csv_generated' => 'Relatório - Exportar CSV',
        'relatorio.pdf_generated' => 'Relatório - Exportar PDF',
        'session.expired' => 'Sessão - Expirada',
        'session.invalidated' => 'Sessão - Invalidada',
        'student.created' => 'Cadastrar Aluno',
        'student.deactivated' => 'Inativar Aluno',
        'student.import_completed' => 'Alunos - Importar PDF',
        'student.import_previewed' => 'Alunos - Conferir Importação',
        'student.import_failed' => 'Alunos - Falha na Importação',
        'student.progressed' => 'Progredir Aluno',
        'student.reactivated' => 'Reativar Aluno',
        'student.regressed' => 'Regredir Aluno',
        'student.senior_class_closed' => 'Turma - Conferir 3º Ano',
        'student.senior_reviewed' => 'Alunos - Conferir 3º Ano',
        'student.status_failed' => 'Alterar Situação do Aluno',
        'student.updated' => 'Editar Aluno',
        'student.year_progressed' => 'Alunos - Progredir Ano Letivo',
        'tipo.created' => 'Certidao - Cadastrar Opcao',
        'tipo.deactivated' => 'Certidao - Excluir Opcao',
        'tipo.updated' => 'Certidao - Editar Opcao',
        'user.activated' => 'Usuario - Ativar',
        'user.created' => 'Usuario - Criar',
        'user.deactivated' => 'Usuario - Inativar',
        'user.dva_alerts_updated' => 'Usuario - Editar Alertas de DVA',
        'user.identity_updated' => 'Usuario - Editar',
        'user.initial_admin_created' => 'Usuario - Criar',
        'user.profile_updated' => 'Usuario - Editar',
        'user.role_updated' => 'Usuario - Editar',
        'user.self_deactivation_blocked' => 'Usuario - Inativação Bloqueada',
        'user.status_change_failed' => 'Usuario - Alterar Situação',
    ];

    /** @var array<string,string> */
    private const RESOURCE_LABELS = [
        'audit' => 'Logs de Auditoria',
        'backup' => 'Cópia de segurança',
        'certidao' => 'Certidão',
        'class' => 'Turma',
        'contrato' => 'Contrato',
        'dva' => 'DVA',
        'folha' => 'Folha do contrato',
        'fornecedor' => 'Fornecedor',
        'passive_box' => 'Caixa do Arquivo Passivo',
        'passive_import' => 'Importação do Arquivo Passivo',
        'passive_record' => 'Registro do Arquivo Passivo',
        'produto' => 'Item do contrato',
        'relatorio' => 'Relatório de alunos e DVA',
        'student' => 'Aluno',
        'student_import' => 'Importação de alunos',
        'student_progression' => 'Progressão anual de alunos',
        'tipo' => 'Tipo de certidão',
        'user' => 'Usuário',
    ];

    /** @var array<string,string> */
    private const CONTRACT_DESCRIPTIONS = [
        'additive_sheet_created' => 'Nota de aditivo cadastrada no contrato.',
        'created' => 'Contrato cadastrado.',
        'deleted' => 'Registro excluído no módulo de contratos.',
        'item_created' => 'Item cadastrado no contrato.',
        'item_updated' => 'Item do contrato atualizado.',
        'legacy_contract_reconciled' => 'Valor de contrato antigo conciliado.',
        'legacy_item_reconciled' => 'Item de contrato antigo conciliado.',
        'sheet_created' => 'Folha criada no contrato.',
        'sheet_duplicated' => 'Folha do contrato duplicada.',
        'sheet_updated' => 'Folha do contrato atualizada.',
        'stock_configured' => 'Alertas do estoque configurados.',
        'stock_entrada' => 'Recebimento de produto registrado no estoque.',
        'stock_estorno' => 'Estorno de movimentação registrado no estoque.',
        'stock_opened' => 'Quantidade inicial do estoque confirmada.',
        'stock_saida' => 'Consumo de produto registrado no estoque.',
        'sheet_received' => 'Recebimento dos produtos da nota registrado no estoque.',
        'updated' => 'Contrato atualizado.',
    ];

    /** @var array<string,list<string>> */
    private const GENERIC_DESCRIPTIONS = [
        'login.success' => ['Autenticação concluída.'],
        'student.created' => ['Cadastro de aluno concluído.', 'Aluno cadastrado.'],
        'student.updated' => ['Dados cadastrais do aluno atualizados.'],
        'student.deactivated' => ['Aluno inativado.'],
        'student.reactivated' => ['Aluno reativado.'],
        'user.created' => ['Conta criada.'],
        'passive.created' => ['Registro do arquivo passivo criado.'],
        'passive.updated' => ['Registro do arquivo passivo atualizado.'],
        'passive.deactivated' => ['Registro do arquivo passivo inativado.'],
        'passive.reactivated' => ['Registro do arquivo passivo restaurado.'],
    ];

    /** @return array<string,string> */
    public static function actionLabels(): array
    {
        return self::sortedLabels(self::ACTION_LABELS);
    }

    public static function actionLabel(string $action): string
    {
        return self::ACTION_LABELS[$action] ?? 'Evento não identificado';
    }

    /** Aceita o código do evento ou o texto exibido na coluna Ação. */
    public static function actionClass(string $action): string
    {
        $label = mb_strtolower(self::ACTION_LABELS[$action] ?? $action, 'UTF-8');
        if (str_contains($label, 'apagar') || str_contains($label, 'excluir')) {
            return 'acao-apagar';
        }
        if (str_contains($label, 'cadastrar') || str_contains($label, 'criar')) {
            return 'acao-cadastrar';
        }
        if (str_contains($label, 'editar') || str_contains($label, 'atualizar')) {
            return 'acao-editar';
        }
        return str_contains($label, 'login') ? 'acao-login' : '';
    }

    /** @param array<string,mixed> $item
     *  @return array{action:string,details:string,user:string,date:string,action_class:string} */
    public static function logEntry(array $item): array
    {
        $action = self::text($item, 'action');
        $label = self::actionLabel($action);
        $result = self::text($item, 'result');
        $rawDescription = self::text($item, 'description');
        $description = self::description($action, $rawDescription);
        $actor = self::text($item, 'actor_name');
        $actorId = self::text($item, 'actor_user_id');
        if ($actor === '') {
            $actor = $actorId !== '' ? 'Usuário #' . $actorId : 'Sistema';
        }

        if ($action === 'contrato.deleted') {
            $label = match (self::text($item, 'resource_type')) {
                'folha' => 'Contrato - Excluir Folha',
                'produto' => 'Contrato - Excluir Produto',
                default => $label,
            };
        }

        if (!isset(self::ACTION_LABELS[$action])) {
            $details = self::withContext(self::joinDetails('Ação: ' . $action . '.', $description), $item);
        } elseif ($result !== 'success') {
            // Um evento sem confirmação não recebe uma descrição de operação concluída.
            $details = self::withContext($rawDescription, $item);
        } elseif (!self::isGenericDescription($action, $rawDescription)) {
            $details = self::withContext($description, $item);
        } else {
            $details = self::successDetails($action, $item, $actor);
            if ($details === '') {
                $details = self::withContext($description, $item);
            }
        }

        if ($result !== 'success') {
            $details = self::joinDetails($details, 'Resultado: ' . self::resultLabel($result) . '.');
        }

        return [
            'action' => $label,
            'details' => $details,
            'user' => $actor,
            'date' => self::dateTime(self::text($item, 'occurred_at')),
            'action_class' => self::actionClass($label),
        ];
    }

    public static function resultLabel(string $result): string
    {
        return match ($result) {
            'success' => 'Concluído',
            'failure' => 'Falhou',
            'blocked' => 'Bloqueado',
            default => 'Resultado não identificado',
        };
    }

    /** @return array<string,string> */
    public static function resourceLabels(): array
    {
        return self::sortedLabels(self::RESOURCE_LABELS);
    }

    public static function resourceLabel(string $resource): string
    {
        return self::RESOURCE_LABELS[$resource] ?? 'Registro';
    }

    public static function description(string $action, string $description): string
    {
        // Traduz somente formatos conhecidos, sem substituir palavras em texto livre.
        if (str_starts_with($action, 'contrato.')) {
            $operation = substr($action, strlen('contrato.'));
            if ($description === 'Módulo 5: ' . $operation && isset(self::CONTRACT_DESCRIPTIONS[$operation])) {
                return self::CONTRACT_DESCRIPTIONS[$operation];
            }
        }

        if ($action === 'backup.created' && str_starts_with($description, 'Snapshot SQLite criado: ')) {
            return 'Cópia de segurança do banco de dados criada: ' . substr($description, strlen('Snapshot SQLite criado: '));
        }

        if ($action === 'backup.downloaded' && str_starts_with($description, 'Download de snapshot SQLite autorizado: ')) {
            return 'Transferência da cópia de segurança do banco de dados autorizada: '
                . substr($description, strlen('Download de snapshot SQLite autorizado: '));
        }

        if (in_array($action, ['relatorio.csv_generated', 'relatorio.pdf_generated'], true)
            && preg_match('/\AFiltros turma=([0-9]*); dva=(|sem_dva|vencida|vence_hoje|a_vencer|vigente); ativo=(0|1|todos); total=([0-9]+)\z/', $description, $matches) === 1) {
            $class = $matches[1] === '' ? 'todas' : '#' . $matches[1];
            $dva = match ($matches[2]) {
                '' => 'todas',
                'sem_dva' => 'sem DVA',
                'vencida' => 'vencida',
                'vence_hoje' => 'vence hoje',
                'a_vencer' => 'a vencer',
                'vigente' => 'vigente',
                default => $matches[2],
            };
            $students = match ($matches[3]) { '0' => 'inativos', '1' => 'ativos', 'todos' => 'todos', default => $matches[3] };
            return 'Filtros: turma ' . $class . '; DVA ' . $dva . '; alunos ' . $students . '; total de resultados: ' . $matches[4] . '.';
        }

        return $description;
    }

    public static function dateTime(string $value): string
    {
        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $value) !== 1) {
            return $value;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            return $value;
        }
        try {
            $zone = new DateTimeZone(Config::string('APP_TIMEZONE', 'America/Campo_Grande'));
        } catch (Exception) {
            $zone = new DateTimeZone('America/Campo_Grande');
        }
        return $date->setTimezone($zone)->format('d/m/Y H:i:s');
    }

    /** @param array<string,mixed> $item */
    private static function successDetails(string $action, array $item, string $actor): string
    {
        $resource = self::resourceDetails($item);
        $name = self::text($item, 'resource_name');
        $id = self::text($item, 'resource_id');
        $box = self::text($item, 'resource_box');
        $target = self::text($item, 'target_name');
        $targetId = self::text($item, 'target_user_id');
        if ($target === '') {
            $target = $name !== '' ? $name : ($targetId !== '' ? 'Usuário #' . $targetId : $resource);
        }

        return match ($action) {
            'login.success' => self::text($item, 'actor_user_id') === '' ? 'Acesso ao sistema registrado.' : 'Usuário ' . $actor . ' acessou o sistema.',
            'backup.created' => str_starts_with(self::text($item, 'description'), 'Snapshot SQLite criado: ')
                ? 'Criou backup manual via painel.' : '',
            'backup.downloaded' => str_starts_with(self::text($item, 'description'), 'Download de snapshot SQLite autorizado: ')
                ? 'Download do backup: ' . substr(self::text($item, 'description'), strlen('Download de snapshot SQLite autorizado: ')) : '',
            'student.created' => 'Novo aluno: ' . $resource,
            'student.updated' => 'Editou: ' . $resource,
            'student.deactivated' => 'Inativou: ' . $resource,
            'student.reactivated' => 'Reativou: ' . $resource,
            'user.created', 'user.initial_admin_created' => 'Criou: ' . $target,
            'passive.created' => 'Adicionou: ' . ($name !== '' ? $name : $resource) . ($box !== '' ? ' na Caixa ' . $box : ''),
            'passive.updated' => $id !== '' ? 'ID: ' . $id . ' editado.' : 'Registro do arquivo passivo editado.',
            'passive.deactivated' => 'Removido ' . ($id !== '' ? 'ID: ' . $id : 'registro') . ($name !== '' ? ' (' . $name . ')' : ''),
            'passive.reactivated' => 'Restaurou: ' . $resource,
            'certidao.created', 'certidao.updated', 'certidao.archived',
            'certidao.archived_by_renewal', 'certidao.restored', 'certidao.deleted' => self::documentDetails($action, $item),
            'fornecedor.created', 'tipo.created', 'fornecedor.updated', 'tipo.updated' => self::optionDetails($action, $item),
            'fornecedor.deactivated', 'tipo.deactivated' => 'Inativou opção: ' . $resource,
            'contrato.created', 'contrato.updated', 'contrato.deleted', 'contrato.sheet_created',
            'contrato.sheet_duplicated', 'contrato.sheet_updated', 'contrato.sheet_billed',
            'contrato.item_created', 'contrato.item_updated' => self::contractDetails($action, $item),
            default => '',
        };
    }

    /** @param array<string,mixed> $item */
    private static function documentDetails(string $action, array $item): string
    {
        $resource = self::resourceDetails($item);
        $name = self::text($item, 'resource_name');
        $id = self::text($item, 'resource_id');
        $type = self::text($item, 'resource_document_type_id');
        $supplier = self::text($item, 'resource_supplier_id');

        return match ($action) {
            'certidao.created' => $type !== '' && $supplier !== ''
                ? 'ID Tipo: ' . $type . ' | ID Fornecedor: ' . $supplier
                : 'Nova certidão: ' . $resource,
            'certidao.updated' => $id !== '' && $type !== '' && $supplier !== ''
                ? 'Atualizou certidão ID: ' . $id . ' | Tipo ID: ' . $type . ' | Fornecedor ID: ' . $supplier
                : 'Atualizou certidão: ' . $resource,
            'certidao.archived' => $id !== '' ? 'Arquivou certidão ID: ' . $id : 'Arquivou certidão: ' . $resource,
            'certidao.archived_by_renewal' => $id !== '' ? 'Arquivou certidão renovada ID: ' . $id : 'Arquivou a certidão anterior após renovação: ' . $resource,
            'certidao.restored' => $id !== '' ? 'Desarquivou certidão ID: ' . $id : 'Desarquivou certidão: ' . $resource,
            'certidao.deleted' => 'Apagou: ' . ($name !== '' ? $name : $resource),
            default => '',
        };
    }

    /** @param array<string,mixed> $item */
    private static function optionDetails(string $action, array $item): string
    {
        $name = self::text($item, 'resource_name');
        $id = self::text($item, 'resource_id');
        $list = str_starts_with($action, 'fornecedor.') ? 'fornecedores' : 'tipos';
        if (str_ends_with($action, '.created')) {
            return $name !== '' ? "Adicionou '" . $name . "' em " . $list : 'Cadastrou opção: ' . self::resourceDetails($item);
        }
        return $id !== '' && $name !== ''
            ? 'Atualizou opção ID ' . $id . ' em ' . $list . " para '" . $name . "'"
            : 'Editou opção: ' . self::resourceDetails($item);
    }

    /** @param array<string,mixed> $item */
    private static function contractDetails(string $action, array $item): string
    {
        $resource = self::resourceDetails($item);
        $name = self::text($item, 'resource_name');
        $id = self::text($item, 'resource_id');
        $parent = self::text($item, 'resource_parent_id');
        $number = self::text($item, 'resource_number');

        if (self::text($item, 'resource_type') === 'contrato'
            && in_array($action, ['contrato.sheet_created', 'contrato.sheet_duplicated'], true)) {
            $parent = $id;
        }

        return match ($action) {
            'contrato.created' => $name !== '' ? "Novo contrato '" . $name . "' cadastrado." : 'Novo contrato cadastrado: ' . $resource . '.',
            'contrato.updated' => 'Contrato' . ($id !== '' ? ' #' . $id : '') . ($name !== '' ? " atualizado para '" . $name . "'." : ' atualizado.'),
            'contrato.deleted' => self::deletedContractDetails($item),
            'contrato.sheet_created' => $parent !== '' ? 'Adicionou nova folha ao contrato #' . $parent . '.' : 'Cadastrou folha: ' . self::contractResourceDetails($item) . '.',
            'contrato.sheet_duplicated' => $parent !== '' ? 'Duplicou folha no contrato #' . $parent . '.' : 'Duplicou folha: ' . self::contractResourceDetails($item) . '.',
            'contrato.sheet_updated' => $number !== '' && $parent !== ''
                ? 'Atualizou observacoes da folha ' . $number . ' do contrato #' . $parent . '.'
                : 'Editou folha: ' . self::contractResourceDetails($item) . '.',
            'contrato.sheet_billed' => $number !== '' && $parent !== ''
                ? 'Atualizou faturamento da folha ' . $number . ' do contrato #' . $parent . '.'
                : 'Atualizou faturamento da folha: ' . self::contractResourceDetails($item) . '.',
            'contrato.item_created' => $name !== '' && $number !== '' && $parent !== ''
                ? "Adicionou produto '" . $name . "' na folha " . $number . ' do contrato #' . $parent . '.'
                : 'Cadastrou produto: ' . self::contractResourceDetails($item) . '.',
            'contrato.item_updated' => $id !== '' && $parent !== ''
                ? 'Atualizou produto ID ' . $id . ' no contrato #' . $parent . '.'
                : 'Editou produto: ' . self::contractResourceDetails($item) . '.',
            default => '',
        };
    }

    /** @param array<string,mixed> $item */
    private static function deletedContractDetails(array $item): string
    {
        $name = self::text($item, 'resource_name');
        $id = self::text($item, 'resource_id');
        $parent = self::text($item, 'resource_parent_id');
        $number = self::text($item, 'resource_number');
        $type = self::text($item, 'resource_type');
        if ($type === 'contrato' && $id !== '' && $name !== '') {
            return 'Contrato #' . $id . " ('" . $name . "') excluido.";
        }
        if ($type === 'folha' && $number !== '' && $parent !== '') {
            return 'Excluiu a folha ' . $number . ' do contrato #' . $parent . '.';
        }
        if ($type === 'produto' && $name !== '' && $id !== '' && $parent !== '') {
            return "Excluiu produto '" . $name . "' (ID " . $id . ') do contrato #' . $parent . '.';
        }
        return 'Excluiu: ' . self::resourceLabel($type) . ' ' . self::contractResourceDetails($item) . '.';
    }

    /** @param array<string,mixed> $item */
    private static function resourceDetails(array $item): string
    {
        $name = self::text($item, 'resource_name');
        $id = self::text($item, 'resource_id');
        if ($name !== '') {
            return $name . ($id !== '' ? ' (ID: ' . $id . ')' : '');
        }
        return $id !== '' ? 'ID: ' . $id : self::resourceLabel(self::text($item, 'resource_type'));
    }

    /** @param array<string,mixed> $item */
    private static function contractResourceDetails(array $item): string
    {
        $details = self::resourceDetails($item);
        $number = self::text($item, 'resource_number');
        $parent = self::text($item, 'resource_parent_id');
        if ($number !== '') {
            $details .= '; número: ' . $number;
        }
        return $details . ($parent !== '' ? '; contrato #' . $parent : '');
    }

    /** @param array<string,mixed> $item */
    private static function withContext(string $description, array $item): string
    {
        $resourceType = self::text($item, 'resource_type');
        $resourceId = self::text($item, 'resource_id');
        $target = self::text($item, 'target_name');
        $targetId = self::text($item, 'target_user_id');
        $context = '';
        if ($resourceId !== '' || self::text($item, 'resource_name') !== '') {
            $context = self::resourceLabel($resourceType) . ': ' . self::resourceDetails($item) . '.';
        } elseif ($target !== '' || $targetId !== '') {
            $context = 'Usuário: ' . ($target !== '' ? $target : '#' . $targetId) . ($target !== '' && $targetId !== '' ? ' (ID: ' . $targetId . ')' : '') . '.';
        }
        return self::joinDetails($description, $context);
    }

    private static function isGenericDescription(string $action, string $description): bool
    {
        if (($action === 'backup.created' && str_starts_with($description, 'Snapshot SQLite criado: '))
            || ($action === 'backup.downloaded' && str_starts_with($description, 'Download de snapshot SQLite autorizado: '))) {
            return true;
        }
        if ($description === '' || in_array($description, self::GENERIC_DESCRIPTIONS[$action] ?? [], true)) {
            return true;
        }
        if (str_starts_with($action, 'contrato.')) {
            $operation = substr($action, strlen('contrato.'));
            return isset(self::CONTRACT_DESCRIPTIONS[$operation])
                && in_array($description, ['Módulo 5: ' . $operation, self::CONTRACT_DESCRIPTIONS[$operation]], true);
        }
        return (str_starts_with($action, 'certidao.') || str_starts_with($action, 'fornecedor.') || str_starts_with($action, 'tipo.'))
            && $description === 'Operação de certidões e fornecedores.';
    }

    private static function joinDetails(string $first, string $second): string
    {
        return $first === '' ? $second : ($second === '' ? $first : $first . ' ' . $second);
    }

    /** @param array<string,mixed> $item */
    private static function text(array $item, string $key): string
    {
        $value = $item[$key] ?? null;
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param array<string,string> $labels
     *  @return array<string,string> */
    private static function sortedLabels(array $labels): array
    {
        $collator = new Collator('pt_BR');
        $collator->asort($labels);
        return $labels;
    }
}
