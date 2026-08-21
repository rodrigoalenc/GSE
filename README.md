# GSE — Módulos 1 e 2

Entrega funcional e endurecida do Gestor de Secretaria Escolar:

- UC002 — controlar usuários;
- UC003 — realizar login.
- UC001 — cadastrar, consultar, editar e inativar alunos, turmas e DVAs;
- RF006 — alertas consolidados de DVA por e-mail, quando habilitados.

O sistema usa PHP 8.3+, SQLite e MVC sem framework. Os Módulos 1 (autenticação e usuários) e 2 (alunos, turmas e DVA) estão publicados. Arquivo passivo, certidões, fornecedores, contratos, estoque, pedidos e relatórios gerais permanecem fora do escopo; seus Models e tabelas preservados não possuem rotas novas nesta entrega.

## Arquitetura de segurança

O front controller `public/index.php` valida o ambiente, host, HTTPS e proxy, inicializa logs, sessão e migrações e entrega a requisição a uma tabela explícita de rotas. Novos métodos públicos de Controller não ficam acessíveis automaticamente.

Componentes principais:

- `Config` valida ambiente e parâmetros com comportamento de produção como fallback;
- `RequestContext` obtém host, HTTPS e IP sem confiar em headers de proxy por padrão;
- `SessionManager` aplica timeout ocioso/absoluto e renovação do identificador;
- `LoginThrottle` limita falhas por conta normalizada e IP;
- `PasswordPolicy` valida frases-senha Unicode e usa Argon2id quando disponível;
- `TextNormalizer` gera nomes de exibição em NFC e chaves de comparação Unicode reutilizadas por alunos, turmas e migrações;
- `AuditLogger` registra eventos de segurança em tabela separada do log técnico;
- `DatabaseInitializer` aplica migrações versionadas, idempotentes e com backup preventivo;
- `Aluno`, `Turma` e `Dva` concentram persistência tipada sem acessar dados HTTP ou sessão;
- `DvaStatus` centraliza datas, filtros e o semáforo usado em telas, dashboard e notificações;
- `DvaNotificationService` usa transporte injetável, entrega consolidada e idempotência diária;
- trigger SQLite e transação `BEGIN IMMEDIATE`, executada por um helper com rollback explícito, protegem o último administrador ativo;
- `SecurityHeaders` aplica CSP sem `unsafe-inline` e HSTS somente sob HTTPS reconhecido.

## Identidade visual e acessibilidade

A interface recupera a identidade azul e o logo institucional da E.E. São José a partir do commit `f0bb641b2d1a074bddd598e52f3e733872d230db` do ProjetoGSE original, usado somente como referência visual. Login, dashboard, usuários, senha, auditoria, alunos, DVAs, turmas e erros compartilham a mesma paleta e hierarquia. O backend, as rotas e as proteções atuais não foram substituídos pelo código legado. A sidebar ocupa cerca de 78 px no desktop, expande para 260 px por `hover` ou `focus-within`, permanece utilizável por teclado e se adapta no mobile sem depender de hover. Logo e favicon usam assets locais; CSP continua sem `unsafe-inline`.

