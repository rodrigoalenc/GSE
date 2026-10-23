# Compatibilidade com correções do Ubuntu 24.04

## Diagnóstico e decisão

O pacote oficial `8.3.6-0ubuntu0.24.04.13` corrige CVE-2026-109618 e CVE-2026-109619, conforme [USN-8948-1](https://ubuntu.com/security/notices/USN-8948-1). A versão base reportada por PHP_VERSION continua 8.3.6. O requisito upstream estrito do Composer e do verificador do GSE bloqueava esse pacote corrigido.

A compatibilidade PHP é reconhecida pela distribuição, revisão do pacote e integridade do arquivo efetivamente executado. Não há variável para ignorar a segurança. A exceção se limita ao Ubuntu 24.04 com pacotes oficiais PHP 8.3.6 de revisão 13 ou posterior; a política do SQLite continua independente. Instalações upstream mantêm os mínimos de segurança anteriores.

## Plano de implementação e verificação

- [x] Reproduzir em testes o bloqueio do pacote corrigido e os casos de pacote antigo, sistema diferente, binário alterado e processo antigo após atualização.
- [x] Implementar a detecção sem comandos de shell, sem acesso ao banco e sem depender de vendor, incluindo CLI, servidor embutido, FPM, CGI e módulo Apache.
- [x] Separar compatibilidade semântica do Composer da verificação de patches; executar o verificador antes de instalar/atualizar dependências e antes de abrir o banco.
- [x] Atualizar o lockfile sem alterar versões de bibliotecas, validar os testes e documentar o procedimento do servidor.
- [x] Manter o trabalho na main, conforme preferência explícita do responsável pelo projeto.

## SQLite

O número do pacote instalado do sistema não comprova qual biblioteca o PDO carrega. Execute na pasta do projeto `php bin/runtime-check.php --json`; esse comando não abre a base escolar. A revisão `3.45.1-1ubuntu2.8` contém correções de outras vulnerabilidades, mas seu arquivo oficial de patches não contém a correção WAL-reset. Não a considere corrigida para WAL apenas por aparecer em noble-security.

O servidor confirmou PDO 3.45.1. A solução adotada é usar rollback journal **DELETE**, sem substituir bibliotecas do Ubuntu. O bug WAL-reset afeta bancos no modo WAL; DELETE mantém atomicidade e rollback com sincronização FULL. O custo é menor concorrência entre leitores e escritores. WAL continua disponível nas versões com correção comprovada. Não é necessário compilar SQLite para usar o GSE nesse servidor.

Versões compatíveis: 3.45.1+ ou backport 3.44.6+ da linha 3.44. `DB_JOURNAL_MODE=auto` escolhe o modo seguro; `delete` permite fixá-lo. Não há opção para forçar WAL nem para desligar o journal. Banco persistente em MEMORY/OFF é recusado. O inventário de segurança do sistema continua sendo responsabilidade das atualizações oficiais do Ubuntu; essa política resolve especificamente o risco WAL e não comprova ausência de todas as vulnerabilidades possíveis.

Fonte do pacote SQLite inspecionado: [arquivo oficial de patches Ubuntu](https://archive.ubuntu.com/ubuntu/pool/main/s/sqlite3/sqlite3_3.45.1-1ubuntu2.8.debian.tar.xz). Critério WAL: [documentação SQLite](https://sqlite.org/wal.html#the_wal_reset_bug).

## Comprovação do PHP e atualização

O verificador lê `/etc/os-release`, o inventário instalado do dpkg e os checksums do pacote correspondente ao SAPI atual. Confere a imagem do processo em `/proc/self/exe`, ou o módulo PHP mapeado no Apache, para não confundir pacote atualizado com processo antigo ainda em execução. CLI/servidor embutido, FPM, CGI e Apache usam seus próprios pacotes. Não chama shell, não acessa `.env` nem banco, não altera arquivos do sistema e não considera uma variável de ambiente como prova de patch.

O checksum MD5 é usado apenas para comparar o arquivo com o inventário local do sistema, cuja integridade e privilégios administrativos são pressupostos; não é usado como assinatura para downloads. Um PHP customizado, arquivo divergente ou pacote de outra distribuição não recebe essa exceção.

Após atualizar os pacotes, reinicie o serviço que efetivamente atende o site (PHP-FPM ou Apache com mod_php). Processos antigos serão recusados. Chroot, open_basedir ou ausência de acesso a procfs/inventário também impedem essa comprovação: providencie uma configuração que permita a leitura desses metadados ou use uma versão upstream corrigida. Não publique phpinfo para inspecionar o servidor.

O requisito de linguagem no Composer passou a `>=8.3.6 <8.6` para permitir resolver esse pacote oficial. A política de segurança fica no verificador, executado automaticamente nos eventos pre-install/pre-update e antes da conexão da aplicação ao banco. Não execute Composer com `--ignore-platform-reqs` nem desative as verificações.

## Preparar o servidor e migrar

Preserve uma cópia recuperável consistente do banco e dos PDFs, com a chave recuperável separadamente. Pare HTTP, PHP/workers escritores e agendamentos antes de preparar journal ou esquema. Não copie `.env`/chaves do desenvolvimento para o servidor e não crie outra chave para uma base já protegida.

Na pasta `/var/www/GSE`, depois de atualizar a main e instalar dependências pelo lockfile:

```bash
php bin/runtime-check.php
php bin/init-db.php --journal-only --maintenance-confirmed
php bin/security.php preflight
php bin/security.php migrate --maintenance-confirmed
```

O primeiro comando aprova a compatibilidade e informa DELETE obrigatório; não abre o banco. O segundo prepara o journal, verifica integridade/FKs e informa o esquema armazenado sem migrá-lo, inclusive em base anterior à v17. O terceiro ensaia as migrações numa cópia. O último aplica as versões ausentes e confere integridade; não renova nem redefine a chave existente. Pare no primeiro erro e preserve a manutenção. Os flags não param serviços automaticamente.

Para bases já protegidas, `php bin/init-db.php` também prepara o modo e aplica as versões ausentes até 21, sem exigir a reconstrução de proteção. Reabra o serviço depois das conferências e valide login, cadastros e documentos com o responsável.

## Evidências de 23/10/2026

O teste do pacote corrigido falhou antes da implementação, e passou após a correção. O mesmo ciclo reproduziu uma omissão inicial do SAPI cli-server, encontrada na revisão independente e corrigida antes da entrega. Testes específicos: oito testes, 47 asserções; revisão independente também aprovada.

A detecção foi exercitada com binário e inventário extraídos do pacote oficial `php8.3-cli_8.3.6-0ubuntu0.24.04.13_amd64.deb`, sem executar esse binário Linux no Windows. O resultado identificou corretamente pacote e revisão. Os casos de processo/módulo usam fixtures isoladas; isso não equivale a executar o verificador em Ubuntu nativo, FPM ou Apache do servidor institucional.

Suíte integrada local (`bin/gse.ps1 check`, saída 0): 581 testes, 5.566 asserções e dois testes específicos do sistema operacional ignorados no Windows; 604 verificações HTTP aprovadas e nenhum aviso de vulnerabilidade conhecido retornado pela auditoria de dependências. Sintaxe aprovada em 241 arquivos PHP e PHPStan sem erros. O comando Composer `run-script pre-install-cmd` executou o verificador; `install --dry-run` confirmou que o lockfile não exige mudanças de bibliotecas. O lockfile mudou apenas no hash de conteúdo e no requisito PHP de plataforma.

O operador posteriormente executou o verificador em `/var/www/GSE` no servidor e confirmou a comprovação do pacote PHP e PDO SQLite 3.45.1. Isso confirma a detecção PHP na CLI real; não comprova o modo do banco nem o SAPI web. A preparação/migração da base institucional não foi executada pelo agente.

Após implementar a política de journal, a suíte integrada (`bin/gse.ps1 check`, saída 0) aprovou 593 testes/5.702 asserções, dois ignorados no Windows, 604 verificações HTTP e auditoria de dependências. O complemento final da CLI e política aprovou 22 testes/198 asserções, incluindo dois testes novos de preparação sem migração; lint final em 245 arquivos e PHPStan sem erros.

Uma rodada adicional em DELETE aprovou 48 testes/354 asserções de estoque, transações, histórico escolar e backup (um teste de link simbólico ignorado no Windows), além das mesmas 604 verificações HTTP com DELETE forçado. O ensaio de backup respeita o modo configurado; mantém sua cobertura de commits no WAL na rodada padrão e de restauração em DELETE na rodada adicional. A revisão independente não identificou outra pendência P1/P2 após incluir o preparo para bases legadas.
