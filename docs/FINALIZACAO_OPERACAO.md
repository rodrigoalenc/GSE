# Finalização operacional do GSE

Revisão local de 05/10/2026, iniciada em `74e7727` e executada sobre o código de trabalho da finalização. Os números abaixo são ensaios reais com dados fictícios; a revisão final e os totais completos constam do relatório de finalização. O `.env` existente foi preservado e as configurações de QA foram fornecidas aos processos descartáveis. Bancos, PDFs de certidões e destinatários institucionais não foram usados no QA nem alterados. Não houve implantação ou agendamento de produção.

O PDF exato `TCC_2_ETAPA_1_MÓDULO_1 - Rodrigo-Calebe.pdf` ainda precisa ser fornecido ou ter sua edição confirmada. As referências a RF006/NF003, UC001 e às figuras de certidões neste documento vêm do plano autorizado pelo usuário, sem declaração de conferência literal desse PDF. A matriz de permissões abaixo descreve o código efetivamente auditado.

## Verificações e correções

| Ensaio | Evidência e resultado |
|---|---|
| DVA: administrador ativo com preferência habilitada, retry e deduplicação diária | `DvaNotificationTest`; transporte em memória, sem rede. |
| DVA: e-mail legado inválido e mudança de conta durante o envio | Duas regressões falharam antes da correção: e-mail inválido chegou ao transporte; quatro contas alteradas continuaram recebendo. Corrigido filtro de e-mail e revalidação de conta ativa, perfil, preferência e e-mail na reserva transacional de cada destinatário. |
| Certidões: destinatários, falha parcial, retry, trabalhador concorrente e queda | `CertidaoNotificationTest`; mantém duas contas administrativas ativas independentemente da preferência DVA; nenhum funcionário/inativo recebe. Ensaio de queda e confirmação local simulado. |
| SMTP/TLS | `PhpMailerTransportTest`; STARTTLS/SMTPS mapeados, `none` recusado em produção, portas padrão incoerentes recusadas. Sem conexão SMTP real. |
| CLI diária desabilitada | `CertidaoCliTest`: `MAIL_ENABLED=false` impede os dois comandos de abrir/criar o banco, mesmo com `APP_ENV=production`; configuração e diretórios fictícios. **3 testes, 21 asserções**. |
| Backup e restauração conjunta | `OperationalBackupTest`: snapshot `VACUUM INTO` e PDF privado restaurados em outro diretório; IDs, vínculos, auditoria, sequências e migrações iguais; hash/tamanho corretos, `integrity_check=ok`, FK vazias, inicialização duas vezes sem mudanças. **1 teste, 12 asserções**. |
| Estoque concorrente e legado | `ModuloCincoTest` e `LegacyStockRecoveryTest`: processos reais competem sobre SQLite temporário; somente uma saída/contagem é aceita, sem saldo negativo; recuperação requer administrador ativo, contagem confirmada, unidade, limites, motivo e auditoria; falha de auditoria desfaz toda a operação. |
| Inicialização/atualização | `DatabaseMigrationTest` e `DatabaseSchemaConvergenceTest`: banco novo, versões legadas, integridade, preservação e idempotência; a revisão final utiliza esquema v16. |
| Manutenção | `MaintenanceTest`: retenção remove somente registros vencidos e é idempotente; páginas e autenticação não executam limpeza. |

A primeira bateria operacional teve **80 testes e 416 asserções** (1min14s), antes de acrescentar o ensaio de restauração e a regressão adicional CLI e antes da consolidação do esquema v16. Esse resultado é intermediário e não substitui a bateria final registrada na entrega.

A bateria direcionada após a consolidação v16 passou com **82 testes e 434 asserções**, em 2min21,669s e pico PHPUnit de 26 MiB. Comando: `php vendor/phpunit/phpunit/phpunit --filter 'DvaNotificationTest|CertidaoNotificationTest|PhpMailerTransportTest|LegacyStockRecoveryTest|ModuloCincoTest|DatabaseMigrationTest|DatabaseSchemaConvergenceTest|MaintenanceTest|CertidaoCliTest|OperationalBackupTest' --no-progress`. Sintaxe dos arquivos PHP operacionais e `git diff --check` também passaram.

Arquivos operacionais alterados: `src/Services/DvaNotificationService.php`, `tests/Integration/DvaNotificationTest.php`, `tests/Integration/CertidaoCliTest.php`, novo `tests/Integration/OperationalBackupTest.php` e os exemplos `.env.example`/`.env.production.example`, que agora explicitam `CERTIDAO_STORAGE_PATH`. O `.env` existente foi preservado.

