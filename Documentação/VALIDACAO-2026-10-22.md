# Validação das correções do GSE

Diagnóstico iniciado em 22/10/2026; integração e conferência final em 23/10/2026. Base Git: `598803b93eaa9e70ce3838b0ebefda7349f54b3a`. Alterações locais na branch `codex/correcoes-auditoria-completa`, sem publicação ou implantação institucional.

Complemento posterior de implantação: [compatibilidade Ubuntu](COMPATIBILIDADE-UBUNTU.md) corrige o reconhecimento do PHP oficial com backport sem retirar a verificação independente do SQLite. A entrega do diagnóstico foi integrada na main; a branch de trabalho foi removida. Os resultados abaixo registram a execução original dos 19 achados; o complemento informa os testes da correção Ubuntu.

## Cobertura dos 19 achados

| Achado | Correção aplicada | Evidência |
|---|---|---|
| A01 — PHP anterior às correções críticas | PHP privado 8.4.27 x64, requisitos mínimos e bloqueio de runtime incompatível | RuntimeRequirementsTest, runtime-check e Composer |
| A02 — edição desfaz progressão | Revisão em edição; troca de turma via cadastro recusada após movimentação, inclusive com revisão atual | StudentIntegrityRevisionTest e HTTP; contatos e correção inicial continuam permitidos |
| A03 — ativo também no passivo | Reativação/restauração validam o vínculo sob transação, preservando histórico | StudentIntegrityRevisionTest e Passivo |
| A04 — sessão invalida formulário | Renovação periódica mantém CSRF; autenticação/encerramento continuam renovando proteção | SessionIdRenewalTest, SessionLifecycleTest e navegador |
| A05 — instalação incompleta | Pacotes ausentes instalados pelo lockfile, ferramentas privadas e comandos reproduzíveis | Composer validate, platform-reqs, diagnose e check |
| A06 — datas fora de 32 bits | Runtime de 64 bits e apresentação por DateTimeImmutable com fuso explícito | UiPresentationTest, lint e navegador |
| A07 — SQLite com problema WAL | SQLite PDO 3.53.4, verificador e preparação de CI; banco operacional local fora do OneDrive | runtime-check, preflight e instalação local |
| A08 — valor inválido aparenta zero | Campo financeiro inválido impede envio e suspende a conferência dos totais | Navegador de contratos |
| A09 — remoção perde preenchimento | Desfazer remoção de nota/produto com conteúdo e foco preservados | Navegador de contratos |
| A10 — DVA com critérios divergentes | Classificação compartilhada entre PHP e função SQLite, inclusive datas legadas inválidas | DvaClassificationConsistencyTest e relatórios |
| A11 — migração concorrente | Revalidação de versão sob exclusão SQLite; HTTP verifica esquema sem migrar | Corrida com três processos, StudentIntegrityRevisionTest e HTTP |
| A12 — consultas sem índices adequados | Índices de produtos por pedido/página e operações por recurso/tipo | Planos de consulta em StudentIntegrityRevisionTest |
| A13 — painel carrega lista inteira | Consulta limitada a dez por situação; contagens completas e acesso à lista filtrada | DvaClassificationConsistencyTest e painel |
| A14 — erro sem campo associado | Rótulos, resumo com destino e atributos aria-invalid/aria-describedby | UiPresentationTest, HTTP e navegador |
| A15 — linguagem técnica nos campos | Rótulos permanentes, unidades legíveis e instruções de tarefa | Views revisadas, navegador e manual |
| A16 — instruções desatualizadas | Passivo e DVA descrevem o fluxo efetivo e preservação dos registros | HTTP, navegador e manual |
| A17 — apresentação inconsistente | Datas com fuso escolar, paginação compartilhada e menu fixável | UiPresentationTest e capturas desktop/celular/paisagem |
| A18 — documentação conflitante | Versão atual 21, tabela completa de migrações e documentos operacionais vigentes | README, atualização técnica, manual e procedimento de recuperação |
| A19 — diagnóstico insuficiente | Cadeia estrutural de causas sem mensagens, argumentos ou dados dos formulários | TechnicalLoggerDiagnosticsTest e ErrorHandler |

A revisão independente também reproduziu e levou à correção de dois casos adicionais: edição com revisão atual após progressão e aprovação indevida de referência a PDF legado. O preflight agora reprova PDFs legados ainda não migrados, sem abrir seus caminhos arbitrários, e verifica arquivos privados atuais e históricos por tamanho/hash.

## Ambiente e comandos

Windows, PHP privado **8.4.27 x64**, SQLite efetivamente carregado pelo PDO **3.53.4**, Composer privado **2.10.3**. Distribuições oficiais conferidas por SHA256; PHP global preservado. As ferramentas ficam em `%LOCALAPPDATA%/GSE`, fora do repositório. O manifesto reproduzível está em `config/runtime-windows.json`.

```powershell
.\bin\gse.ps1 check
.\bin\gse.ps1 composer browser-test
.\bin\gse.ps1 composer check-platform-reqs
.\bin\gse.ps1 composer diagnose
.\bin\gse.ps1 php bin/preflight.php --json
.\bin\gse.ps1 php -t public -v
git diff --check
```

O encaminhamento `php -t public -v` foi conferido para garantir que opções nativas do PHP não sejam consumidas pelo PowerShell. `serve` aceita `-Port NUMERO` e `-Lan`; o servidor de desenvolvimento não substitui Nginx/PHP-FPM em produção.

