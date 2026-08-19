# GSE — Módulo 1: Autenticação e Controle de Usuários

Entrega funcional e endurecida do Módulo 1 do Gestor de Secretaria Escolar:

- UC002 — controlar usuários;
- UC003 — realizar login.

O sistema usa PHP 8.3+, SQLite e MVC sem framework. Gestão de alunos, DVA, certidões, contratos, estoque e demais módulos acadêmicos não fazem parte desta entrega. Models e tabelas antigos desses domínios foram preservados para trabalho futuro, mas não possuem rotas nem telas publicadas.

## Arquitetura de segurança

O front controller `public/index.php` valida o ambiente, host, HTTPS e proxy, inicializa logs, sessão e migrações e entrega a requisição a uma tabela explícita de rotas. Novos métodos públicos de Controller não ficam acessíveis automaticamente.

Componentes principais:

- `Config` valida ambiente e parâmetros com comportamento de produção como fallback;
- `RequestContext` obtém host, HTTPS e IP sem confiar em headers de proxy por padrão;
- `SessionManager` aplica timeout ocioso/absoluto e renovação do identificador;
- `LoginThrottle` limita falhas por conta normalizada e IP;
- `PasswordPolicy` valida frases-senha Unicode e usa Argon2id quando disponível;
- `AuditLogger` registra eventos de segurança em tabela separada do log técnico;
- `DatabaseInitializer` aplica migrações versionadas, idempotentes e com backup preventivo;
- trigger SQLite e transação `BEGIN IMMEDIATE`, executada por um helper com rollback explícito, protegem o último administrador ativo;
- `SecurityHeaders` aplica CSP sem `unsafe-inline` e HSTS somente sob HTTPS reconhecido.

