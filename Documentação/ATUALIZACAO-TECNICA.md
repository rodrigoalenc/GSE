# Atualização técnica e rastreabilidade

Data: 22/10/2026. Esta atualização acompanha a documentação acadêmica original e substitui instruções técnicas antigas conflitantes. O manual de usuário e o instrumento de avaliação vigentes estão nos arquivos MANUAL-USUARIO.md e AVALIACAO-USABILIDADE.md desta pasta.

## Situação implementada

O GSE implementa MVC em PHP, interface HTML/CSS/JavaScript e persistência SQLite. Os módulos de login/usuários, alunos/turmas/DVA, arquivo passivo, certidões/fornecedores, contratos/estoque e relatórios estão presentes. Não se deve descrever o backend e os testes já implementados como trabalhos futuros. A homologação institucional continua sendo uma etapa distinta.

O esquema atual é **21**. As versões 12–20 continuam sendo marcos históricos: arquivo passivo, certidões, contratos/estoque, proteção de dados, aditivos e movimentação/progressão anual. A v21 acrescenta revisões de edição e índices. Instruções que esperavam 16, 17 ou 18 como versão atual ficam substituídas por 21. Migrações são exclusivamente CLI; HTTP não altera o esquema.

## Matriz de requisitos e validação

| Requisito/caso de uso | Implementação | Evidência técnica | Aceite institucional |
|---|---|---|---|
| RF001 / UC001 alunos | Cadastro, edição, status e movimentação com histórico/revisão | ModuleTwo, StudentIntegrityRevision, ProgressaoAnual e HTTP/browser | Conferir fluxo da secretaria |
| RF002/RF003 DVA | Histórico, critérios compartilhados, filtros, painel e relatórios | DvaClassificationConsistency, DvaStatus, Relatorio | Conferir prazos e responsáveis |
| RF004 / UC004 passivo | Localização, pendências legadas, importação/arquivamento e preservação | Passivo, PassiveMigration e browser autenticado | Conferir caixas e numeração física |
| RF005 / UC005 certidões | Fornecedores, tipos, PDFs privados, histórico e vigência | Certidao, CertidaoMigration, HTTP/downloads | Validar atores e documentos |
| RF006 notificações | Serviços CLI, transporte SMTP e controle diário | DvaNotification e CertidaoNotification | SMTP e agendador reais |
| RF007 auditoria | Evento obrigatório em transações e diagnóstico sem payload | AuditAtomicity e TechnicalLoggerDiagnostics | Aprovar acesso e retenção |
| RF008 / UC006 contratos/estoque | Valores em centavos, revisão, recebimento, consumo e estorno | StockWorkflow, SqliteTransaction e HTTP/browser | Conferir operação física/documental |
| UC002/UC003 login e contas | Perfis, senha temporária, Argon2id, sessão e limites | Authentication, PasswordSecurity e browser senha | Definir recuperação e revogação |
| UC007 relatórios | Filtros, PDF/CSV e downloads | Relatorio, HTTP e browser downloads | Autorizar circulação de dados |
| NF001 responsividade | Layout adaptativo, teclado e menu fixável | Browser desktop/celular/paisagem | Zoom, leitor de tela e tarefas reais |
| NF002/NF004 tecnologias/MVC | PHP, JavaScript, SQLite e separação MVC | Lint, PHPStan e revisão | Sem mudança de stack exigida |
| NF003 intranet | Nginx/PHP-FPM, HTTPS e configuração institucional | Exemplo servidor e ensaio HTTP local | Servidor efetivo e carga |
| NF005 senhas | Hash Argon2id; não criptografia reversível | PasswordPolicy e PasswordSecurity | Política e recuperação de acesso |

## Decisões de domínio preservadas

Funcionários têm acesso operacional compartilhado; ações administrativas são protegidas no backend. A matriz de rotas efetiva é a tabela explícita em src/Core/Router.php, validada pelos testes de segurança. Ferramentas/Enumerar do passivo foram removidas das rotas e da orientação de uso.

Retirar um registro da lista ativa preserva seu histórico. Quantidade contratada, quantidade física e faturamento são conceitos distintos. Restauração e reativação devem manter consistência de vínculo entre aluno e passivo. Prévia não representa gravação; a confirmação revalida dados e permissões.

As divergências históricas entre termos de exclusão, atores e importação substitutiva/aditiva devem ser apresentadas ao responsável acadêmico/institucional com esta descrição do comportamento efetivo. Não foi inventada uma aprovação dessas decisões.

## Privacidade documental

A versão acadêmica original pode conter dados de contato dos participantes. Para distribuição pública, produza uma cópia que contenha somente dados cuja divulgação foi aprovada e necessária. O original acadêmico não precisa circular junto do pacote executável. O pacote de implantação contém código e documentos operacionais; exclui .env, chaves, banco, logs, snapshots e cadastros institucionais.

## Evidências da revisão

Consulte VALIDACAO-2026-10-22.md para os comandos e resultados executados. Uma execução de CI de um commit anterior não valida um diff posterior. O roteiro AVALIACAO-USABILIDADE.md deve ser preenchido após aplicação com usuários, nunca com resultados presumidos.
