# Validação do Módulo 5

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