## Permissões de telas, ações e URLs diretas

Fonte: tabela explícita de `src/Core/Router.php`, `Router::authorize()`, `Auth`, controles complementares de `ContratoController` e regras transacionais dos modelos. “Não” significa bloqueio/encaminhamento para login no servidor; esconder um botão não é a proteção. Toda rota POST, incluindo login/logout, valida CSRF. Contas inativas não possuem sessão válida. Senha temporária limita o usuário à troca da própria senha e saída até a alteração obrigatória.

| Tela/ação e rota | Visitante | Funcionário | Administrador |
|---|---|---|---|
| Login: `GET /`, `/login`; `POST /login/entrar` | Sim | Sim | Sim |
| Logout; própria senha: `POST /login/sair`, `GET/POST /senha/alterar` | Não | Sim | Sim |
| Painel: `GET /dashboard` | Não | Sim | Sim |
| Alunos: lista, perfil, criar/editar; `GET /aluno`, `/aluno/perfil/{id}`, `GET/POST /aluno/criar`, `/aluno/editar/{id}` | Não | Sim | Sim |
| DVA: painel e cadastro/edição; `GET /dva`, `GET/POST /aluno/dva/{id}` | Não | Sim | Sim |
| Ativar/inativar aluno: `POST /aluno/status/{id}` | Não | Não | Sim |
| Usuários: `GET /usuario`; criar/editar `GET/POST`; `POST /usuario/status/{id}` | Não | Não | Sim |
| Turmas: `GET /turma`; criar/editar `GET/POST`; `POST /turma/status/{id}` | Não | Não | Sim |
| Auditoria: `GET /auditoria` | Não | Não | Sim |
| Passivo: lista/detalhe/criar/editar; `GET /passivo`, `/passivo/detalhes/{id}`, `GET/POST /passivo/criar`, `/passivo/editar/{id}` | Não | Sim | Sim |
| Passivo: exclusão lógica `POST /passivo/excluir/{id}`, consulta inativos `GET /passivo/inativos`, exportar `POST /passivo/exportar` | Não | Sim | Sim |
| Passivo: restaurar/status `POST /passivo/status/{id}` | Não | Não | Sim |
| Passivo: CSV e enumeração, incluindo todas as prévias/confirmações `/passivo/importar*`, `/passivo/ferramentas*` | Não | Não | Sim |
| Arquivamento individual e lote: `GET/POST /aluno/arquivar/{id}`; seleção/prévia/confirmação `/aluno/arquivar-lote*` | Não | Não | Sim |
| Certidões: lista/detalhe/PDF, cadastro/edição/renovação; `GET /certidao*`, `GET/POST /certidao/cadastrar`, `/certidao/editar/{id}`, `/certidao/renovar/{id}` | Não | Sim | Sim |
| Certidões: arquivar, desarquivar e excluir logicamente; `POST /certidao/{arquivar,desarquivar,excluir}/{id}` | Não | Sim | Sim |
| Certidões arquivadas/excluídas e fornecedores/tipos: `GET /certidao/arquivadas`, `/certidao/excluidas`, `GET/POST /certidao/configurar` | Não | Sim | Sim |
| Contratos/notas/produtos: consulta, criar/editar, faturar inicialmente, imprimir/histórico; rotas `/contrato*` operacionais | Não | Sim | Sim |
| Estoque: consulta `GET /estoque`, limites/abertura comum `POST /contrato/estoque/{id}`, entrada/saída `POST /contrato/movimentar/{id}` | Não | Sim | Sim |
| Corrigir faturamento já registrado: `POST /contrato/faturar/{id}` com motivo | Não | Não | Sim |
| Estorno: `POST /contrato/movimentar/{id}` com `tipo=estorno`, motivo e movimento original | Não | Não | Sim |
| Conciliação e recuperação de abertura antiga: `POST /contrato/conciliar/{id}`, `/contrato/conferir-abertura/{id}`; abertura legada em `/contrato/estoque/{id}` | Não | Não | Sim |
| Exclusão lógica de contrato/nota/produto: `POST /contrato/excluir/{id}`, respeitando saldo e faturamento | Não | Sim | Sim |
| Relatórios e exportações: `GET /relatorio`, `/relatorio/csv`, `/relatorio/pdf` | Não | Sim | Sim |
| PDFs privados por URL de armazenamento ou diretório legado | Não | Não | Não |

