# Implantação, recuperação e operação do GSE

Revisão 24/10/2026. Esquema atual: 22. Este procedimento exige operador responsável e execução inicial em homologação. Execute a manutenção como o proprietário do banco e das chaves; no servidor configurado para www-data, use `sudo -u www-data php ...`. Não contém credenciais e não autoriza apagar arquivos institucionais.

## Preparar o ambiente

Use PHP de 64 bits corrigido: 8.3.36+, 8.4.27+ ou 8.5.12+ dentro da linha correspondente. Verifique a biblioteca SQLite carregada pelo PDO, não apenas o cliente de terminal. São aceitas versões 3.45.1+ e o backport 3.44.6+ da linha 3.44. WAL exige o patch (3.51.3+ ou backports documentados); sem ele, o GSE exige DELETE com synchronous=FULL. Mantenha os pacotes de segurança do fornecedor atualizados. Execute `php bin/runtime-check.php` e `composer check-platform-reqs`. No Windows, `bin/install-runtime.ps1` instala ferramentas privadas verificadas e `bin/gse.ps1` executa os comandos com elas.

No Ubuntu 24.04, o verificador também aceita o backport PHP oficial comprovado de USN-8948-1. Execute os comandos de dentro da pasta do projeto. O procedimento e os limites da detecção estão em [COMPATIBILIDADE-UBUNTU.md](COMPATIBILIDADE-UBUNTU.md); o simples número PHP_VERSION não comprova ausência nem presença do patch da distribuição.

Em produção, use Nginx/PHP-FPM com raiz em public, HTTPS, host permitido, proxy confiável explícito quando aplicável e permissões privadas. O arquivo config/nginx-gse.conf é um exemplo que precisa dos caminhos/certificado reais. php -S é destinado ao desenvolvimento e demonstrações controladas. Configure upload_max_filesize=10M e post_max_size=12M e desabilite session.upload_progress.enabled no PHP do servidor.

Mantenha SQLite, documentos, sessões, logs e chaves fora de public, com identidade de serviço própria. Não sincronize um SQLite aberto por OneDrive e não coloque WAL em compartilhamento de rede. O projeto pode estar em pasta sincronizada; o banco operacional deve usar armazenamento local controlado. Defina proteção de volume, cópia externa, retenção e responsáveis conforme a avaliação institucional.

## Instalação e atualização do esquema

1. Identifique a versão do código e execute suas verificações em ambiente separado.
2. Configure .env a partir do exemplo, com APP_ENV=production e os caminhos institucionais. Não publique esse arquivo.
3. Gere o keyring em diretório privado externo ao projeto com bin/security.php keygen. Guarde cópia independente em cofre; não inclua a chave no pacote de backup.
4. Para atualização, pare HTTP, notificações e manutenção agendada. Confirme espaço livre e recuperação do estado anterior.
5. Faça cópia conjunta do banco e dos PDFs do mesmo ponto de recuperação. Não copie somente o arquivo SQLite aberto, ignorando WAL/SHM.
6. Se o banco estiver em WAL e a biblioteca não tiver a correção, prepare primeiro `php bin/init-db.php --journal-only --maintenance-confirmed`, ainda com todos os escritores parados e backup recuperável. O comando confere o modo retornado, integridade/FKs e não aplica migrações nem altera cadastros; informa a versão armazenada do esquema. Para base já protegida, execute `php bin/init-db.php` na manutenção. Para banco anterior à v17, depois desse preparo siga a simulação e a migração explícita de SECURITY-OPERATIONS.md. Não substitua a base por um banco vazio.
7. Confirme PRAGMA user_version=22, schema_migrations de 1 a 22, integrity_check=ok e foreign_key_check sem linhas. Valide amostras e contagens com o estado anterior.
8. Confira o modo informado: DELETE é obrigatório no SQLite 3.45.1 do Ubuntu. Reabra o serviço somente depois da conferência. HTTP e CLIs de rotina verificam o modo sem modificá-lo; mudança de journal é reservada à inicialização/migração CLI em manutenção. HTTP também verifica o esquema sem aplicar migrações; start-server.ps1 não migra ao iniciar.

