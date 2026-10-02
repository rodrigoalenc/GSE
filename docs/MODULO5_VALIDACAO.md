# Validação do Módulo 5

## Revisão de 02/10/2026

Branch `Modulo5`, estado inicial `755d28d2c3a69844e3413ff4ea49eefc6bb4b4c2`, árvore limpa. O head remoto consultado estava nessa mesma revisão. Os testes e demonstrações usam SQLite temporário e dados fictícios, sem migração em banco institucional. Ambiente: Windows, PHP 8.4.13, Composer, Node 24.11 e Chrome headless com perfil descartável. As extensões exigidas (`intl`, `fileinfo`, `mbstring`, `pdo_sqlite` e `curl`, entre outras) estão habilitadas; não foi usado `--ignore-platform-req`.

| Verificação atual | Resultado |
|---|---|
| `composer validate --strict` | Aprovado |
| `composer audit --locked` | Sem avisos de vulnerabilidade; consulta direta ao Packagist concluída |
| `composer lint` | 141 arquivos PHP sem erro de sintaxe |
| `composer analyse` | Zero erros |
| `composer test` | 243 testes, 2.132 asserções, 1 ignorado |
| `composer http-test` | 239 verificações aprovadas |
| `node --check public/assets/js/app.js` | Aprovado |
| `node tests/browser-contract-tabs.mjs` | 45 verificações no Chrome real |
| `git diff --check` | Sem erros de espaços |

O teste ignorado verifica permissões de arquivo POSIX (`SqliteProtectionTest`); não se aplica ao Windows. O CI mantém a execução PHP/HTTP no Linux e acrescenta o teste de navegador em um job próprio. Essa configuração foi validada localmente; a execução remota deste commit depende de publicação posterior, fora desta tarefa.

A falha inicial das 85 rotas foi reproduzida: o teste esperava 84. A expectativa foi corrigida e as verificações foram ampliadas para unicidade por método/caminho, autenticação, autorização administrativa, ação de desarquivamento e IDs válidos/inválidos em todas as rotas parametrizadas. Nenhuma autorização foi relaxada.

### Regressões funcionais exercitadas

- Navegação com três notas: links reais, visibilidade dos painéis, URLs internas diretas, fragmentos inválidos, foco, fechamento, teclado, cliques modificados, voltar/avançar e ciclo de impressão. O mesmo teste falhou com o JavaScript anterior, demonstrando a regressão original.
- Recuperação positiva e zero de estoque antigo: conta administrativa ativa, vínculos, revisão, unidade, contagem confirmada, limites, justificativa, registro atual e auditoria. Reenvio, disputa real entre dois processos e falha da auditoria não geram saldo ou comprovação duplicados.
- Rascunhos de produto, observação, limites, abertura, movimento, estorno e faturamento: retenção após erro e refresh, separação entre recursos e abas, expiração/tamanho/estrutura, escape, sucesso, conflitos e exclusão antes ou depois da submissão. Tokens não integram o preenchimento.
- Faturamento: checkbox marcado exige data; a tentativa desmarcada permanece desmarcada. Primeiro registro operacional é permitido ao funcionário. Correção ou remoção do faturamento anterior exige administrador e justificativa.
- Administrador, funcionário e visitante; contas inativas; CSRF/métodos; IDs inexistentes ou de outro contrato; contratos excluídos; PDF/CSV, filtros e DVA corrente; certidões correntes, arquivadas, excluídas, edição, renovação, arquivamento/desarquivamento e downloads privados.
- Prévia local de PDF: seleção carrega o documento no quadro; limpar revoga a URL temporária, oculta quadro/link, esvazia a seleção e devolve o foco. A CSP permite `blob:` somente em `frame-src`; execução de scripts/estilos continua restrita à própria origem, com `object-src 'none'`, `frame-ancestors 'none'` e `X-Frame-Options: DENY`.

### Navegador e impressão

As telas finais foram exercitadas com dados fictícios em 1366×768, 1920×1080 e 390×844, escala 1. O navegador integrado não apresentou instância disponível; o ensaio usou Chrome headless instalado, com perfil temporário próprio. Layout, formulário aberto, nota selecionada, erro e estados vazios foram capturados. As evidências versionadas mostram somente o GSE e têm legendas próprias em [REVISAO_VISUAL.md](REVISAO_VISUAL.md).

Na aplicação autenticada, o fluxo de contratos passou em 17 verificações no desktop e nas mesmas 17 em 390×844: seleção de nota, abertura/fechamento, formulário recusado, preenchimento escapado, recuperação de estoque e retorno da impressão. Certidões passaram em 58 verificações nas três resoluções; a prévia PDF foi retestada após a correção da CSP, sem violação da política.

