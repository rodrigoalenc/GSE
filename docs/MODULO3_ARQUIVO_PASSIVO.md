# Modulo 3 - Arquivo Passivo

## Escopo funcional

O RF004/UC004 localiza fisicamente pastas de ex-alunos por caixa e numero/posicao. O modulo inclui painel, cards de caixas, contagem, navegacao anterior/proxima, busca global sem acento, filtro, paginacao, ordenacao permitida, cadastro, detalhes, edicao, ciclo logico, CSV aditivo, enumeracao, TXT e integracao explicita com alunos inativos.

O aluno original nunca e excluido. O envio copia nome e nascimento, grava `aluno_origem_id`, exige caixa/confirmacao/admin e ocorre em `BEGIN IMMEDIATE`. DVA, turma e demais relacionamentos nao sao alterados. O indice `ux_passivo_aluno_origem_ativo` impede dois registros ativos do passivo para o mesmo aluno.

## Permissoes

| Operacao | Funcionario | Administrador |
|---|---:|---:|
| Consultar, buscar, filtrar e detalhar | sim | sim |
| Cadastrar e editar | sim | sim |
| Exportar TXT via POST + CSRF | sim | sim |
| Inativar e restaurar | nao | sim |
| Importar CSV | nao | sim |
| Enumerar caixa | nao | sim |
| Enviar aluno inativo ao passivo | nao | sim |

Toda rota exige autenticacao. O Router retorna 403 para perfil insuficiente, 404 para ID inexistente, 405/`Allow` para metodo incorreto e 419 para CSRF invalido. GET nao altera estado.

## Normalizacao e conflitos

`TextNormalizer::searchKey()` e exclusivo para pesquisa. Ele consolida espacos, normaliza Unicode, converte para minusculas e remove marcas diacriticas. `comparisonKey()` dos Modulos 1 e 2 nao mudou. Consultas `LIKE` escapam `\\`, `%` e `_`, limitam o termo a 100 caracteres e usam parametros.

Nome, caixa e numero mantem valor de exibicao e chave normalizada. Numero e opcional; caixa e obrigatoria em novos cadastros. A aplicacao detecta caixa/numero ocupado e apresenta conflito, mas a v12 nao cria unicidade fisica sem regra escolar comprovada. Colisoes legadas permanecem intactas para revisao em homologacao.

## CSV

Formato obrigatorio:

```text
Nome;Data;Numero;Caixa
Maria Exemplo;2000-01-31;12;CX-01
```

- UTF-8, com ou sem BOM;
- datas `YYYY-MM-DD` ou `DD/MM/YYYY`;
- exatamente quatro colunas;
- ate 2 MiB e 5.000 linhas;
- MIME textual, arquivo regular recebido por upload;
- previa com validos, invalidos, duplicados e conflitos;
- ate 50 erros exibidos com numero da linha;
- token aleatorio, vinculado a sessao/admin, TTL de 15 minutos e uso unico;
- temporario aleatorio fora de `public`, com permissao restrita quando POSIX;
- confirmacao revalida hash, arquivo e banco;
- insercao das linhas validas em uma unica transacao;
- auditoria contem somente contagens, nunca conteudo ou nome completo.

A importacao comum e aditiva. O comportamento original que executava `DELETE FROM alunos_passivo` foi removido. Substituicao completa nao esta implementada.

## Ferramentas

Enumeracao seleciona uma caixa existente, ordena registros ativos sem numero por `nome_normalizado`, inicia depois do maior numero inteiro daquela caixa, preserva todos os numeros existentes, mostra previa e revalida tudo na confirmacao. Qualquer mudanca/conflito impede a aplicacao inteira.

O TXT usa `Numero - Nome`, ordem numerica/normalizada deterministica, `Content-Type: text/plain; charset=UTF-8`, `nosniff`, `no-store` e nome de arquivo derivado apenas de chave segura. Valores potencialmente interpretados como formula recebem apostrofo defensivo.

## Retencao e LGPD

O modulo oferece somente inativacao logica e restauracao. O trigger `trg_prevent_passive_delete` bloqueia exclusao fisica acidental. Eliminacao definitiva futura depende de base legal, politica de retencao, autorizacao e procedimento formal da escola; esta fora do Modulo 3.

## Auditoria

Operacoes transacionais gravam auditoria obrigatoria na mesma transacao. Falha de `security_audit` provoca rollback. Eventos: `passive.created`, `passive.updated`, `passive.deactivated`, `passive.reactivated`, `passive.student_archived`, `passive.import_previewed`, `passive.import_completed`, `passive.import_failed`, `passive.enumeration_previewed`, `passive.enumerated`, `passive.exported`, bloqueios de autorizacao e conflitos.