`DB_JOURNAL_MODE=auto` é o padrão: escolhe WAL corrigido ou DELETE. É possível definir `delete` para usar rollback journal também nas versões corrigidas. Não se aceita forçar WAL. DELETE preserva transações e rollback, mas leituras e escritas têm menor concorrência; chamadas aguardam o busy_timeout de cinco segundos e operações transacionais mantêm suas verificações. Não use configurações OFF/MEMORY em bancos persistentes e não apague journals/sidecars manualmente. O flag `--maintenance-confirmed` não para os serviços: o operador precisa pará-los antes.

## Backup conjunto

O botão da interface gera backup protegido somente do banco. Para a recuperação completa, mantenha também os PDFs privados, a versão compatível do código/configuração e uma cópia recuperável das chaves em cofre separado. Estabeleça frequência, retenção externa, perda máxima tolerável e prazo de recuperação com a escola.

Pare escritores antes de obter o conjunto consistente banco/PDF. Guarde um manifesto privado com data, versão do código, esquema, quantidade de documentos e hashes do conjunto. O snapshot SQLite da aplicação inclui os commits no WAL e é validado; ele não inclui automaticamente os PDFs. Cópias em claro anteriores continuam exigindo inventário e proteção específica.

## Ensaio de restauração em destino novo

1. Escolha destino privado novo em outra instalação; preserve o serviço e os backups originais.
2. Disponibilize a chave correta por mecanismo privado. Nunca envie o keyring em tickets, logs ou no mesmo pacote da cópia.
3. Use `php bin/security.php restore ORIGEM_PROTEGIDA DESTINO_NOVO` para restaurar o SQLite. O comando não deve sobrescrever um banco existente.
4. Restaure o diretório de PDFs do mesmo ponto de recuperação e use código compatível. Evite apontar o ensaio ao armazenamento de produção.
5. Execute `php bin/preflight.php --database=DESTINO_NOVO --storage=DIRETORIO_PDFS --json`. O comando abre em modo somente leitura, confere esquema/integridade/FKs e tamanho/hash dos PDFs privados referenciados, incluindo históricos. Referências legadas ainda sem PDF privado também reprovam: recupere os documentos e conclua a migração para armazenamento privado antes do aceite. O verificador não abre caminhos arbitrários do legado. Sem um diretório fornecido, um banco que referencia PDFs privados reprova.
6. Em homologação, configure o banco restaurado e a chave correta, entre com conta de teste autorizada e confira vínculos, documentos, estoque, histórico e relatórios. A ferramenta de integridade não comprova por si só que a chave institucional é recuperável.
7. Registre duração, ponto recuperado, verificações, operador e resultado. Somente esse ensaio comprova o prazo operacional. Falha de chave, documento ausente ou integridade divergente impede o aceite.

## Agendamento e observabilidade

Agende bin/maintenance.php diariamente e monitore código de saída. Configure as notificações DVA/certidões com SMTP institucional protegido e destinatários aprovados; habilite as flags somente após ensaio. Confirme execução diária, erro de transporte, retentativas e ausência de duplicação diária em condições normais. Não execute testes enviando mensagens para destinatários reais sem uma tarefa institucional específica.

Monitore erros HTTP, database_busy, falhas de backup/limpeza, espaço livre, tarefas atrasadas e falhas SMTP. O logger registra identificador de requisição e cadeia estrutural de causas; não registra mensagens arbitrárias, argumentos ou formulário. Defina responsável e ação para cada alerta. Conserve auditoria e logs conforme retenção aprovada.

## Checklist de liberação

- [ ] Runtime e dependências aprovados no servidor efetivo.
- [ ] HTTPS/cookies/headers, proxy e host conferidos.
- [ ] .env, banco, sidecars, logs, documentos, backups e chaves inacessíveis por HTTP.
- [ ] Permissões/ACL e proteção do volume verificadas.
- [ ] Migração exclusiva até 22 e verificações de integridade concluídas.
- [ ] Restauração conjunta e custódia separada de chaves demonstradas.
- [ ] SMTP e tarefas programadas testados em homologação.
- [ ] Rotinas de entrada/saída de funcionários, recuperação de conta e exportações definidas.
- [ ] Roteiro de usabilidade aplicado; problemas críticos corrigidos.
- [ ] Aceite assinado com versão do código, data e responsável.

A aprovação dos testes automatizados do repositório não marca automaticamente os itens acima.