As linhas com `*` resumem famílias, não autorizam rotas implícitas: somente os métodos e padrões declarados no Router são aceitos; IDs inválidos e métodos incorretos são recusados. O download válido de PDF ocorre exclusivamente por `GET /certidao/pdf/{id}`, autenticado e auditado. O funcionário pode desarquivar uma certidão na operação comum do módulo; a restauração crítica de passivo e a recuperação de estoque seguem restritas ao administrador.

Interpretações a validar academicamente: o plano relata UC001 com ator “Usuário” e autenticação como pré-condição. Mantém-se a inativação de alunos administrativa, considerando seu caráter crítico; a pré-condição genérica não concede todas as ações. O plano também relata Figura 6/p.22 com `include` de anexo PDF e Figura 17/p.29 com “Anexar PDF (Opcional)”. O código preserva anexar/armazenar/consultar/baixar e permite cadastrar/renovar sem anexo, conforme a opção de mockup relatada. A exclusão é lógica: sai da consulta ativa e preserva histórico, vínculos e auditoria; a atribuição dessa interpretação ao PDF original permanece pendente de conferência literal.

## Alertas diários e limites reais de SMTP

- **DVA:** `php bin/notify-dva.php` exige `MAIL_ENABLED=true`. Só administradores ativos com `recebe_alertas_dva=1` e endereço válido; são considerados alunos e DVAs ativos. Padrão de preferência: desabilitada, removida ao inativar/rebaixar a conta. Janelas separadas `DVA_WARNING_DAYS` (painel) e `DVA_EMAIL_WARNING_DAYS` (e-mail).
- **Certidões:** `php bin/notify-certidoes.php` exige simultaneamente `APP_ENV=production`, `MAIL_ENABLED=true`, `CERTIDAO_MAIL_ENABLED=true`. Inclui somente certidões correntes vencidas, vencendo hoje ou dentro de `CERTIDAO_WARNING_DAYS`. Destinatários são administradores ativos com endereço válido; não reutiliza a preferência DVA. Avisos sem administrador válido resultam em falha.
- SMTP admite STARTTLS (`MAIL_ENCRYPTION=tls`, normalmente 587) ou TLS implícito (`smtps`, normalmente 465), exige remetente válido e senha quando há usuário SMTP. `none` só é aceito fora de produção. Não há bypass de verificação de certificado. Conexão/leitura limita-se a 15s e comando SMTP a 30s; esses limites não garantem um teto único para um lote inteiro.
- Credenciais ficam no `.env` privado ou ambiente protegido; não em versionamento, HTML ou logs. Logs registram códigos/classe de falha e contagens, sem senha ou mensagem SMTP integral. Os e-mails de DVA contêm nome do aluno, turma e vencimento; destinatários e retenção devem ser aprovados pela escola.
- DVA reserva entrega por dia e usuário em transação curta; falha pode ser tentada novamente e reserva `processing` expira em uma hora. Certidões usam `flock` não bloqueante no arquivo persistente `<DB_PATH>.certidao-notify.lock`, tentativas registradas e entrega diária confirmada após o SMTP. **Nenhuma transação SQLite permanece aberta durante o I/O SMTP de certidões.** Não remover/substituir o lock enquanto houver trabalhadores; todos precisam do mesmo arquivo local.
- Deduplicação diária depende da confirmação local. Queda após aceite SMTP e antes da confirmação pode duplicar retry em ambos os módulos; SMTP não fornece transação atômica conjunta com SQLite, garantia de leitura pelo destinatário ou entrega exatamente uma vez. Uma alteração de conta após a reserva e imediatamente antes do envio ainda possui pequena janela inevitável sem bloqueio externo. Homologar entrega, caixas de spam e bounces no SMTP candidato; não assumir aprovação pelo resultado de transporte falso.
- Saída CLI: `0` sucesso/desabilitado; `1` configuração/execução falhou; `2` envio com falhas. Monitorar códigos e mensagens. Não foi habilitado nenhum alerta real nesta revisão.

## Exportações no volume máximo

Ambiente local: Windows, PHP CLI 8.4.13, extensões exigidas carregadas, dependências do lock existente, bancos temporários. Medida do controlador real, excluindo criação da fixture; `hrtime` e `memory_get_peak_usage(true)`, em processo novo por formato, com saída gravada em arquivo. Pico é memória do alocador PHP, não RSS do sistema operacional. Uma execução medida por formato; não é ensaio de capacidade da intranet.

