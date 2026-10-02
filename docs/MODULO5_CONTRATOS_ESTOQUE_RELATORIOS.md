# Módulo 5 — Contratos, Estoque e Relatórios

## Fontes e alcance

Revisão de 02/10/2026, branch `Modulo5`, a partir de `755d28d2c3a69844e3413ff4ea49eefc6bb4b4c2`, com árvore inicialmente limpa. O PDF acadêmico `TCC_2_ETAPA_1_MÓDULO_1 - Rodrigo-Calebe.pdf` não foi localizado no repositório nem nos diretórios locais de anexos consultados. O arquivo versionado em `Documentação/` é outra edição e não foi usado como equivalente. As referências de páginas abaixo foram recebidas na solicitação e aguardam leitura literal. A descrição funcional é sustentada pelo código e pelos testes, sem declaração de aprovação acadêmica.

| Referência recebida | Comportamento a conferir no PDF | Implementação atual | Teste ou evidência do GSE | Situação |
|---|---|---|---|---|
| UC006, tabela 9 e figura 7, p. 23; tabela 11, p. 28 | Cadastro, lista, edição e exclusão de contratos, notas e produtos | `Contrato`, rotas `/contrato`, IDs estáveis, exclusão lógica | `ModuloCincoTest`, integração HTTP, evidências de contratos | Implementado; texto acadêmico não conferido |
| RF007/RF008, p. 15 | Relação dos requisitos com estoque e autoria | `estoque_movimentos`, `/estoque`, auditoria transacional, alerta de mínimo | `LegacyStockRecoveryTest`, `ModuloCincoTest`, HTTP | Implementação verificada; correspondência literal pendente |
| UC007, tabela 10 e figura 8, p. 24 | Relatório de alunos, turma, DVA, visualização e exportação | `/relatorio`, prévia paginada, PDF e CSV | Testes de DVA corrente, filtros, PDF/CSV e limites | Implementado; texto acadêmico não conferido |
| Figuras 19, 20 e 22, p. 29–30 | Organização das telas de contratos e relatórios | Cabeçalhos, indicadores, formulários, abas e impressão | Capturas finais com dados fictícios; ensaio de impressão | Conferência das figuras acadêmicas pendente |
| Requisitos não funcionais, p. 16, e módulos anteriores | Segurança, desempenho e usabilidade móvel exigidos pelo documento | Autorização, CSRF, sessões, arquivos privados, limites de exportação e layout responsivo | Testes de segurança/HTTP e navegador em três resoluções | Código verificado; requisitos literais e homologação institucional pendentes |

O vínculo com fornecedor reutiliza `lista_fornecedores` do Módulo 4. Notas, itens, duplicação, faturamento documental e impressão fazem parte do fluxo implementado do GSE. A exclusão lógica, os limites financeiros e de estoque e as permissões detalhadas abaixo são decisões técnicas. A ausência do PDF impede atribuir esses detalhes a requisitos expressos, entrevistas ou decisões do orientador.

### Separação das fontes

- **Referências acadêmicas recebidas:** RF, UC, tabelas e figuras acima; o texto do PDF não foi conferido.
- **Comportamentos implementados e testados:** contratos com fornecedor, notas, itens, faturamento documental, duplicação, impressão, estoque e relatórios.
- **Decisões técnicas:** abertura explícita, recuperação administrativa de estoque antigo sem comprovação, centavos, exclusão lógica, idempotência, auditoria, revisões, proteção de downloads e limites de exportação.

### Revisão funcional desta entrega

O cadastro oferece folhas e múltiplos produtos na mesma tela. A prévia calcula itens e saldo contratual; o servidor valida nome, marca, unidade, quantidades, preços, teto do contrato e limites de 20 folhas e 100 produtos. Contrato, notas, produtos e auditoria são gravados na mesma transação. Nenhum item cadastrado produz entrada física de estoque. O endpoint antigo de cadastro simples continua aceito para clientes já integrados.

A lista consulta ativos ou excluídos com busca e paginação, e informa data e responsável da exclusão. Os indicadores ativos cobrem toda a busca, não só a página. Contratos e notas excluídos não entram nos indicadores; o valor soma apenas contratos com valor em centavos já conferido e mostra quantos ainda precisam de conciliação. A página histórica permite ver contrato, notas, produtos, movimentos e impressão sem formulários de alteração.

