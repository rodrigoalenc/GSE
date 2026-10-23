# Operação da proteção v17

A proteção de campos e backups foi introduzida na v17. O esquema atual é v21; as garantias e verificações correntes estão em Documentação/VALIDACAO-2026-10-22.md. Não há homologação implícita do servidor institucional.

## Escopo e inventário

| Local | Tratamento e limite |
|---|---|
| `usuarios.nome`, `usuarios.email` | AEAD XChaCha20-Poly1305; versão, identificação de chave, nonce aleatório de 24 bytes; contexto autenticado inclui campo e ID |
| `usuarios.email_lookup` | HMAC-SHA256 do e-mail em minúsculas/trim, com chave separada da criptografia; índice UNIQUE; sem coluna alternativa em claro |
| `usuarios.senha` | Hash Argon2id obrigatório para novas definições; não é criptografia reversível |
| Índices antigos de nome/e-mail | Indexam ciphertext após a migração; não permitem busca pessoal em claro |
| `login_attempts` | HMAC com domínio separado sobre os identificadores anteriores; v17 preserva contagens, bloqueios e datas ao converter as chaves |
| Orçamentos e recibos | HMAC para identificadores e conteúdo; recibos persistem usuário, operação, token aleatório e ID do resultado; não copiam formulário em claro |
| Auditoria de segurança | IDs, IP, descrição operacional fixa, data e request ID continuam legíveis; alterações de usuário não registram nome/e-mail ou senhas |
| Tabela `logs` legada | Pode conter identificação e detalhes pessoais históricos em claro; não é reescrita silenciosamente. Não recebe novas gravações dos fluxos atuais de usuários. Exige inventário, ACL e proteção do volume, além dos backups criptografados |
| Logs técnicos | Contexto sensível é removido; mensagens de exceções não são registradas pelo handler; hashes, envelopes e e-mails são redigidos pelo logger |
| Sessões PHP e rascunhos | Contêm nome de exibição e dados temporários do formulário em claro no armazenamento privado de sessão; exigem ACL, expiração e proteção do volume |
| Alunos, responsáveis, telefones, nascimento, passivo, observações e nomes normalizados | Permanecem fora da criptografia de campos desta etapa: sustentam busca compartilhada, relatórios e histórico. Acesso autenticado e permissões privadas continuam obrigatórios. São dados pessoais, inclusive de menores; a criptografia do volume é uma camada operacional adicional necessária conforme avaliação institucional |
| PDFs, CSV, impressões, relatórios e e-mails entregues | Conteúdo pessoal em claro para destinatários autorizados. PDFs privados e exportações não integram automaticamente o backup do banco; mantenha proteção e retenção próprias |
| Backups novos, inclusive preventivos | Secretstream XChaCha20-Poly1305, formato `GSEB` versão 1, chave identificada no cabeçalho, blocos limitados a 1 MiB, metadados autenticados e quadro final obrigatório; rejeita adulteração, truncamento e bytes extras |
| Backups anteriores em claro | Preservados no disco, ocultos da listagem e recusados pelo download; proteção CLI explícita disponível |

A camada não cifra todo o arquivo SQLite. IDs, relações, contagens, estrutura, perfis, datas e metadados permanecem observáveis. Ela protege contra leitura dos campos abrangidos e dos backups sem a chave; não protege contra comprometimento do processo autorizado ou do usuário que já pode exportar dados. Um HMAC ainda revela igualdade e frequência dentro de seu domínio. Quem obtém a chave de índice pode testar candidatos; por isso ela recebe o mesmo cuidado operacional das chaves de criptografia.

Busca exata de e-mail usa índice HMAC. A pesquisa parcial e a ordenação de usuários descriptografam no servidor após autorização administrativa, retornam 50 registros por página e limitam a navegação a 100 páginas por filtro. Refine o filtro acima de 5.000 resultados. O processamento ocorre no SQLite, sem carregar todos os registros no PHP; `temp_store=MEMORY` evita gravar a ordenação pessoal em arquivos temporários. O custo de varredura cresce com o total de contas, e não há índice de busca parcial. O teste de desempenho usa 1.006 contas sintéticas, incluindo paginação e unicidade; volumes institucionais maiores exigem medição própria.

## Chaves e inicialização