As decisões seguem as recomendações de [Authentication](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html), [Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html) e [Logging](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html) da OWASP, além das APIs nativas de senha do [manual do PHP](https://www.php.net/manual/en/book.password.php).

## Requisitos

- PHP 8.3 ou superior;
- Composer 2;
- extensões `pdo`, `pdo_sqlite`, `intl`, `mbstring`, `session`, `filter` e `hash`;
- extensão `curl` apenas para o teste HTTP automatizado.

```bash
php -v
php -m
composer --version
```

`ext-intl` é obrigatória para normalização NFC consistente. Em Windows, habilite `extension=intl` no `php.ini`; em Linux, instale o pacote `php8.3-intl` ou o equivalente da distribuição antes do Composer.

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

Alertas de DVA são opt-in. O primeiro administrador inicia sem alertas; para uma escolha explícita já na criação, acrescente `--enable-dva-alerts`. A preferência também pode ser habilitada depois pela tela administrativa.

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

## Gestão de alunos e turmas

Funcionários e administradores autenticados podem listar, pesquisar, filtrar, cadastrar, editar e consultar o perfil de alunos. Somente administradores podem inativar/reativar alunos e administrar turmas. Não existe rota de exclusão física: a situação do cadastro muda, enquanto os dados e o histórico permanecem preservados.

Nome, nascimento, turma e telefones são validados no servidor. Telefones opcionais são normalizados para 10 ou 11 dígitos e só geram links independentes de WhatsApp após essa validação. A busca escapa `%`, `_` e `\`. `TextNormalizer` remove espaços externos, reduz sequências internas, converte para NFC e cria a chave minúscula com `mb_strtolower`, sem remover acentos nem modificar a capitalização do nome exibido. Um nome normalizado e nascimento coincidentes produzem um alerta de possível duplicidade tanto no cadastro quanto na edição e exigem confirmação explícita; consulta e gravação ocorrem na mesma transação `BEGIN IMMEDIATE`. Não há unicidade absoluta porque pessoas diferentes podem compartilhar esses dados.

Turmas possuem nome, ano letivo e situação. A combinação `nome_normalizado + ano_letivo` é única, inclusive para diferenças de caixa acentuada ou representações Unicode canonicamente equivalentes. O nome original em NFC continua sendo exibido. Uma turma com alunos ativos não pode ser inativada até que esses alunos sejam remanejados ou inativados. Turmas legadas ficam com `ano_letivo` nulo para preenchimento administrativo: a migração não inventa datas históricas.

## DVA, histórico e semáforo

Uma DVA inicial é opcional. Renovar não altera o documento anterior: em uma transação `BEGIN IMMEDIATE`, a DVA vigente é arquivada e uma nova versão é inserida. Um índice único parcial garante no banco no máximo uma DVA ativa por aluno. Registros históricos não são excluídos, inclusive após a inativação do aluno.

`DvaStatus` usa datas civis no fuso `APP_TIMEZONE` e classifica:

- `sem_dva`: nenhum registro vigente;
- `vencida`: vencimento anterior a hoje;
- `vence_hoje`: vencimento igual a hoje;
- `a_vencer`: de amanhã até `DVA_WARNING_DAYS` (30 por padrão), inclusive;
- `vigente`: após esse limite.

Datas vencidas podem ser registradas para correção histórica e são sinalizadas imediatamente. Timestamps de criação, substituição e auditoria são UTC.

## Notificações opcionais de DVA

O comando `php bin/notify-dva.php` funciona somente em CLI, consolida DVAs vencidas ou dentro de `DVA_EMAIL_WARNING_DAYS` e envia somente a administradores ativos que habilitaram a preferência. Migrações e criação por CLI mantêm essa opção em `0`; funcionários, contas rebaixadas e contas inativas não são destinatários. O e-mail não inclui telefone, nascimento nem observação integral. A execução do mesmo dia não duplica uma entrega já concluída; falhas parciais ficam aptas a nova tentativa. Testes usam transporte falso e nunca enviam e-mail real.

O envio usa PHPMailer por SMTP. `tls` é mapeado para `PHPMailer::ENCRYPTION_STARTTLS`, `smtps` para `PHPMailer::ENCRYPTION_SMTPS` e `none` desativa também `SMTPAutoTLS`. Produção exige `tls` ou `smtps` e rejeita combinações incoerentes com as portas padrão; a validação de certificados não é relaxada. Com `MAIL_ENABLED=false`, o comando retorna zero sem abrir conexão. Sempre teste o SMTP institucional em homologação. Exemplo de cron diário:

```cron
25 7 * * * cd /var/www/gse && /usr/bin/php bin/notify-dva.php >> /var/log/gse/notify-dva.log 2>&1
```

Proteja o log do agendador e o `.env`; nunca coloque `MAIL_PASSWORD` no crontab ou no repositório. Em Windows, use o Agendador de Tarefas com o mesmo usuário restrito da aplicação e execute `php.exe bin\notify-dva.php` no diretório do projeto.

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
| `APP_TIMEZONE` | fuso das datas civis; padrão `America/Cuiaba` |
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
| `DVA_WARNING_DAYS` | `30`; limite visual do semáforo |
| `DVA_EMAIL_WARNING_DAYS` | `15`; limite do resumo por e-mail |
| `MAIL_ENABLED` | `false` por padrão |
| `MAIL_HOST` / `MAIL_PORT` | servidor SMTP e porta (`587`) |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | credenciais SMTP, nunca versionadas |
| `MAIL_ENCRYPTION` | `tls`, `smtps` ou `none` conforme o provedor |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | remetente institucional |
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

`schema_migrations` controla versões individuais de 1 a 10. `php bin/init-db.php` pode ser repetido: cria esquema limpo ou aplica somente versões ausentes, em ordem, sem apagar tabelas/Models futuros. As versões 5 a 10 acrescentam o ciclo de vida de alunos, dados de turmas, histórico da DVA, recursos de auditoria, preferência de alertas, controle idempotente de entregas e comparação Unicode persistida.

| Versão | Alteração |
|---|---|
| 1–4 | esquema base e hardening de usuários do Módulo 1 |
| 5 | situação, atualização e inativação de alunos |
| 6 | ano letivo, situação e timestamps de turmas |
| 7 | DVA vigente/histórica e índice único parcial |
| 8 | recurso de auditoria e preferência de alertas |
| 9 | idempotência de notificações e triggers de integridade |
| 10 | chaves de nomes em NFC/minúsculas, busca Unicode e unicidade de turmas por nome normalizado/ano |

Na atualização legada, todos os alunos permanecem ativos, `atualizado_em` deriva do timestamp de criação quando disponível e anos letivos desconhecidos continuam nulos. A v6 reconstrói a restrição antiga de turmas com `foreign_keys` alterado somente fora da transação, preserva IDs e o mapa exato `aluno_id → id_turma`, compara contagens e IDs de alunos/turmas/DVAs e exige `PRAGMA foreign_key_check` vazio antes do commit e após restaurar a proteção. Qualquer divergência provoca rollback. Para múltiplas DVAs antigas, a vigente é escolhida deterministicamente por `criado_em` e, em empate, pelo maior ID; as demais viram históricas sem datas fabricadas.

Antes de alteração estrutural em banco existente, é criado um backup SQLite consistente em `backups/`, validado com `PRAGMA integrity_check`. O banco original nunca é substituído ou apagado. Se e-mails legados conflitarem apenas por caixa, a migração para com erro e preserva os dados para correção manual sobre uma cópia.

A v10 preenche `alunos.nome_normalizado` e `turmas.nome_normalizado` sem alterar nomes, IDs, vínculos ou DVAs. Antes do commit, compara contagens, IDs e o mapa `aluno_id → id_turma`, além de exigir `PRAGMA foreign_key_check` vazio e `PRAGMA integrity_check=ok`. Se duas turmas do mesmo ano se tornarem equivalentes após NFC e conversão Unicode para minúsculas, a migração faz rollback e informa os IDs envolvidos; não exclui, mescla, renomeia nem escolhe automaticamente qual registro prevalece. Corrija a colisão em uma cópia homologada e execute novamente.

Bancos locais de teste que já executaram a versão v6 defeituosa anterior a esta correção podem ter perdido os vínculos e devem ser restaurados a partir do backup `pre-migration`; o sistema não tenta reconstruí-los por adivinhação.

Mantenha backups fora do servidor, criptografados e com restauração testada. Backups locais, bancos e sidecars estão no `.gitignore`.

Para restaurar, coloque a aplicação em manutenção, preserve uma cópia do estado atual, remova somente sidecars `-wal`/`-shm` depois de encerrar todos os processos PHP, copie o backup validado para o `DB_PATH`, reaplique permissões e execute `PRAGMA integrity_check` antes de reabrir o serviço. Faça esse procedimento primeiro em homologação; o inicializador nunca substitui automaticamente um banco existente.

## Rotas explícitas

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
| GET | `/aluno` | autenticado |
| GET/POST | `/aluno/criar` | autenticado |
| GET | `/aluno/perfil/{id}` | autenticado |
| GET/POST | `/aluno/editar/{id}` | autenticado |
| POST | `/aluno/status/{id}` | administrador + CSRF |
| GET/POST | `/aluno/dva/{id}` | autenticado |
| GET | `/dva` | autenticado |
| GET | `/turma` | administrador |
| GET/POST | `/turma/criar` | administrador |
| GET/POST | `/turma/editar/{id}` | administrador |
| POST | `/turma/status/{id}` | administrador + CSRF |

Rota desconhecida retorna 404, método incorreto 405, funcionário autenticado recebe 403 e visitante é redirecionado ao login.

## Testes e comandos Composer

```bash
composer validate-project
composer lint
composer analyse
composer test
composer http-test
composer maintenance
composer notify-dva # somente após configurar MAIL_ENABLED
composer security-check
composer check
```

PHPUnit usa bancos temporários e cobre autenticação, bloqueio/expiração, sessões, senha temporária, CSRF, autorização, usuários, último administrador, alunos, turmas, DVA, semáforo, rollback, notificações, auditoria, headers, host/proxy/HTTPS, migração e SQLite. `tests/http-smoke.php` inicia servidores temporários e testa o fluxo HTTP real dos dois módulos em Windows/Linux. No PowerShell, `tests/manual-http.ps1` é um wrapper equivalente.

`composer analyse` executa PHPStan no nível 6 sobre o núcleo, controllers, Models ativos dos Módulos 1 e 2, serviços, e comandos CLI. Views possuem uma verificação dedicada contra estilos, scripts e handlers inline. Não há baseline nem `ignoreErrors`. O workflow usa PHP 8.3, actions fixadas por SHA imutável e executa instalação limpa, validação estrita, auditoria, lint, PHPStan, PHPUnit e HTTP em pushes e pull requests.

## Roteiro de demonstração

1. Em diretório limpo, copie `.env.example` para `.env` e execute `composer install`.
2. Execute `php bin/init-db.php` duas vezes para demonstrar idempotência.
3. Crie o primeiro administrador e anote a senha temporária mostrada uma vez.
4. Acesse `/dashboard` sem sessão e observe o redirecionamento.
5. Entre e demonstre que somente troca de senha/logout estão disponíveis.
6. Troque a senha e faça novo login.
7. Cadastre uma turma e um aluno com DVA inicial.
8. Mostre perfil, WhatsApp validado, semáforo e filtros.
9. Renove a DVA e confirme a versão anterior no histórico.
10. Inative/reative o aluno e demonstre que não ocorre exclusão física.
11. Cadastre funcionário, demonstre e-mail `NOCASE`, senha temporária, acesso a alunos e 403 para turma/status.
12. Mostre dashboard integrado e auditoria filtrada por `student`, `dva` e `class`.
13. Com transporte SMTP de homologação, execute `php bin/notify-dva.php` duas vezes e confirme idempotência.
14. Execute `composer check`.

## Limitações conhecidas e fora do escopo

- não há MFA nem recuperação de senha por e-mail nesta etapa;
- auditoria local não substitui SIEM/armazenamento imutável;
- disponibilidade distribuída exigiria rate limiting e sessões em armazenamento compartilhado;
- alterações futuras do logo ou da identidade institucional dependem de aprovação da escola;
- notificações dependem de um SMTP institucional configurado e de agendamento externo;
- arquivo passivo, certidões, fornecedores, contratos, estoque, pedidos e relatórios gerais não têm telas/rotas funcionais nesta entrega.

O dashboard combina indicadores do Módulo 1 com dados operacionais limitados do Módulo 2. Não antecipa indicadores dos Módulos 3, 4 ou 5.

Antes de implantar, conclua [docs/PRODUCTION_CHECKLIST.md](docs/PRODUCTION_CHECKLIST.md) e leia [SECURITY.md](SECURITY.md).