| Formato | Alunos filtrados | Tempo | Base/pico PHP | Arquivo | Verificação |
|---|---:|---:|---:|---:|---|
| CSV | 10.000 | 0,201s | 4 / 10 MiB | 640.195 bytes | 10.000 nomes únicos na ordem 00001–10000, UTF-8/BOM, turma/DVA/ativo corretos; excluídos alunos inativos, de outra turma e DVA vigente. Exportação total acima de 10.000 recusada. |
| PDF | 500 | 3,062s | 4 / 88 MiB | 55.850 bytes | 14 páginas; extração completa confirmou 00001–00500 na ordem, sem faltas/duplicidades, com filtros corretos e cabeçalho repetido. |

Não se alteraram os limites de 500 alunos/PDF e 10.000 na exportação total. O teto PDF permanece no controlador antes da geração; o teto total no modelo. A implementação materializa as linhas selecionadas em memória; CSV não é leitura contínua do banco. A memória observada sustenta os limites locais, sem prometer capacidade de vários PDFs simultâneos no servidor candidato.

Extração com pypdf; rasterização com PyMuPDF local porque Poppler não estava disponível. Inspeção das páginas [1](evidencias/2026-10-05/relatorio-500-alunos-pagina-01.png), [8](evidencias/2026-10-05/relatorio-500-alunos-pagina-08.png) e [14](evidencias/2026-10-05/relatorio-500-alunos-pagina-14.png): cabeçalhos e linhas legíveis, sem sobreposição/corte. Os PNGs mostram somente GSE e dados fictícios. Builders, PDF/CSV e resultados detalhados ficam em `.local-qa/operations-*`, como material técnico temporário.

## Roteiro institucional: PHP-FPM, Nginx, HTTPS e dados privados

Os comandos seguintes são um roteiro preparado para revisão, **não executado no servidor da escola**. Exemplo de host dedicado Linux com PHP 8.3; adaptar versão, pacotes, domínio, certificado e serviço ao ambiente homologado. A implantação e qualquer atualização real dependem da autorização institucional.

1. Instalar PHP CLI/FPM e extensões `fileinfo`, `intl`, `mbstring`, PDO/SQLite, `openssl`, `dom`, `xml`, além de Nginx, Composer e `sqlite3`. Conferir as extensões em **CLI e FPM**. Instalar `composer install --no-dev --classmap-authoritative` do lock revisado no artefato candidato. Assets são locais em `public/assets`; não é necessário habilitar CDN ou recursos remotos de Dompdf.
2. Código em `/srv/gse` pertencente à conta de implantação, legível pela conta de serviço `gse`; sem escrita da aplicação em código, `vendor` ou `public`. Diretórios de escrita privados:

```bash
sudo useradd --system --home /srv/gse --no-create-home --shell /usr/sbin/nologin gse
sudo install -d -o gse -g gse -m 0700 /var/lib/gse /var/lib/gse/certidoes /var/lib/gse/sessions /var/log/gse
sudo install -o root -g gse -m 0640 /srv/gse/.env.production.example /srv/gse/.env
```

3. Revisar `.env`: `APP_ENV=production`, URL HTTPS oficial sem subcaminho, `APP_ALLOWED_HOSTS` restrito, `FORCE_HTTPS=true`; `DB_PATH=/var/lib/gse/gse.sqlite`, `CERTIDAO_STORAGE_PATH=/var/lib/gse/certidoes`, `LOG_PATH=/var/log/gse/php_errors.log`, `DB_DIRECTORY_MODE=0700`, `DB_FILE_MODE=0600`. Rever `APP_TIMEZONE` com a escola. Manter ambos os envios `false` até o ensaio SMTP autorizado. `TRUSTED_PROXIES` vazio em acesso direto; se houver proxy, limitar aos IPs reais e sobrescrever headers na borda.
4. Exemplo de pool `/etc/php/8.3/fpm/pool.d/gse.conf`:

```ini
[gse]
user = gse
group = gse
listen = /run/php/gse-fpm.sock
listen.owner = gse
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 4
pm.process_idle_timeout = 10s
clear_env = yes
chdir = /srv/gse
security.limit_extensions = .php
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_value[error_log] = /var/log/gse/php_errors.log
php_admin_value[session.save_path] = /var/lib/gse/sessions
php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 10M
php_admin_value[post_max_size] = 12M
```