Exige PHP de 64 bits corrigido (8.3.36+, 8.4.27+ ou 8.5.12+ na respectiva linha), SQLite carregado pelo PDO com correção WAL, sodium, intl, mbstring, pdo_sqlite e as demais extensões do Composer, na CLI e no PHP-FPM. Confirme `php bin/runtime-check.php` e `composer check-platform-reqs`; no Windows, use o runtime privado de `bin/gse.ps1`. Não altere o código para aceitar chaves ausentes. O HTTP falha de forma controlada quando chave ou ciphertext não pode ser aberto.

Também é aceito o backport oficial do PHP 8.3.6 no Ubuntu 24.04 quando a revisão instalada e o binário carregado são comprovados conforme [compatibilidade Ubuntu](Documentação/COMPATIBILIDADE-UBUNTU.md). Isso não dispensa o requisito SQLite nem autoriza ignorar verificações de plataforma.

1. Crie um diretório privado fora do repositório, de `public`, do banco e dos pacotes de backup. Em Linux, use diretório `0700` e keyring `0600`, pertencentes à identidade que precisa lê-los. Em Windows, restrinja a ACL a essa identidade e ao administrador responsável; os testes locais não homologam essa ACL.
2. Execute `php bin/security.php keygen /caminho/privado/keys.json`. O destino precisa ser novo. Chaves aleatórias são geradas dentro do processo, sem recebê-las por argumento ou imprimi-las.
3. Configure `GSE_KEYRING_PATH` com esse caminho absoluto. Exemplos `.env` não contêm chaves reais. Proteja o `.env` por permissões do SO e mantenha a raiz web em `public`.
4. Guarde uma cópia recuperável do keyring em cofre independente dos backups. Perder as chaves torna os dados protegidos irrecuperáveis. Não envie keyrings em logs, screenshots, tickets, artefatos ou pacotes de CI.
5. Bancos novos vazios podem ser criados com `php bin/init-db.php`; depois use a criação inicial de administrador já existente. Bancos com usuários anteriores à v17 exigem o procedimento explícito abaixo.

O JSON privado tem `version: 1`, `active`, mapa `keys` com identificações e chaves de 32 bytes codificadas em base64 e `index_key` independente, também de 32 bytes. Base64 é apenas o transporte das chaves e do envelope. Não edite esses valores manualmente para “consertar” um erro. Symlinks no caminho do keyring são recusados. Permissões POSIX são verificadas pelo código; Windows depende da ACL do SO.

## Migração e simulação

Não execute este procedimento sobre dados reais sem autorização específica e uma janela de manutenção. Ensaios desta revisão usam bancos sintéticos.

1. Faça inventário de banco, anexos e versão do código. Garanta espaço para snapshot, WAL e reconstrução. Guarde também uma cópia protegida prévia em local independente.
2. Configure o keyring e execute `php bin/security.php preflight`. A simulação usa snapshot consistente por `VACUUM INTO` em diretório temporário privado, aplica migrações na cópia, verifica integridade e FKs e remove os temporários em `finally`. Não registra v17 no original.
3. Pare HTTP, workers SMTP, manutenção agendada e todos os escritores. O flag CLI é uma confirmação operacional; não desliga serviços automaticamente.
4. Execute `php bin/security.php migrate --maintenance-confirmed`. O mecanismo existente aplica versões ausentes; a versão 17 cifra os campos, normaliza unicidade, converte chaves do limitador sem apagar seu orçamento e cria orçamentos/recibos numa transação `BEGIN IMMEDIATE`.
5. Ausência de chave, dado inválido, colisão ou falha de gravação impede o commit da v17. IDs, hashes, vínculos, sequências, exclusões lógicas e histórico são preservados. Migrações anteriores mantêm suas transações individuais, conforme o mecanismo existente.
6. O comando exige checkpoint `TRUNCATE` sem escritor concorrente, reconstrói por `VACUUM` com `secure_delete`, faz novo checkpoint e verifica `integrity_check`/`foreign_key_check`. Se falhar após o commit da v17, mantenha a manutenção e repita o comando; ele é reexecutável. A versão do schema não é, sozinha, comprovação de limpeza de resíduos.
7. Restaure o backup protegido num destino descartável, compare dados abrangidos, IDs, hashes, relações e contagens; inicie a aplicação com esse banco e a chave correta antes de reabrir o original.

