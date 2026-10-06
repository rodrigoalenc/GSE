# Finalização verificável dos cinco módulos do GSE

Revisão de 05/10/2026, sobre a árvore de trabalho iniciada limpa em `74e772765b7440443b6bfb476f41ebf3ccf41d2b`. Não foram encontrados `AGENTS.md` aplicáveis no projeto ou nos diretórios ancestrais. O plano autorizado é `Prompt_Codex_Finalizacao_TCC.md`. As alterações permanecem locais para revisão; não houve commit, push, merge, implantação ou migração institucional.

## Fonte acadêmica e limites

O arquivo exato `TCC_2_ETAPA_1_MÓDULO_1 - Rodrigo-Calebe.pdf` não foi localizado no projeto, Downloads, Documents/Codex ou OneDrive. Foi solicitado ao usuário o caminho ou a confirmação de equivalência da edição. O PDF versionado tem 34 páginas e SHA-256 `523acefd883acbf91363e0e4b0ed051b5e59d502c5a25f09c4e24458f5f9faf8`; não foi presumido equivalente nem alterado. As referências de páginas e casos de uso abaixo provêm do plano autorizado. A leitura integral, os diagramas e os mockups da edição correta permanecem pendentes; resultados locais não constituem conformidade acadêmica integral.

E = exigência explicitada pelo plano, cuja redação literal no TCC ainda precisa ser conferida; I = interpretação de ambiguidade; T = decisão técnica adicional. O resultado funcional se refere ao código e aos testes, separadamente da confirmação acadêmica.

## Matriz de rastreabilidade

| Módulo / requisito e página indicada | Tipo | Ator | Comportamento esperado | Implementação | Teste/evidência | Resultado e pendência |
|---|---|---|---|---|---|---|
| 1 / UC003, p. 20; NF005, p. 16 | E | Visitante, usuário | Login, senha protegida e sessão válida | `LoginController`, `Auth`, `PasswordPolicy`, `SessionManager` | `AuthenticationTest`, `SessionLifecycleTest`, `RequestSecurityTest`, HTTP | Verificado localmente; texto/diagrama da edição correta pendente |
| 1 / UC002, p. 19 | E | Administrador | Criar/editar contas, perfis, situação e senha temporária | `Router`, `UsuarioController`, `Usuario` | `AdminIntegrityTest`, `UsuarioTest`, HTTP/403/CSRF | Verificado; proteção do último administrador preservada |
| 2 / RF001–RF003, p. 15; UC001, p. 18 | E | Usuário autenticado | Cadastro, busca, edição, turma e DVA corrente/histórica | `Aluno`, `Turma`, `Dva`, `AlunoController` | `ModuleTwoTest`, `AlunoPainelTest`, HTTP | Verificado; vínculo e histórico preservados |
| 2 / UC001, p. 18; atores p. 17–18 | I | Administrador para inativação/reativação | Autenticação genérica não concede toda ação crítica | `Router`, `AlunoController::status` | HTTP funcionário/visitante/admin e CSRF | Restrição administrativa preservada; interpretação exige validação acadêmica |
| 2 / RF006, p. 15 | E + T | Administrador ativo com opt-in | Alerta consolidado de DVA, configuração habilitada, destinatário válido, repetição controlada | `DvaNotificationService`, `PhpMailerTransport`, `notify-dva.php` | `DvaNotificationTest`, `PhpMailerTransportTest`, `CertidaoCliTest` | Corrigidos e-mail inválido e conta alterada durante envio; SMTP/agendamento institucional pendentes |
| 3 / RF004, p. 15; UC004, p. 21 | E | Funcionário e administrador | Localização completa contém caixa e posição; consulta/cadastro/edição | `Passivo`, `PassivoController`, views passivo, migração v16 | `PassivoTest`, `PassiveMigrationTest`, HTTP, capturas atuais | Corrigido; criação/importação/arquivo individual recusam incompletude, legado permanece pendente |
| 3 / UC004, p. 21 | I | Funcionário exclui; administrador restaura | Exclusão lógica retira da consulta ativa e preserva histórico/vínculos | `Passivo::definirAtivo`, rotas específicas | `PassivoTest`, HTTP autorização/rollback/restauração | Preservado; o plano não exige DELETE físico; interpretação acadêmica pendente |
| 3 / Enumeração, CSV e arquivamento individual/lote | T | Administrador | Prévia/confirmar, não reutilizar maior posição histórica, nenhuma DVA perdida | `Passivo`, `PassivoCsvService` | `PassivoCsvTest`, `PassiveBatchArchiveTest`, HTTP, browser | Verificado; enumeração usa aritmética decimal e rejeita overflow de 40 dígitos/estado alterado |
| 4 / RF005, p. 15; UC005, p. 22 | E | Funcionário e administrador | Fornecedores/tipos, matriz, consulta, renovação, validade, anexar/baixar PDF privado | `Certidao`, `CertidaoController`, `CertidaoStorage` | `CertidaoTest`, `CertidaoMigrationTest`, HTTP multipart/download | Verificado; arquivos privados e histórico preservados |
| 4 / Figura 6 p. 22 e Figura 17 p. 29 | I | Funcionário e administrador | Capacidade de anexar PDF e cadastro sem anexo | `Certidao::create`, formulário e download autenticado | `CertidaoTest`, HTTP, capturas cadastro/renovação | PDF opcional preservado. O plano relata `include` versus “Opcional”; conferência literal e aceite da interpretação pendentes |
| 4 / RF006, p. 15 | E + T | Administradores ativos com e-mail válido | Alertas diários de certidões independem do opt-in DVA | `CertidaoNotificationService`, `notify-certidoes.php` | `CertidaoNotificationTest`, CLI fake/disabled | Verificado; lock de processo/transações curtas, SMTP institucional pendente |
| 5 / RF008, p. 15; UC006, p. 23 | E | Funcionário; administrador nas correções críticas | Pedido, notas, produtos, unidades e faturamento | `Contrato`, `ContratoController`, views contratos | `ModuloCincoTest`, `PedidoTest`, `ContractFormDraftTest`, HTTP e navegador autenticado | Verificado; rascunhos, autorização, CSRF e valores em centavos preservados |
| 5 / UC006, p. 23; estoque antigo | E + T | Funcionário movimenta; administrador estorna/concilia/recupera | Quantidade contratada separada do saldo físico; contagem, motivo, limites, unidade, auditoria | `Contrato`, `EstoqueController`, transações imediatas | `LegacyStockRecoveryTest`, corridas entre processos, HTTP | Verificado localmente; não inferido saldo legado nem criado movimento fictício |
| 5 / UC007, p. 24 | E + T | Usuário autenticado | Relatórios filtrados da DVA corrente; PDF até 500, CSV até 10.000 | `RelatorioAlunos`, `RelatorioController` | `RelatorioTest`, HTTP, benchmark com nomes sequenciais e PDF renderizado | Verificado; CSV 10.000 e PDF 500 medidos, filtros/continuidade preservados |
| Todos / RF007, p. 15 | E + T | Usuário autor da operação | Registro de autoria e auditoria obrigatória em mutações | `AuditLogger`, `SqliteTransaction`, models | `AuditTest`, falha forçada da auditoria nos módulos | Verificado; rollback conserva dados/vínculos/estoque |
| Todos / NF001–NF004, p. 16; arquitetura p. 26–28; mockups p. 29–30 | E + T | Usuários da escola | MVC PHP/JS/SQLite, assets locais, interface responsiva, operação privada | `public/index.php`, MVC, CSS/JS locais; roteiro FPM/Nginx | lint/PHPStan/HTTP/browser, 3 viewports, backup e integridade | Verificado localmente; literal PDF, Linux, equivalência visual integral e intranet/aceite pendentes |

