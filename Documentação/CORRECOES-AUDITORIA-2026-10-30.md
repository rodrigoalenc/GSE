# Correções da auditoria de segurança de 19/10/2026

Base de implementação: d33a5fd, após o pull de 30/10/2026. O PDF examina 9cb9e58 e identifica S01-S05, O01, P01-P05 e G01-G03. Estes códigos são distintos dos A01-A19 da revisão de 22/10.

## Controles implementados

| Item | Mudança e limite da conclusão |
|---|---|
| S01 | Admissão atômica antes do custo de senha; recusa barata com 429/Retry-After; eventos de esgotamento agregados, sem espera prolongada nem auditoria por recusa já bloqueada. Exemplo Nginx limita taxa e conexões antes do FPM, incluindo aliases do front controller. |
| S02 | Orçamento por conta+origem e janela curta por origem; falhas de uma origem não bloqueiam globalmente a conta. Não equivale a impedir ataques distribuídos ou DDoS de rede; capacidade e limites no provedor precisam de homologação. |
| S03 | Parsing em PHP CLI isolado, com 192 MiB, 12 segundos de tempo total, até dois workers e limites agregados na decodificação. Sem herdar variáveis de dados/chaves/SMTP; falha não publica importação parcial. |
| S04 | Amostras DVA limitadas, aniversariantes paginados com contagem completa, busca paginada e orçamentos de frequência nas rotas de consulta. Projeções SQL respeitam permissões de saúde/contatos. |
| S05 | Regra única de confirmação da própria identidade e incremento da versão de sessão na mudança de e-mail, incluindo editor administrativo e perfil. Sessões anteriores são recusadas. |
| O01 | Runtime privado atualizado e verificador de requisitos; PHP global preservado. O servidor institucional deve executar os mesmos checks em CLI e no processo web efetivo. |
| P01 | Saúde, contatos, edição e exportações restritos por capacidade; concessões nominativas com aprovação e validade. Cadastros básicos e filtros não expõem DVA por inferência. Leitura de perfil auditada. Matriz e escopo institucional ainda requerem aprovação da escola. |
| P02 | Verificador de criptografia de volume e permissões por categoria de armazenamento; aprovação falha se a evidência não existe. Instalação real da proteção de disco/ACL não pode ser inferida de testes sintéticos. |
| P03 | Alertas DVA usam contagem agregada e link autenticado, sem lista nominal, turma, vencimento ou situação individual no e-mail. Provedor, destinatários e retenção ainda precisam de aprovação institucional. |
| P04 | Prévia e execução de descarte de dados operacionais, com política, impedimentos, assinatura HMAC do plano, confirmação recente, dupla conferência e auditoria transacional. Documentos escolares preservados. Política de temporalidade, exceções e tratamento de backups exigem aprovação institucional. |
| P05 | Prévia CSV privada e identificada; manutenção periódica elimina expirados independentemente de novo uso, com proteção contra links e arquivos alheios. Resíduos legados exigem propriedade comprovada ou conferência explícita do operador. |
| G01-G03 | Modelo de registro de finalidades, responsáveis, matriz, aviso, atendimento, incidentes e guarda em PRIVACIDADE-INSTITUCIONAL.md. Campos sem preenchimento continuam pendentes; não representam conformidade certificada. |

Também foram tratados o veto ao PDF com mais de 500 alunos antes da seleção nominal e limites totais de notas/produtos dos contratos. Modelos legados sem rota ativa continuam preservados, pois existem consumidores de teste; sua remoção não é tratada como correção de vulnerabilidade confirmada.

## Operação

- Novos limites de login: `LOGIN_PAIR_MAX_ATTEMPTS=5`, `LOGIN_PAIR_WINDOW_SECONDS=60`, `LOGIN_ORIGIN_MAX_ATTEMPTS=60`, `LOGIN_ORIGIN_WINDOW_SECONDS=10`. As antigas variáveis de bloqueio global/atraso não definem a nova admissão. Ajustes exigem homologação com IP compartilhado e capacidade do servidor.
- A configuração Nginx deve ser incluída dentro de `http {}`; adaptar host, TLS e socket, validar com `nginx -t` e medir antes da ativação. O exemplo não foi instalado no servidor institucional.
- O parser requer PHP CLI correspondente ao servidor, extensões disponíveis e `proc_open`. Se o isolamento não estiver disponível, a importação falha sem voltar ao parser no processo HTTP. O volume temporário também deve ser privado e protegido.
- Agende `bin/maintenance.php` e monitore o código de saída. O padrão de prévias fica em diretório privado ao lado do SQLite; `PASSIVO_IMPORT_DIRECTORY` aceita caminho absoluto privado alternativo. Resíduos legados são tratados somente no diretório reconhecido `gse-passive-imports`; no Windows, confira propriedade/ACL antes de usar `--cleanup-legacy-previews`.
- O PHP privado está em `%LOCALAPPDATA%/GSE/php/8.4.27-x64`; `bin/gse.ps1` seleciona essa instalação. `bin/runtime-check.php` não abre o banco escolar. A atualização não migra automaticamente dados institucionais.