O snapshot preventivo também é criptografado. Um snapshot antigo restaurado mantém seu schema anterior e precisa de migração explícita antes de abrir o HTTP. Os resíduos de páginas livres, WAL e journals anteriores, snapshots do SO, arquivos já copiados e retenções externas precisam ser tratados no inventário. `VACUUM`, checkpoint e `unlink` não garantem apagamento físico de SSD. Não destrua cópias legadas ou sua única chave automaticamente.

## Limpeza verificada dos snapshots

Backups manuais e preventivos usam um diretório exclusivo `backups/.snapshot-<operation>` (`0700` em POSIX), com `snapshot.sqlite` e recibo `identity.json` privados (`0600`). Windows exige ACL restrita herdada do armazenamento privado; `chmod` não substitui essa ACL. A reserva exclusiva e a identidade do arquivo/diretório limitam a limpeza à operação atual. A aplicação confere a ausência do arquivo após a tentativa de exclusão, inclusive quando ele já desapareceu, e não remove um caminho cuja identidade mudou.

Falha de snapshot, criptografia ou gravação registra `backup_operation_failed`, com `phase=snapshot`, `encryption` ou `write`. Falha de limpeza registra `backup_plaintext_cleanup_failed`, com identificador aleatório `operation`, fase, classe da exceção e `encrypted=yes/no`. Mensagens de exceções, caminhos privados, dados pessoais, chaves e tokens não são copiados para esses eventos. A CLI de manutenção também configura o log técnico. Uma exceção no logger não interrompe a tentativa de limpeza nem substitui a exceção original; há fallback mínimo para o log do runtime, cuja persistência também depende do armazenamento disponível. Verifique esse armazenamento e monitore esses eventos.

Uma falha de limpeza impede o retorno de sucesso completo: o backup manual retorna falha e não registra `backup.created`; a criação preventiva lança uma exceção antes das migrações. Se também houve falha de operação, a exceção original é preservada e a falha de limpeza fica registrada separadamente. Um backup criptografado concluído é preservado, mesmo que a limpeza falhe; pode aparecer na listagem de backups protegidos, enquanto o temporário e seu recibo nunca são disponibilizados por ela ou por download HTTP. Na simulação `preflight`, a existência de um diretório residual também impede a remoção do backup protegido e da cópia de trabalho daquela simulação.

Procedimento para uma pendência, somente no servidor por um operador autorizado:

1. Mantenha HTTP, workers e tarefas parados durante a conferência. Preserve backups anteriores e o keyring independente. Restrinja a ACL/permissões do armazenamento; não use permissões públicas para contornar um lock ou erro de acesso.
2. Obtenha o identificador hexadecimal de 32 caracteres do evento técnico. Procure exclusivamente o diretório exato `.snapshot-<operation>` em `dirname(DB_PATH)/backups`. Para `preflight`, confira também os diretórios privados `gse-preflight-*` no temporário do SO, dentro de `backups`. Não publique caminhos, recibos ou snapshots em tickets, telas ou HTTP.
3. Resolva os caminhos canônicos e recuse links simbólicos em qualquer componente. Compare `operation` do recibo com o nome do diretório e compare `lstat` do diretório e de `snapshot.sqlite` com os campos `dev`, `ino`, `uid`, `mode` e `nlink` registrados em `directory` e `file`. Tamanho e datas da reserva não identificam o snapshot final, pois o SQLite escreve depois. Havendo divergência, recibo ausente/incompleto, ACL inesperada ou arquivos alheios, interrompa a remoção e faça inventário privado; o nome sozinho não comprova propriedade.
4. O campo `backup` do recibo é somente o nome do destino planejado no diretório pai, não prova que a criptografia terminou. Quando o arquivo protegido existir, valide-o por restauração em destino privado novo com `php bin/security.php restore ORIGEM_PROTEGIDA DESTINO_NOVO` e confira integridade e dados antes de remover o resíduo. Preserve o arquivo criptografado. Sem backup protegido válido, confirme uma fonte original recuperável e uma cópia protegida verificada; nunca elimine a única cópia recuperável.
5. Resolva a causa (permissão, lock, espaço ou armazenamento). Com a operação encerrada e as identidades novamente conferidas, remova somente o caminho exato `snapshot.sqlite` dessa operação e confirme sua ausência por nova consulta ao filesystem. Depois remova somente seu recibo e o diretório se estiver vazio. Não use limpeza recursiva nem exclusão por curingas; não remova arquivos inesperados ou backups anteriores. Se o snapshot já não existir, não recrie nem substitua o arquivo. Confira separadamente a cópia de trabalho retida por `preflight` antes de removê-la, preservando o backup protegido.
6. Registre a resolução operacional sem dados pessoais ou segredos e ensaie novamente o backup/restauração antes de reabrir o serviço. Exclusão lógica de arquivo não comprova apagamento físico de SSD, snapshots do volume, cópias externas ou retenções do SO.