A [matriz de permissões](FINALIZACAO_OPERACAO.md) cobre visitante, funcionário, administrador, telas, ações e URLs diretas. Nenhuma pré-condição genérica de login foi usada para reduzir autorização administrativa, CSRF ou validação de vínculo.

## Correções implementadas

1. **Arquivo Passivo:** localização completa exige caixa e posição válidas. Criação, importação e arquivamento individual revalidam no servidor; o lote gera posições na prévia. Registros históricos incompletos continuam editáveis, conservando campos conhecidos e sem inventar localização. Concluir a localização revalida conflitos, autoria e auditoria. Filtro completa/pendente, indicador textual e TXT deixam a situação explícita.
2. **Migração v16:** corrige somente `localizacao_pendente` em registros incompletos, instala guards de INSERT/UPDATE e índice. Não renumera, cria caixa, altera datas/autoria/IDs/vínculos ou remove dados. Backup, equivalência do esquema, idempotência, integridade e rollback são testados.
3. **Enumeração:** continua após o maior inteiro usado na caixa, incluindo pastas excluídas; usa aritmética em strings para preservar números maiores que o inteiro do PHP. Preenche apenas posições vazias de registros ativos e invalida prévia desatualizada. Overflow de 40 dígitos é recusado antes de escrever.
4. **POST corrigido:** cadastro e arquivamento individual passam a usar o payload atual; um formulário anterior guardado na sessão não sobrepõe uma posição corrigida no POST seguinte.
5. **Alertas DVA:** descarta e-mails legados inválidos e revalida conta ativa, perfil administrativo, preferência e endereço durante a reserva de cada envio. Duas regressões falharam no código anterior, demonstrando o problema. Transporte fictício preserva retry e deduplicação diária.
6. **Navegador:** o helper aguarda alvo visível, habilitado, com geometria estável e confirmado por hit-test. Mantida a cobertura; acrescentada regressão determinística e fluxo autenticado com três notas e submissão real do construtor. `public/assets/js/app.js` foi preservado.
7. **Interface/impressão/entrega:** corrigidos espaço entre Salvar/Cancelar, separador inferior e aparência do campo Caixa do cadastro passivo. A impressão de nota longa permite quebra entre produtos: o ensaio com 80 produtos passou de cinco para quatro páginas, com produtos na primeira página, cabeçalhos repetidos, linhas intactas e ordem preservada. Configuração privada está explícita nos exemplos de ambiente. A licença integral MIT do Bootstrap foi incluída, com origem oficial, preservando créditos e licenças existentes. O workflow declara extensões, verifica Chrome/Chromium e guarda diagnósticos de falha com action fixada por SHA.

