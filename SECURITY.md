# Política de Segurança do GSE

## Comunicação responsável

Não publique vulnerabilidades, credenciais, dados escolares ou detalhes exploráveis em uma issue pública.

Use o recurso **Security > Advisories > New draft security advisory** deste repositório no GitHub. Se o recurso ainda não estiver habilitado, solicite ao proprietário do repositório um canal privado antes de enviar detalhes. Inclua versão/commit, impacto, pré-condições e passos mínimos para reprodução, sempre com dados fictícios.

O mantenedor deve confirmar o recebimento, avaliar severidade e coordenar correção e divulgação. Prazos dependem do impacto e da disponibilidade do projeto acadêmico; não há SLA formal nesta fase.

## Escopo suportado

Novas senhas exigem Argon2id registrado em `password_algos()` no PHP 8.3+ usado pela CLI e pelo servidor web. Não há fallback para bcrypt. Ausência do algoritmo ou falha de geração impede alterações de senha e criação de contas, preservando transações, auditoria e versões de sessão. Os custos nativos de Argon2id são mantidos. Novas frases-senha Unicode de 15 a 128 caracteres são preservadas sem truncamento, trim ou normalização; UTF-8 inválido e bytes nulos são recusados.

Bcrypt legado verifica somente entradas de até 72 bytes; entradas maiores falham genericamente, mantêm limitação de tentativas e não autenticam nem disparam rehash. Essa proteção também se aplica à confirmação de senha atual. Argon2id verifica integralmente entradas acima de 72 bytes. Rehash oportunista depende de Argon2id disponível, preserva o hash anterior em falhas e usa atualização condicionada ao hash verificado para não sobrescrever resets concorrentes. Hashes fictícios bcrypt usados no login não são hashes de novas contas.

Hashes bcrypt não permitem inferir o tamanho original da senha. Contas afetadas exigem confirmação institucional da identidade e reset temporário por administrador autorizado pela interface existente, com auditoria e troca obrigatória. A CLI inicial não substitui a recuperação de uma conta de administrador já ativa; se for o único administrador, encaminhe ao procedimento operacional supervisionado. Não trunque a senha, edite hashes diretamente ou enfraqueça a proteção do último administrador. Não há reset em massa nem recuperação pública. Consulte a política de senhas no README.

Alterações de alunos, DVAs, turmas, contas, senhas e preferência de alertas usam auditoria obrigatória na transação da mutação. Falhas do log desfazem os dados e as versões da sessão envolvidas. Eventos de autenticação, sessão e tentativas bloqueadas usam registro independente; não fazem parte dessa garantia transacional de alterações do domínio.

Backups administrativos exigem conta ativa com perfil de administrador e sessão atualizada. A geração usa POST, CSRF e confirmação da senha atual. Snapshots e downloads permanecem fora de `public`, sem cache, com nomes e caminhos restritos e auditoria obrigatória. Falha da auditoria da geração remove somente o snapshot recém-criado; falha na auditoria do download impede o envio do arquivo. A interface não restaura bancos nem substitui o procedimento de backup conjunto de SQLite, PDFs privados e versão compatível do código.

O código desta branch abrange os Módulos 1 — Autenticação e Controle de Usuários —, 2 — Gestão de Alunos, Turmas e DVA —, 3 — Arquivo Passivo —, 4 — Certidões e Fornecedores — e 5 — Contratos, Estoque e Relatórios. Isso não constitui homologação ou publicação em produção.

### Contratos, estoque e relatórios (Módulo 5)

Rotas novas exigem sessão autenticada; estornos, conciliação legada e correção de faturamento exigem administrador. POST usa CSRF, transação e auditoria obrigatória. Movimentos são imutáveis, saldo é derivado do histórico, e a exclusão lógica não elimina registros. O PDF de relatórios usa Dompdf via Composer com rede e PHP executável desabilitados e HTML escapado; CSV neutraliza fórmulas. Ambos os downloads usam `private, no-store`. Homologue os fluxos com o acervo institucional antes de utilizá-los em produção.

### Certidões e Fornecedores (Módulo 4)

PDFs permanecem fora de `public`, com download autenticado e verificação de integridade. Renovação preserva o documento anterior; exclusão é lógica e distinta do arquivamento. Revisões de certidões, fornecedores e tipos impedem sobrescrita por formulários antigos, com auditoria na mesma transação. Homologue a migração v14 em cópia antes de atualizar uma instalação e mantenha todos os escritores na mesma versão do código.

Notificações de certidões usam lock local exclusivo e transações curtas; o SMTP não mantém reserva de escrita SQLite. Não remova o arquivo `.certidao-notify.lock` durante operação nem execute esse mecanismo em filesystem compartilhado sem homologar a coordenação. O aceite SMTP seguido de falha de confirmação local pode causar duplicação na retentativa. SMTP e agendamento exigem homologação institucional. Faça o backup conjunto de SQLite, PDFs privados e versão compatível do código com todos os escritores parados e ensaie a restauração antes da implantação.

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

Antes da implantação, homologue SMTP, Nginx/PHP-FPM, HTTPS e intranet e obtenha o aceite escolar.


A revisão v17 acrescenta criptografia dos campos de usuários e novos backups, HMAC separado, confirmações limitadas, idempotência e CSRF com tipos inesperados. Consulte [SECURITY-OPERATIONS.md](SECURITY-OPERATIONS.md) para o escopo exato, limites, chaves externas e operação. Senhas legadas válidas de 12 caracteres continuam autenticando. A homologação do servidor real continua pendente.

O ajuste posterior verifica a remoção dos snapshots temporários de backups manuais e preventivos. Uma pendência de limpeza impede sucesso completo e gera evento técnico sem conteúdo pessoal; o backup criptografado concluído é preservado. O [procedimento de limpeza offline](SECURITY-OPERATIONS.md#limpeza-verificada-dos-snapshots) exige conferência de identidade e recuperação antes da remoção. Isso não amplia a criptografia para outros dados pessoais, todo o SQLite ou o disco, nem promete apagamento físico seguro de SSD.