## Rotação, proteção de legado e restauração

Com todos os escritores parados:

```sh
php bin/security.php rotate-key --maintenance-confirmed
php bin/security.php rotate-data --maintenance-confirmed
php bin/security.php restore /backup/protegido.db.gse /diretorio/privado/novo.sqlite
php bin/security.php protect-legacy /backup/antigo.db /backup/antigo.db.gse
```

`rotate-key` usa lock privado estável, escrita exclusiva privada, sincronização e substituição atômica do keyring. Acrescenta nova chave ativa e preserva todas as anteriores. Não remova o arquivo `.rotation.lock` enquanto houver operação. `rotate-data` reabre e cifra novamente os campos na mesma transação, preserva IDs/hash/HMAC e executa a reconstrução controlada. Faça uma nova cópia independente do keyring após a rotação. Backups anteriores continuam abrindo com sua chave retida. A chave de índice fica estável nesta rotação; trocá-la exigiria migração coordenada de índices, contadores e recibos e não é suportado como edição manual do JSON.

`restore` exige um arquivo novo em diretório privado, nunca sobrescreve o banco existente, valida o término autenticado do stream, a integridade SQLite, FKs e os campos protegidos das versões novas. Em falha, remove somente o destino que criou. O serviço precisa continuar parado até comparação e teste de reinício. Para usar o resultado, configure explicitamente outro `DB_PATH`; este comando não troca o banco da aplicação.

O backup administrativo inclui somente SQLite. Para recuperação conjunta, pare todos os escritores, preserve também PDFs privados e versão compatível do código em pacote externo protegido e verifique hashes dos anexos na restauração. A suíte mantém esse ensaio conjunto com PDFs sintéticos; o comando de backup do banco não promete incluir esses arquivos.

`protect-legacy` cria uma cópia autenticada sem remover o original. Restaure a cópia num destino descartável e valide antes de decidir retenção/remoção do original. Não basta renomear `.db` para `.db.gse`. A aplicação valida o stream completo antes de autorizar bytes do download.

## Autorização, submissões e limites

As únicas rotas públicas são GET da apresentação/login e POST de credenciais. Todos os POST exigem CSRF, inclusive login. IDs de recursos são validados na rota e seus vínculos no model/controlador. Os registros escolares são compartilhados conforme perfil, sem regra artificial de “somente criador”. SQLite não fornece RLS nativa: a autorização é feita no backend.

Sessões têm conta ativa, perfil e versão reconsultados. `BEGIN IMMEDIATE` revalida a identidade HTTP sob o lock de escrita antes de operações transacionais, evitando gravação com sessão revogada entre a checagem da rota e o commit. Alterações intencionais da própria versão continuam permitidas dentro dessa operação. A proteção do último administrador, CSRF, auditoria obrigatória e exclusões lógicas permanecem.

Novas senhas: 15–128 caracteres Unicode, sem composição arbitrária, sem trim/truncamento. Login e rehash continuam aceitando credenciais legadas válidas, inclusive 12 caracteres; bcrypt recusa entrada acima de 72 bytes. Falhas Argon2id preservam o hash antigo no rehash oportunista e impedem novas definições. O navegador conta pontos de código, incluindo caracteres fora do BMP; o servidor permanece a autoridade.

| Orçamento | Padrão | Persistência e resposta |
|---|---|---|
| Login | 5 falhas/conta, 40/IP; janela e bloqueio de 900 s | Conta normalizada e IP independentes; sucesso não apaga orçamento do IP; política anterior preservada |
| Confirmação | `CONFIRM_MAX_ATTEMPTS=10`, por usuário/finalidade/300 s | Reserva antes do hash, transação curta; finalidades perfil, senha e backup independentes; HTTP 429 genérico e `Retry-After` |
| IP em confirmações | `CONFIRM_IP_MAX_ATTEMPTS=80`/300 s | Compartilhado entre finalidades; usa IP calculado com proxies confiáveis explícitos |
| Operações caras | `EXPENSIVE_MAX_ATTEMPTS=60`/usuário/rota/300 s | Exports, relatórios CSV/PDF, impressão, download de PDF/backup, prévias e upload/edição/renovação de certidão; 429 e `Retry-After` |

