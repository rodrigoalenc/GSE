# Homologacao e migracao do Modulo 3

## Antes da janela

1. Use uma copia recente do SQLite real e a mesma versao de PHP 8.3, SQLite e `ext-intl` da producao.
2. Registre contagem, IDs, maior ID, `sqlite_sequence`, nomes, datas, numeros, caixas e colisoes de caixa/numero.
3. Confirme espaco para o backup `pre-migration` e que o diretorio nao e publicado pelo servidor web.
4. Interrompa todos os escritores durante o ensaio.

## Executar

```bash
php bin/init-db.php
```

O inicializador cria e valida um backup antes da v12, desliga FKs antes do `BEGIN IMMEDIATE` somente quando o rebuild e necessario, copia colunas explicitamente, troca a tabela, restaura `sqlite_sequence`, indices/triggers e o estado anterior de FKs. Falha em qualquer etapa, inclusive ao registrar a versao, reverte o rebuild e nao deixa `alunos_passivo_v12`.

## Validar

```sql
SELECT version FROM schema_migrations ORDER BY version;
PRAGMA user_version;
PRAGMA foreign_key_check;
PRAGMA integrity_check;
SELECT name FROM sqlite_master WHERE name = 'alunos_passivo_v12';
SELECT COUNT(*), MIN(id), MAX(id) FROM alunos_passivo;
SELECT seq FROM sqlite_sequence WHERE name = 'alunos_passivo';
SELECT COUNT(*) FROM alunos_passivo WHERE localizacao_pendente = 1;
SELECT caixa_normalizada, numero_normalizado, COUNT(*)
FROM alunos_passivo
WHERE numero_normalizado IS NOT NULL
GROUP BY caixa_normalizada, numero_normalizado
HAVING COUNT(*) > 1;
```

Resultado esperado: versoes 1..12, `user_version=12`, nenhum FK invalido, integridade `ok`, nenhuma tabela temporaria e equivalencia de contagem/IDs/sequencia com o relatorio anterior. Registros sem caixa devem ser preservados com `localizacao_pendente=1`. Colisoes devem permanecer e entrar em lista de revisao.

## Resolver dados inconsistentes

Nunca exclua, mescle ou renumere automaticamente. Trabalhe em copia de homologacao, confirme a pasta fisica com a escola, documente a decisao e use a tela de edicao depois da migracao. Se UTF-8 invalido ou FK quebrada interromper a v12, corrija a origem na copia com trilha de aprovacao e repita do backup.

## Rollback operacional

Se a aplicacao nao puder abrir apos a migracao, mantenha manutencao, encerre processos PHP, preserve uma copia do estado com falha, abra o backup preventivo e exija `integrity_check=ok`. Restaure o arquivo somente com sidecars `-wal`/`-shm` fechados, reaplique permissoes, execute `foreign_key_check`, `integrity_check` e `user_version`, e valide primeiro em homologacao. O sistema nao substitui o banco automaticamente.

## Checklist funcional

- cards, contagens, caixa anterior/proxima, busca, filtro, pagina e ordem;
- CRUD, 404, PRG, XSS e conflitos;
- inativacao/restauracao sem DELETE;
- aluno ativo bloqueado; aluno inativo vinculado; DVA preservada;
- CSV BOM, datas, erros, duplicidade, conflito, rollback, TTL e uso unico;
- enumeracao apenas sem numero e por caixa;
- TXT, headers e cache;
- funcionario/admin, 403, 405, 419 e auditoria;
- desktop, celular, teclado, foco e leitores de tela.
