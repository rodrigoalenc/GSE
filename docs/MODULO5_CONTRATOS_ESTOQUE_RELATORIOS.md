# Módulo 5 — Contratos, Estoque e Relatórios

## Fontes e alcance

Esta revisão parte da branch `Modulo5` em `7fb9cd4a5df2b7a18f3a3e5981aebb9e345a6a64`. A árvore pública do [projeto original](https://github.com/rodrigoraa/ProjetoGSE) foi localizada, mas o conteúdo das views e dos CSS no commit `f0bb641b2d1a074bddd598e52f3e733872d230db` não pôde ser aberto ou clonado neste ambiente. A aproximação visual foi feita a partir dos requisitos recebidos e da identidade azul já existente no projeto; comparação visual fiel com o original continua pendente. O PDF acadêmico `TCC_2_ETAPA_1_MÓDULO_1 - Rodrigo-Calebe.pdf` não foi anexado junto ao texto e não estava disponível no repositório. A matriz abaixo usa **somente as referências transcritas** no pedido; RF007/RF008 (p. impressa 15), requisitos não funcionais (p. 16), UC006/tabela 9/figura 7 (p. 23), UC007/tabela 10/figura 8 (p. 24), tabela 11 (p. 28) e figuras 19, 20 e 22 (p. 29–30) aguardam conferência literal. O PDF versionado em `Documentação/` não foi tratado como equivalente.

| Fonte transcrita | Exigência ou interpretação | Implementação | Evidência | Situação |
|---|---|---|---|---|
| Tabela 11, p. 28; UC006, Tabela 9/Figura 7, p. 23 | Contratos, folhas e produtos; cadastro, lista, edição e exclusão | `Contrato`, rotas `/contrato`, folhas estáveis, itens e exclusão lógica | `ModuloCincoTest`, HTTP smoke | Implementado; conferência literal pendente |
| Objetivos, p. 13; entrevista, p. 14, Q5; RF008, p. 15 | Entrada, saída, autoria, saldo e aviso de mínimo | `estoque_movimentos`, `/estoque`, histórico e alerta inclusivo | `ModuloCincoTest` | Implementado; homologação operacional pendente |
| RF007, p. 15 | Autoria de alterações sensíveis | auditoria obrigatória junto à transação, usuário nos movimentos | teste de rollback de auditoria | Implementado |
| UC007, Tabela 10/Figura 8, p. 24 | Relatório de alunos por turma e DVA, visualização e exportação | `/relatorio`, prévia paginada, PDF e CSV (Excel) | teste DVA corrente, HTTP PDF/CSV, PDF de três páginas | Implementado; conferência literal pendente |
| Figuras 19–20, p. 29–30; Figura 22, p. 30 | Organização visual de contratos e relatórios | sidebar azul, cartões, formulários e tabelas responsivas | CSS e inspeção de PDF | Homologação visual de navegador pendente |

O vínculo com fornecedor reutiliza `lista_fornecedores` do Módulo 4, por integração com a entrevista; não é um campo explicitamente listado no UC006. Duplicação de folha, faturamento documental e impressão seguem recursos do original, adaptados ao desenho atual. A exclusão lógica, os limites financeiros e de estoque e as permissões detalhadas abaixo são **decisões técnicas**, não citações do TCC.

### Separação das fontes

- **Exigências expressas do TCC:** apenas as referências de RF, UC, tabela e figuras transcritas no pedido acima podem ser identificadas; o texto do PDF não foi conferido. Não há declaração de conformidade literal.
- **Comportamentos preservados do original:** fluxo de contratos com fornecedor, notas, itens, faturamento documental, duplicação e impressão, conforme recursos descritos no pedido e na implementação anterior. A aparência exata do original ainda exige comparação com seus arquivos reais.
- **Decisões técnicas adicionais:** abertura explícita do estoque, revisão administrativa de zero antigo ambíguo, centavos, exclusão lógica, idempotência, auditoria obrigatória, controle de revisão, proteção de downloads e limites de exportação.

### Revisão funcional desta entrega

O cadastro oferece folhas e múltiplos produtos na mesma tela. A prévia calcula itens e saldo contratual; o servidor valida nome, marca, unidade, quantidades, preços, teto do contrato e limites de 20 folhas e 100 produtos. Contrato, notas, produtos e auditoria são gravados na mesma transação. Nenhum item cadastrado produz entrada física de estoque. O endpoint antigo de cadastro simples continua aceito para clientes já integrados.

A lista consulta ativos ou excluídos com busca e paginação, e informa data e responsável da exclusão. Os indicadores ativos cobrem toda a busca, não só a página. Contratos e notas excluídos não entram nos indicadores; o valor soma apenas contratos com valor em centavos já conferido e mostra quantos ainda precisam de conciliação. A página histórica permite ver contrato, notas, produtos, movimentos e impressão sem formulários de alteração.

Salvar limites sem saldo de abertura mantém `estoque_inicializado=0`. A abertura exige saldo explícito, inclusive `0`, chave de operação única, revisão corrente, transação e auditoria; legado exige administrador. Movimentos permanecem bloqueados antes da abertura. Registros antigos com `estoque_inicializado=1`, nenhum movimento e nenhuma operação de abertura registrada são **ambíguos**: podem ser zero legítimo ou a inicialização incorreta da versão anterior. Nenhum deles é redefinido automaticamente. A tela os sinaliza, bloqueia novas movimentações e oferece ao administrador confirmação auditada do zero após conferência documental e contagem física, com motivo. Se o saldo real não for zero, não confirme: registre a ocorrência e faça reconciliação supervisionada antes de movimentar. A operação não inventa movimento nem reescreve saldos.

A unidade do produto fica imutável após o primeiro movimento, mesmo quando o saldo volta a zero. Para usar outra unidade, cadastre outro produto. O histórico mostra contrato, folha, produto, unidade, sinal e tipo do movimento, responsável, motivo, vínculo de estorno e data/hora no fuso configurado. A regra evita atribuir unidade nova a movimentos antigos sem registro próprio de unidade. Movimentos anteriores a esta correção não registravam a unidade em cada linha: se a unidade de um produto já tiver sido alterada no passado, o sistema não consegue reconstruir seu valor antigo e exige conferência documental antes de interpretar esse histórico.

As telas atualizadas usam cabeçalho, métricas, filtros e botões em azul compatíveis com a identidade atual, abas de folhas com navegação por teclado e tabela com rolagem horizontal em telas estreitas. A aproximação visual permanece **não homologada** porque não houve acesso ao conteúdo dos arquivos originais nem navegador funcional. A ausência de JavaScript mantém as folhas visíveis em sequência e o formulário básico utilizável.

## Dados, valores e migração

A migração v15 amplia `pedidos`, `pedido_paginas` e `pedido_produtos` sem renomear IDs e sem criar um segundo acervo. Ela cria `estoque_movimentos` e `modulo5_valores_legados`. Valores `REAL` legados ficam preservados como texto nesta última tabela. Campos em centavos permanecem nulos até conciliação explícita pelo administrador; nenhuma quantidade antiga vira saldo físico. A tela sinaliza o estado pendente. A conciliação de item exige quantidade inteira e preço confirmado; a de contrato exige itens conciliados e valor suficiente. A operação é auditada. Uma quantidade legada fracionária exige decisão documental antes da conciliação; não há truncamento automático.

Esta revisão não altera o esquema nem exige nova migração: usa a tabela `modulo5_operacoes` já criada pela v15. A v15 trata apenas as tabelas internas `pedidos`, `pedido_paginas` e `pedido_produtos`; ela **não importa automaticamente** `contratos` ou `contrato_folhas` de uma instalação independente do projeto original. Uma importação entre projetos requer mapeamento e validação próprios antes de uso institucional.

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