## Causa comprovada da falha de navegador

Na quarta repetição instrumentada da suíte existente, a terceira nota estava selecionada e visível, o formulário de produto permanecia fechado e o hash era `#folha-3`. O helper mediu o clique perto de y=755 numa janela de aproximadamente 764×485; `elementFromPoint` retornava `null`. A rolagem de `followHash/hashchange`, ainda pendente, substituíra o `scrollIntoView` do teste. Esperar somente dois frames e observar o hash não garantia que o controle estivesse disponível para interação.

O helper anterior também falhou numa reprodução determinística com rolagem adiada por frames. O helper corrigido passou dez repetições consecutivas. A aplicação autenticada com três notas foi executada com ambos os helpers e passou nessa comparação; não foi comprovado defeito de produção nesse markup compacto. A mudança corrige a sincronização da automação e não mascara falhas do aplicativo.

Diagnósticos de DOM/URL/console/PNG são materiais técnicos temporários em `.local-qa/old-helper-regression/` e `.local-qa/browser-diagnostic-run-4.log`. As evidências acadêmicas contêm somente o GSE, com dados fictícios. As duas suítes permanentes verificam notas 2/3, produto/faturamento, fragmentos inválidos/internos, fechamento, foco, teclado, Ctrl+click, histórico, impressão/retorno, remoção/reindexação, unidades, nomes únicos de `FormData`, POST persistido e seleção/arquivamento de alunos entre páginas/filtros.

## Validação final

Ambiente local: Windows, PHP 8.4.13, Composer 2.8.12, Node 24.11.0, Chrome 154.0.8037.98; extensões do lock presentes. Bancos, PDFs de ensaio, sessões e perfis foram descartáveis. As configurações, bancos e PDF acadêmico presentes antes do trabalho foram conferidos por hash. Os resultados finais e o manifesto de arquivos testados constam de `evidencias/2026-10-05/validacao.json`.

| Verificação executada no código final | Resultado real |
|---|---|
| `composer validate --strict` | Aprovado, saída 0 |
| `composer audit --locked` | Aprovado, sem avisos de vulnerabilidade; acesso de rede habilitado para esta consulta |
| `composer check-platform-reqs --lock` | Todos os requisitos instalados; nenhum requisito ignorado |
| Instalação nova a partir do lock, em diretório descartável | Aprovada; 32 pacotes com versões e referências idênticas ao lock e ao vendor usado nos testes |
| `composer lint` | 147 arquivos PHP sem erro de sintaxe |
| `composer analyse` | PHPStan nível 6 sem erros, saída 0 |
| `composer test -- --display-skipped` | 274 testes, 2.579 asserções, 1 teste POSIX ignorado no Windows; 3 min 12,573 s, pico de 20 MiB |
| `composer http-test` | 298 verificações aprovadas |
| `composer browser-test` | 127 verificações aprovadas: 58 de contratos e 69 no fluxo autenticado; repetido após a correção de impressão |
| `node --check public/assets/js/app.js` | Aprovado, saída 0 |
| `git diff --check` | Aprovado, sem erros de espaços no diff |
| Interface e impressão atuais | 141 capturas em três viewports; PDF de 80 produtos com quatro páginas, pedido de três notas e nota individual com uma página cada |
| Relatórios e restauração | CSV de 10.000 e PDF de 500 alunos medidos; backup/restauração conjunta verificada; detalhes no roteiro operacional |
| Preservação dos arquivos anteriores | Quatro arquivos protegidos conferidos: nenhuma alteração nem inclusão de dados/configuração institucional |
| Workflow | YAML válido, dois jobs, permissões de leitura e actions fixadas por SHA; execução Linux/CI remoto pendente |