Configure as janelas com `CONFIRM_WINDOW_SECONDS` e `EXPENSIVE_WINDOW_SECONDS`. Contadores SQLite são atômicos, sobrevivem ao processo e expiram; não há reset por sucesso nas confirmações. Monitore rede compartilhada e volume escolar antes de ajustar os padrões. O limite por requisição complementa os limites de arquivos/linhas, não os substitui.

O frontend bloqueia repetição após validação e confirmação, anuncia processamento, preserva submitter/name/value/formaction e libera bfcache; downloads recebem guarda curta de 3 s. A proteção real de criação de contrato, aluno (inclusive duplicidade confirmada) e registro passivo usa recibo por usuário/operação/token e HMAC do conteúdo normalizado, transação e chave primária composta. Replay retorna o mesmo ID sem nova auditoria; conteúdo divergente é recusado; rollback não consome o recibo. Sem JavaScript, o campo oculto continua sendo enviado. Tokens de estoque, revisões, unicidade de localização, DVA vigente, certidão vigente/renovação e importações confirmadas continuam protegendo outros efeitos. Não há promessa de entrega SMTP exatamente uma vez após queda durante envio.

Formulários de download usam `data-submit-download="true"`; botões podem sobrescrever a indicação, como `data-submit-download="false"` em **Atualizar prévia**. O relatório principal usa a seleção PDF/CSV quando não há `formaction` explícito; botões com esse atributo conservam sua ação. A marcação cobre também o TXT do Arquivo Passivo. A compatibilidade com formulários antigos conserva o reconhecimento de caminhos de download e destino `_blank`. Depois de 3 s, a guarda libera novos envios e remove `aria-busy` e **Processando…**; isso não atesta a conclusão do download. `pageshow` também limpa o estado e cancela o timer anterior. Não há AJAX nem alteração dos tokens ou da validação no servidor.

## Servidor e checklist

Exemplo Nginx: [config/nginx-gse.conf](config/nginx-gse.conf). Adapte host, TLS, socket e diretórios, valide `nginx -t` e confirme que a raiz é `public`. Os bloqueios incluem arquivos estáticos sensíveis e uploads legados; `.gitignore` não controla acesso HTTP. HTTP institucional, PHP-FPM, ACL, SMTP e agendamentos não foram acessados nesta revisão.

| Item | Implementação / verificação pendente |
|---|---|
| `.env`, chaves, arquivos sensíveis | Fora da raiz web, exemplos sem segredo, keyring externo; conferir ACL e regras Nginx reais |
| Frontend/backend | Tipos seguros, limites, Unicode; testes HTTP e navegador; manter validação backend sem JS |
| SQL injection e XSS | Parâmetros preparados, listas fechadas e escape por contexto; persistência autenticada não elimina necessidade de escape |
| Autenticação e IDOR | Rotas explícitas, perfis, versão de sessão, vínculos pai/filho, último administrador e auditoria; matriz abaixo |
| Senhas e força bruta | Argon2id, compatibilidade legada delimitada, contadores persistidos independentes e confirmações limitadas |
| Envio, CSRF e tokens | Guarda frontend, recibos backend, CSRF com tipo/formato, tokens de confirmação e revisão existentes |
| Uploads | PDF privado com origem, MIME/estrutura/tamanho/caminho; CSV de até 2 MiB e 5.000 linhas; não substitui antimalware |
| Informações/logs | Respostas genéricas, campos de senha vazios, redatores e diagnósticos sintéticos; retenção de dados legados requer inventário |
| Dependências | Auditoria Composer travada, sodium exigido; componentes frontend abaixo não são cobertos pelo Composer |
| Cookies/proxy | HttpOnly, SameSite=Lax, Secure sob HTTPS confiável, rotação/expiração/invalidação preservadas; conferir HTTPS e proxy reais |
| SSRF e PDF | Dompdf remoto/PHP desativados, chroot em assets e protocolo `data://`; nenhuma nova URL fornecida por usuário |
| CORS | Mesma origem, sem wildcard de credenciais; testar headers no servidor institucional |

