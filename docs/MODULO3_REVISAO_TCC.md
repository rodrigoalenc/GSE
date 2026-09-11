# Revisão do Módulo 3 frente ao TCC

Revisão de 11/09/2026, sobre a branch `Modulo3`, base `3cc4e67d2a198d8c12975bbd5b2038398759f207`. A árvore estava limpa; `git ls-remote origin refs/heads/Modulo3` confirmou a mesma base remota. Não foi encontrado `AGENTS.md` no repositório nem nos diretórios ancestrais aplicáveis. Não houve push, merge, deploy ou acesso de escrita a banco real.

## Fonte e limites da comparação

Foi lido o PDF versionado [Documentação GSE.pdf](../Documentação/Documentação%20GSE.pdf), de 34 páginas, autores Rodrigo Alencar de Araújo e Calebe Henrique dos Santos Delmatta. SHA-256: `523acefd883acbf91363e0e4b0ed051b5e59d502c5a25f09c4e24458f5f9faf8`. A numeração impressa coincide com a página do arquivo nas referências abaixo. O texto foi extraído e as figuras relevantes foram renderizadas: caso de uso geral (p. 17), UC004 completo (p. 21), classes (p. 25) e mockups (p. 30).

O nome fornecido no pedido foi `TCC_2_ETAPA_1_MÓDULO_1 - Rodrigo-Calebe.pdf`; esse nome não foi encontrado no repositório nem nos anexos disponíveis. Os requisitos transcritos coincidem com o PDF versionado, usado como fonte disponível. Foi solicitada confirmação de equivalência ao usuário; até esta entrega, essa identificação permanece pendente. O PDF não foi alterado.

A Figura 5 associa o funcionário a controlar o arquivo, cadastrar, buscar, editar, excluir e organizar por caixa; a Tabela 7 exige autenticação, sem restrição administrativa. A descrição geral do funcionário (p. 17) menciona consulta do passivo, mas não diz que ela seja seu único acesso; adotou-se o UC004 específico para suas operações. O administrador mantém acesso total conforme p. 18. A Figura 9 apresenta `numero: string`, `caixa: string` e `excluir(id): bool`, sem nulabilidade, obrigatoriedade de número ou definição de exclusão física.

## Matriz de rastreabilidade

E = exigência explícita; I = interpretação de ambiguidade; A = recurso adicional compatível. “Antes” refere-se à base indicada acima.