Salvar limites sem saldo de abertura mantém `estoque_inicializado=0`. A abertura exige saldo explícito, inclusive `0`, chave única, revisão corrente, transação e auditoria; legado exige administrador. Movimentos permanecem bloqueados antes da abertura. Registros antigos inicializados com saldo zero, sem movimentos nem operação de abertura comprovada são ambíguos. A tela distingue abertura pendente, zero antigo sem comprovação, zero confirmado e saldo confirmado. Nenhum registro é redefinido automaticamente.

O administrador usa **Conferir abertura antiga sem comprovação**, informa a quantidade contada (zero ou positiva), confirma a unidade cadastrada, mínimo/máximo e justificativa documental, e marca a confirmação de contagem física. A operação exige conta administrativa ativa, contrato/nota/produto ativos e vinculados, revisão atual, ausência de movimentos e de abertura comprovada, e quantidade dentro dos limites. Uma contagem positiva gera movimento de abertura identificado como conferência física atual, com usuário e horário atuais. Zero gera comprovação de abertura sem movimento fictício. A tabela `modulo5_operacoes` registra a chave única e o tipo da conferência. Auditoria, movimento, revisão e operação são confirmados na mesma transação; falha da auditoria reverte tudo. Reenvios e disputas entre processos são rejeitados. Não há movimento retroativo nem alteração de saldo histórico.

A unidade do produto fica imutável após o primeiro movimento, mesmo quando o saldo volta a zero. Para usar outra unidade, cadastre outro produto. O histórico mostra contrato, folha, produto, unidade, sinal e tipo do movimento, responsável, motivo, vínculo de estorno e data/hora no fuso configurado. A regra evita atribuir unidade nova a movimentos antigos sem registro próprio de unidade. Movimentos anteriores a esta correção não registravam a unidade em cada linha: se a unidade de um produto já tiver sido alterada no passado, o sistema não consegue reconstruir seu valor antigo e exige conferência documental antes de interpretar esse histórico.

Links internos selecionam o painel ancestral da nota antes de abrir o formulário. URLs diretas, voltar/avançar, teclado, foco, destinos inexistentes ou malformados e retorno da impressão são exercitados no Chrome real por `tests/browser-contract-tabs.mjs`. Fechar o cadastro de produto recolhe o formulário, mantém a nota e devolve o foco ao botão de abertura. Sem JavaScript, as notas continuam visíveis em sequência e os formulários básicos permanecem utilizáveis.

Erros de produto, observação, limites, abertura, movimento, estorno e faturamento mantêm apenas campos permitidos em rascunhos de sessão por operação, recurso e contexto da página. Cada rascunho tem identificador aleatório, expira em 30 minutos, usa limites de tamanho e há no máximo 12 rascunhos por sessão. Atualizar a página preserva a tentativa; sucesso limpa somente o contexto correspondente. CSRF e chaves de operação não são armazenados como preenchimento. Em conflito de revisão, os campos editáveis mostram o estado atual e uma tabela apresenta a tentativa recusada ao lado dele; dados antigos não são reaplicados automaticamente. Recursos excluídos ficam somente para consulta com a tentativa preservada. Testes HTTP exercitam duas abas, escape, conflitos e exclusão concorrente.

As telas finais são verificadas com dados fictícios em 1366×768, 1920×1080 e 390×844. O registro de telas, estados, impressão e limitações está em [REVISAO_VISUAL.md](REVISAO_VISUAL.md). A homologação acadêmica depende da leitura do PDF correto e a operacional depende da instituição.

## Dados, valores e migração

A migração v15 amplia `pedidos`, `pedido_paginas` e `pedido_produtos` sem renomear IDs e sem criar um segundo acervo. Ela cria `estoque_movimentos` e `modulo5_valores_legados`. Valores `REAL` legados ficam preservados como texto nesta última tabela. Campos em centavos permanecem nulos até conciliação explícita pelo administrador; nenhuma quantidade antiga vira saldo físico. A tela sinaliza o estado pendente. A conciliação de item exige quantidade inteira e preço confirmado; a de contrato exige itens conciliados e valor suficiente. A operação é auditada. Uma quantidade legada fracionária exige decisão documental antes da conciliação; não há truncamento automático.

