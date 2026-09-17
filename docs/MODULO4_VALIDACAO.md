# Módulo 4 — evidências de validação

Execução local em 16/09/2026, Windows, PHP 8.4.13 e PHPUnit 12.5.33. Base `61930860554535af1915622b8eb273a155917c72`; branch `Modulo4`. Todos os bancos, PDFs e usuários de teste são sintéticos e temporários. Nenhum e-mail real foi enviado.

Commit da implementação: `7e3c47a` — `feat(modulo4): implementa certidoes e fornecedores com PDFs privados e auditoria`. A documentação de entrega está em commit local subsequente. Nenhum commit foi enviado ao remoto.

## Antes das alterações

O PHP local não carregava `intl` nem `fileinfo`. A primeira execução teve 139 testes, 636 asserções, 31 erros, 16 falhas, 20 avisos e um teste ignorado, principalmente em normalização Unicode e validação MIME já existentes. Essas falhas foram isoladas como configuração de ambiente.

Sem alterar o `php.ini` global, foi usado `PHP_INI_SCAN_DIR=E:\Projetos\GSE\.local-qa` com `extensions.ini` contendo `extension=intl` e `extension=fileinfo`. Com isso, a base passou em **139 testes, 1.034 asserções e um teste ignorado**, antes da implementação. O diretório `.local-qa` é local e ignorado pelo Git.

## Resultado final

| Comando | Resultado executado |
|---|---|
| `composer validate --strict` | Válido |
| `composer audit --locked` | Nenhum aviso de vulnerabilidade encontrado |
| `composer lint` | Sintaxe válida em 120 arquivos PHP |
| `composer analyse` | PHPStan nível 6 sem erros; código novo incluído, sem baseline |
| `composer test` | **171 testes, 1.207 asserções, zero falhas/erros, um ignorado** |
| `composer http-test` | **146 verificações aprovadas** |
| `git diff --check` e `git diff --cached --check` | Sem erros de whitespace |

O teste ignorado é `SqliteProtectionTest::testLinuxDatabaseAndSidecarsReceiveRestrictivePermissions`: verifica modos POSIX no Linux; o Windows usa ACL. O CI configura `fileinfo` explicitamente, além de `intl`, e mantém PHP 8.3/Linux. O CI remoto não foi executado nesta entrega local.

Durante a implementação, os testes identificaram e permitiram corrigir a criação das tabelas de certidões em instalações antigas do Módulo 1 e o fechamento de um cursor antes de `VACUUM INTO` no comando de cópia legada. Resultados intermediários com falhas não foram apresentados como aprovação final.

## Cobertura acrescentada

- `CertidaoStatusTest`: fronteiras inclusivas, vence hoje, vencidas, datas reais, ano zero, ano bissexto, fuso civil e configuração do limite.
- `CertidaoTest`: funcionário, multiplicidade de documentos, renovação vinculada, preservação de arquivos, edição sem substituição, arquivamento/exclusão distintos, estados/contagens/filtros/paginação, opção inativa, Unicode, IDs/datas/limites, origem HTTP, MIME, nomes manipulados, tamanho, diretório público, corrupção, armazenamento indisponível, concorrência com duas conexões e reenvios.
- Auditoria: falha induzida reverte renovação, metadados, arquivamento, exclusão e opções; compensação preserva arquivos anteriores e remove só o novo não referenciado.
- `CertidaoMigrationTest`: v12→v13, dados inválidos preservados, flags/caminhos/IDs/sequências, idempotência, convergência de estrutura, integridade, chaves estrangeiras e rollback de DDL/versão.
- `CertidaoNotificationTest`: destinatários ativos e válidos independentes do opt-in DVA, HTML escapado sem observações, fronteira de aviso, falha parcial e retry, idempotência diária, exclusão de arquivadas/excluídas, ausência de pendências/destinatários e disputa de workers durante o envio falso.
- `CertidaoCliTest`: simulação sem alteração documental, cópia privada com backup SQLite/PDF validado, arquivo original preservado, auditoria, reexecução, inventário e envio real desabilitado em testes.
- `http-certidoes.php`: fluxo real do funcionário com multipart, edição, renovação, arquivo e exclusão; downloads por funcionário/admin, bloqueio do visitante/conta inativa/troca obrigatória, CSRF, GET sem mutação, IDs inválidos/inexistentes, headers e escape HTML.
- Testes de expiração ociosa/absoluta e invalidação de sessão existentes continuam passando. As rotas de certidão passam pelo mesmo front controller; não foi criado mecanismo paralelo de sessão.

Os testes antigos foram mantidos, exceto a substituição justificada do teste do Model legado de certidão: ele validava comportamentos incompatíveis com o novo contrato (datas invertidas, substituição silenciosa de PDF, exclusão física). As expectativas de schema passaram de 12 para 13 e de rotas explícitas de 48 para 63. Nenhuma permissão global dos outros módulos foi reduzida.

## Limitações e aceite restante

1. **PDF acadêmico integral:** não acessível; requisitos aplicados pelas transcrições do prompt. Conferência de páginas/mockups e interpretação de exclusão lógica exigem validação acadêmica.
2. **Visual desktop/celular:** navegador indisponível (`No browser is available`; descoberta retornou lista vazia). CSS responsivo e estrutura foram entregues, mas não há screenshots ou homologação visual. Verificar layout, foco, teclado e rolagem da matriz no ambiente de aceite.
3. **Operação:** SMTP real e Agendador/cron precisam ser configurados, ativados e monitorados para cumprir o envio diário. Os testes usam transporte falso. A janela entre aceite SMTP e COMMIT ainda permite duplicação após queda abrupta.
4. **Armazenamento:** os testes cobrem falhas de domínio/banco/auditoria, corrupção e diretório indisponível; não simulam queda física de energia, disco cheio real nem todas as ACL/junctions possíveis do Windows. A implantação deve revisar ACL, aliases públicos, espaço e locks do filesystem. MIME/assinatura não substituem análise antimalware.
5. **Legado e backup:** nenhum PDF/banco real foi lido, movido ou apagado. Inventário, bloqueio dos diretórios públicos antigos, aplicação da migração documental e restauração completa com dados da instituição cabem à implantação controlada.

Por essas pendências, a entrega distingue código implementado e testes executados de homologação acadêmica/visual e operação em produção.
