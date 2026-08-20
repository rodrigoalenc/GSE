# Checklist de Produção — GSE Módulos 1 e 2

## Plataforma

- [ ] PHP 8.3+ suportado e atualizado.
- [ ] Extensões `pdo`, `pdo_sqlite`, `mbstring`, `session`, `filter` e `hash` habilitadas.
- [ ] `composer install --no-dev --classmap-authoritative` executado a partir do lock file revisado.
- [ ] Raiz pública do servidor apontando exclusivamente para `public/`.
- [ ] Usuário de serviço sem privilégios administrativos no sistema operacional.

## Ambiente e rede

- [ ] `.env.production.example` copiado para `.env` e todos os valores revisados.
- [ ] `APP_ENV=production` e `APP_URL=https://...` válidos.
- [ ] `APP_ALLOWED_HOSTS` contém somente hosts publicados.
- [ ] `FORCE_HTTPS=true` após validar TLS.
- [ ] `TRUSTED_PROXIES` vazio no acesso direto ou restrito aos IPs/CIDRs reais do proxy.
- [ ] Headers encaminhados são sobrescritos pelo proxy de borda.
- [ ] HSTS, CSP, `Secure`, `HttpOnly` e `SameSite=Lax` verificados no navegador/cURL.
- [ ] Firewall libera somente portas necessárias.

## Dados e arquivos

- [ ] `DB_PATH` e `LOG_PATH` estão fora de `public/`.
- [ ] Diretório SQLite em `0700` ou `0750`; arquivo, `-wal` e `-shm` em `0600` ou `0640` no Linux.
- [ ] Diretório de logs restrito ao usuário de serviço.
- [ ] Backup externo, criptografado, retido e restaurado em teste.
- [ ] Backup preventivo de migração verificado e movido para armazenamento protegido.
- [ ] Migração de cópia legada conferida: contagens de alunos/turmas/DVAs, uma DVA vigente por aluno e `PRAGMA integrity_check=ok`.
- [ ] Estratégia de preenchimento de `ano_letivo` das turmas legadas aprovada, sem inventar histórico.
- [ ] Nenhum `.env`, SQLite, log, backup, cookie, senha ou token está versionado.

## Contas e operação

- [ ] Primeiro administrador criado por CLI com dados institucionais.
- [ ] Senha temporária trocada no primeiro acesso e descartada.
- [ ] Pelo menos dois administradores ativos institucionais, quando a governança permitir.
- [ ] Retenção de auditoria aprovada pela escola (padrão: 365 dias; mínimo técnico: 90).
- [ ] Processo de revisão dos eventos `login.blocked`, acessos negados e mudanças administrativas definido.
- [ ] `php bin/maintenance.php` agendado diariamente por cron/Task Scheduler sob o usuário restrito da aplicação.
- [ ] Log do agendador protegido e falhas do comando de manutenção monitoradas.
- [ ] Relógio do servidor sincronizado; auditoria opera em UTC.
- [ ] Procedimento de inativação imediata de contas desligadas documentado.
- [ ] Permissões de funcionários e administradores sobre alunos, status e turmas revisadas com a escola.
- [ ] Retenção dos dados pessoais de alunos e responsáveis definida conforme a finalidade institucional e LGPD.
- [ ] `DVA_WARNING_DAYS`, `DVA_EMAIL_WARNING_DAYS` e `APP_TIMEZONE` revisados.
- [ ] Administradores destinatários habilitados individualmente e e-mails institucionais conferidos.
- [ ] Se `MAIL_ENABLED=true`, SMTP/TLS testado em homologação sem credenciais no repositório.
- [ ] `php bin/notify-dva.php` agendado diariamente e idempotência verificada; com e-mail desabilitado, nenhum agendamento é necessário.
- [ ] Logs do agendador não contêm telefone, nascimento, observação integral ou credencial.

## Verificação e GitHub

- [ ] `composer check` passa no artefato candidato.
- [ ] Teste de migração realizado sobre uma cópia, nunca sobre o único banco real.
- [ ] Página 500 genérica confirmada sem stack trace em produção.
- [ ] Host inválido retorna 400; HTTP retorna 308 para a URL HTTPS oficial.
- [ ] Branch `main` protegida contra push direto e exclusão.
- [ ] CI obrigatório e aprovado antes de merge, revisão por outra pessoa e resolução de comentários exigidas.
- [ ] GitHub Security Advisories habilitado para relato privado.