Esta revisão não altera o esquema nem exige nova migração: usa a tabela `modulo5_operacoes` já criada pela v15. A v15 trata apenas as tabelas internas `pedidos`, `pedido_paginas` e `pedido_produtos`; ela **não importa automaticamente** `contratos` ou `contrato_folhas` de outra instalação. Uma importação entre bases requer mapeamento e validação próprios antes de uso institucional.

Dinheiro novo usa centavos inteiros, sem arredondamento implícito: o formulário aceita até duas casas com vírgula, sem separador de milhar. Quantidades são **inteiras** na unidade cadastrada (`un`, `resma`, `caixa` etc.). Quantidade contratada/distribuída é documental. Saldo físico vem exclusivamente da soma de movimentos. Mínimo é o ponto de reposição inclusivo (`saldo <= mínimo`); máximo é o teto físico permitido. Saldo zero é mostrado separadamente. O valor contratado não muda com saídas de estoque. A alocação nova não pode ultrapassar o valor contratado. Folhas parcialmente preenchidas são permitidas.

Folhas mantêm `id` e número originais; uma nova folha recebe o próximo número, inclusive após exclusão lógica. Duplicar copia observação e itens documentais, sem faturamento nem movimentos nem saldo. Excluir produto, folha ou contrato com saldo físico é bloqueado até destinação registrada. Movimentos confirmados são imutáveis; estorno vincula o original, exige administrador e justificativa, e tem unicidade no banco. `BEGIN IMMEDIATE`, revisão e chave persistida de idempotência evitam saldo negativo e reenvio do mesmo movimento.

Instalação nova e atualização usam o inicializador atual e backup preventivo. Antes de atualizar acervo real, pare todos os escritores, faça backup consistente do SQLite **e** dos PDFs privados dos Módulos 1–4, teste restauração conjunta em cópia e revise relatórios de integridade. Se a migração falhar, preserve o banco e o backup; restaure banco, PDFs e versão anterior do código em conjunto. Não aplique v15 em dados institucionais apenas para testar esta branch.

## Permissões e proteção

Visitantes, contas inativas, sessões expiradas e contas com troca obrigatória de senha não acessam telas nem downloads. Funcionários e administradores cadastram e consultam contratos, folhas, produtos, movimentos e relatórios; exclusão lógica operacional é permitida aos dois perfis com confirmação. Apenas administradores corrigem faturamento anterior, estornam e conciliam valores e abertura de estoque legado. Fornecedor inativo permanece exibido em vínculos antigos e não pode ser escolhido para vínculo novo. A autorização está nas rotas e nos controllers, além dos controles da interface.

Alterações usam POST, CSRF, transação curta e `AuditLogger::recordRequired`. A falha da auditoria reverte a alteração. Downloads usam autenticação, `private, no-store` e nome fixo seguro. Relatório PDF usa Dompdf 3.1.6 via Composer, renderiza somente HTML criado pelo servidor, escapa dados, impede rede e PHP executável e restringe caminhos locais. PDF limita 500 alunos; exportações totais limitam 10.000 registros. CSV usa UTF-8 com BOM, delimitador `;`, aspas padronizadas e neutralização de fórmulas iniciadas mesmo após espaço ou caractere de controle. Os logs de exportação incluem apenas usuário, formato, filtros não sensíveis e total; a gravação significa geração iniciada, não comprovação de entrega.

## Demonstração com dados sintéticos

1. Em instalação de teste, crie administrador via `bin/create-admin.php` e funcionário pela interface. Cadastre fornecedor ativo em **Certidões e Fornecedores**.
2. Como funcionário, abra **Contratos**, crie contrato, folha e itens. Edite o título e confirme que folhas e IDs permanecem. Duplique uma folha e confirme saldo físico zero da cópia.
3. Configure mínimo/máximo e abertura no item novo, registre entrada e saída, veja **Estoque** e **Histórico**. Tente saída acima do saldo e reenviar o mesmo formulário: a operação é rejeitada. O administrador pode estornar com justificativa.
4. Filtre **Relatórios** por turma, DVA e aluno ativo/inativo, compare prévia com CSV e PDF, incluindo aluno com DVA histórica. Teste URL direta sem autenticação e confirme redirecionamento.

Não use dados pessoais reais ou credenciais fixas na demonstração. As pendências de homologação e os comandos executados estão em [MODULO5_VALIDACAO.md](MODULO5_VALIDACAO.md).
