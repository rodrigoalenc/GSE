# Revisão visual das telas do GSE

Revisão de 02/10/2026 na branch `Modulo5`. As telas existentes dos cinco módulos foram exercitadas com dados fictícios no Chrome instalado, perfil temporário, escala 1 e janelas de 1366×768, 1920×1080 e 390×844. O navegador integrado não apresentou instância disponível. As evidências abaixo pertencem somente ao GSE.

## Telas e comportamentos verificados

| Área | Telas e estados | Ajustes desta revisão |
|---|---|---|
| Login e menu | Formulário vazio, erro de credenciais, menu reduzido/expandido, desktop e celular | Largura do cartão, tipografia, controles, cores, foco e altura do menu móvel; perfil permanece acessível sem aumentar o rodapé |
| Usuários | Lista, cadastro e edição | Campos, largura do formulário, confirmação de senha, botões e orientação de acesso; política de senha e troca obrigatória preservadas |
| Painel, alunos e DVA | Painel carregado, lista e busca vazia, cadastro, edição, perfil e consulta de DVAs | Cabeçalhos, resumo, formulários, contatos, avisos, ações e histórico recolhível; DVA corrente e versões anteriores preservadas |
| Arquivo Passivo | Lista/caixa, cadastro, edição, importação e ferramentas | Cartões, localização física, dicas, alerta de enumeração e botões; prévia/confirmação, permissões e rastreabilidade mantidas |
| Certidões | Matriz corrente, configuração, cadastro, edição, renovação, detalhes, arquivadas e excluídas | Fonte de ícones local, dimensões da matriz, cores por prazo, botões, filtros, PDF opcional e formulários |
| Contratos | Lista, cadastro integrado, edição, segunda/terceira nota, produto aberto e faturamento | Indicadores, resumo financeiro, abas, faturamento inline, tabela de sete colunas e formulários recolhíveis |
| Estoque e histórico | Abertura pendente, zero antigo sem comprovação, saldo confirmado e histórico | Quantidade contratada separada do estoque físico, recuperação administrativa, limites, autoria, justificativa e consulta de movimentos |
| Relatórios | Gerador, filtros, seleção PDF/CSV e prévia | Agrupamento dos controles, botão principal, filtros de aluno/turma/DVA, datas brasileiras e limites visíveis |
| Impressão | Pedido com três notas, nota individual e pedido com 80 itens | Cabeçalho, resumo, observações, tabela, margens e divisão de notas; repetição do cabeçalho em continuação |

Foram inspecionados alinhamento, dimensões, fontes, assets, rolagem, tabelas, formulários e estados. A política de segurança permanece ativa, sem `unsafe-inline`; ícones e scripts do GSE são servidos localmente. Os estados de erro preservam valores escapados e o formulário correspondente. Tabelas largas usam rolagem própria no celular.

## Evidências com dados fictícios

| Evidência | Legenda |
|---|---|
| [Login móvel](evidencias/2026-10-02/login-390.png) | Formulário de acesso em janela de 390×844 |
| [Painel](evidencias/2026-10-02/painel-1366.png) | Visão administrativa com alunos e prazos fictícios |
| [Alunos](evidencias/2026-10-02/alunos-1366.png) | Listagem de alunos de demonstração |
| [Cadastro de aluno](evidencias/2026-10-02/aluno-cadastro-390.png) | Campos e contatos na apresentação móvel |
| [Arquivo Passivo](evidencias/2026-10-02/passivo-1366.png) | Consulta de caixas e registro físico fictício |
| [Certidões](evidencias/2026-10-02/certidoes-1920.png) | Matriz por fornecedor e tipo com prazo e ações |
| [Configuração](evidencias/2026-10-02/certidoes-config-1366.png) | Listas administrativas de fornecedores e tipos |
| [Pedidos](evidencias/2026-10-02/contratos-1366.png) | Lista, indicadores e acesso aos detalhes |
| [Nota selecionada](evidencias/2026-10-02/contrato-nota2-1366.png) | Segunda nota do pedido, faturamento e itens |
| [Produto aberto](evidencias/2026-10-02/contrato-produto2-1920.png) | Formulário interno da segunda nota |
| [Relatórios](evidencias/2026-10-02/relatorios-1366.png) | Formatos de exportação e filtros |
| [Erro de produto](evidencias/2026-10-02/nota2-validacao.png) | Quantidade recusada com nome literal e preenchimento preservado |
| [Conferência de estoque](evidencias/2026-10-02/abertura-legada.png) | Formulário administrativo de contagem física atual |
| [Impressão das notas](evidencias/2026-10-02/impressao-pedido.png) | Cabeçalho, valores, observações e produtos das três notas |
| [Impressão de nota individual](evidencias/2026-10-02/impressao-nota.png) | Segunda nota com seus próprios total e saldo |
| [Pedido com 80 itens: continuação](evidencias/2026-10-02/impressao-80-itens-pagina-3.png) | Cabeçalho repetido e linhas inteiras |
| [Pedido com 80 itens: última página](evidencias/2026-10-02/impressao-80-itens-pagina-5.png) | Últimos produtos e segunda nota sem corte |
| [Unidade do pedido no desktop](evidencias/2026-10-02/unidades-pedido-1366.png) | Seleção de Litros após adicionar/remover produtos e notas |
| [Unidade do pedido no celular](evidencias/2026-10-02/unidades-pedido-390.png) | Campo de escolha em 390×844, sem escrita livre |
| [Seleção de alunos no desktop](evidencias/2026-10-02/passivo-lote-selecao-1366.png) | Checkbox por aluno e ações para envio ao Arquivo Passivo |
| [Seleção em outra página](evidencias/2026-10-02/passivo-lote-pagina-2-1366.png) | Seleção anterior mantida ao marcar aluno na segunda página |
| [Prévia do lote no desktop](evidencias/2026-10-02/passivo-lote-previa-1366.png) | Caixa existente e numeração após a maior pasta já usada |
| [Prévia do lote no celular](evidencias/2026-10-02/passivo-lote-previa-390.png) | Posições, confirmação e envio em 390×844 |
| [Seleção de alunos no celular](evidencias/2026-10-02/passivo-lote-selecao-390.png) | Alunos ativos e inativos selecionados em páginas diferentes |