O ensaio de contratos inclui as três notas no mesmo documento, nota individual e um pedido com 80 itens mais uma segunda nota. Os PDFs de 80 itens, completos e individuais, têm cinco páginas; todos os 80 nomes aparecem uma vez, as linhas permanecem inteiras e os cabeçalhos das tabelas reaparecem nas continuações. As páginas foram renderizadas e inspecionadas. Os totais da nota individual consideram somente aquela nota; o link de retorno preserva seu ID.

### Procedimento de recuperação do estoque anterior

1. Um administrador ativo abre o pedido e expande o item identificado como **zero anterior sem comprovação**.
2. Confere a documentação e realiza a contagem física atual. Em **Conferir abertura antiga sem comprovação**, informa quantidade (zero ou positiva), unidade já cadastrada, mínimo/máximo e justificativa documental de 10 a 180 caracteres; confirma a contagem.
3. O servidor valida os vínculos e a revisão atual, ausência de movimentos e de abertura comprovada, saldo/limites e chave única. Contagem positiva gera abertura identificada como conferência atual; zero gera a comprovação sem movimento fictício. Auditoria registra autoria, horário, quantidade, unidade, limites e motivo.
4. A transação confirma operação, revisão, auditoria e eventual movimento juntos. Reenvio, conflito ou falha não produzem efeito parcial. Nenhum estoque é redefinido em massa e nenhum movimento histórico é alterado.

Esta revisão usa o esquema v15 existente e não acrescenta migração. Instalação nova e atualização continuam cobertas pelos testes do inicializador.

### Arquivos desta revisão

| Grupo | Arquivos alterados ou adicionados |
|---|---|
| CI e comandos | `.github/workflows/ci.yml`, `composer.json` |
| Navegação, apresentação e assets | `public/assets/js/app.js`; CSS de login, painel/dashboard, usuários, alunos, passivo, certidões, contratos, relatórios e impressão; fonte e licença em `public/assets/vendor/fontawesome/` |
| Fluxos e segurança | `src/Controllers/ContratoController.php`, `CertidaoController.php`, `PassivoController.php`; `src/Model/Contrato.php`; `src/Core/ContractFormDraft.php` e `SecurityHeaders.php` |
| Telas | Views de login/layout, usuários, alunos/perfil, passivo, certidões, contratos e relatórios |
| Regressões | `tests/Security/CoreSecurityTest.php`, `tests/Integration/LegacyStockRecoveryTest.php`, `tests/Unit/ContractFormDraftTest.php`, `tests/browser-contract-tabs.mjs`, `tests/fixtures/stock-recovery-race.php`, `tests/http-contract-drafts.php`, `tests/http-certidoes.php` e `tests/http-smoke.php` |
| Entrega e evidências | README; documentos de módulos 3, 4 e 5 revisados; `docs/PRODUCTION_CHECKLIST.md`, `docs/REVISAO_VISUAL.md`; 17 PNGs e manifesto com dimensões/SHA-256 em `docs/evidencias/2026-10-02/` |

As capturas usam dados fictícios. Bases, configurações, perfis do navegador e artefatos auxiliares de QA não integram o commit. A relação exata de caminhos pode ser consultada em `git show --stat` no commit desta revisão.

### Pendências concretas

- O arquivo **TCC_2_ETAPA_1_MÓDULO_1 - Rodrigo-Calebe.pdf** não foi localizado. RF007/RF008, UC006/UC007, tabelas/figuras/páginas indicadas e requisitos dos módulos anteriores afetados aguardam leitura literal. A matriz é provisória e não declara conformidade acadêmica integral.
- A homologação institucional, o teste de intranet com o acervo esperado e a restauração conjunta SQLite/PDFs precisam ocorrer no ambiente candidato. Nenhum banco institucional foi usado neste ensaio.
- PDF conserva o teto de 500 alunos; CSV, 10.000. O CSV continua montado em memória; medir memória/tempo com o maior acervo esperado antes da implantação. Os tetos não foram removidos.
- O detalhamento visual e os ajustes técnicos adicionais são descritos em `REVISAO_VISUAL.md`. A leitura do PDF e o aceite institucional permanecem necessários.

## Histórico de 26/09/2026

Resultados preservados da revisão a partir de `7fb9cd4a5df2b7a18f3a3e5981aebb9e345a6a64`. Eles não aprovam o código atual.

