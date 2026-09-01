# Módulo 3 — Arquivo Passivo

## Escopo funcional

O RF004/UC004 localiza fisicamente as pastas de ex-alunos por caixa e número/posição. O módulo inclui painel, cards de caixas, contagem, navegação anterior/próxima, busca global sem acento, filtro, paginação, ordenação permitida, cadastro, detalhes, edição, ciclo lógico, CSV aditivo, enumeração, TXT e integração explícita com alunos inativos. Os Módulos 4 e 5 permanecem fora do escopo.

O aluno original nunca é excluído. O envio copia nome e nascimento, grava `aluno_origem_id`, exige caixa, confirmação e administrador e ocorre em `BEGIN IMMEDIATE`. DVA, turma e demais relacionamentos não são alterados. O índice `ux_passivo_aluno_origem_ativo` impede dois registros ativos do passivo para o mesmo aluno.

Registros ativos com `localizacao_pendente=1` exibem “Revisão pendente” em amarelo; ativos regulares usam verde e inativos usam vermelho. O texto explícito acompanha a cor em todos os casos.

## Permissões

| Operação | Funcionário | Administrador |
|---|---:|---:|
| Consultar, pesquisar, filtrar e detalhar | permitido | permitido |
| Cadastrar e editar | permitido | permitido |
| Exportar TXT via POST + CSRF | permitido | permitido |
| Inativar e restaurar | bloqueado | permitido |
| Importar CSV | bloqueado | permitido |
| Enumerar caixas | bloqueado | permitido |
| Enviar aluno inativo ao passivo | bloqueado | permitido |

Toda rota exige autenticação. O Router retorna 403 para perfil insuficiente, 404 para ID inexistente, 405 com `Allow` para método incorreto e 419 para CSRF inválido. GET não altera estado.

## Normalização e conflitos

`TextNormalizer::searchKey()` é exclusivo para pesquisa. Ele consolida espaços, normaliza Unicode, converte para minúsculas e remove marcas diacríticas. `comparisonKey()` dos Módulos 1 e 2 não mudou. Consultas `LIKE` escapam `\\`, `%` e `_`, limitam o termo a 100 caracteres e usam parâmetros.

Nome, caixa e número mantêm o valor de exibição e a chave normalizada. Número é opcional; caixa é obrigatória em novos cadastros. A aplicação detecta caixa/número ocupado e apresenta conflito, mas a v12 não cria unicidade física sem regra escolar comprovada. Colisões legadas permanecem intactas para revisão em homologação.

## CSV

Formato obrigatório:

```text
Nome;Data;Numero;Caixa
Maria Exemplo;2000-01-31;12;CX-01
```

- UTF-8, com ou sem BOM;
- datas `YYYY-MM-DD` ou `DD/MM/YYYY`;
- exatamente quatro colunas;
- até 2 MiB e 5.000 linhas de dados;
- MIME textual e arquivo regular recebido por upload HTTP com `is_uploaded_file()`;
- prévia com válidos, inválidos, duplicados e conflitos;
- até 50 erros exibidos com número da linha;
- token aleatório de 256 bits, vinculado à sessão e ao administrador, TTL de 15 minutos e uso único;
- temporário com nome aleatório fora de `public` e permissão restrita quando POSIX;
- confirmação que revalida SHA-256, arquivo e a impressão digital da análise diante do banco atual;
- inserção das linhas válidas em uma única transação;
- rollback integral diante de falha de inserção ou auditoria obrigatória;
- remoção do temporário após confirmação, falha ou expiração;
- auditoria com contagens, nunca conteúdo do CSV ou nome completo.

A importação comum é exclusivamente aditiva. O comportamento original que executava `DELETE FROM alunos_passivo` foi removido. Substituição completa não está implementada.

## Ferramentas

A enumeração seleciona uma caixa existente, ordena registros ativos sem número por `nome_normalizado`, inicia depois do maior número inteiro daquela caixa, preserva todos os números existentes, mostra prévia e revalida tudo na confirmação. Qualquer mudança ou conflito impede a aplicação inteira.

O TXT usa `Número - Nome`, ordem numérica/normalizada determinística, `Content-Type: text/plain; charset=UTF-8`, `nosniff`, `no-store` e nome de arquivo derivado apenas de chave segura. Valores potencialmente interpretados como fórmula recebem apóstrofo defensivo.

## Retenção e LGPD

O módulo oferece somente inativação lógica e restauração. O trigger `trg_prevent_passive_delete` bloqueia exclusão física acidental. Eliminação definitiva futura depende de base legal, política de retenção, autorização e procedimento formal da escola; está fora do Módulo 3.

## Auditoria e testes

Operações transacionais gravam auditoria obrigatória na mesma transação. Falha de `security_audit` provoca rollback. Eventos: `passive.created`, `passive.updated`, `passive.deactivated`, `passive.reactivated`, `passive.student_archived`, `passive.import_previewed`, `passive.import_completed`, `passive.import_failed`, `passive.enumeration_previewed`, `passive.enumerated`, `passive.exported`, bloqueios de autorização e conflitos.

Os testes automatizados cobrem limites e MIME do CSV, UTF-8/cabeçalho/colunas, expiração e vínculo do token, uso único, alteração do temporário, mudança concorrente do banco, rollback do lote e da auditoria, matriz HTTP de permissões, métodos/CSRF/404 e garantias da migração v12. A homologação visual e a migração de uma cópia anonimizada real continuam no [roteiro manual](MODULO3_VALIDACAO_MANUAL.md).