As imagens de relatório já registradas antes desta revisão permanecem como evidências históricas: [primeira página](evidencias/modulo5-relatorio-pagina-1.png) e [última página](evidencias/modulo5-relatorio-pagina-3.png). Elas não aprovam alterações posteriores.

## Verificações e limites

`tests/browser-contract-tabs.mjs` executa 56 verificações com links reais no Chrome: painel ancestral, visibilidade, foco, fechamento, teclado, URLs inválidas, histórico, impressão e unidades do construtor de pedidos. A aplicação autenticada passou em 17 verificações no desktop e nas mesmas 17 no celular, com três notas, erro real de produto e abertura administrativa. O PDF de 80 produtos tem cinco páginas; a extração confirma uma ocorrência por item, e as páginas renderizadas permitem conferir margens e continuidade.

O complemento de unidades passou em mais 25 verificações na aplicação autenticada: opções UN/K/Litros, escolha por teclado, padrão UN nas novas linhas/notas, remoção/reindexação, seleção preservada após erro e atualização, unidade anterior na edição e confirmação da unidade atual no estoque legado. As duas imagens de unidade registram esse complemento. As capturas iniciais de formulário de produto e conferência antecedem a troca dos campos de unidade por seletores; os demais campos e a preservação dos valores seguem verificados.

O envio de alunos em lote passou em 32 verificações na aplicação autenticada, com banco fictício próprio e Chrome isolado. Foram exercitados seleção entre páginas e filtros, caixa existente e nova, prévia sem alterações, numeração após a maior pasta inclusive inativa, confirmação com inativação dos ativos, preservação integral das DVAs, bloqueio de alunos já arquivados e limpeza da seleção. As páginas cabem nas janelas de 1366×768 e 390×844; as tabelas usam rolagem local. As cinco capturas do lote registram somente alunos fictícios. O teste permanente é `tests/browser-passivo-batch.mjs`.

O ensaio autenticado de certidões acrescentou 58 verificações nas três resoluções: entrada em tela cheia nativa, filtros, saída pelo controle ou Esc, retorno do foco, edição, renovação, detalhes, arquivadas e excluídas. Não houve exceções de JavaScript, IDs duplicados ou transbordamento da página nesses estados.

A seleção local de PDF foi retestada no Chrome: o documento aparece na prévia sem violação da CSP; limpar remove a seleção, revoga a URL temporária e devolve o foco. `blob:` é permitido apenas para o quadro da prévia; scripts/estilos locais e bloqueio de incorporação da aplicação permanecem exigidos. A configuração de certidões foi recapturada nas três resoluções após o ajuste final dos campos de catálogo.

Os componentes de segurança e estoque permanecem explícitos: confirmação de senha e senha temporária, prévia/confirmação administrativa, fornecedor, composição de produtos por nota, justificativa de correção, dados atuais versus tentativa recusada e movimentos auditados. Essas informações acrescentam conteúdo e alteram a altura de alguns blocos. As telas de estoque e DVA têm consultas próprias; menus administrativos adicionais permanecem protegidos. Esta revisão não implementa agenda ou etiquetas.

A conferência literal das figuras e dos requisitos de usabilidade do arquivo `TCC_2_ETAPA_1_MÓDULO_1 - Rodrigo-Calebe.pdf` continua pendente porque ele não foi localizado. Capturas e testes em ambiente fictício não constituem aceite da escola nem aprovação do orientador. A homologação com o documento acadêmico correto e o ambiente institucional permanece necessária; esta revisão não declara conclusão integral desse aceite.
