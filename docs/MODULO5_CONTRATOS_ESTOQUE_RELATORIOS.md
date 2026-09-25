# Módulo 5 — Contratos, Estoque e Relatórios

## Fontes e alcance

Esta implementação parte da branch `Modulo5` em `f413a59fed7bac6af22a4cdcfb1e0d9f9f557518`. O original `rodrigoraa/ProjetoGSE` foi consultado no commit `f0bb641b2d1a074bddd598e52f3e733872d230db`, nas telas, modelos, controllers e CSS de contratos e relatórios. O PDF acadêmico `TCC_2_ETAPA_1_MÓDULO_1 - Rodrigo-Calebe.pdf` não estava disponível no ambiente. A matriz abaixo usa **somente as referências transcritas** no prompt recebido; a conferência literal do PDF, inclusive hash `d459c6cec1bddf7225fdbfe4062ffc4c5f3ff540fff22eb61d2ca6ddd51e1afe`, permanece pendente. O PDF versionado em `Documentação/` é outra edição.

| Fonte transcrita | Exigência ou interpretação | Implementação | Evidência | Situação |
|---|---|---|---|---|
| Tabela 11, p. 28; UC006, Tabela 9/Figura 7, p. 23 | Contratos, folhas e produtos; cadastro, lista, edição e exclusão | `Contrato`, rotas `/contrato`, folhas estáveis, itens e exclusão lógica | `ModuloCincoTest`, HTTP smoke | Implementado; conferência literal pendente |
| Objetivos, p. 13; entrevista, p. 14, Q5; RF008, p. 15 | Entrada, saída, autoria, saldo e aviso de mínimo | `estoque_movimentos`, `/estoque`, histórico e alerta inclusivo | `ModuloCincoTest` | Implementado; homologação operacional pendente |
| RF007, p. 15 | Autoria de alterações sensíveis | auditoria obrigatória junto à transação, usuário nos movimentos | teste de rollback de auditoria | Implementado |
| UC007, Tabela 10/Figura 8, p. 24 | Relatório de alunos por turma e DVA, visualização e exportação | `/relatorio`, prévia paginada, PDF e CSV (Excel) | teste DVA corrente, HTTP PDF/CSV, PDF de três páginas | Implementado; conferência literal pendente |
| Figuras 19–20, p. 29–30; Figura 22, p. 30 | Organização visual de contratos e relatórios | sidebar azul, cartões, formulários e tabelas responsivas | CSS e inspeção de PDF | Homologação visual de navegador pendente |

O vínculo com fornecedor reutiliza `lista_fornecedores` do Módulo 4, por integração com a entrevista; não é um campo explicitamente listado no UC006. Duplicação de folha, faturamento documental e impressão seguem recursos do original, adaptados ao desenho atual. A exclusão lógica, os limites financeiros e de estoque e as permissões detalhadas abaixo são **decisões técnicas**, não citações do TCC.

## Dados, valores e migração

A migração v15 amplia `pedidos`, `pedido_paginas` e `pedido_produtos` sem renomear IDs e sem criar um segundo acervo. Ela cria `estoque_movimentos` e `modulo5_valores_legados`. Valores `REAL` legados ficam preservados como texto nesta última tabela. Campos em centavos permanecem nulos até conciliação explícita pelo administrador; nenhuma quantidade antiga vira saldo físico. A tela sinaliza o estado pendente. A conciliação de item exige quantidade inteira e preço confirmado; a de contrato exige itens conciliados e valor suficiente. A operação é auditada. Uma quantidade legada fracionária exige decisão documental antes da conciliação; não há truncamento automático.

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
