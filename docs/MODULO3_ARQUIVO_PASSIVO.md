# Módulo 3 — Arquivo Passivo

## Escopo funcional

O RF004/UC004 localiza fisicamente as pastas de ex-alunos por caixa e número/posição. O módulo inclui painel, cards de caixas, contagem, navegação anterior/próxima, busca global sem acento, filtro, paginação, ordenação permitida, cadastro, detalhes, edição, ciclo lógico, CSV aditivo, enumeração, TXT e integração explícita com alunos inativos. Os Módulos 4 e 5 permanecem fora do escopo.

O aluno original nunca é excluído. O envio copia nome e nascimento, grava `aluno_origem_id`, exige caixa, confirmação e administrador e ocorre em `BEGIN IMMEDIATE`. DVA, turma e demais relacionamentos não são alterados. O índice `ux_passivo_aluno_origem_ativo` impede dois registros ativos do passivo para o mesmo aluno.

O envio em lote permite selecionar alunos ativos ou inativos que saíram da escola. Na confirmação, alunos ainda ativos são inativados na mesma transação do arquivamento; cadastros, contatos, turma e todo o histórico de DVA são preservados. O envio individual anterior continua disponível para alunos já inativos.

Registros ativos com `localizacao_pendente=1` exibem “Revisão pendente” em amarelo; ativos regulares usam verde e excluídos logicamente usam vermelho com o texto “Excluído do acervo ativo”. O texto explícito acompanha a cor em todos os casos.

## Permissões

| Operação | Funcionário | Administrador |
|---|---:|---:|
| Consultar, pesquisar, filtrar e detalhar | permitido | permitido |
| Cadastrar e editar | permitido | permitido |
| Exportar TXT via POST + CSRF | permitido | permitido |
| Excluir logicamente e consultar excluídos | permitido | permitido |
| Restaurar | bloqueado | permitido |
| Importar CSV | bloqueado | permitido |
| Enumerar caixas | bloqueado | permitido |
| Enviar aluno inativo ao passivo | bloqueado | permitido |
| Selecionar e enviar alunos em lote | bloqueado | permitido |

Toda rota exige autenticação. O Router retorna 403 para perfil insuficiente, 404 para ID inexistente, 405 com `Allow` para método incorreto e 419 para CSRF inválido. GET não altera estado.

`POST /passivo/excluir/{id}` executa apenas exclusão lógica, ignorando qualquer tentativa de enviar `ativo=1`. A rota legada `/passivo/status/{id}` permanece administrativa, inclusive para restauração. A permissão do UC004 não libera CSV, enumeração nem envio de aluno. A justificativa documental está na [matriz da revisão do TCC](MODULO3_REVISAO_TCC.md).

## Normalização e conflitos

`TextNormalizer::searchKey()` é exclusivo para pesquisa. Ele consolida espaços, normaliza Unicode, converte para minúsculas e remove marcas diacríticas. `comparisonKey()` dos Módulos 1 e 2 não mudou. Consultas `LIKE` escapam `\\`, `%` e `_`, limitam o termo a 100 caracteres e usam parâmetros.

Nome, caixa e número mantêm o valor de exibição e a chave normalizada. Número é opcional; caixa é obrigatória em novos cadastros. A aplicação detecta caixa/número ocupado e apresenta conflito, mas a v12 não cria unicidade física sem regra escolar comprovada. Colisões legadas permanecem intactas para revisão em homologação.

Excluídos não ocupam posições do acervo ativo. A edição de um excluído preserva sua situação e pode corrigir seus dados sem disputar a posição ativa. A restauração revalida posição e vínculo de aluno antes de retornar ao acervo. Filtros e navegação de caixas acompanham a situação consultada; resumo, TXT e enumeração continuam considerando apenas ativos. Exportar uma caixa sem ativos retorna à consulta com mensagem explicativa, inclusive para funcionários.

## CSV

A tela **Arquivo Passivo → Importar CSV** oferece **Baixar modelo CSV**. O arquivo contém somente o cabeçalho abaixo, em UTF-8 com BOM, sem registros de exemplo. Preencha a partir da segunda linha, preserve as quatro colunas e salve em CSV UTF-8 separado por ponto e vírgula. Nome e Caixa são obrigatórios; Data de nascimento e Numero são opcionais.

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