`pm.max_children` é ponto inicial de homologação, a dimensionar pela RAM e PDFs concorrentes. O limite de 256M não é garantia de disponibilidade; validar pico, espaço temporário, permissões e falhas no ambiente real.

5. Exemplo Nginx, publicado exclusivamente em `public/`. Usar certificado válido e confiável nos dispositivos da intranet; não desligar sua validação:

```nginx
server {
    listen 80;
    server_name gse.escola.example;
    return 308 https://gse.escola.example$request_uri;
}
server {
    listen 443 ssl;
    server_name gse.escola.example;
    root /srv/gse/public;
    index index.php;
    ssl_certificate /etc/ssl/gse/fullchain.pem;
    ssl_certificate_key /etc/ssl/gse/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    client_max_body_size 11m;
    location ~ /\. { deny all; }
    location ^~ /uploads/certidoes/ { return 404; }
    location ^~ /storage/ { return 404; }
    location ^~ /database/ { return 404; }
    location / { try_files $uri /index.php$is_args$args; }
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /srv/gse/public/index.php;
        fastcgi_param HTTPS on;
        fastcgi_pass unix:/run/php/gse-fpm.sock;
    }
    location ~ \.php$ { return 404; }
}
```

Não criar alias para dados privados e bloquear também qualquer diretório legado de upload. Configurar um servidor padrão que recuse hosts alheios. Validar antes de iniciar:

```bash
sudo php-fpm8.3 -t
sudo nginx -t
cd /srv/gse
sudo -u gse php bin/init-db.php
sudo -u gse php bin/create-admin.php --name='Administrador Ficticio' --email=admin@teste.local
sudo -u gse php bin/certidoes-maintenance.php
```

O exemplo de administrador serve à instalação **fictícia de homologação**. Na instalação institucional, usar a identidade real aprovada e registrar a senha temporária em canal protegido; trocá-la no primeiro acesso. Não passar senha na linha de comando ou versionar a saída. CLI `init-db` também atualiza esquema: primeiro ensaiar em cópia e fazer backup conjunto; não executar sobre o único banco real.

6. Conferir HTTPS, certificado, HSTS/CSP, cookies `Secure`/`HttpOnly`/`SameSite`, host inválido, URLs diretas e CSRF, download autenticado e bloqueio de armazenamento. Fazer cadastros fictícios completos com funcionário e administrador; repetir exportação máxima e concorrência. Windows local não comprova permissões Unix, PHP-FPM, Nginx ou TLS institucional.

## Backup e restauração conjunta executáveis

Precondições: janela de manutenção, acesso bloqueado, FPM da aplicação e **todas** as tarefas/trabalhadores/CLI GSE parados. Confirmar que não há escritor remanescente. Nunca copiar apenas o arquivo principal de um SQLite ativo em WAL. Os comandos usam caminhos fixos privados do roteiro; manter snapshots anteriores e código anterior. Não executar substituições em banco/PDF institucional fora dessa janela.

Snapshot offline em diretório novo protegido; não reutilizar nome existente:

```bash
set -euo pipefail
umask 077
gse_snapshot="/var/backups/gse/$(date -u +%Y%m%dT%H%M%SZ)"
test ! -e "$gse_snapshot"
mkdir -p "$gse_snapshot/certidoes"
sqlite3 /var/lib/gse/gse.sqlite ".backup '$gse_snapshot/database.sqlite'"
cp -a /var/lib/gse/certidoes/. "$gse_snapshot/certidoes/"
test "$(sqlite3 -readonly "$gse_snapshot/database.sqlite" 'PRAGMA integrity_check;')" = ok
test -z "$(sqlite3 -readonly "$gse_snapshot/database.sqlite" 'PRAGMA foreign_key_check;')"
tar -czf "$gse_snapshot/code.tar.gz" --exclude=.env --exclude=.git --exclude=.local-qa -C /srv/gse .
git -C /srv/gse rev-parse HEAD > "$gse_snapshot/revision.txt"
(cd "$gse_snapshot" && find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS)
```

Manter `.env`/credenciais em cofre separado; criptografar o backup externo segundo a governança da escola. O snapshot deve incluir PDFs correntes, arquivados e excluídos e eventuais documentos legados ainda referenciados. Conferir cada `pdf_privado`/`pdf_sha256`/`pdf_bytes` com o arquivo correspondente; não apagar órfãos automaticamente. `certidoes-maintenance.php` fornece inventário conservador, sem substituir a conferência de hashes.

