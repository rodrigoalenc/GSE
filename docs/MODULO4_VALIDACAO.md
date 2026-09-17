# Módulo 4 — evidências de validação

## Revisão de 17/09/2026

Repositório local `rodrigoalenc/GSE`, branch `Modulo4`, HEAD inicial `6773381`. A árvore estava limpa, sem commits locais posteriores ao considerado pela revisão. Não havia `AGENTS.md` aplicável no repositório ou diretórios pais consultados. Foram lidos README, política de segurança, documentação do módulo, scripts Composer/CI e código/testes atuais. Alterações entregues na árvore de trabalho, sem push, merge ou deploy.

### Correções e evidências

- **SMTP sem transação longa:** lock exclusivo no arquivo lateral do banco, transação curta para registrar tentativa, SMTP sem transação, transação curta para confirmação. Testes fazem INSERT por outra conexão durante transporte simulado e disputam o lock, verificam falha parcial/reexecução, término de processo e recuperação, e aceite simulado seguido de falha de confirmação que efetivamente duplica na retentativa. Não é entrega exatamente uma vez. Timeouts explícitos de 15 s para conexão/leitura e 30 s por comando SMTP, sem alegar limite global do lote.
- **Edição concorrente:** v14 adiciona revisão às duas listas e tabela de tentativas. Dois editores em conexões diferentes não sobrescrevem nem reativam registros com revisão antiga/ausente. A falha de auditoria reverte também a revisão. HTTP cobre dois usuários, mensagem em português, rascunho escapado e salvamento após comparação com revisão atual.
- **Migração:** testes v13→v14 com fornecedor inativo, IDs, sequência, entregas prévias e tipo legado preservados; aplicação repetida e rollback de DDL/versão por falha induzida. Instalação limpa e migrações antigas continuam convergindo. Nenhum banco institucional foi migrado.
- **Matriz:** 5 fornecedores por página, até 10 documentos por coluna e navegação documental independente (máximo 50 cartões); filtros combinados, totais e limites são exercitados. Pendências incluem vencidas, vencem hoje, a vencer, vencimento inválido e referência de PDF ausente, respeitando o estado selecionado. Cartões trazem emissão, vencimento e prazo civil; tela cheia tem saída por botão/Esc e estado/foco acessíveis no código.
- **Regras preservadas:** funcionário mantém as operações UC005; a renovação arquiva apenas a selecionada e preserva PDF/histórico. Exclusão lógica e arquivo continuam distintos, vencidas continuam correntes, downloads são privados/autenticados e rotas mantêm CSRF. Suítes dos módulos anteriores continuam presentes.

### Referência acadêmica e inspeção visual

Foi lido `Documentação/Documentação GSE.pdf`, 34 páginas, SHA-256 `523acefd883acbf91363e0e4b0ed051b5e59d502c5a25f09c4e24458f5f9faf8`. A página 29 foi renderizada com Poppler e inspecionada; não houve alteração do PDF. Não foi encontrado o arquivo separado chamado TCC_2 e não se presume equivalência entre versões.

| Referência | Observação no PDF local/original | Tratamento nesta revisão |
|---|---|---|
| Figura 16 | Matriz com tipos nas linhas, fornecedores nas colunas, cartões de datas/prazo, ações e identidade azul | Orientação preservada; emissão/prazo, pendências e tela cheia acrescentados; paginação limitada por fornecedor/documentos |
| Figura 17 | Formulário com fornecedor, tipo, emissão, vencimento, PDF e observação | Campos e formulário atuais preservados, com validação no servidor e PDF privado |
| Figura 18 | Configuração em duas listas, fornecedores e tipos | Duas listas preservadas, com revisão oculta e recuperação explícita do conflito |