A instalação nova utilizou temporariamente a extensão ZIP já disponível, somente no processo Composer; não modificou `php.ini` nem ignorou requisitos. Logs técnicos completos permanecem em `.local-qa/final-*.log`. O [registro de validação](evidencias/2026-10-05/validacao.json) contém hashes dos arquivos de código e configuração; a impressão SHA-256 do conjunto de código/configuração testado é `579f5f8989d31e3f9f3fb5bf9593a6365153a354a791e0e8ae4ecd074c2bfa9b`, no campo `workingTreeCodeSha256`. O [manifesto visual](evidencias/2026-10-05/manifesto.json) registra dimensões e hashes dos 163 arquivos PNG/PDF entregues. Documentação posterior não modifica essa impressão do código testado.

Os totais anteriores (inclusive 259 testes e 2.407 asserções citados no plano) permanecem históricos. A primeira execução desta sessão coincidiu com testes novos e correções em andamento; suas falhas não foram usadas como aprovação final. Os resultados finais acima se referem ao código estabilizado.

O ensaio de exportação e o de restauração estão em [FINALIZACAO_OPERACAO.md](FINALIZACAO_OPERACAO.md). O [inventário visual](INTERFACE_FINALIZACAO.md) identifica telas/estados e capturas atuais; imagens de 02/10 são preservadas como histórico. Não foram alterados os limites de exportação nem aplicado schema em banco institucional.

## Arquivos alterados para revisão

| Grupo | Arquivos e finalidade |
|---|---|
| Localização e migração | `src/Model/Passivo.php`, `src/Controllers/PassivoController.php`, `src/Core/DatabaseInitializer.php`, `database/schema.sql`, views passivo `form`, `index`, `detalhes`, `importar` e `arquivar-aluno`: validação, legado pendente, enumeração e v16 |
| Alertas e operação | `src/Services/DvaNotificationService.php`, `.env.example`, `.env.production.example`: destinatários revalidados e caminho privado explícito |
| Aparência e impressão | `public/assets/css/passivo.css`, `public/assets/css/modulo5-print.css`: diferenças medidas no cadastro e paginação de notas longas |
| Regressões PHP/HTTP | Testes de passivo, lote, CSV, migração/convergência, DVA, CLI e segurança; novo `OperationalBackupTest.php`; `tests/http-smoke.php` |
| Navegador | `tests/browser-contract-tabs.mjs`, `tests/browser-passivo-batch.mjs`, `tests/fixtures/passivo-browser.php`: sincronização, diagnóstico e fluxo autenticado persistido |
| CI e créditos | `.github/workflows/ci.yml`, licença e README em `public/assets/vendor/bootstrap/`: extensões, Chrome, evidências de falha e licença integral |
| Documentação/evidências | README e documentos existentes dos módulos/checklist; novos `FINALIZACAO_TCC.md`, `FINALIZACAO_OPERACAO.md`, `INTERFACE_FINALIZACAO.md` e `docs/evidencias/2026-10-05/` |

O diff local é a lista exata de alterações. `composer.json`, `composer.lock`, o JavaScript da aplicação, o logo, o PDF acadêmico e as configurações/dados institucionais não foram modificados. Materiais técnicos temporários estão ignorados em `.local-qa/`.

## Homologação e parecer separado

| Dimensão | Parecer | Próximo passo concreto |
|---|---|---|
| Implementação dos cinco módulos | Correções verificáveis concluídas; testes locais aprovados conforme tabela final | Revisar diff, migração v16 e executar o mesmo artefato no Linux da implantação |
| Aderência ao TCC | Rastreabilidade provisória completa; conformidade integral não declarada | Fornecer caminho/confirmar edição do PDF exato; ler integralmente figuras/texto e confirmar ambiguidades UC001, PDF opcional e exclusão lógica |
| Fidelidade visual | Identidade existente preservada, estados atuais capturados e inspecionados; equivalência integral não declarada | Concluir comparação de todos os estados equivalentes e conferir mockups da edição acadêmica correta, mantendo informações de segurança/estoque |
| Prontidão para operação escolar | Código e roteiro preparados; operação institucional não homologada | Ensaiar migração/backup conjunto em cópia anonimizada, FPM/Nginx/ACL/HTTPS, SMTP isolado, cron, intranet, carga e aceite com a escola |

Não há Ubuntu compatível disponível localmente: WSL lista apenas `docker-desktop`, e o daemon Docker não está ativo. A execução remota exigiria publicação, excluída pelo plano. O teste POSIX permanece ativo no CI e é ignorado explicitamente no Windows. A configuração do workflow foi revisada; isso não equivale a execução aprovada em Linux.

O roteiro institucional executável está em [FINALIZACAO_OPERACAO.md](FINALIZACAO_OPERACAO.md), com etapas offline de SQLite/PDFs, comandos de integridade e esquema, SMTP, monitoramento e critérios de aceite. Os checklists institucionais permanecem desmarcados até execução real. Não foram inventados autoria, autorização do logo, testes ou aprovações; a autorização institucional do logo deve constar do aceite da escola.
