# Homologação e migração do Módulo 3

Este documento é um roteiro pendente de homologação, não uma evidência de execução em banco real.

## Antes da janela

1. Use uma cópia anonimizada recente do SQLite real e a mesma versão de PHP 8.3, SQLite e `ext-intl` da produção.
2. Registre contagem, IDs, maior ID, `sqlite_sequence`, nomes, datas, números, caixas, relacionamentos e colisões de caixa/número.
3. Confirme espaço para o backup `pre-migration` e que o diretório não é publicado pelo servidor web.
4. Interrompa todos os escritores durante o ensaio.

## Executar

```bash
php bin/init-db.php
```

O inicializador cria e valida um backup antes da v12, desliga FKs antes do `BEGIN IMMEDIATE` somente quando o rebuild é necessário, copia colunas explicitamente, troca a tabela, restaura `sqlite_sequence`, índices, triggers e o estado anterior de FKs. Falha em qualquer etapa, inclusive ao registrar a versão, reverte o rebuild e não deixa `alunos_passivo_v12`.

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

Resultado esperado: versões 1 a 12, `user_version=12`, nenhuma FK inválida, integridade `ok`, nenhuma tabela temporária e equivalência de contagem, IDs, valores, relacionamentos e sequência com o relatório anterior. Registros sem caixa devem ser preservados com `localizacao_pendente=1`. Colisões devem permanecer e entrar em lista de revisão.

## Resolver dados inconsistentes

Nunca exclua, mescle ou renumere automaticamente. Trabalhe em cópia de homologação, confirme a pasta física com a escola, documente a decisão e use a tela de edição depois da migração. Se UTF-8 inválido ou FK quebrada interromper a v12, corrija a origem na cópia com trilha de aprovação e repita a partir do backup.

## Rollback operacional

Se a aplicação não puder abrir após a migração, mantenha a manutenção, encerre processos PHP, preserve uma cópia do estado com falha, abra o backup preventivo e exija `integrity_check=ok`. Restaure o arquivo somente com sidecars `-wal`/`-shm` fechados, reaplique permissões, execute `foreign_key_check`, `integrity_check` e `user_version` e valide primeiro em homologação. O sistema não substitui o banco automaticamente.

## Checklist funcional pendente

- [ ] cards, contagens, caixa anterior/próxima, busca, filtro, página e ordem;
- [ ] cadastro, edição, detalhes, 404, PRG, XSS e conflitos;
- [ ] “Revisão pendente” em amarelo, “Ativo” em verde e “Inativo” em vermelho;
- [ ] inativação e restauração sem `DELETE`;
- [ ] aluno ativo bloqueado, aluno inativo vinculado e DVA preservada;
- [ ] CSV com BOM, datas, erros, duplicidade, conflito, rollback, TTL e uso único;
- [ ] enumeração apenas sem número e por caixa;
- [ ] TXT, headers e cache;
- [ ] funcionário e administrador, 403, 405, 419 e auditoria;
- [ ] desktop, celular, teclado, foco e leitores de tela.

Registre data, responsável, evidência e resultado em [MODULO3_VALIDACAO_MANUAL.md](MODULO3_VALIDACAO_MANUAL.md). Não marque os itens como concluídos sem evidência real.
