# Módulo 4 — Certidões e Fornecedores

Implementado sobre `61930860554535af1915622b8eb273a155917c72` (Modulo3), continuando a branch existente `Modulo4`. Consulta remota realizada em 16/09/2026: ambas as branches apontavam para essa base. Não houve push, merge ou deploy.

## Fontes e limites da conclusão

A especificação de execução é `Prompt_Codex_Modulo4_GSE.md`, fornecida pelo usuário. O arquivo `TCC_2_ETAPA_1_MÓDULO_1 - Rodrigo-Calebe.pdf` não estava disponível pelo caminho/anexo indicado. As referências acadêmicas abaixo correspondem **às transcrições do prompt**, não a uma leitura ou autenticação do PDF. O PDF versionado `Documentação/Documentação GSE.pdf` não foi tratado como o mesmo documento. Nenhum TCC foi alterado.

Referência de implementação: [ProjetoGSE no commit consultado](https://github.com/rodrigoraa/ProjetoGSE/tree/f0bb641b2d1a074bddd598e52f3e733872d230db), especialmente controller, model, quatro formulários/consultas, matriz, CSS, script de PDF, cron e teste de certidões. A organização foi adaptada à arquitetura atual: azul `#16508f`, sidebar, resumo superior, ações rápidas, filtros, tipos nas linhas, fornecedores nas colunas e múltiplos cartões por célula. Não foram transplantados exclusão física, destinatários genéricos, acesso público ou código inline do legado.

**Não há declaração de conformidade acadêmica integral.** A exclusão lógica depende de validação acadêmica; comparação com as Figuras 16–18 do PDF integral e homologação visual desktop/celular continuam pendentes. O runtime de navegador respondeu `No browser is available`; a lista de navegadores estava vazia. Não foram produzidas screenshots nem alegada homologação por meio dos testes HTTP.

## Rastreabilidade

| Fonte transcrita | Natureza | Implementação | Evidência | Situação |
|---|---|---|---|---|
| Tabela 11, p. 28 / UC005 | Exigência | Rotas `certidao`, sidebar e dashboard | HTTP: matriz autenticada | Implementado |
| RF005, p. 15 | Exigência | `Certidao::saveOption`, configuração pesquisável, edição e inativação | Testes Unicode, histórico e permissões HTTP | Implementado |
| UC005 / Figura 6, p. 22 | Exigência | Cadastro com fornecedor, tipo, emissão, vencimento, observação e PDF | PHPUnit: campos inválidos; HTTP multipart | Implementado |
| UC005, p. 22 | Exigência | Detalhes, edição e download autenticado por ID | HTTP: escape, headers, preservação do PDF | Implementado |
| Entrevista, p. 14 | Exigência | `anterior_id`, renovação transacional e arquivo anterior preservado | `CertidaoTest`: multiplicidade, falha da auditoria, concorrência e reenvio | Implementado |
| UC005, p. 22 | Exigência | Arquivamento manual; filtros fornecedor, tipo e ano/todos | Testes de paginação, matriz e HTTP | Implementado |
| UC005: excluir | Exigência + interpretação | Exclusão lógica distinta de arquivar; sem purga ou restauração | Testes do ciclo de vida e funcionário HTTP | Implementado; interpretação acadêmica pendente |
| Objetivo específico, p. 13 | Exigência | Matriz paginada, resumo e indicadores textuais de validade | Testes HTTP de estrutura e filtros | Implementado; visual pendente |
| RF006, p. 15 | Exigência | `CertidaoNotificationService`, relatório diário para administradores ativos com e-mail válido | Transporte falso: conteúdo, falha parcial, repetição e concorrência | Código testado; SMTP/agendamento dependem da implantação |
| RF007 | Exigência | Auditoria obrigatória na mesma transação; autor, recurso, resultado e UTC | Rollback de cadastro/renovação/edição/arquivo/exclusão/configuração | Implementado |
| NF001–NF005 | Exigência | MVC PHP/JS/SQLite, Bootstrap local, CSS externo, sessão e senha existentes | PHPStan, lint, HTTP e testes anteriores | Implementado; visual responsivo pendente |
| Figuras 16–18, p. 29 | Referência visual | Matriz, cadastro e configuração | Organização do código original consultada | Comparação com PDF e screenshots pendentes |
| 15 dias de aviso | Decisão de implementação | `CERTIDAO_WARNING_DAYS`, regra compartilhada | Fronteiras de datas e fuso | Testado; não é prazo imposto pelo TCC |
| Configuração por funcionário | Decisão de implementação | Fornecedores/tipos acessíveis a funcionário e administrador | HTTP e autorização de domínio | Implementado conforme orientação do prompt |
| Exclusão e inativação conservadoras | Decisão de implementação | Nenhum DELETE de documentos/opções, referências preservadas | Histórico após inativação e exclusão | Implementado |
| PDFs privados, 10 MiB, validação e compensação | Melhoria técnica | `CertidaoStorage` | MIME, assinatura, nomes, tamanho, origem HTTP e corrupção | Testado nos cenários documentados |
| Migração preservadora | Melhoria técnica | Migração v13 aditiva, backup validado e diagnóstico CLI | Migração/convergência/rollback/integridade | Testado com dados sintéticos |

Contratos, compras, estoque, pedidos e relatórios gerais do Módulo 5 estão fora desta entrega. A abstração de DVA e suas preferências de e-mail foram preservadas.

## Operação e permissões

| Operação | Visitante | Funcionário ativo | Administrador ativo |
|---|---|---|---|
| Matriz, detalhes, PDF e históricos | Não | Sim | Sim |
| Cadastrar, corrigir metadados, renovar, arquivar, excluir logicamente | Não | Sim | Sim |
| Fornecedores e tipos | Não | Sim | Sim |
| SMTP e agendamento | Não | Não | Configuração operacional/CLI |
| Inventário/migração de arquivos | Não | Não | CLI com controle do servidor; aplicação da cópia exige ID administrativo ativo |

O `Router` aplica autenticação e CSRF a todas as rotas. Conta inativa, expiração de sessão e troca obrigatória de senha usam a infraestrutura existente, inclusive no PDF. Não há rota de restauração/desarquivamento ou purga. Cada mudança usa POST e confirmação quando altera o ciclo de vida; formulários seguem PRG. `revisao` rejeita atualização ou renovação baseada em dados antigos.

Matriz: até 25 documentos por página, no máximo 25 fornecedores e 25 tipos presentes nesse conjunto. A interface informa total filtrado, documentos e fornecedores da página e páginas seguintes. Vários documentos na mesma célula são permitidos; documentos do mesmo fornecedor podem continuar em outras páginas. O resumo superior é global, apenas de correntes, independentemente dos filtros. Ano em branco ou `todos` inclui todos os anos. Vencimento não arquiva automaticamente.

Nomes são comparados em NFC, com espaços normalizados e caixa Unicode. A grafia de exibição é preservada em NFC. Nomes equivalentes já existentes não são mesclados; o inventário os identifica e o cadastro rejeita nova duplicidade. Para resolver uma colisão legada, revisar e renomear explicitamente um registro; os IDs e vínculos permanecem. Uma opção inativa não pode ser usada em novo documento ou renovação, mas pode permanecer na correção de um documento já vinculado.

## Migração v13 e banco

`DatabaseInitializer` mantém as migrações 1–12 e acrescenta v13. Em instalação antiga sem tabelas de certidões, v13 também cria as tabelas legadas vazias antes da extensão. A instalação limpa aplica as mesmas migrações e converge na estrutura final.

- Listas: `ativo`, `atualizado_por`, `atualizado_em`.
- Certidões: `anterior_id`, `excluido_em`, autoria/horários, `revisao`, `pdf_privado`, `pdf_nome`, `pdf_bytes`, `pdf_sha256`.
- Índice único parcial em `anterior_id` impede duas renovações da mesma certidão. Não existe UNIQUE de fornecedor + tipo.
- `certidao_notification_deliveries` identifica envio bem-sucedido por data civil e administrador.
- Campos legados `arquivo_pdf`, datas, IDs e flags não são reescritos. Autoria histórica desconhecida continua nula.

Mapeamento de leitura: `excluido_em` preenchido → excluída; senão `arquivado=1` **ou** `status=0` → arquivada; demais → corrente. `NULL` usa o padrão legado (`arquivado=0`, `status=1`). Combinações ambíguas são preservadas e diagnosticadas. Escritas novas usam `(arquivado,status)=(0,1)` para corrente e `(1,0)` para arquivada/excluída. Datas inválidas aparecem como “Data pendente” e não geram alerta baseado em uma data inventada.

Antes de migrar banco existente, o inicializador cria `backups/*-pre-migration-*.sqlite` por `VACUUM INTO` e valida `integrity_check`. Falha interrompe a migração. A extensão ocorre sob `BEGIN IMMEDIATE`, sem desativar `foreign_keys`; integridade e referências são verificadas antes do commit. Testes específicos demonstram rollback de DDL e versão e preservação de IDs/sequências/flags/caminhos. O backup preventivo do inicializador é **somente do SQLite**; o backup operacional completo precisa também dos arquivos abaixo.

## PDFs e reconciliação

`CERTIDAO_STORAGE_PATH` deve ser absoluto e fora de `public`; ausente/vazio usa `<raiz>/storage/certidoes`. O diretório deve pertencer ao usuário do serviço, sem permissão de gravação por terceiros. Unix: diretório 0700 e arquivos 0600; Windows: conceder acesso somente à conta do serviço e administradores via ACL. Requer armazenamento local com locks confiáveis, não compartilhamento NFS/SMB ou múltiplos servidores sem coordenação.

`CERTIDAO_PDF_MAX_BYTES=10485760` define 10 MiB; aceita configuração de 1 até 104857600 bytes. Ajustar conjuntamente `upload_max_filesize`, `post_max_size` e limite HTTP do servidor. Configuração inicial sugerida: `upload_max_filesize=10M`, `post_max_size=12M`; Nginx `client_max_body_size 12m`.

Uploads exigem origem HTTP, sucesso integral, arquivo regular não vazio, extensão única `.pdf`, nome sem caminhos/controles, tamanho real, MIME por `fileinfo` e assinatura `%PDF-`. Nomes com múltiplos pontos são rejeitados conservadoramente. MIME e assinatura não comprovam ausência de conteúdo malicioso. Não são executados conversores, comandos ou renderizadores sobre uploads. Download é `attachment`, `application/pdf`, `nosniff`, `private, no-store`, com nome seguro gerado por ID. Nome original só é apresentado escapado na tela. Tamanho e SHA-256 são conferidos no acesso.

O arquivo recebe nome aleatório, é copiado para staging e disponibilizado por rename; só então o registro é inserido. Upload e transação usam um lock do diretório. Se banco/auditoria falhar, a compensação remove exclusivamente o novo arquivo não referenciado. O anterior não é substituído. SQLite e filesystem não têm atomicidade conjunta: interrupção abrupta pode deixar staging ou órfão. `php bin/certidoes-maintenance.php` adquire o mesmo lock e inventaria arquivos com mais de 24 horas sem referência, preservando todos para revisão manual. Não oferece purga; documentos referenciados e arquivos recentes não são candidatos. Falha de compensação/disco exige revisão do inventário.

O inventário também informa IDs com datas inválidas, flags ambíguas, duplicidades Unicode e PDFs ausentes/legados. Retorna 2 quando há pendências cadastrais, 1 em falha operacional, 0 sem pendências cadastrais; a seção `storage` deve ser revisada mesmo com código 0.

### Arquivos legados

Nunca há fallback web para `arquivo_pdf`. Antes de disponibilizar o sistema, bloquear todos os diretórios públicos antigos, inclusive aliases/customizações locais. Para Nginx, adicionar ao server block:

```nginx
location ^~ /uploads/certidoes/ { return 404; }
```

Para Apache, no VirtualHost (ajustar caminho físico):

```apache
<Directory "/caminho/GSE/public/uploads/certidoes">
    Require all denied
</Directory>
```

O servidor PHP de desenvolvimento também recusa `/uploads/certidoes/`. O bloqueio do servidor web real é indispensável, pois arquivos estáticos podem ser servidos antes do PHP.

Em janela offline e após backup completo, simular:

```powershell
php bin/migrate-certidao-pdfs.php --source="E:\dados\certidoes-legadas"
```

Aplicação deliberada pelo operador, não executada nesta entrega:

```powershell
php bin/migrate-certidao-pdfs.php --source="E:\dados\certidoes-legadas" --apply --offline-confirmed --backup="E:\backups\certidoes-migracao-20260916" --actor=1
```

O backup deve ser um diretório novo, privado e absoluto. O comando valida administrador ativo, cria backup SQLite validado e cópia/hash de cada PDF candidato; só então copia para armazenamento privado e atualiza metadados/auditoria atomicamente por registro. Originais não são removidos. Caminhos legados complexos exigem mapeamento/revisão manual e são preservados. Simulação identifica candidatos/ausências; MIME, assinatura e integridade completa são validados na aplicação. Código 2 indica itens pendentes/falhos. O comando pode ser retomado: registros já privados são ignorados. O backup desse comando cobre SQLite e os PDFs legados copiados; não substitui o backup operacional dos PDFs privados preexistentes.

### Backup e rollback operacionais

1. Interromper aplicação, workers e agendamentos; impedir upload/escrita durante toda a cópia.
2. Gerar snapshot SQLite consistente por `VACUUM INTO` ou API de backup; validar `integrity_check` e `foreign_key_check`.
3. Copiar o diretório privado inteiro e documentos legados ainda referenciados para armazenamento restrito; registrar manifesto de tamanho/SHA-256 e verificar a cópia.
4. Para restaurar, manter processos parados; restaurar conjuntamente o snapshot SQLite e os PDFs correspondentes, além do código/configuração compatíveis. Tratar WAL/SHM somente com todas as conexões encerradas, seguindo o procedimento existente do projeto.
5. Reaplicar ACL/permissões e bloqueio HTTP; executar inventário e testar downloads autenticados antes de reabrir. Não restaurar só o SQLite sobre um conjunto incompatível de PDFs.

## Relatório diário e SMTP

`php bin/notify-certidoes.php` fica fora de `public`. Reutiliza `MailTransport`/`PhpMailerTransport`. Só permite envio real com `APP_ENV=production`, `MAIL_ENABLED=true` e `CERTIDAO_MAIL_ENABLED=true`. Desenvolvimento/testes retornam sucesso com envio desabilitado; a suíte injeta transporte falso e não envia mensagens reais.

Todos os administradores ativos com e-mail válido recebem o relatório, independentemente de `recebe_alertas_dva`. Inclui correntes vencidas, que vencem hoje e até o limite inclusivo de `CERTIDAO_WARNING_DAYS` (padrão 15). Datas civis usam `APP_TIMEZONE`; interface, filtros e mensagens usam `CertidaoStatus`. Não inclui PDFs nem observações. Sem pendências não envia mensagem vazia. Com pendências e nenhum destinatário válido retorna falha/código 2.

Cada envio mantém uma reserva de escrita SQLite até o transporte ter sucesso e o registro diário ser confirmado. Trabalhadores concorrentes não enviam a mesma entrega confirmada; falhas podem ser retentadas. Isso pode bloquear outras escritas durante o timeout SMTP, devendo ser agendado fora do pico e monitorado. Se o SMTP aceitar e o processo cair antes do COMMIT, uma repetição pode duplicar a mensagem; não se promete entrega exatamente uma vez.

Linux, exemplo diário às 07:00 no fuso do servidor:

```cron
0 7 * * * cd /srv/gse && /usr/bin/php bin/notify-certidoes.php >> /var/log/gse-certidoes.log 2>&1
```

Windows, Agendador de Tarefas: gatilho diário às 07:00; programa `C:\Windows\php\php.exe`; argumentos `"E:\Projetos\GSE\bin\notify-certidoes.php"`; iniciar em `E:\Projetos\GSE`. Usar a conta do serviço com ACL do banco/PDFs e impedir nova instância sobreposta. Configurar a tarefa existente de DVA separadamente. Validar SMTP com ambiente de homologação antes de ativar produção e monitorar códigos 1/2 e ausência de execução.

## Verificação e demonstração

Resultados da entrega: consultar [MODULO4_VALIDACAO.md](MODULO4_VALIDACAO.md). Testes antigos que fixavam v12 foram atualizados para v13; a quantidade de rotas passa de 48 para 63. O teste legado de certidão, que aceitava data inválida, sobrescrita documental e exclusão física, foi substituído por testes do contrato novo. Não foram adicionados baseline ou exclusões de análise estática.

Para demonstrar em banco **novo e sintético** no PowerShell, sem reutilizar `.env` de produção (a leitura de `.env` tem precedência no projeto):

```powershell
composer install --no-interaction
$env:APP_ENV = 'development'
$env:APP_TIMEZONE = 'America/Cuiaba'
$env:DB_PATH = 'storage/demo-modulo4.sqlite'
$env:CERTIDAO_STORAGE_PATH = 'E:\Projetos\GSE\storage\demo-certidoes'
$env:MAIL_ENABLED = 'false'
$env:CERTIDAO_MAIL_ENABLED = 'false'
php bin/init-db.php
php bin/create-admin.php --name="Administrador Demo" --email="admin@example.test"
php -d upload_max_filesize=10M -d post_max_size=12M -S 127.0.0.1:8080 -t public public/index.php
```

`create-admin.php` imprime uma senha temporária aleatória: anotar localmente, entrar em `http://127.0.0.1:8080/login` e alterá-la. Pela gestão de usuários, criar funcionário de demonstração. Na sidebar, abrir Certidões e Fornecedores, cadastrar fornecedor/tipo, anexar um PDF sintético, consultar/editar/renovar e verificar arquivadas/excluídas. Não há credencial fixa versionada. Se houver `.env`, preparar uma cópia isolada do checkout e configurar esse arquivo apenas com os valores de demonstração.

O PHP local desta execução exigiu carregar `intl` e `fileinfo` num diretório de configuração temporário ignorado pelo Git. Em instalação normal, habilitar ambas no `php.ini` efetivamente usado pelo CLI e servidor; verificar com `php -m`. Não foi modificado o `php.ini` global do usuário.