## Resultados

Conferência final do conjunto integrado em 23/10/2026:

| Verificação | Resultado observado |
|---|---|
| `bin/gse.ps1 check` | Código de saída 0 |
| Runtime e Composer validate | Ambiente compatível; manifesto e lockfile válidos |
| Sintaxe PHP | 239 arquivos aprovados |
| PHPStan, nível 6 | Sem erros |
| PHPUnit | 577 testes, 5.543 asserções; dois ignorados por dependência do sistema operacional |
| Integração HTTP | 604 verificações aprovadas |
| Auditoria de dependências pelo lockfile | Nenhum aviso de vulnerabilidade conhecido retornado |
| Chrome real, cinco suítes | 406 verificações aprovadas; código de saída 0 |
| Revisão independente | Casos adicionais corrigidos e delta final sem outra pendência concreta identificada |
| Scripts PowerShell e Bash | Sintaxe aprovada; argumentos PHP e versão do Composer conferidos |
| Documentação e diff | Links locais conferidos em nove documentos; `git diff --check` sem erros |

O navegador cobriu movimentações de alunos (109), senhas (44), contratos (68), contratos/passivo autenticados (102) e prévias/downloads (83), incluindo computador, celular e paisagem conforme cada suíte. Foram preservadas 56 capturas na pasta de evidências da conversa; a revisão visual incluiu progressão no celular e detalhes de pedido no computador. Os testes usam bancos descartáveis e dados fictícios; não enviam mensagens a destinatários institucionais.

Dois testes dependem do sistema operacional e são ignorados no Windows: permissões POSIX de SQLite e criação de links simbólicos sem a permissão necessária. A configuração de CI contempla Linux; a execução remota deste diff não foi realizada nem declarada aprovada.

`composer check-platform-reqs` aprovou os requisitos instalados. Na última execução de `composer diagnose`, plataforma, chaves públicas, versão, vulnerabilidades do próprio Composer, Git, espaço e conectividade HTTPS foram aprovados. O teste adicional de conectividade HTTP sem TLS ao Packagist recebeu HTTP 403 e o diagnóstico terminou com código 2. Isso permanece registrado como limitação observada da conectividade externa; não se desabilitou TLS nem `secure-http` para contorná-la. A instalação e a auditoria usam HTTPS. A [configuração oficial do Composer](https://github.com/composer/composer/blob/main/doc/06-config.md#secure-http) mantém HTTPS como padrão.

## Recuperação da instalação de desenvolvimento local

A configuração local apontava para um banco no esquema 11 e não tinha keyring utilizável. Foi preservada uma cópia privada da configuração e obtido snapshot consistente pela API de backup SQLite. O snapshot foi cifrado, restaurado em destino novo e migrado em manutenção até o esquema 21 antes de ativar a cópia.

Foram comparados os dados anteriores com os restaurados: identificadores, cadastro, hash de senha e auditoria foram preservados. Nome/e-mail foram conferidos após decifração; contadores de tentativas foram comparados considerando a transformação HMAC prevista pela migração 17, sem reiniciar os limites. Integridade e chaves estrangeiras foram verificadas. Nenhum valor pessoal ou segredo consta deste relatório.

O banco operacional e o diretório de PDFs agora ficam em uma pasta privada de `%LOCALAPPDATA%/GSE/data`; a chave fica separada em `%LOCALAPPDATA%/GSE/secrets`. Diretórios com ACL restrita à conta local. Somente DB_PATH, GSE_KEYRING_PATH e CERTIDAO_STORAGE_PATH foram atualizados no `.env` ignorado pelo Git. O banco anterior foi preservado; cópias legadas exigem a política de retenção e proteção prevista no procedimento operacional.

A tela de login da instalação efetiva respondeu HTTP 200 com campos de acesso e Content-Security-Policy; a senha existente não foi redefinida. Preflight da base ativada confirmou esquema 21, integridade e zero referências a documentos naquele ponto de recuperação. O servidor temporário de conferência foi encerrado.

O conjunto privado de recuperação contém configuração anterior, snapshot cifrado, manifesto, resultados de preservação e ensaio. Ele não é uma cópia externa de contingência. A custódia externa da chave e do backup precisa ser estabelecida pelo responsável, conforme `OPERACAO-E-RESTAURACAO.md`.

## Limites das evidências

Este documento registra as verificações do código e da instalação local. Não atesta SMTP institucional, HTTPS/proxy/ACL do servidor real, agendamento, carga de produção, restauração em outra máquina, política de retenção ou aceite dos profissionais da escola. Esses itens têm roteiro e critérios explícitos em `OPERACAO-E-RESTAURACAO.md` e `AVALIACAO-USABILIDADE.md`; não foram preenchidos com resultados presumidos.

Transferência lateral entre turmas da mesma série após movimentação não tem fluxo próprio nesta versão. O sistema impede que a edição cadastral seja usada para contornar o histórico; o manual orienta a secretaria a procurar o responsável. Progressão, regressão e envio de concluintes ao passivo usam as movimentações existentes.

Fontes técnicas do ambiente: [correções PHP de outubro de 2026](https://www.php.net/archive/2026.php) e [documentação SQLite sobre WAL](https://sqlite.org/wal.html). Documentação de APIs consultada com Context7 e fontes oficiais; navegação exercitada em Chrome isolado, com dados sintéticos.
