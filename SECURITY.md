# Política de Segurança do GSE

## Comunicação responsável

Não publique vulnerabilidades, credenciais, dados escolares ou detalhes exploráveis em uma issue pública.

Use o recurso **Security > Advisories > New draft security advisory** deste repositório no GitHub. Se o recurso ainda não estiver habilitado, solicite ao proprietário do repositório um canal privado antes de enviar detalhes. Inclua versão/commit, impacto, pré-condições e passos mínimos para reprodução, sempre com dados fictícios.

O mantenedor deve confirmar o recebimento, avaliar severidade e coordenar correção e divulgação. Prazos dependem do impacto e da disponibilidade do projeto acadêmico; não há SLA formal nesta fase.

## Escopo suportado

O código desta branch abrange os Módulos 1 — Autenticação e Controle de Usuários —, 2 — Gestão de Alunos, Turmas e DVA —, 3 — Arquivo Passivo — e 4 — Certidões e Fornecedores. Models e tabelas preservados para o Módulo 5 não representam funcionalidades publicadas. Isso não constitui homologação ou publicação em produção.

### Certidões e Fornecedores (Módulo 4)

PDFs permanecem fora de `public`, com download autenticado e verificação de integridade. Renovação preserva o documento anterior; exclusão é lógica e distinta do arquivamento. Revisões de certidões, fornecedores e tipos impedem sobrescrita por formulários antigos, com auditoria na mesma transação. Homologue a migração v14 em cópia antes de atualizar uma instalação e mantenha todos os escritores na mesma versão do código.

Notificações de certidões usam lock local exclusivo e transações curtas; o SMTP não mantém reserva de escrita SQLite. Não remova o arquivo `.certidao-notify.lock` durante operação nem execute esse mecanismo em filesystem compartilhado sem homologar a coordenação. O aceite SMTP seguido de falha de confirmação local pode causar duplicação na retentativa. Consulte os limites, pendências de SMTP/agendamento e o procedimento de backup conjunto SQLite/PDF em [docs/MODULO4_CERTIDOES.md](docs/MODULO4_CERTIDOES.md).

### Arquivo Passivo (Módulo 3)

O Módulo 3 faz parte do escopo suportado. Alterações sensíveis usam POST, CSRF, autorização central, `BEGIN IMMEDIATE` e auditoria obrigatória na mesma transação. O banco bloqueia exclusão física de `alunos_passivo`; eliminação definitiva por LGPD não está implementada e depende de política formal da escola.

Uploads CSV ficam fora de `public`, recebem nome aleatório, limite de 2 MiB/5.000 linhas, validação de MIME e UTF-8 e expiração de 15 minutos. Tokens de prévia são vinculados à sessão e ao administrador, não são reutilizáveis e o temporário é removido na confirmação, falha ou expiração. A confirmação revalida o SHA-256 do arquivo e a mesma análise de dados diante do estado atual do banco. A importação comum nunca executa `DELETE FROM alunos_passivo`.

Antes da produção, homologue a migração v12 em cópia, valide o backup preventivo, IDs, sequência, localizações pendentes, `PRAGMA foreign_key_check` e `PRAGMA integrity_check`. Trate colisões físicas em homologação sem exclusão, mesclagem ou renumeração silenciosa.

## Dados que nunca devem ser enviados

- senhas reais ou temporárias em uso;
- cookies, identificadores de sessão ou tokens CSRF;
- `.env`, bancos SQLite, backups ou logs reais;
- dados pessoais de alunos, funcionários ou responsáveis.

Use contas e senhas artificiais em qualquer prova de conceito.

## Recomendações de implantação

- publique somente `public/` como raiz do servidor web;
- use PHP 8.3 atualizado e HTTPS;
- mantenha `ext-intl` habilitada com a mesma versão suportada em desenvolvimento, homologação e produção;
- mantenha `APP_ENV=production`, `APP_URL` fixa e `APP_ALLOWED_HOSTS` restrita;
- configure `TRUSTED_PROXIES` apenas com IPs/CIDRs controlados;
- armazene banco e logs fora de `public/`, com usuário de serviço dedicado;
- proteja `.env`, SQLite, `-wal`, `-shm`, logs e backups por permissões do sistema operacional;
- monitore auditoria, erros, espaço em disco e falhas de backup;
- atualize dependências somente após CI e `composer audit --locked`;
- configure backups externos criptografados e teste restauração;
- restrinja o acesso aos dados pessoais de alunos à finalidade escolar e revise periodicamente contas ativas;
- não envie bancos, telas reais de alunos ou relatórios de DVA em canais públicos de suporte;
- mantenha alunos inativos para preservação controlada do histórico; qualquer futura política de eliminação deve ser formal, auditada e aprovada pela escola;
- execute notificações de DVA somente em CLI, com SMTP institucional protegido por STARTTLS/TLS implícito, destinatários administradores habilitados por opt-in e logs restritos;
- antes de migrar um banco real, valide o backup `pre-migration` e ensaie a v11 em cópia com a mesma versão de PHP/SQLite e `ext-intl`; confirme IDs, sequências, mapas aluno/turma e DVA/aluno, `PRAGMA foreign_key_check` vazio e `PRAGMA integrity_check=ok`;
- trate colisões Unicode de turmas manualmente em homologação; nunca mescle ou renomeie registros automaticamente em produção;
- mantenha uma janela de manutenção sem escritores durante a migração e um procedimento de rollback testado a partir do backup validado;
- habilite proteção da branch `main`, revisão e checks obrigatórios no GitHub.
- agende `php bin/maintenance.php` diariamente e monitore seu código de saída, sem executar limpezas em requisições HTTP.

Consulte também [docs/PRODUCTION_CHECKLIST.md](docs/PRODUCTION_CHECKLIST.md).
