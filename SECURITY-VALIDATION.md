# Validação local da proteção v17

Data: 12/10/2026. Base Git: `e73bb7952491b4747cf17c833672aa056795a5f6`.
A revisão é o conjunto de alterações locais sobre essa base, incluindo os arquivos novos; não há novo commit publicado ou execução remota que valide esta revisão.

Identificação adicional: SHA-256 `5be30133f1e2ca150120778ab0f6e3a8f959c2d5a3e2c80e09b23844f7ee1e72` do manifesto dos 87 caminhos modificados/novos, excluindo este relatório. Cálculo: caminhos únicos ordenados; para cada caminho, concatenar seu UTF-8, byte NUL e digest SHA-256 binário do conteúdo; aplicar SHA-256 ao conjunto.

Ambiente: Windows, PHP 8.4.13, Node 24, Composer e Chrome real. As extensões fileinfo, intl e sodium foram habilitadas para estes comandos por `PHP_INI_SCAN_DIR` apontando para um INI temporário. Bancos, contas, documentos e chaves de testes são sintéticos e descartáveis. O banco institucional e seu `.env` não foram migrados.

| Comando | Resultado desta revisão |
|---|---|
| `composer check` | Sucesso (exit 0): Composer estrito, lint de 172 arquivos PHP, PHPStan sem erros; PHPUnit 353 testes, 3.485 asserções, 2 ignorados; HTTP 379 verificações; auditoria travada sem avisos de vulnerabilidades |
| `composer browser-test` | Sucesso: segurança de senhas 36, navegação de contratos 58, operações autenticadas 102 verificações |
| `node --check public/assets/js/app.js` e todos os `tests/*.mjs` | Sucesso |
| `php -l public/index.php` e `php -l tests/http-security-hardening.php` | Sucesso após a última validação de tipos na entrada da rota |
| `composer check-platform-reqs` | Sucesso, incluindo sodium |
| `actionlint -shellcheck= -pyflakes= .github/workflows/ci.yml` | Sucesso, actionlint 1.7.12: sintaxe, estrutura e expressões do Actions; analisadores externos opcionais de shell/Python não instalados |
| `git diff --check` | Sucesso |

O comando `composer check` engloba validação estrita do Composer, lint PHP, PHPStan, PHPUnit, integração HTTP e auditoria das dependências travadas. A mensagem real do navegador foi `Browser password security: 36 checks passed`, seguida das aprovações dos outros dois testes. O carregamento explícito de `password-runtime.php` pelo roteador exclusivo de testes foi preservado; não há simulação de falhas no código público.

Os testes novos verificam persistência criptografada, contexto autenticado, nonces distintos, adulteração, chaves ausentes/incorretas, unicidade normalizada, busca/paginação, migração explícita e rollback, preservação dos limitadores, rotação, reconstrução, restauração e reinício. Também exercitam concorrência em processos separados, replay HTTP sem JavaScript, conteúdo divergente, CSRF malformado, tipos inesperados, HTTP 429, proteção de arquivos estáticos e CORS fechado. As suítes existentes de autorização, uploads, vínculos, sessões, auditoria e PDF foram mantidas.

## Limites de verificação

- Dois testes dependentes de permissões POSIX/links simbólicos são ignorados no Windows por condições já existentes. Devem executar no runner Linux; não se declara aprovação desses casos neste ambiente.
- Os jobs `test` e `browser` continuam configurados, com Composer, sodium, ações fixadas por SHA, permissões mínimas e diagnóstico em falha. A confirmação remota aguarda publicação autorizada. Nenhum resultado antigo de CI é usado como evidência desta revisão.
- Nginx não está instalado localmente. O exemplo `config/nginx-gse.conf` exige adaptação e `nginx -t` no servidor antes da ativação. HTTPS, certificados, ACLs de produção, proxy, cookies e exposição no servidor institucional não foram homologados.
- Criptografia abrange nome/e-mail dos usuários e novos backups. Outros dados pessoais, sessões, exportações e PDFs têm os limites descritos em [SECURITY-OPERATIONS.md](SECURITY-OPERATIONS.md). Não se declara criptografia de todo o SQLite ou do disco.
- Ativação exige keyring privado externo e manutenção com migração explícita. Não houve migração real, push, merge ou deploy.
