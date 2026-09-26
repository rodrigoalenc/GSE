# Validação do Módulo 5

## Revisão atual da branch Modulo5 — 26/09/2026

Estado inicial: `7fb9cd4a5df2b7a18f3a3e5981aebb9e345a6a64`, árvore limpa. Testes locais usaram SQLite temporário e dados fictícios. Nenhum banco de produção foi acessado. As extensões `intl` e `fileinfo` foram habilitadas somente nos processos de teste por um `PHP_INI_SCAN_DIR` temporário ignorado pelo Git.

| Verificação executada nesta revisão | Resultado real |
|---|---|
| `composer validate --strict` | Aprovado: `composer.json` válido |
| `composer audit --locked` | Inconclusivo: Packagist inacessível pela restrição de rede (curl error 7) |
| `composer lint` | Aprovado: 136 arquivos PHP |
| `composer analyse` | Aprovado: zero erros |
| `composer test` | Aprovado: 200 testes, 1.468 asserções, 1 ignorado |
| `composer http-test` | Aprovado: 175 verificações |
| `node --check public/assets/js/app.js` | Aprovado |
| `git diff --check` | Aprovado; avisos locais de conversão LF/CRLF sem erro de espaços |

O primeiro `composer lint` encontrou erro de sintaxe na nova lista de contratos e foi corrigido. O primeiro `composer test` usou o PHP padrão sem `intl`/`fileinfo` e falhou por dependências do ambiente; a execução acima é posterior e completa. Os testes novos do Módulo 5 cobrem limites sem abertura, abertura zero, restrição do legado, bloqueio de reenvio e de duas aberturas concorrentes, recuperação auditada de zero ambíguo, unidade após saldo zero, lista de excluídos e indicadores, recusa de IDs cruzados e rollback do cadastro completo. O teste isolado `ModuloCincoTest` passou com 15 testes e 70 asserções antes da execução completa.

### Evidências e pendências de homologação

- O PDF acadêmico solicitado não estava no anexo nem no repositório; RF007/RF008, UC006/UC007, tabela 11, figuras 19/20/22 e requisitos não funcionais não foram conferidos literalmente. `Documentação/Documentação GSE.pdf` é outra edição e não foi usado como substituto.
- A árvore do original foi localizada no GitHub, mas as views e os CSS não puderam ser abertos pelo acesso web e `git ls-remote` falhou pela restrição de rede. Logo, não há comparação visual fiel com o commit original nesta revisão.
- O navegador integrado retornou `[]` na descoberta de navegadores. Não houve screenshots comparáveis de desktop/celular nem homologação visual interativa, inclusive impressão e uso por teclado. A aprovação de testes HTTP não substitui essa etapa.
- `composer audit --locked` precisa ser repetido quando Packagist estiver acessível. A revisão de dependências ainda não foi validada nesta execução.
- O cadastro e o relatório têm testes de transação/filtro, mas ainda falta ensaio visual lado a lado com o original e o TCC usando dados fictícios equivalentes. Os limites de PDF (500) e CSV (10.000) foram mantidos; o CSV total ainda é montado em memória, então acervos próximos do teto precisam de medição de memória/tempo antes da implantação.

### Procedimento para estoque zero anterior ambíguo

Na tela do contrato, filtre o item marcado **Zero anterior: conferir**. Um administrador deve conferir documentos de entrada/saída e fazer contagem física. Se o resultado comprovado for zero, use **Conferir abertura zero anterior**, informe a base da conferência e confirme. A operação registra usuário, horário e motivo na auditoria e libera movimentos futuros; o saldo não é alterado. Se houver saldo real, não confirme zero: suspenda movimentos e reconcilie o caso documentalmente com suporte técnico antes de registrar nova abertura. Reenvio ou revisão antiga são recusados. Essa medida não reclassifica automaticamente todos os registros sem movimentos.

---

## Histórico da validação anterior

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

### Pendências concretas

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