| Verificação naquela data | Resultado registrado |
|---|---|
| `composer validate --strict` | Aprovado |
| `composer audit --locked` | Inconclusivo: falha de rede (curl error 7) |
| Lint / análise estática | 136 arquivos PHP; zero erros de análise |
| PHPUnit | 200 testes, 1.468 asserções, 1 ignorado |
| Integração HTTP | 175 verificações |
| JavaScript / `git diff --check` | Sem erros |

Naquela execução, faltavam `intl` e `fileinfo` no PHP padrão; os resultados aprovados foram obtidos após habilitá-las por configuração temporária. O primeiro lint identificou erro de sintaxe que foi corrigido. O teste isolado `ModuloCincoTest` registrou 15 testes e 70 asserções. Navegação visual, leitura do PDF correto e auditoria de dependências permaneciam pendentes naquela data. A recuperação antiga aceitava somente comprovação de zero; a contagem positiva foi acrescentada na revisão atual.

---

## Histórico anterior a 26/09/2026

Ambiente de teste: PHP 8.4.13, SQLite, Windows. As extensões `intl` e `fileinfo` existem no computador, mas não estavam habilitadas no `php.ini` padrão; foram ativadas somente para os processos de validação. A execução inicial, antes dessa correção local, falhou por falta de `Normalizer` e MIME. Isso não representa regressão de código.

| Verificação | Resultado |
|---|---|
| Composer `validate --strict` | Válido |
| Composer `audit --locked` com acesso à rede | Sem avisos de vulnerabilidade |
| Lint PHP | 135 arquivos sem erro de sintaxe na execução registrada |
| PHPStan nível 6 incluindo novos Models | Sem erros na execução registrada |
| PHPUnit completo | 192 testes, 1.434 verificações, 1 ignorado no gate final |
| `tests/Integration/ModuloCincoTest.php` | 7 testes, 41 verificações; contratos, estoque, duas saídas em processos separados, duplicação, idempotência, estorno, conciliação, auditoria e DVA corrente |
| Integração HTTP `tests/http-smoke.php` | 166 verificações; inclui visitante, contrato, PDF e CSV do Módulo 5 |
| `node --check public/assets/js/app.js` e `git diff --check` | Sem erros |
| PDF de 80 alunos | Três páginas; [primeira](evidencias/modulo5-relatorio-pagina-1.png) e [última](evidencias/modulo5-relatorio-pagina-3.png) renderizadas e inspecionadas, sem corte visível |

### Pendências registradas naquela execução

- O PDF acadêmico TCC_2 citado no prompt não estava disponível. A matriz usa referências transcritas e precisa de conferência literal posterior.
- O navegador integrado retornou lista vazia de navegadores disponíveis. Não houve screenshots de desktop/celular nem homologação visual das telas; o PDF foi inspecionado por renderização independente.
- A disputa de duas saídas em processos separados passou no SQLite temporário: somente uma confirmou quando havia saldo para uma. Repetir a prova no ambiente candidato antes da homologação.
- Nenhuma migração foi aplicada a dados institucionais. Testes usaram SQLite temporário e dados sintéticos. Divergências em valores/quantidades legados exigem conferência documental e conciliação administrativa.
- O CSV total é limitado a 10.000 linhas e atualmente é montado em memória antes da transmissão. Validar consumo de memória com o maior acervo esperado; para volumes maiores, implementar consulta em lotes.
- Relatórios financeiros específicos de contratos e impressão filtrada de movimentos não substituem o UC007; histórico pode ser impresso pelo navegador. Revisar necessidade institucional adicional antes de ampliar o escopo.

### Comandos de repetição

```powershell
php -d extension=php_intl.dll -d extension=php_fileinfo.dll C:\composer\composer.phar validate --strict
php -d extension=php_intl.dll -d extension=php_fileinfo.dll bin/lint.php
php -d extension=php_intl.dll -d extension=php_fileinfo.dll vendor/phpstan/phpstan/phpstan.phar analyse --memory-limit=1G --no-progress
php -d extension=php_intl.dll -d extension=php_fileinfo.dll vendor/bin/phpunit --no-progress
php -d extension=php_intl.dll -d extension=php_fileinfo.dll tests/http-smoke.php
node --check public/assets/js/app.js
git diff --check
```

Para testar `composer audit --locked`, habilite as extensões no processo Composer e acesso ao Packagist. Os testes que lançam subprocessos PHP também exigem essas extensões no `php.ini` ou em um diretório `PHP_INI_SCAN_DIR` temporário.