As decisões seguem as recomendações de [Authentication](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html), [Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html) e [Logging](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html) da OWASP, além das APIs nativas de senha do [manual do PHP](https://www.php.net/manual/en/book.password.php).

## Requisitos

- PHP 8.3 ou superior;
- Composer 2;
- extensões `pdo`, `pdo_sqlite`, `mbstring`, `session`, `filter` e `hash`;
- extensão `curl` apenas para o teste HTTP automatizado.

```bash
php -v
php -m
composer --version
```

## Desenvolvimento local

```bash
git clone https://github.com/rodrigoalenc/GSE.git
cd GSE
composer install
```

No Windows:

```powershell
Copy-Item .env.example .env
php bin/init-db.php
php bin/create-admin.php --name="Administrador GSE" --email="admin@example.test"
php -S 127.0.0.1:8000 -t public public/index.php
```

No Linux/macOS:

```bash
cp .env.example .env
php bin/init-db.php
php bin/create-admin.php --name="Administrador GSE" --email="admin@example.test"
php -S 127.0.0.1:8000 -t public public/index.php
```

O servidor embutido é somente para desenvolvimento/demonstração. `.env.example` facilita o ambiente local e **não deve ser usado em produção sem revisão integral**.

O PowerShell opcional valida e inicia o ambiente:

```powershell
.\bin\start-server.ps1
.\bin\start-server.ps1 -Lan
```

## Primeiro administrador e senha temporária

O primeiro administrador é criado somente por CLI:

```bash
php bin/create-admin.php --name="Administrador GSE" --email="admin@example.test"
```

O comando gera uma senha temporária aleatória, mostra-a uma única vez e marca a conta para troca obrigatória. Até concluir a troca, somente `/senha/alterar` e logout ficam disponíveis. A troca pede senha atual, nova senha e confirmação; depois atualiza `password_changed_at`, incrementa `session_version`, encerra a sessão anterior e exige novo login.

Para automação controlada, `GSE_ADMIN_PASSWORD` pode ser fornecida apenas ao processo CLI. Não coloque senha em argumentos, `.env`, scripts versionados ou logs. A senha fornecida continua temporária.

Senhas definidas ou redefinidas por administrador também são temporárias e nunca são exibidas novamente pela aplicação.

## Política de senhas

- 12 a 128 caracteres Unicode;
- espaços e frases-senha são permitidos;
- não há exigência artificial de símbolo, maiúscula ou dígito;
- senhas vazias, comuns ou muito semelhantes ao nome/e-mail são recusadas;
- senhas nunca são truncadas;
- hash preferencial Argon2id, com fallback para `PASSWORD_DEFAULT`;
- login usa `password_verify()` e rehash transparente com `password_needs_rehash()`.

O login apresenta sempre a mesma mensagem para conta inexistente, inativa, senha errada ou bloqueio interno.

## Rate limiting

Falhas são controladas separadamente por hash da conta normalizada e por hash do IP. O padrão bloqueia a conta após 5 falhas e o IP após 40 falhas dentro de 15 minutos, com bloqueio temporário de 15 minutos. O atraso exponencial, limitado a 2 segundos, acompanha o histórico da conta para não penalizar coletivamente redes escolares sob NAT.

Um login válido limpa somente o estado daquela conta. O histórico compartilhado do IP é preservado para que uma conta válida não permita contornar a proteção de rede. Os limites independentes mantêm proteção contra ataques distribuídos à mesma conta e evitam que cinco erros de uma pessoa bloqueiem toda a escola.

Trocar maiúsculas/minúsculas no e-mail não contorna o limite. `X-Forwarded-For` só é usado quando `REMOTE_ADDR` pertence a `TRUSTED_PROXIES`.

## Sessões

Padrões:

- inatividade: 30 minutos;
- duração absoluta: 8 horas;
- renovação do identificador: 15 minutos;
- cookie de sessão: `HttpOnly`, `SameSite=Lax` e `Secure` sob HTTPS;
- regeneração no login e em mudanças relevantes;
- encerramento completo no logout/expiração;
- invalidação por `session_version` após senha, perfil ou situação da conta mudar.

Uma senha alterada pelo próprio usuário encerra a sessão e exige novo login.

## Auditoria de segurança

Administradores acessam `/auditoria`, com paginação e filtros por ação, resultado e período. A interface é somente leitura. São registrados login válido/inválido/bloqueado, logout, expiração/invalidação de sessão, acesso administrativo negado, criação/alteração de conta, perfil, senha, ativação/inativação e bloqueios ligados ao próprio/último administrador.

Cada evento registra horário UTC, ação, resultado, IDs aplicáveis, IP seguro, ID aleatório de requisição e descrição objetiva. Senhas, hashes, cookies, sessão completa e CSRF não são gravados.

`AUDIT_RETENTION_DAYS` usa 365 dias por padrão e nunca aceita menos de 90. A escola deve aprovar a retenção conforme obrigações legais e administrativas. Para proteção adicional, exporte eventos para armazenamento externo imutável/SIEM.

## Manutenção periódica

Limpezas de tentativas antigas e auditoria não são executadas durante requisições HTTP. Isso evita transformar leituras comuns em escritas SQLite e reduz contenção. Execute o comando idempotente diariamente, de preferência fora do horário de maior uso:

```bash
composer maintenance
# equivalente: php bin/maintenance.php
```

O comando usa uma transação imediata única, preserva registros recentes, informa somente quantidades removidas e retorna código diferente de zero em caso de falha. Exemplo de `cron` Linux, executado pelo mesmo usuário restrito da aplicação:

```cron
17 2 * * * cd /var/www/gse && /usr/bin/php bin/maintenance.php >> /var/log/gse/maintenance.log 2>&1
```

Proteja também o arquivo de log do cron e ajuste `/var/www/gse` ao caminho real. `LOGIN_RETENTION_DAYS` controla tentativas antigas (padrão: 7 dias) e `AUDIT_RETENTION_DAYS` controla a auditoria.

## Ambientes e variáveis

`APP_ENV` aceita somente `development`, `testing` ou `production`. Ausência/valor desconhecido assume produção.

| Variável | Padrão/uso |
|---|---|
| `APP_ENV` | fallback `production` |
| `APP_URL` | obrigatório e absoluto em produção |
| `APP_ALLOWED_HOSTS` | hosts adicionais separados por vírgula |
| `DB_PATH` | arquivo SQLite fora de `public/` |
| `LOG_PATH` | log técnico fora de `public/` |
| `FORCE_HTTPS` | padrão seguro `true` em produção |
| `TRUSTED_PROXIES` | IPs/CIDRs explícitos; vazio confia em nenhum proxy |
| `SESSION_IDLE_TIMEOUT` | `1800` segundos |
| `SESSION_ABSOLUTE_TIMEOUT` | `28800` segundos |
| `SESSION_RENEWAL_INTERVAL` | `900` segundos |
| `LOGIN_ACCOUNT_MAX_FAILURES` | `5`; limite restritivo por conta normalizada |
| `LOGIN_IP_MAX_FAILURES` | `40`; limite mais alto para redes compartilhadas |
| `LOGIN_THROTTLE_WINDOW_SECONDS` | `900` |
| `LOGIN_BLOCK_SECONDS` | `900` |
| `LOGIN_DELAY_BASE_MS` / `LOGIN_DELAY_MAX_MS` | `200` / `2000` |
| `LOGIN_RETENTION_DAYS` | `7` |
| `AUDIT_RETENTION_DAYS` | `365` (mínimo 90) |
| `DB_DIRECTORY_MODE` | Linux: `0700` ou `0750` |
| `DB_FILE_MODE` | Linux: `0600` ou `0640` |

Use `.env.production.example` como modelo sem credenciais.

Para instalações antigas, `LOGIN_MAX_FAILURES` continua aceito como fallback somente do limite por conta e `LOGIN_WINDOW_SECONDS` como fallback da janela. O limite por IP nunca herda o valor baixo antigo: se `LOGIN_IP_MAX_FAILURES` estiver ausente ou inválido, usa 40. Migre para os nomes novos ao revisar a configuração.

## Produção, HTTPS e proxy reverso

Em produção, `APP_URL` inválida interrompe a inicialização; host não permitido retorna 400; exceções geram página 500 genérica com ID da requisição. Detalhes ficam em `LOG_PATH`, fora de `public/`.

### Acesso direto

Deixe `TRUSTED_PROXIES=` vazio. Termine TLS no próprio servidor web, use `APP_URL=https://host-oficial`, `APP_ALLOWED_HOSTS=host-oficial` e `FORCE_HTTPS=true`.

### Servidor local sem TLS

Use apenas em `development`, com `FORCE_HTTPS=false` e `APP_URL` vazio. Não exponha o servidor embutido à internet.

### Proxy reverso

1. Termine TLS no proxy.
2. Remova headers encaminhados recebidos do cliente e defina novos valores.
3. Envie `X-Forwarded-Proto: https` e acrescente `X-Forwarded-For` corretamente.
4. Configure em `TRUSTED_PROXIES` somente o IP/CIDR do proxy que se conecta ao PHP.
5. Mantenha `APP_URL` fixa em HTTPS e teste ausência de loop.

Headers `Forwarded`, `X-Forwarded-Proto` e `X-Forwarded-For` são ignorados quando a conexão não vem de proxy confiável.

### Cloudflare Tunnel

Para `cloudflared` na mesma máquina, publique o serviço local apenas em loopback e use, por exemplo, `TRUSTED_PROXIES=127.0.0.1,::1`. Configure o hostname público em `APP_URL`/`APP_ALLOWED_HOSTS`. Se `cloudflared` estiver em outro host/container, use somente o IP/CIDR interno específico. Não adicione faixas amplas da internet e não confie diretamente em headers fornecidos pelo cliente.

Quando HTTPS é reconhecido com segurança, o sistema ativa cookie `Secure`, HSTS e URLs HTTPS. O redirecionamento usa `APP_URL`, nunca um `Host` arbitrário.

## SQLite, migrações e backups

O banco permanece fora de `public/`; em produção essa regra é validada e uma configuração insegura é recusada. No Linux, diretório e arquivos SQLite/`-wal`/`-shm` recebem permissões restritivas. No Windows, o código não tenta aplicar modos POSIX; use ACLs NTFS para o usuário do serviço.

`schema_migrations` controla versões. `php bin/init-db.php` pode ser repetido: cria esquema limpo ou atualiza banco legado sem apagar tabelas/Models futuros. A migração adiciona `ativo`, `atualizado_em`, `session_version`, `deve_alterar_senha`, `password_changed_at`, auditoria e tentativas. Um índice `COLLATE NOCASE` garante e-mail único no banco.

Antes de alteração estrutural em banco existente, é criado um backup SQLite consistente em `backups/`, validado com `PRAGMA integrity_check`. O banco original nunca é substituído ou apagado. Se e-mails legados conflitarem apenas por caixa, a migração para com erro e preserva os dados para correção manual sobre uma cópia.

Mantenha backups fora do servidor, criptografados e com restauração testada. Backups locais, bancos e sidecars estão no `.gitignore`.

## Rotas do Módulo 1

| Método | Rota | Acesso |
|---|---|---|
| GET | `/login` | público |
| POST | `/login/entrar` | público + CSRF |
| POST | `/login/sair` | autenticado + CSRF; permitido na troca obrigatória |
| GET | `/dashboard` | autenticado após troca obrigatória |
| GET/POST | `/senha/alterar` | autenticado; permitido na troca obrigatória |
| GET | `/usuario` | administrador |
| GET/POST | `/usuario/criar` | administrador |
| GET/POST | `/usuario/editar/{id}` | administrador |
| POST | `/usuario/status/{id}` | administrador + CSRF |
| GET | `/auditoria` | administrador, somente leitura |

Rota desconhecida retorna 404, método incorreto 405, funcionário autenticado recebe 403 e visitante é redirecionado ao login.

## Testes e comandos Composer

```bash
composer validate-project
composer lint
composer test
composer http-test
composer maintenance
composer security-check
composer check
```

PHPUnit usa bancos temporários e cobre autenticação, bloqueio/expiração, sessões, senha temporária, CSRF, autorização, usuários, último administrador, auditoria, headers, host/proxy/HTTPS, migração e SQLite. `tests/http-smoke.php` inicia servidores temporários e testa o fluxo HTTP real em Windows/Linux. No PowerShell, `tests/manual-http.ps1` é um wrapper equivalente.

`.github/workflows/ci.yml` usa PHP 8.3 e `actions/checkout@v5`, e roda instalação limpa, validação estrita, auditoria, lint, PHPUnit e HTTP em pushes e pull requests. Qualquer etapa com falha interrompe o job. Nenhum baseline de análise estática foi criado; uma ferramenta estática poderá ser adotada em etapa própria, com correção real dos achados.

## Roteiro de demonstração

1. Em diretório limpo, copie `.env.example` para `.env` e execute `composer install`.
2. Execute `php bin/init-db.php` duas vezes para demonstrar idempotência.
3. Crie o primeiro administrador e anote a senha temporária mostrada uma vez.
4. Acesse `/dashboard` sem sessão e observe o redirecionamento.
5. Entre e demonstre que somente troca de senha/logout estão disponíveis.
6. Troque a senha e faça novo login.
7. Mostre painel neutro, gestão de usuários e auditoria.
8. Cadastre funcionário, demonstre e-mail `NOCASE`, senha temporária e 403 administrativo.
9. Redefina senha/perfil/situação e demonstre invalidação da sessão alvo.
10. Demonstre bloqueio da autoinativação e do último administrador.
11. Execute `composer check`.

## Limitações conhecidas e fora do escopo

- não há MFA nem recuperação de senha por e-mail nesta etapa;
- auditoria local não substitui SIEM/armazenamento imutável;
- disponibilidade distribuída exigiria rate limiting e sessões em armazenamento compartilhado;
- identidade visual institucional definitiva ainda depende da escola;
- alunos, turmas, DVA, arquivo passivo, certidões, fornecedores, contratos, estoque, relatórios e notificações não têm telas/rotas funcionais nesta entrega.

O dashboard foi deliberadamente alterado para exibir somente informações do Módulo 1. Indicadores de alunos e DVA antecipavam entregas posteriores; seus Models e tabelas permanecem intactos.

Antes de implantar, conclua [docs/PRODUCTION_CHECKLIST.md](docs/PRODUCTION_CHECKLIST.md) e leia [SECURITY.md](SECURITY.md).