Bibliotecas locais: Bootstrap 5.3.8 (bundle JavaScript inclui Popper) e fontes Font Awesome Free 6.4.0, identificadas pelos arquivos distribuídos e READMEs. A página oficial de [avisos Bootstrap](https://github.com/twbs/bootstrap/security/advisories) consultada não publicou avisos; isso não prova ausência de vulnerabilidades. Não houve evidência que justificasse atualizar recursos visuais nesta revisão. Font Awesome usado aqui contém fontes locais, sem runtime de JavaScript do pacote npm. Continue acompanhando os avisos oficiais e auditando versões travadas.

Roteiro para uma URL expressamente autorizada: verificar redirecionamento HTTP→HTTPS sem loop; certificado/TLS; `Set-Cookie` Secure/HttpOnly/SameSite; CSP/HSTS/nosniff/no-store; rejeição de Host e proxy forjados; ausência de CORS aberto; GET e HEAD de `.env`, `.git/config`, código, banco/`-wal`/`-shm`, logs, chaves, backups e uploads legados retornando 403/404 sem bytes sensíveis; downloads permitidos somente após autenticação/autorização. Não execute essas sondagens contra servidor sem autorização. Configuração de exemplo e ensaio local não equivalem à homologação de produção.

Referências das primitivas: [PHP AEAD XChaCha20-Poly1305](https://www.php.net/manual/en/function.sodium-crypto-aead-xchacha20poly1305-ietf-encrypt.php), [PHP secretstream](https://www.php.net/manual/en/function.sodium-crypto-secretstream-xchacha20poly1305-init-push.php), [OWASP Authentication](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html).

## Matriz de rotas

Todas as operações internas exigem conta ativa e sessão vigente. Conta com senha temporária acessa somente troca de senha e logout. “Compartilhado” designa os registros escolares permitidos ao perfil; relacionamentos de folha/produto/contrato, PDF/certidão, aluno/DVA e arquivo de backup são conferidos no backend. Ações administrativas condicionais (estorno, remoção de faturamento e restauração) continuam exigindo administrador mesmo quando a rota também atende outras ações de funcionário.

| Método | Caminho | Controlador | Perfil | Recurso | CSRF | Limite |
|---|---|---|---|---|---|---|
| GET | `/` | `LoginController::index` | Público | Credenciais | — | — |
| GET | `/login` | `LoginController::index` | Público | Credenciais | — | — |
| POST | `/login/entrar` | `LoginController::entrar` | Público | Credenciais | Sim | Login (conta/IP) |
| POST | `/login/sair` | `LoginController::sair` | Funcionário / administrador | Própria conta | Sim | — |
| GET | `/dashboard` | `DashboardController::index` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| GET | `/contrato` | `ContratoController::index` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| GET | `/estoque` | `EstoqueController::index` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| GET | `/contrato/criar` | `ContratoController::criar` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| POST | `/contrato/criar` | `ContratoController::criar` | Funcionário / administrador | Compartilhado; regra do recurso | Sim | — |
| GET | `/contrato/detalhes/{id}` | `ContratoController::detalhes` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| GET | `/contrato/editar/{id}` | `ContratoController::editar` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| POST | `/contrato/editar/{id}` | `ContratoController::editar` | Funcionário / administrador | Compartilhado; regra do recurso | Sim | — |
| POST | `/contrato/folha/{id}` | `ContratoController::folha` | Funcionário / administrador | Compartilhado; regra do recurso | Sim | — |
| POST | `/contrato/editar-folha/{id}` | `ContratoController::editarFolha` | Funcionário / administrador | Compartilhado; regra do recurso | Sim | — |
| POST | `/contrato/faturar/{id}` | `ContratoController::faturar` | Funcionário / administrador | Compartilhado; regra do recurso | Sim | — |
| POST | `/contrato/produto/{id}` | `ContratoController::produto` | Funcionário / administrador | Compartilhado; regra do recurso | Sim | — |
| POST | `/contrato/estoque/{id}` | `ContratoController::estoque` | Funcionário / administrador | Compartilhado; regra do recurso | Sim | — |
| POST | `/contrato/conferir-abertura/{id}` | `ContratoController::conferirAbertura` | Administrador | Compartilhado; regra do recurso | Sim | — |
| POST | `/contrato/movimentar/{id}` | `ContratoController::movimentar` | Funcionário / administrador | Compartilhado; regra do recurso | Sim | — |
| POST | `/contrato/excluir/{id}` | `ContratoController::excluir` | Funcionário / administrador | Compartilhado; regra do recurso | Sim | — |
| POST | `/contrato/conciliar/{id}` | `ContratoController::conciliar` | Administrador | Compartilhado; regra do recurso | Sim | — |
| GET | `/contrato/historico/{id}` | `ContratoController::historico` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| GET | `/contrato/imprimir/{id}` | `ContratoController::imprimir` | Funcionário / administrador | Compartilhado; regra do recurso | — | Custo |
| GET | `/relatorio` | `RelatorioController::index` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| GET | `/relatorio/csv` | `RelatorioController::csv` | Funcionário / administrador | Compartilhado; regra do recurso | — | Custo |
| GET | `/relatorio/pdf` | `RelatorioController::pdf` | Funcionário / administrador | Compartilhado; regra do recurso | — | Custo |
| GET | `/certidao` | `CertidaoController::index` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| GET | `/certidao/cadastrar` | `CertidaoController::cadastrar` | Funcionário / administrador | Compartilhado; regra do recurso | — | Custo |
| POST | `/certidao/cadastrar` | `CertidaoController::cadastrar` | Funcionário / administrador | Compartilhado; regra do recurso | Sim | Custo |
| GET | `/certidao/detalhes/{id}` | `CertidaoController::detalhes` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| GET | `/certidao/pdf/{id}` | `CertidaoController::pdf` | Funcionário / administrador | Compartilhado; regra do recurso | — | Custo |
| GET | `/certidao/editar/{id}` | `CertidaoController::editar` | Funcionário / administrador | Compartilhado; regra do recurso | — | Custo |
| POST | `/certidao/editar/{id}` | `CertidaoController::editar` | Funcionário / administrador | Compartilhado; regra do recurso | Sim | Custo |
| GET | `/certidao/renovar/{id}` | `CertidaoController::renovar` | Funcionário / administrador | Compartilhado; regra do recurso | — | Custo |
| POST | `/certidao/renovar/{id}` | `CertidaoController::renovar` | Funcionário / administrador | Compartilhado; regra do recurso | Sim | Custo |
| POST | `/certidao/arquivar/{id}` | `CertidaoController::arquivar` | Funcionário / administrador | Compartilhado; regra do recurso | Sim | — |
| POST | `/certidao/desarquivar/{id}` | `CertidaoController::desarquivar` | Funcionário / administrador | Compartilhado; regra do recurso | Sim | — |
| POST | `/certidao/excluir/{id}` | `CertidaoController::excluir` | Funcionário / administrador | Compartilhado; regra do recurso | Sim | — |
| GET | `/certidao/arquivadas` | `CertidaoController::arquivadas` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| GET | `/certidao/excluidas` | `CertidaoController::excluidas` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| GET | `/certidao/configurar` | `CertidaoController::configurar` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| POST | `/certidao/configurar` | `CertidaoController::configurar` | Funcionário / administrador | Compartilhado; regra do recurso | Sim | — |
| GET | `/usuario/perfil` | `PerfilController::editar` | Funcionário / administrador | Própria conta | — | — |
| POST | `/usuario/perfil` | `PerfilController::editar` | Funcionário / administrador | Própria conta | Sim | Confirmação |
| GET | `/usuario` | `UsuarioController::index` | Administrador | Compartilhado; regra do recurso | — | — |
| GET | `/usuario/criar` | `UsuarioController::criar` | Administrador | Compartilhado; regra do recurso | — | — |
| POST | `/usuario/criar` | `UsuarioController::criar` | Administrador | Compartilhado; regra do recurso | Sim | — |
| GET | `/usuario/editar/{id}` | `UsuarioController::editar` | Administrador | Compartilhado; regra do recurso | — | — |
| POST | `/usuario/editar/{id}` | `UsuarioController::editar` | Administrador | Compartilhado; regra do recurso | Sim | — |
| POST | `/usuario/status/{id}` | `UsuarioController::status` | Administrador | Compartilhado; regra do recurso | Sim | — |
| GET | `/senha/alterar` | `SenhaController::alterar` | Funcionário / administrador | Própria conta | — | — |
| POST | `/senha/alterar` | `SenhaController::alterar` | Funcionário / administrador | Própria conta | Sim | Confirmação |
| GET | `/auditoria` | `AuditoriaController::index` | Administrador | Compartilhado; regra do recurso | — | — |
| GET | `/backup` | `BackupController::index` | Administrador | Compartilhado; regra do recurso | — | — |
| POST | `/backup/criar` | `BackupController::criar` | Administrador | Compartilhado; regra do recurso | Sim | Confirmação + custo |
| GET | `/backup/baixar/{nome}` | `BackupController::baixar` | Administrador | Compartilhado; regra do recurso | — | Custo |
| GET | `/aluno` | `AlunoController::index` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| GET | `/aluno/criar` | `AlunoController::criar` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| POST | `/aluno/criar` | `AlunoController::criar` | Funcionário / administrador | Compartilhado; regra do recurso | Sim | — |
| GET | `/aluno/perfil/{id}` | `AlunoController::perfil` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| GET | `/aluno/editar/{id}` | `AlunoController::editar` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| POST | `/aluno/editar/{id}` | `AlunoController::editar` | Funcionário / administrador | Compartilhado; regra do recurso | Sim | — |
| POST | `/aluno/status/{id}` | `AlunoController::status` | Administrador | Compartilhado; regra do recurso | Sim | — |
| GET | `/aluno/dva/{id}` | `AlunoController::dva` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| POST | `/aluno/dva/{id}` | `AlunoController::dva` | Funcionário / administrador | Compartilhado; regra do recurso | Sim | — |
| GET | `/dva` | `AlunoController::painelDva` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| GET | `/passivo` | `PassivoController::index` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| GET | `/passivo/criar` | `PassivoController::criar` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| POST | `/passivo/criar` | `PassivoController::criar` | Funcionário / administrador | Compartilhado; regra do recurso | Sim | — |
| GET | `/passivo/detalhes/{id}` | `PassivoController::detalhes` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| GET | `/passivo/editar/{id}` | `PassivoController::editar` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| POST | `/passivo/editar/{id}` | `PassivoController::editar` | Funcionário / administrador | Compartilhado; regra do recurso | Sim | — |
| POST | `/passivo/excluir/{id}` | `PassivoController::excluir` | Funcionário / administrador | Compartilhado; regra do recurso | Sim | — |
| POST | `/passivo/status/{id}` | `PassivoController::status` | Administrador | Compartilhado; regra do recurso | Sim | — |
| GET | `/passivo/inativos` | `PassivoController::inativos` | Funcionário / administrador | Compartilhado; regra do recurso | — | — |
| GET | `/passivo/importar` | `PassivoController::importar` | Administrador | Compartilhado; regra do recurso | — | — |
| POST | `/passivo/importar/preview` | `PassivoController::previewImport` | Administrador | Compartilhado; regra do recurso | Sim | Custo |
| POST | `/passivo/importar/confirmar` | `PassivoController::confirmImport` | Administrador | Compartilhado; regra do recurso | Sim | — |
| POST | `/passivo/exportar` | `PassivoController::exportar` | Funcionário / administrador | Compartilhado; regra do recurso | Sim | Custo |
| POST | `/aluno/arquivar-lote/selecionar` | `PassivoController::selecionarLote` | Administrador | Compartilhado; regra do recurso | Sim | — |
| GET | `/aluno/arquivar-lote` | `PassivoController::arquivarLote` | Administrador | Compartilhado; regra do recurso | — | — |
| POST | `/aluno/arquivar-lote/preview` | `PassivoController::previewLote` | Administrador | Compartilhado; regra do recurso | Sim | Custo |
| POST | `/aluno/arquivar-lote/confirmar` | `PassivoController::confirmarLote` | Administrador | Compartilhado; regra do recurso | Sim | — |
| GET | `/aluno/arquivar/{id}` | `PassivoController::arquivarAluno` | Administrador | Compartilhado; regra do recurso | — | — |
| POST | `/aluno/arquivar/{id}` | `PassivoController::arquivarAluno` | Administrador | Compartilhado; regra do recurso | Sim | — |
| GET | `/turma` | `TurmaController::index` | Administrador | Compartilhado; regra do recurso | — | — |
| GET | `/turma/criar` | `TurmaController::criar` | Administrador | Compartilhado; regra do recurso | — | — |
| POST | `/turma/criar` | `TurmaController::criar` | Administrador | Compartilhado; regra do recurso | Sim | — |
| GET | `/turma/editar/{id}` | `TurmaController::editar` | Administrador | Compartilhado; regra do recurso | — | — |
| POST | `/turma/editar/{id}` | `TurmaController::editar` | Administrador | Compartilhado; regra do recurso | Sim | — |
| POST | `/turma/status/{id}` | `TurmaController::status` | Administrador | Compartilhado; regra do recurso | Sim | — |