A importação comum é exclusivamente aditiva: adiciona registros válidos sem executar `DELETE FROM alunos_passivo` ou substituir o acervo. Substituição completa não está implementada.

## Ferramentas

### Envio de alunos em lote

1. Em **Gestão de Alunos**, marque os alunos que saíram da escola, por transferência ou conclusão do ensino médio. A seleção acompanha páginas e filtros, até 200 alunos; **Selecionar esta página** e **Limpar seleção** ajudam a revisar o lote. Alunos com vínculo ativo no passivo ficam indisponíveis para nova seleção.
2. Clique em **Escolher caixa e conferir** e escolha **Caixa existente** ou **Nova caixa**.
3. Gere a prévia. As pastas recebem números sequenciais em ordem alfabética dos alunos, depois do maior número inteiro já usado na caixa, considerando inclusive registros excluídos do acervo ativo. Os números anteriores são preservados. Uma nova caixa começa em 1.
4. Confira os alunos, a caixa e as posições, marque a confirmação e envie. O sistema abre a caixa de destino e limpa a seleção.

A prévia não grava alunos nem pastas. A confirmação reconsulta alunos e caixa sob `BEGIN IMMEDIATE`, verifica a impressão digital da prévia e exige nova conferência se os dados mudarem. Falha de inserção ou auditoria provoca rollback integral, incluindo as inativações. Reenvio não duplica o lote.

Seleção e prévia usam tokens aleatórios vinculados à sessão e ao administrador, com validade de 30 minutos. IDs e destino confirmados vêm do estado do servidor. Todas as etapas de escrita exigem POST e CSRF; GET apenas consulta. O navegador guarda somente IDs da seleção por aba e usuário; sem JavaScript, a seleção funciona na página atual.

### Enumeração e exportação

A enumeração seleciona uma caixa existente, ordena registros ativos sem número por `nome_normalizado`, inicia depois do maior número inteiro daquela caixa, preserva todos os números existentes, mostra prévia e revalida tudo na confirmação. Qualquer mudança ou conflito impede a aplicação inteira.

O TXT usa `Número - Nome`, ordem numérica/normalizada determinística, `Content-Type: text/plain; charset=UTF-8`, `nosniff`, `no-store` e nome de arquivo derivado apenas de chave segura. Valores potencialmente interpretados como fórmula recebem apóstrofo defensivo.

## Retenção e LGPD

O módulo consolida a inativação existente como exclusão lógica (`ativo=0`), com restauração administrativa. O UC004 usa “exclusão” sem definir se é física ou lógica: esta é uma interpretação conservadora, ainda pendente de validação acadêmica. O trigger `trg_prevent_passive_delete` continua bloqueando exclusão física. Dados, vínculos e auditoria são preservados. Eliminação definitiva não foi implementada nesta revisão.

## Auditoria e testes

Operações transacionais gravam auditoria obrigatória na mesma transação. Falha de `security_audit` provoca rollback. Eventos: `passive.created`, `passive.updated`, `passive.deactivated`, `passive.reactivated`, `passive.student_archived`, `passive.batch_archived`, `student.deactivated`, `passive.import_previewed`, `passive.import_completed`, `passive.import_failed`, `passive.enumeration_previewed`, `passive.enumerated`, `passive.exported`, bloqueios de autorização e conflitos.

Os testes automatizados cobrem o download do modelo e sua importação após preenchimento, limites e MIME do CSV, UTF-8/cabeçalho/colunas, expiração e vínculo do token, uso único, alteração do temporário, mudança concorrente do banco, rollback do lote e da auditoria, matriz HTTP de permissões, métodos/CSRF/404 e garantias da migração v12. A homologação visual e a migração de uma cópia anonimizada real continuam no [roteiro manual](MODULO3_VALIDACAO_MANUAL.md).

O arquivamento em lote acrescenta 11 testes de integração com 140 asserções e 44 verificações HTTP. `node tests/browser-passivo-batch.mjs` executa 32 verificações na aplicação autenticada, com Chrome isolado e banco temporário fictício: seleção entre páginas/filtros, caixa existente/nova, numeração, prévia sem escrita, confirmação, preservação das DVAs, limpeza da seleção e interface em 1366×768 e 390×844.