Restaurar primeiro em diretório novo, sem abrir o serviço. Definir explicitamente o snapshot escolhido:

```bash
set -euo pipefail
umask 077
gse_snapshot=/var/backups/gse/SNAPSHOT_ESCOLHIDO
gse_restored="/var/lib/gse-restore-$(date -u +%Y%m%dT%H%M%SZ)"
test ! -e "$gse_restored"
(cd "$gse_snapshot" && sha256sum -c SHA256SUMS)
mkdir -p "$gse_restored/certidoes"
cp "$gse_snapshot/database.sqlite" "$gse_restored/gse.sqlite"
cp -a "$gse_snapshot/certidoes/." "$gse_restored/certidoes/"
chown -R gse:gse "$gse_restored"
find "$gse_restored" -type d -exec chmod 0700 {} +
find "$gse_restored" -type f -exec chmod 0600 {} +
test "$(sqlite3 -readonly "$gse_restored/gse.sqlite" 'PRAGMA integrity_check;')" = ok
test -z "$(sqlite3 -readonly "$gse_restored/gse.sqlite" 'PRAGMA foreign_key_check;')"
cd /srv/gse
sudo -u gse env DB_PATH="$gse_restored/gse.sqlite" CERTIDAO_STORAGE_PATH="$gse_restored/certidoes" \
    MAIL_ENABLED=false CERTIDAO_MAIL_ENABLED=false php bin/init-db.php
sudo -u gse env DB_PATH="$gse_restored/gse.sqlite" CERTIDAO_STORAGE_PATH="$gse_restored/certidoes" \
    MAIL_ENABLED=false CERTIDAO_MAIL_ENABLED=false php bin/certidoes-maintenance.php
```

O diretório restaurado começa sem `-wal`/`-shm` antigos. Comparar IDs, contagens, vínculos aluno/DVA, sequências, números/caixas, estoque e PDFs com o ponto de restauração; conferir migrações 1–16 sem lacunas e executar o inicializador novamente para verificar idempotência. Só então decidir a troca conjunta de `DB_PATH` e `CERTIDAO_STORAGE_PATH`, com código compatível e processos parados. Preservar o diretório anterior inteiro; não misturar banco restaurado com PDFs de outro momento. Rollback de migração usa o código anterior **e** snapshot conjunto anterior; o backup preventivo automático do inicializador contém somente SQLite.

## Habilitação institucional e aceite

Depois do SMTP isolado autorizado, cadastrar/conferir administradores reais, habilitar individualmente a preferência DVA e revisar as duas flags de envio. Validar SMTP com caixa exclusivamente de homologação antes de qualquer endereço institucional. Configurar domínio/remetente e autenticação/TLS em ambiente privado; não imprimir credenciais nos comandos. Aceite SMTP, políticas do domínio, spam/bounces e leitura dependem da infraestrutura institucional.

Exemplo para crontab da conta dedicada `gse`, depois da aprovação. Horários são os do servidor; confirmar fuso/relógio com `APP_TIMEZONE`. Não instalar essas entradas durante QA:

```cron
0 6 * * * cd /srv/gse && /usr/bin/php bin/notify-dva.php >>/var/log/gse/dva-cron.log 2>&1
10 6 * * * cd /srv/gse && /usr/bin/php bin/notify-certidoes.php >>/var/log/gse/certidoes-cron.log 2>&1
20 6 * * * cd /srv/gse && /usr/bin/php bin/maintenance.php >>/var/log/gse/maintenance-cron.log 2>&1
```

Adicionar ao monitoramento execução diária esperada, códigos de saída, falhas/retries, disco, backup externo/restauração periódica e retenção protegida de logs. Não confundir ausência de novos avisos com falha do agendador. SQLite e locks exigem armazenamento local compatível; não usar cópias divergentes do DB/lock ou presumir garantias em filesystem de rede.

Pendências concretas: conferência literal/aceite acadêmico da edição exata do PDF e das ambiguidades; execução no Linux compatível com CI; sintaxe/integridade do Nginx/FPM do candidato; permissões Unix/HTTPS real; SMTP isolado e entregabilidade; volume e concorrência no servidor da escola; backup externo criptografado/restauração institucional; governança de perfis e retenção; homologação dos cinco módulos e aceite da escola. Resultados locais aprovados não encerram essas etapas.
