# Compatibilidade com correções do Ubuntu 24.04

## Diagnóstico e decisão

O pacote oficial `8.3.6-0ubuntu0.24.04.13` corrige CVE-2026-109618 e CVE-2026-109619, conforme [USN-8948-1](https://ubuntu.com/security/notices/USN-8948-1). A versão base reportada por PHP_VERSION continua 8.3.6. O requisito upstream estrito do Composer e do verificador do GSE bloqueava esse pacote corrigido.

A compatibilidade é reconhecida pela distribuição, revisão do pacote e integridade do arquivo PHP efetivamente executado. Não há variável para ignorar a segurança. A exceção se limita ao Ubuntu 24.04 com pacotes oficiais PHP 8.3.6 de revisão 13 ou posterior; a verificação do SQLite continua independente. Instalações upstream mantêm os mínimos de segurança anteriores.

## Plano de implementação e verificação

- [x] Reproduzir em testes o bloqueio do pacote corrigido e os casos de pacote antigo, sistema diferente, binário alterado e processo antigo após atualização.
- [x] Implementar a detecção sem comandos de shell, sem acesso ao banco e sem depender de vendor, incluindo CLI, servidor embutido, FPM, CGI e módulo Apache.
- [x] Separar compatibilidade semântica do Composer da verificação de patches; executar o verificador antes de instalar/atualizar dependências e antes de abrir o banco.
- [x] Atualizar o lockfile sem alterar versões de bibliotecas, validar os testes e documentar o procedimento do servidor.
- [x] Manter o trabalho na main, conforme preferência explícita do responsável pelo projeto.

## SQLite

O número do pacote instalado do sistema não comprova qual biblioteca o PDO carrega. Execute na pasta do projeto `php bin/runtime-check.php --json`; esse comando não abre a base escolar. A revisão `3.45.1-1ubuntu2.8` contém correções de outras vulnerabilidades, mas seu arquivo oficial de patches não contém a correção WAL-reset. Não a considere corrigida apenas por aparecer em noble-security. Verificação do PHP aprovada não libera SQLite incompatível.

Fonte do pacote SQLite inspecionado: [arquivo oficial de patches Ubuntu](https://archive.ubuntu.com/ubuntu/pool/main/s/sqlite3/sqlite3_3.45.1-1ubuntu2.8.debian.tar.xz). Critério WAL: [documentação SQLite](https://sqlite.org/wal.html#the_wal_reset_bug).

## Comprovação do PHP e atualização

O verificador lê `/etc/os-release`, o inventário instalado do dpkg e os checksums do pacote correspondente ao SAPI atual. Confere a imagem do processo em `/proc/self/exe`, ou o módulo PHP mapeado no Apache, para não confundir pacote atualizado com processo antigo ainda em execução. CLI/servidor embutido, FPM, CGI e Apache usam seus próprios pacotes. Não chama shell, não acessa `.env` nem banco, não altera arquivos do sistema e não considera uma variável de ambiente como prova de patch.

O checksum MD5 é usado apenas para comparar o arquivo com o inventário local do sistema, cuja integridade e privilégios administrativos são pressupostos; não é usado como assinatura para downloads. Um PHP customizado, arquivo divergente ou pacote de outra distribuição não recebe essa exceção.

Após atualizar os pacotes, reinicie o serviço que efetivamente atende o site (PHP-FPM ou Apache com mod_php). Processos antigos serão recusados. Chroot, open_basedir ou ausência de acesso a procfs/inventário também impedem essa comprovação: providencie uma configuração que permita a leitura desses metadados ou use uma versão upstream corrigida. Não publique phpinfo para inspecionar o servidor.

O requisito de linguagem no Composer passou a `>=8.3.6 <8.6` para permitir resolver esse pacote oficial. A política de segurança fica no verificador, executado automaticamente nos eventos pre-install/pre-update e antes da conexão da aplicação ao banco. Não execute Composer com `--ignore-platform-reqs` nem desative o verificador para liberar um SQLite incompatível.

## Evidências de 23/10/2026

O teste do pacote corrigido falhou antes da implementação, e passou após a correção. O mesmo ciclo reproduziu uma omissão inicial do SAPI cli-server, encontrada na revisão independente e corrigida antes da entrega. Testes específicos: oito testes, 47 asserções; revisão independente também aprovada.

A detecção foi exercitada com binário e inventário extraídos do pacote oficial `php8.3-cli_8.3.6-0ubuntu0.24.04.13_amd64.deb`, sem executar esse binário Linux no Windows. O resultado identificou corretamente pacote e revisão. Os casos de processo/módulo usam fixtures isoladas; isso não equivale a executar o verificador em Ubuntu nativo, FPM ou Apache do servidor institucional.

Suíte integrada local (`bin/gse.ps1 check`, saída 0): 581 testes, 5.566 asserções e dois testes específicos do sistema operacional ignorados no Windows; 604 verificações HTTP aprovadas e nenhum aviso de vulnerabilidade conhecido retornado pela auditoria de dependências. Sintaxe aprovada em 241 arquivos PHP e PHPStan sem erros. O comando Composer `run-script pre-install-cmd` executou o verificador; `install --dry-run` confirmou que o lockfile não exige mudanças de bibliotecas. O lockfile mudou apenas no hash de conteúdo e no requisito PHP de plataforma.

No servidor informado, o próximo diagnóstico é `cd /var/www/GSE` seguido de `php bin/runtime-check.php --json`, depois de atualizar o código. Isso precisa mostrar a evidência do pacote PHP e a versão efetivamente carregada do SQLite antes de instalar dependências ou migrar a base escolar.
