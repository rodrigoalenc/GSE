# Revisão visual das telas

Escopo: telas existentes no sistema. Os ajustes de interface preservam filtros,
históricos, auditoria, permissões e demais funções de cada módulo.

## Gestão de Alunos

| Tela | Elementos conferidos |
| --- | --- |
| Lista | Faixa de resumo, ações rápidas, filtros, tabela e paginação. |
| Cadastro | Dados pessoais, turma, DVA inicial opcional, contatos e botões. |
| Edição | Campos de cadastro, navegação para renovação de DVA e cancelamento. |
| Perfil | Estados da DVA, dados, contatos, WhatsApp, ações e histórico. |
| DVAs | Painel de filtros, prazo, renovação e preservação de versões anteriores. |

## Certidões

| Tela | Elementos conferidos |
| --- | --- |
| Matriz | Resumo, filtros, colunas por fornecedor, cartões, prazos e cores das ações. |
| Tela cheia | Controles de validade, estado ativo, saída e ocultação de linhas vazias. |
| Cadastro e renovação | Campos, prazo em dias, PDF opcional, prévia e botões. |
| Edição e detalhes | PDF existente ou ausente, datas, validade e ações. |
| Arquivadas e excluídas | Tabela, filtro por ano, paginação, edição, desarquivamento e exclusão lógica. |
| Configuração | Listas de fornecedores e tipos, estados vazios, formulários e retorno. |

## Demais telas

| Área | Elementos conferidos |
| --- | --- |
| Estrutura e menu | Ordem e rótulos das rotas, navegação por teclado e adaptação ao celular. |
| Painel | Cartões, avisos, listas e links para as áreas do sistema. |
| Arquivo Passivo | Listagem, formulário, importação, ferramentas e manutenção do acervo. |
| Contratos | Listagem, detalhes, formulários, impressão e ações de estoque. |
| Relatórios | Controles, tabelas, filtros e impressão. |
| Usuários | Listagem, cadastro e edição. |
| Login | Formulário, mensagens e acesso ao sistema. |

As folhas de estilo e as views são organizadas por módulo. Componentes compartilhados
mantêm a mesma identidade visual sem eliminar os fluxos próprios de cada tela.

## Conferência final

1. Verificar cada tela em desktop e celular com dados de teste.
2. Conferir estados vazios, carregados, de erro e de confirmação.
3. Testar links, filtros, formulários, permissões e ações após ajustes visuais.

Os testes automatizados verificam os fluxos principais. Capturas comparativas de
telas autenticadas ainda não foram executadas.
