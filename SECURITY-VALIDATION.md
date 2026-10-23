> Registro histórico de 12/10/2026. Para a revisão atual, consulte [Validação de 22/10/2026](Documentação/VALIDACAO-2026-10-22.md). As versões de runtime abaixo pertencem àquela data e não atendem aos requisitos atuais.

# Validação local — downloads e limpeza de snapshots

Data: 12/10/2026. Base Git: `41dcb5d963b569e140f76eb6913c31b11ed09e61`.
A entrega é o diff local sobre essa base, incluindo arquivos novos. Não houve commit, push ou execução de CI para este ajuste; resultados remotos anteriores não validam estas alterações.

Identificação: SHA-256 `f4216847e0d1473bb20e90e492ddd00f6105df80ce7de50d0bfa02e3821cd4ae` do manifesto dos 20 caminhos modificados/novos, excluindo este relatório. Cálculo: caminhos únicos ordenados; concatenar, para cada caminho, seu UTF-8, byte NUL e digest SHA-256 binário do conteúdo; aplicar SHA-256 ao conjunto.

Ambiente: Windows, PHP 8.4.13, Composer 2.8.12, Node 24.13.0 e Chrome real isolado. `fileinfo`, `intl` e `sodium` foram habilitadas por `PHP_INI_SCAN_DIR` com INI temporário. Todos os bancos, documentos, usuários e keyrings utilizados nos testes são sintéticos e descartáveis.

| Verificação | Resultado |
|---|---|
| `composer check` | Sucesso (exit 0): Composer estrito, lint de 176 arquivos PHP, PHPStan sem erros, PHPUnit 372 testes/3.732 asserções/2 ignorados, HTTP 379 verificações e auditoria travada sem avisos de vulnerabilidades |
| `composer browser-test` | Sucesso (exit 0): senhas 36, contratos 58, operações autenticadas 102 e downloads de relatórios 40 verificações |
| `php vendor/phpunit/phpunit/phpunit --filter BackupCleanupTest` | Sucesso: 19 testes, 247 asserções, sem avisos ou testes ignorados |
| `node --check public/assets/js/app.js` e todos os `tests/*.mjs` | Sucesso |
| `composer check-platform-reqs` | Sucesso, incluindo sodium |
| `git diff --check` | Sucesso após concluir este relatório |

`composer check` executa Composer estrito, lint, PHPStan nível 6, PHPUnit, testes HTTP e auditoria das dependências travadas. A suíte HTTP inclui os testes de contratos, certidões, passivo, perfil, auditoria, backups e endurecimento. O job de navegador recebeu instalação das dependências Composer e extensões necessárias para gerar PDFs reais; sua execução remota não foi realizada.

Os testes de navegador usam os controles reais e uma aplicação PHP autenticada com dados sintéticos. Verificam PDF e CSV pelo botão principal, bloqueio de repetição imediata, liberação sem recarga, novos filtros e conteúdo CSV, ação específica de Baixar CSV, atualização posterior da prévia, Enter, envio sem submitter, parâmetros, nome/valor do botão, ausência de processamento permanente e navegação de volta. Também verificam `pageshow`, cancelamento do timer anterior e exportação TXT real com POST/CSRF e validação nativa.

A cobertura de backup exercita os fluxos manual e preventivo: criptografia e remoção, falha de criptografia, falha real de escrita com o tratamento de warnings usado em produção, falha de exclusão, retorno enganoso de exclusão, arquivo já removido e falhas simultâneas. Confere restauração dos backups preservados, identidade/propriedade, preservação de arquivos alheios e backups anteriores, ausência de sucesso/auditoria administrativa completa quando a limpeza falha e logs sem dados pessoais ou segredos. Também verifica que falhas do logger não impedem a limpeza nem substituem a exceção original. As simulações usam subclasses PHP internas e métodos privados nos testes; não foram introduzidos controles HTTP ou variáveis de ambiente para desativar proteções.

Limites reais:

- Dois testes existentes dependentes de modos POSIX/links simbólicos são ignorados no Windows. As asserções condicionais de permissões POSIX dos novos testes também não são executadas aqui. Linux e ACLs de produção não foram homologados nesta entrega.
- Nginx, HTTPS, proxy, SMTP, agendamento, armazenamento institucional e servidor de produção não foram acessados. O workflow foi atualizado, mas não há resultado remoto desta revisão.
- O ajuste verifica os snapshots de `Sistema::criarBackupManual()` e `DatabaseInitializer::createValidatedBackup()`. Pendências exigem o procedimento offline de [SECURITY-OPERATIONS.md](SECURITY-OPERATIONS.md#limpeza-verificada-dos-snapshots), com serviços parados, identidade conferida e cópia recuperável validada. Não se promete apagamento físico de SSD nem persistência de logs quando o próprio armazenamento está indisponível.
- A criptografia continua limitada ao nome/e-mail dos usuários e aos backups protegidos. Não abrange todos os dados pessoais, o arquivo SQLite inteiro ou o disco.
- O banco escolar e seu `.env` foram preservados; não houve migração em dados escolares, merge ou deploy.