## Validação

As regressões usam bancos, chaves, PDFs e contas sintéticas, sem envio de mensagens institucionais. Comandos executados com o PHP privado 8.4.27 x64 / SQLite PDO 3.53.4:

| Verificação | Resultado |
|---|---|
| `bin/runtime-check.php --json` e launcher Windows | Runtime compatível, lista de erros vazia |
| Composer `validate --strict` e `check-platform-reqs` | Aprovados |
| `bin/lint.php` | 287 arquivos PHP com sintaxe válida |
| PHPStan nível 6, escopo completo configurado | Zero erros |
| PHPUnit, todos os casos em cinco lotes independentes | 723 testes contabilizados, 6.623 asserções, zero falhas/erros nos lotes; 3 casos específicos de Linux ignorados neste Windows |
| Composer `audit --locked` | Sem advisories e sem pacotes abandonados informados na consulta |
| `tests/http-smoke.php` | 720 verificações aprovadas |
| Oito scripts de navegador configurados no Composer | Aprovados em Chrome isolado, com testes de desktop/celular e navegação por teclado |
| `tests/audit-volume.php` | 10.000 alunos sintéticos; 20 aniversariantes, 15 resultados de busca e 10 nomes no grupo DVA; totais completos; PDF acima de 500 recusado. Consultas sequenciais em 74,51 ms nesta execução local |
| PDF sintético normal no limite anunciado | 200 páginas / 5.000 alunos analisados no worker, sem importação parcial |
| `git diff --check` | Sem erros de whitespace |

As regressões verificam admissão concorrente de login, recusa sem hash/espera/escrita repetitiva, resposta 429/Retry-After, reautenticação nas três rotas de identidade, revogação e recuperação com novo CSRF, permissões separadas/expiradas, rascunhos após revogação, e-mails sem dados nominais, limpeza de CSV sem novo uso, falhas/timeout/memória/concorrência do parser, quotas totais, retenção com impedimentos, proteção contra troca de base e alterações concorrentes e rollback quando a auditoria falha.

O PHPUnit foi executado em cinco processos de teste independentes: Unit (148 testes/681 asserções), Integration A-F (206/1.731, dois ignorados), Integration G-M (100/791), Integration N-Z (201/1.883, um ignorado) e Security (68/1.537). A divisão usa o nome dos arquivos `*Test.php`, sem sobreposição ou exclusão de casos. Todos os lotes terminaram com código zero. Comandos: `vendor/phpunit/phpunit/phpunit --testsuite Unit`, `--testsuite Security` e os arquivos de Integration selecionados pelas faixas de iniciais A-F, G-M e N-Z, com `--display-skipped --display-warnings`.

A execução monolítica neste ambiente Windows apresentou `proc_open(): CreateProcess failed: Acesso negado` ao acumular testes que iniciam subprocessos; não foi aprovada nesse modo. As mesmas verificações, incluindo concorrência, passaram nos lotes independentes. A causa nativa exata não foi estabelecida; não houve redução de asserções nem desativação de proteção do sistema operacional.

Os três casos não executados no Windows são `AdministrativeBackupTest::testSymlinkedFileAndBackupDirectoryAreRejected`, `SqliteProtectionTest::testLinuxDatabaseAndSidecarsReceiveRestrictivePermissions` e `AuditRemediationTest::testExplicitCliPreviewsAndAppliesWithoutLoadingTheInstitutionalEnvironment`. O último exige Linux para conferir o fluxo integral com permissões privadas; os testes de plano/aplicação de retenção e modelos de ACL foram executados no Windows. Estes resultados não atestam a execução do CI Linux, que precisa rodar sobre o commit a ser publicado.

Limites de implantação: não houve validação de Nginx/FPM, criptografia de disco/ACLs reais, agendador, SMTP institucional ou políticas aprovadas pela escola. O verificador de armazenamento reprova quando não obtém evidência; ele não cifra o volume. O ensaio de 10.000 alunos é local e sequencial, sem conclusão sobre capacidade concorrente de produção.

Na conferência posterior da configuração, foi corrigido o valor vazio de `PASSIVO_IMPORT_DIRECTORY`: assim como a variável ausente, ele usa o diretório privado padrão, conforme `.env.example`. Regressão adicional e testes de prévia CSV: 16 testes/114 asserções aprovados; PHPStan do serviço sem erros. A `.env` operacional não foi alterada nessa conferência.

A revisão automática bloqueou a limpeza recursiva de resíduos sintéticos de testes no Temp com a razão `blocked by policy`. O diretório da execução HTTP aprovada, `gse-http-fc5b5723b39a`, permaneceu no Temp; nenhum diretório institucional foi usado como alvo dessa limpeza.