Também foi consultado [ProjetoGSE](https://github.com/rodrigoraa/ProjetoGSE), com inspeção do código da cópia local no commit `f0bb641b2d1a074bddd598e52f3e733872d230db`. A comparação relaciona as imagens de referência ao código atual; **não é uma comparação entre screenshots de duas aplicações renderizadas**.

Navegador: seleção para URL local retornou `No browser is available`; após consultar o diagnóstico da habilidade de navegador, a descoberta retornou `[]`. Assim, **não foram realizados testes visuais desktop/celular nem geradas screenshots da aplicação**. Evidência local da referência: `.local-qa/academic-29.png` (ignorado, reproduzível renderizando p. 29 do PDF versionado). Sintaxe JavaScript foi verificada com `node --check public/assets/js/app.js`. HTTP/HTML e revisão de CSS não comprovam responsividade, foco ou funcionamento real de fullscreen.

### Execuções da revisão

Ambiente: Windows, PHP 8.4.13, PHPUnit 12.5.33, `PHP_INI_SCAN_DIR=E:\Projetos\GSE\.local-qa`, com `intl` e `fileinfo` habilitados localmente. Sem alteração do `php.ini` global ou e-mail real.

A primeira execução completa chegou a 178 testes e encontrou falha no novo auxiliar de encerramento de processo por ausência do autoload do PHPUnit. O auxiliar foi corrigido; esse resultado intermediário não constitui aprovação.

| Comando/verificação | Resultado final observado |
|---|---|
| `composer validate-project` (via `composer check`) | Composer válido em modo estrito |
| `composer lint` (via `composer check`) | 121 arquivos PHP com sintaxe válida |
| `composer analyse` (via `composer check`) | PHPStan nível 6 sem erros |
| `composer test` (via `composer check`) | **178 testes, 1.267 asserções, sem falhas/erros, 1 ignorado** |
| `composer http-test` (via `composer check`) | **156 verificações aprovadas** |
| `php vendor/phpunit/phpunit/phpunit --filter CertidaoNotificationTest` | **5 testes, 32 asserções aprovadas**, após reforçar o teste com dois processos simultâneos e gravação enquanto o filho simula SMTP |
| `composer audit --locked` | Nenhum aviso de vulnerabilidade encontrado na consulta autorizada ao Packagist |
| `node --check public/assets/js/app.js` | Sintaxe JavaScript válida |
| `git diff --check` | Sem erros de whitespace |
| Diff e SHA-256 do PDF acadêmico | Nenhuma alteração; hash acima mantido |

`composer check` executou todas as etapas funcionais, mas encerrou com código 100 na auditoria por bloqueio de rede do sandbox. Apenas a etapa de auditoria foi repetida com acesso de rede autorizado e cache em `.local-qa/composer-cache`, concluindo com código 0. Portanto, não se registra uma execução única de `composer check` com código 0. O reforço final do teste de notificação foi validado pela execução focal acima; não houve mudança no código de produção após a suíte completa.

O teste ignorado continua sendo `SqliteProtectionTest::testLinuxDatabaseAndSidecarsReceiveRestrictivePermissions` (modos POSIX, indisponíveis no Windows). CI Linux/PHP 8.3 não foi disparado. Logs locais ignorados pelo Git: `.local-qa/revisao-check.log` (execução intermediária) e `.local-qa/revisao-check-final.log` (suíte funcional aprovada, erro de rede na auditoria). A auditoria autorizada e o teste focal retornaram seus resultados diretamente no terminal da sessão.

### Pendências de homologação e operação

1. SMTP institucional real: TLS, autenticação, aceitação, comportamento dos timeouts e entrega; definir tratamento operacional da possível duplicação após aceite sem confirmação.
2. Agendador/cron: conta de serviço, fuso, execução diária, retentativas no mesmo dia e monitoramento de ausência/falha. Homologar `flock` no filesystem local Windows/Linux; manter arquivo de lock estável e todos os escritores na versão nova. NFS/SMB e coordenação entre hosts não foram implementados.
3. Migração v14 e arquivos legados: ensaio em cópia institucional, backup consistente de SQLite + PDFs, restore e inventário. Foram usados apenas dados sintéticos.
4. Navegador desktop/celular: testar 1440×900 e 390×844, filtros combinados, rolagem sem estourar a página, colunas extensas, navegação independente, Tab/Shift+Tab/Enter/Esc, foco e saída de fullscreen, conflitos e downloads autenticados. Validar com leitor de tela e navegador móvel suportado.
5. Aceite acadêmico: confirmar a versão TCC_2 e interpretação da exclusão lógica. A comparação com o PDF disponível e testes aprovados não estabelecem conformidade integral.

## Registro histórico da implementação de 16/09/2026

Os resultados e limitações a seguir pertencem à entrega anterior; as evidências da revisão acima atualizam a disponibilidade do PDF e a arquitetura de notificações.

Execução local em 16/09/2026, Windows, PHP 8.4.13 e PHPUnit 12.5.33. Base `61930860554535af1915622b8eb273a155917c72`; branch `Modulo4`. Todos os bancos, PDFs e usuários de teste são sintéticos e temporários. Nenhum e-mail real foi enviado.

Commit da implementação: `7e3c47a` — `feat(modulo4): implementa certidoes e fornecedores com PDFs privados e auditoria`. A documentação de entrega está em commit local subsequente. Nenhum commit foi enviado ao remoto.

## Antes das alterações

O PHP local não carregava `intl` nem `fileinfo`. A primeira execução teve 139 testes, 636 asserções, 31 erros, 16 falhas, 20 avisos e um teste ignorado, principalmente em normalização Unicode e validação MIME já existentes. Essas falhas foram isoladas como configuração de ambiente.

Sem alterar o `php.ini` global, foi usado `PHP_INI_SCAN_DIR=E:\Projetos\GSE\.local-qa` com `extensions.ini` contendo `extension=intl` e `extension=fileinfo`. Com isso, a base passou em **139 testes, 1.034 asserções e um teste ignorado**, antes da implementação. O diretório `.local-qa` é local e ignorado pelo Git.

## Resultado final

| Comando | Resultado executado |
|---|---|
| `composer validate --strict` | Válido |
| `composer audit --locked` | Nenhum aviso de vulnerabilidade encontrado |
| `composer lint` | Sintaxe válida em 120 arquivos PHP |
| `composer analyse` | PHPStan nível 6 sem erros; código novo incluído, sem baseline |
| `composer test` | **171 testes, 1.207 asserções, zero falhas/erros, um ignorado** |
| `composer http-test` | **146 verificações aprovadas** |
| `git diff --check` e `git diff --cached --check` | Sem erros de whitespace |

O teste ignorado é `SqliteProtectionTest::testLinuxDatabaseAndSidecarsReceiveRestrictivePermissions`: verifica modos POSIX no Linux; o Windows usa ACL. O CI configura `fileinfo` explicitamente, além de `intl`, e mantém PHP 8.3/Linux. O CI remoto não foi executado nesta entrega local.

Durante a implementação, os testes identificaram e permitiram corrigir a criação das tabelas de certidões em instalações antigas do Módulo 1 e o fechamento de um cursor antes de `VACUUM INTO` no comando de cópia legada. Resultados intermediários com falhas não foram apresentados como aprovação final.

## Cobertura acrescentada

- `CertidaoStatusTest`: fronteiras inclusivas, vence hoje, vencidas, datas reais, ano zero, ano bissexto, fuso civil e configuração do limite.
- `CertidaoTest`: funcionário, multiplicidade de documentos, renovação vinculada, preservação de arquivos, edição sem substituição, arquivamento/exclusão distintos, estados/contagens/filtros/paginação, opção inativa, Unicode, IDs/datas/limites, origem HTTP, MIME, nomes manipulados, tamanho, diretório público, corrupção, armazenamento indisponível, concorrência com duas conexões e reenvios.
- Auditoria: falha induzida reverte renovação, metadados, arquivamento, exclusão e opções; compensação preserva arquivos anteriores e remove só o novo não referenciado.
- `CertidaoMigrationTest`: v12→v13, dados inválidos preservados, flags/caminhos/IDs/sequências, idempotência, convergência de estrutura, integridade, chaves estrangeiras e rollback de DDL/versão.
- `CertidaoNotificationTest`: destinatários ativos e válidos independentes do opt-in DVA, HTML escapado sem observações, fronteira de aviso, falha parcial e retry, idempotência diária, exclusão de arquivadas/excluídas, ausência de pendências/destinatários e disputa de workers durante o envio falso.
- `CertidaoCliTest`: simulação sem alteração documental, cópia privada com backup SQLite/PDF validado, arquivo original preservado, auditoria, reexecução, inventário e envio real desabilitado em testes.
- `http-certidoes.php`: fluxo real do funcionário com multipart, edição, renovação, arquivo e exclusão; downloads por funcionário/admin, bloqueio do visitante/conta inativa/troca obrigatória, CSRF, GET sem mutação, IDs inválidos/inexistentes, headers e escape HTML.
- Testes de expiração ociosa/absoluta e invalidação de sessão existentes continuam passando. As rotas de certidão passam pelo mesmo front controller; não foi criado mecanismo paralelo de sessão.

Os testes antigos foram mantidos, exceto a substituição justificada do teste do Model legado de certidão: ele validava comportamentos incompatíveis com o novo contrato (datas invertidas, substituição silenciosa de PDF, exclusão física). As expectativas de schema passaram de 12 para 13 e de rotas explícitas de 48 para 63. Nenhuma permissão global dos outros módulos foi reduzida.

## Limitações e aceite restante

1. **PDF acadêmico integral:** não acessível; requisitos aplicados pelas transcrições do prompt. Conferência de páginas/mockups e interpretação de exclusão lógica exigem validação acadêmica.
2. **Visual desktop/celular:** navegador indisponível (`No browser is available`; descoberta retornou lista vazia). CSS responsivo e estrutura foram entregues, mas não há screenshots ou homologação visual. Verificar layout, foco, teclado e rolagem da matriz no ambiente de aceite.
3. **Operação:** SMTP real e Agendador/cron precisam ser configurados, ativados e monitorados para cumprir o envio diário. Os testes usam transporte falso. A janela entre aceite SMTP e COMMIT ainda permite duplicação após queda abrupta.
4. **Armazenamento:** os testes cobrem falhas de domínio/banco/auditoria, corrupção e diretório indisponível; não simulam queda física de energia, disco cheio real nem todas as ACL/junctions possíveis do Windows. A implantação deve revisar ACL, aliases públicos, espaço e locks do filesystem. MIME/assinatura não substituem análise antimalware.
5. **Legado e backup:** nenhum PDF/banco real foi lido, movido ou apagado. Inventário, bloqueio dos diretórios públicos antigos, aplicação da migração documental e restauração completa com dados da instituição cabem à implantação controlada.

Por essas pendências, a entrega distingue código implementado e testes executados de homologação acadêmica/visual e operação em produção.