| Requisito e página | Tipo | Antes / arquivo responsável | Diferença confirmada | Correção e evidência |
|---|---|---|---|---|
| RF004, p. 15; UC004, p. 21: localização, cadastro, busca, atualização e caixas | E | `src/Model/Passivo.php`, controller e views já oferecem campos, pesquisa, edição e navegação | Operações básicas já acessíveis ao funcionário | Preservadas; HTTP verifica cadastro, pesquisa, edição e filtro por funcionário e administrador; integração verifica mudança de caixa e conflito |
| UC004, Figura 5/Tabela 7, p. 21: funcionário executa exclusão autenticado | E (operação e ator) | `src/Core/Router.php` e `src/Views/passivo/detalhes.php`: situação restrita a administrador | Funcionário não podia executar exclusão | Nova rota autenticada `POST /passivo/excluir/{id}` e ação nos detalhes; HTTP verifica alteração persistida, CSRF, GET, visitante, 404 e auditoria |
| UC004, p. 21; classes, p. 25: significado de excluir | I | `Passivo::definirAtivo()` já inativava e restaurava preservando histórico | Texto não relacionava a inativação à exclusão prevista no UC004 | Consolidada exclusão lógica: `ativo=0`, sem DELETE; interface explica retirada do acervo e preservação. Validação acadêmica pendente |
| UC004, p. 21: organização e busca no fluxo de exclusão | E + I (consulta histórica) | Router, controller, model e `views/passivo/index.php`: atalho de inativos administrativo; caixas sempre ativas; links perdiam situação | Caixa contendo apenas excluídos não aparecia no filtro; navegação voltava ao acervo ativo | Consulta liberada; caixas e navegação respeitam ativos/excluídos/todos; links mantêm situação; detalhes voltam à caixa na situação correta |
| RF004, p. 15; entradas do UC004, p. 21; classes, p. 25 | I | Model e `views/passivo/form.php`: nome/caixa obrigatórios, número opcional, legado pendente preservado | PDF lista número sem impor preenchimento inicial | Mantidos preenchimento posterior, validação e enumeração; sem números fictícios, renumeração automática ou migração. Interpretação acadêmica pendente |
| RF007, p. 15; entrevista, p. 14: autor das alterações | E | `AuditLogger`, `SqliteTransaction`, `Passivo::definirAtivo()` já auditavam na mesma transação | Sem alteração necessária no mecanismo | Novo fluxo reutiliza auditoria obrigatória; testes comprovam autor/ação/registro e rollback integral em criação, edição, exclusão, restauração e importação |
| NF001, p. 16; aceitação, p. 13; mockups, p. 30 | E | Views, `public/assets/css/passivo.css`, layout e Bootstrap local | Ações/textos precisavam acompanhar permissões; CSS existente compatível em inspeção de código | Identidade, sidebar, cards, tabelas, foco e media queries preservados. Verificação visual desktop/celular e usabilidade ainda pendentes |
| NF002/NF004, p. 16 | E | PHP/JavaScript/SQLite e separação Model–View–Controller | Nenhuma diferença confirmada | Arquitetura preservada; lint, análise estática, testes de integração e HTTP |
| NF003, p. 16; ambiente, p. 27 | E | Assets locais e SQLite, sem CDN necessário ao módulo | Nenhuma dependência externa adicionada ao funcionamento | HTTP local validado; desempenho e operação na intranet escolar real pendentes |
| NF005, p. 16; segurança, p. 27 | E | `PasswordPolicy`, `Auth`, `Usuario`: hashes e autenticação existentes | Nenhuma alteração necessária identificada nesta revisão do módulo | Fluxos preservados; suíte dos módulos anteriores e HTTP de login/troca de senha executados |
| CSV, enumeração, TXT, restauração e envio de aluno | A | Ferramentas existentes, com restrições administrativas específicas | Liberação conjunta ampliaria acesso além do UC004 | Restauração, CSV, enumeração e envio continuam administrativos; TXT mantém permissão existente. Nova rota não aceita restaurar via payload |
| Consistência de conflitos e exportação após exclusão | I + A | `Passivo::atualizar()` disputava localização mesmo para excluído; exportação vazia voltava a ferramentas administrativas | Histórico podia ficar impedido de edição por posição já reutilizada; funcionário podia terminar em 403 | Edição de excluído não ocupa posição; restauração revalida posição/vínculo. TXT sem ativos informa o motivo e retorna à consulta autorizada |

## Decisões preservadas

- Exclusão lógica remove o registro de consultas, contagens, caixas, TXT e enumeração padrão. Consulta histórica é explícita. Registro, IDs, dados pessoais, posição, vínculos e auditoria permanecem disponíveis.
- Restauração é adicional ao UC004 e permanece administrativa. `/passivo/status/{id}` conserva a proteção anterior; `/passivo/excluir/{id}` só pode desativar, mesmo com `ativo=1` enviado pelo cliente.
- Nome e caixa continuam obrigatórios. Número pode ser preenchido depois, sem criar posição fictícia. `localizacao_pendente` e avisos de legado permanecem como estavam.
- A posição fica disponível para outro registro ativo após exclusão. A restauração bloqueia posição ocupada e vínculo ativo duplicado. Corrigir um excluído não o restaura.
- Nenhuma migração ou alteração de schema foi necessária. Trigger contra DELETE, FKs, índices e migrações publicadas foram preservados.
- CSV continua aditivo, limitado, com prévia, confirmação única vinculada à sessão e rollback transacional. Aluno original e DVA continuam preservados; envio de aluno ativo permanece bloqueado.

