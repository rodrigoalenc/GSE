# Comparação visual com ProjetoGSE

Escopo: somente as telas já existentes no TCC. Funcionalidades adicionais dessas
telas, como filtros, DVAs, estoque e auditoria, devem continuar disponíveis.

Referência: `rodrigoraa/ProjetoGSE`, ramo `main`, diretório
`sistema_escolar_root/sistema_escolar`. A comparação usa os arquivos do commit
`f0bb641` presentes em `.reference-original`.

## Estrutura das telas

| Área | Original | TCC | Situação |
| --- | --- | --- | --- |
| Estrutura e menu | `partials/menu.php`, páginas independentes | `layouts/app.php` | Estrutura diferente; ordem e rótulos das rotas comuns alinhados. |
| Painel | `painel.php` | `dashboard/index.php` | Dados e componentes distintos; requer alinhamento de cartões, avisos e listas. |
| Alunos | `alunos/index.php`, `cadastrar.php`, `editar.php`, `perfil.php` | `alunos/index.php`, `form.php`, `perfil.php`, `dva-index.php`, `dva.php` | Estrutura, cores e ações comuns alinhadas; DVA e histórico preservados no TCC. |
| Certidões | `certidoes/*.php` | `certidoes/*.php` | Fluxo consolidado; comparar tabela, formulário, configuração e arquivos. |
| Contratos | `contratos/*.php` | `contratos/*.php` | Fluxo consolidado e estoque próprio; comparar listagem, detalhes e impressão. |
| Passivo | `passivo/*.php` | `passivo/*.php` | Fluxo consolidado; comparar listagem, formulário, importação e ferramentas. |
| Relatórios | `relatorios/index.php` | `relatorios/index.php` | Comparar controles, tabelas e impressão. |
| Usuários | `usuarios/*.php` | `usuarios/*.php` | Formulário consolidado; comparar listagem e edição. |
| Login | `login.php` | `login.php` | Mesma função principal, estilos diferentes. |

## Folhas de estilo

O original contém `agenda.css`, `alunos.css`, `certidoes.css`, `contrato.css`,
`etiquetas.css`, `imprimir.css`, `login.css`, `painel.css`, `passivo.css`,
`relatorios.css`, `sistema.css`, `style.css` e `usuarios.css`.

O TCC contém `alunos.css`, `certidoes.css`, `contratos.css`, `dashboard.css`,
`error.css`, `login.css`, `modulo5.css`, `modulo5-print.css`, `painel.css`,
`passivo.css` e `usuarios.css`. Os nomes comuns não indicam equivalência de
componentes: a marcação e as regras foram reorganizadas. Copiar os CSS do
original diretamente criaria conflitos com os módulos novos e com o Bootstrap.

## Gestão de Alunos

| Tela ou estado | Conferência com o original | Ajuste no TCC |
| --- | --- | --- |
| Lista | Faixa azul, resumo, ações rápidas, tabela, links azuis e paginação | Cores, espaçamentos e ações alinhados; filtros de turma, situação e DVA continuam disponíveis. |
| Cadastro | Cartão branco, título de seção, nome e linha com nascimento, turma e DVA em caixa azul | DVA inicial opcional colocada na mesma linha; observação opcional preservada. |
| Edição | Cartão, introdução, campos e botões | Botão principal e cancelamento alinhados; a renovação segue em tela própria para guardar versões anteriores. |
| Perfil | Cabeçalho, estados coloridos da DVA, dados em cartões, contatos e WhatsApp | Textos e cores dos estados, ações e cartões alinhados; inativação lógica e histórico preservados. |
| Painel e renovação de DVA | Não há tela equivalente no original | Links, cores dos estados, filtros e prazo apresentados de forma coerente com Gestão de Alunos. |

Os testes automatizados cobrem cadastro, pesquisa, edição, renovação, histórico,
inativação e permissões. A conferência por capturas de telas autenticadas ainda
não foi executada.

## Certidões

| Tela ou estado | Referência visual | Ajuste no TCC |
| --- | --- | --- |
| Matriz corrente | Resumo azul, barra de ações, filtros de validade, colunas por fornecedor e cartões com borda superior por prazo | Cores dos botões e ações por função, coluna de tipo fixa, datas e estados mantidos. |
| Tela cheia | Filtros escuros no topo, filtro ativo branco e saída vermelha | Filtros ocultam também as linhas vazias; saída permanece visível. |
| Cadastro e renovação | Formulário branco em duas e três colunas, prévia do PDF, botão azul | PDF permanece opcional; prévia, prazo em dias e ações alinhados. A renovação preserva a versão anterior. |
| Edição | Aviso do PDF existente, campos e ação principal | Aviso verde quando há PDF, estado sem PDF explícito; substituição documental segue pela renovação. |
| Arquivadas e excluídas | Histórico em tabela com filtro de ano | Tabela, datas, PDF e ações alinhados; edição, desarquivamento, paginação e exclusão lógica com auditoria. |
| Configuração | Introdução azul clara e duas listas de fornecedores e tipos | Listas, estados vazios, botões e retorno à matriz alinhados; controle de revisão preservado. |
| Detalhes | Não há tela equivalente no original | Datas, validade, PDF e ações usam as cores do módulo. |

## Critérios para concluir a paridade

1. Confrontar cada tela equivalente em desktop e celular, com dados reais de teste.
2. Igualar tipografia, espaçamento, cores, navegação, cartões, tabelas e formulários.
3. Verificar os estados vazio, erro, carregado e impressão.
4. Conferir links, botões, filtros, formulários e rotas depois de cada mudança visual.

Esta auditoria é estrutural; ainda não constitui validação visual por capturas de tela.