A referência visual foi conferida no [ProjetoGSE original](https://github.com/rodrigoraa/ProjetoGSE), commit `f0bb641b2d1a074bddd598e52f3e733872d230db`, lendo `public/assets/css/passivo.css` e `src/Views/passivo/index.php` sob `sistema_escolar_root/sistema_escolar`. Foram preservados azul institucional, cards, sidebar, organização dos formulários e tabelas. Nenhuma rotina destrutiva foi importada.

## Verificações executadas

Ambiente: Windows, PHP 8.4.13, PHPUnit 12.5.33. As extensões `fileinfo` e `intl` estavam instaladas, mas desabilitadas no PHP global. Foram ativadas por `PHP_INI_SCAN_DIR` apontando para configuração temporária do projeto; `zip` também foi ativada para instalar os arquivos do lockfile. Nenhuma configuração global foi editada. Bancos de testes são sintéticos e temporários, inclusive nos cenários que simulam configuração de produção.

| Comando | Resultado |
|---|---|
| `git ls-remote origin refs/heads/Modulo3` | Remoto na base indicada; primeira tentativa bloqueada pela rede do sandbox, consulta autorizada concluída |
| `composer install --no-interaction --prefer-dist` | Concluído com o lockfile, sem atualizar dependências; primeira tentativa detectou extensões desabilitadas; tentativa sem ZIP ficou no clone do PHPStan e foi interrompida; instalação com ZIP concluída |
| `composer validate --strict` | Passou |
| `composer audit --locked` | Passou; nenhum aviso de vulnerabilidade encontrado |
| `composer lint` | Passou; 102 arquivos PHP |
| `composer analyse` | Passou; nenhum erro |
| `composer test -- --display-skipped` | Passou: 139 testes, 1.034 asserções, 1 ignorado (permissões POSIX em Windows) |
| `composer http-test` | Passou: 117 verificações |
| `git diff --check` | Passou; sem erros de whitespace |

Uma primeira execução HTTP dos novos testes falhou porque o próprio teste enviou acento sem codificação na URL. Corrigido com `rawurlencode`, sem reduzir as asserções. A execução seguinte passou com 115 verificações; a rodada final passou com 117, incluindo o retorno da exportação de caixa vazia. Lint, análise estática e PHPUnit foram repetidos após a última alteração de código. O hash do PDF permaneceu idêntico.

Para reproduzir a configuração temporária de extensões neste Windows, sem editar o `php.ini` global:

```powershell
$reviewIni = Join-Path $env:TEMP 'gse-php-review'
New-Item -ItemType Directory -Force $reviewIni | Out-Null
Set-Content -Encoding ASCII (Join-Path $reviewIni 'extensions.ini') @('extension=fileinfo', 'extension=intl', 'extension=zip')
$env:PHP_INI_SCAN_DIR = $reviewIni
composer check
```

As DLLs devem existir no `extension_dir` do PHP. Na revisão, foi usada a mesma configuração em `tmp/php-config`, removida ao final junto aos intermediários da leitura do PDF. O teste HTTP malsucedido deixou um diretório temporário de teste no TEMP do sistema; a execução final concluiu sem aviso de limpeza.

Cobertura acrescentada: exclusão pelo funcionário e administrador, interface/URL direta, bloqueio de visitante, CSRF inválido, GET sem mutação, ID inexistente, autor da auditoria, rollback HTTP e de modelo, dados preservados, retirada das consultas ativas, caixas históricas, exportação/enumeração só de ativos, posição reutilizada, conflito de restauração e preservação do vínculo de aluno/DVA. Continuam testados os bloqueios administrativos de usuários, turmas, situação de aluno, CSV, enumeração e envio de aluno.

## Pendências de validação

1. Confirmar que o PDF versionado é a mesma edição nomeada no pedido.
2. Validar academicamente exclusão lógica e número preenchido posteriormente. O texto não resolve essas ambiguidades; renomear a interface não as elimina.
3. Verificação visual desktop/celular, teclado, leitores de tela e navegadores: não executada. O runtime do navegador retornou `No browser is available`; a descoberta retornou lista vazia. Inspeção de código e testes HTTP não equivalem a homologação visual.
4. Teste POSIX de permissões SQLite: ignorado automaticamente no Windows; deve rodar em Linux. Não foi enfraquecido ou removido.
5. Homologação com a escola, desempenho em intranet e migração de uma cópia anonimizada real continuam pendentes conforme os roteiros existentes. Nenhum dado real foi usado.

## Arquivos alterados

- `src/Core/Router.php`: rota específica de exclusão e consulta histórica autenticada.
- `src/Controllers/PassivoController.php`: exclusão fixa, separação de restauração, filtros e retorno de exportação.
- `src/Model/Passivo.php`: caixas por situação, conflitos apenas no acervo ativo durante edição e mensagem de exportação vazia.
- `src/Views/passivo/{detalhes,index,form}.php`: ações, explicações, estados e navegação.
- `tests/Integration/PassivoTest.php`, `tests/http-smoke.php`: comportamento e regressões.
- `tests/Security/{CoreSecurityTest,TemplateSecurityTest}.php`: número de rotas e estado renderizado atualizados, sem remover verificações.
- `README.md`, `docs/MODULO3_ARQUIVO_PASSIVO.md`, `docs/MODULO3_HOMOLOGACAO.md`, `docs/MODULO3_VALIDACAO_MANUAL.md` e este relatório: permissões, rastreabilidade e limites reais.

As operações explícitas do UC004 foram ajustadas e verificadas nos testes disponíveis. Exclusão lógica e número opcional são interpretações adotadas; equivalência da fonte, validação acadêmica, homologação visual e operação escolar permanecem pendentes. Esta revisão não declara conformidade integral.
