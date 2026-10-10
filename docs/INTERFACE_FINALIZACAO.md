# Interface atual e evidências do GSE

Inspeção de 06/10/2026 com dados inteiramente fictícios, Chrome 154, escala 1 e janelas 1366 × 768, 1920 × 1080 e 390 × 844. As imagens, legendas e estados desta entrega mostram somente o GSE. A fixture contém 33 alunos, 31 ativos, 2 contas, 3 pastas, 3 certidões e 2 pedidos; os registros excluídos e inativos são preservados.

O ensaio final registra 306 capturas de telas/estados. Verificou ausência de transbordamento da página, imagens quebradas, IDs duplicados, violações da CSP e exceções de JavaScript. Formulários e estados interativos receberam conferência de URI relativa, filtros, foco e fontes efetivamente carregadas. A rolagem horizontal de tabelas largas permanece dentro de seu contêiner.

Organização dos anexos em 09/10/2026: imagens, PDFs, logs e manifestos completos ficam em pacote local separado para a entrega acadêmica; o repositório contém este relatório e o inventário textual. Os caminhos `evidencias/...` abaixo identificam arquivos dentro desse pacote. Esta organização não representa nova execução dos ensaios de 06/10. Cada padrão `nome-{1366,1920,390}.png` identifica as três capturas do mesmo estado, respectivamente em 1366 × 768, 1920 × 1080 e 390 × 844.

## Telas e estados observados

| Tela / estado | Arquivos em `evidencias/2026-10-06/` no pacote separado |
|---|---|
| Login: formulário vazio e foco | `login-{1366,1920,390}.png` |
| Login: orientação para recuperar acesso | `login-ajuda-{1366,1920,390}.png` |
| Login: credenciais recusadas | `login-erro-{1366,1920,390}.png` |
| Painel: indicadores preenchidos | `painel-{1366,1920,390}.png` |
| Usuários: consulta | `usuarios-{1366,1920,390}.png` |
| Usuários: busca sem resultados | `usuarios-vazio-{1366,1920,390}.png` |
| Usuário: cadastro | `usuario-cadastro-{1366,1920,390}.png` |
| Usuário: edição | `usuario-edicao-{1366,1920,390}.png` |
| Senha: alteração | `senha-{1366,1920,390}.png` |
| Meu Perfil: administrador | `usuario-perfil-{1366,1920,390}.png` |
| Alunos: listagem preenchida | `alunos-{1366,1920,390}.png` |
| Alunos: filtro sem resultados | `alunos-vazio-{1366,1920,390}.png` |
| Aluno: cadastro | `aluno-cadastro-{1366,1920,390}.png` |
| Aluno: observações da DVA abertas | `aluno-observacoes-{1366,1920,390}.png` |
| Aluno: perfil | `aluno-perfil-{1366,1920,390}.png` |
| Aluno: edição | `aluno-edicao-{1366,1920,390}.png` |
| Alunos: consulta dos inativos | `alunos-inativos-{1366,1920,390}.png` |
| DVA: alunos sem declaração | `dva-sem-{1366,1920,390}.png` |
| DVA: painel e prazos | `dva-{1366,1920,390}.png` |
| DVA: renovação | `dva-renovacao-{1366,1920,390}.png` |
| Turmas: consulta | `turmas-{1366,1920,390}.png` |
| Turma: cadastro | `turma-cadastro-{1366,1920,390}.png` |
| Arquivo Passivo: acervo preenchido | `passivo-{1366,1920,390}.png` |
| Arquivo Passivo: filtro de localização pendente | `passivo-pendentes-{1366,1920,390}.png` |
| Arquivo Passivo: cadastro completo obrigatório | `passivo-cadastro-{1366,1920,390}.png` |
| Arquivo Passivo: edição de legado incompleto | `passivo-edicao-legado-{1366,1920,390}.png` |
| Arquivo Passivo: detalhes da pasta localizada | `passivo-detalhes-{1366,1920,390}.png` |
| Arquivo Passivo: importação e modelo CSV | `passivo-importacao-{1366,1920,390}.png` |
| Arquivo Passivo: prévia CSV sem gravação | `passivo-importacao-previa-{1366,1920,390}.png` |
| Arquivo Passivo: prévia de enumeração sem gravação | `passivo-enumeracao-previa-{1366,1920,390}.png` |
| Arquivo Passivo: enumeração e exportação | `passivo-ferramentas-{1366,1920,390}.png` |
| Arquivo Passivo: consulta dos excluídos | `passivo-excluidos-{1366,1920,390}.png` |
| Aluno inativo: arquivamento individual | `arquivar-aluno-{1366,1920,390}.png` |
| Certidões: matriz preenchida | `certidoes-{1366,1920,390}.png` |
| Certidões: fornecedores e tipos | `certidoes-configuracao-{1366,1920,390}.png` |
| Certidão: cadastro e PDF opcional | `certidao-cadastro-{1366,1920,390}.png` |
| Certidão: edição | `certidao-edicao-{1366,1920,390}.png` |
| Certidão: renovação | `certidao-renovacao-{1366,1920,390}.png` |
| Certidão: detalhes e ciclo de vida | `certidao-detalhes-{1366,1920,390}.png` |
| Certidões: documentos arquivados | `certidoes-arquivadas-{1366,1920,390}.png` |
| Certidões: documentos excluídos | `certidoes-excluidas-{1366,1920,390}.png` |
| Pedidos: consulta | `pedidos-{1366,1920,390}.png` |
| Pedidos: busca sem resultados | `pedidos-vazio-{1366,1920,390}.png` |
| Pedido: notas, produtos e unidades | `pedido-cadastro-{1366,1920,390}.png` |
| Pedido: edição | `pedido-edicao-{1366,1920,390}.png` |
| Pedido: segunda nota selecionada | `nota2-{1366,1920,390}.png` |
| Pedido: produto aberto na terceira nota | `produto3-{1366,1920,390}.png` |
| Pedido: faturamento aberto na terceira nota | `faturamento3-{1366,1920,390}.png` |
| Estoque: saldo físico e limites | `estoque-{1366,1920,390}.png` |
| Estoque: conferência administrativa do saldo antigo | `estoque-legado-{1366,1920,390}.png` |
| Pedido: histórico auditado | `historico-{1366,1920,390}.png` |
| Relatórios: filtros e exportação | `relatorios-{1366,1920,390}.png` |
| Auditoria: consulta administrativa | `auditoria-{1366,1920,390}.png` |
| Erro: página inexistente | `erro404-{1366,1920,390}.png` |
| Arquivo Passivo: posição vazia recusada pelo navegador | `passivo-invalido-{1366,1920,390}.png` |
| Menu: foco e expansão | `menu-expandido-{1366,1920,390}.png` |
| Impressão: pedido completo | `impressao-pedido-{1366,1920,390}.png` |
| Impressão: nota individual | `impressao-nota2-{1366,1920,390}.png` |
| Impressão: pedido com 80 produtos | `impressao-80-produtos-{1366,1920,390}.png` |
| Meu Perfil: funcionário | `usuario-perfil-funcionario-{1366,1920,390}.png` |
| Erro: acesso administrativo recusado ao funcionário | `erro403-{1366,1920,390}.png` |
| Aluno: confirmação de possível duplicidade sem gravar | `aluno-duplicidade-{1366,1920,390}.png` |
| Login: alternância de visibilidade da senha | `login-senha-visivel-{1366,1920,390}.png` |
| Alunos: ativos e inativos no mesmo filtro | `alunos-todos-{1366,1920,390}.png` |
| Alunos: segunda página de ativos e inativos | `alunos-todos-pagina2-{1366,1920,390}.png` |
| Arquivo Passivo: caixa 2, ordenação por número | `passivo-caixa2-{1366,1920,390}.png` |
| Arquivo Passivo: busca sem resultados | `passivo-vazio-{1366,1920,390}.png` |
| Arquivo Passivo: edição de pasta completa | `passivo-edicao-{1366,1920,390}.png` |
| Pedido: primeira nota faturada | `nota1-{1366,1920,390}.png` |
| Pedido: terceira nota não faturada | `nota3-{1366,1920,390}.png` |
| Pedido: edição de produto e contexto de estoque | `produto-edicao-{1366,1920,390}.png` |
| Certidões: matriz em tela cheia | `certidoes-tela-cheia-{1366,1920,390}.png` |
| Pedido: duas linhas de produtos na primeira nota | `pedido-produtos-dois-{1366,1920,390}.png` |
| Aluno: campo obrigatório recusado pelo navegador | `aluno-cadastro-invalido-{1366,1920,390}.png` |
| Usuário: campo obrigatório recusado pelo navegador | `usuario-cadastro-invalido-{1366,1920,390}.png` |
| Arquivo Passivo: campo obrigatório recusado pelo navegador | `passivo-cadastro-invalido-{1366,1920,390}.png` |
| Certidão: campo obrigatório recusado pelo navegador | `certidao-cadastro-invalido-{1366,1920,390}.png` |
| Painel: alunos sem DVA | `painel-sem-dva-{1366,1920,390}.png` |
| Painel: DVAs vencidas | `painel-vencidas-{1366,1920,390}.png` |
| Painel: DVAs a vencer | `painel-a-vencer-{1366,1920,390}.png` |
| Painel: DVAs vigentes | `painel-vigentes-{1366,1920,390}.png` |
| Painel: alerta de certidões aberto | `painel-certidoes-{1366,1920,390}.png` |
| Painel: pesquisa com resultado | `painel-busca-{1366,1920,390}.png` |
| Painel: pesquisa sem resultados | `painel-busca-vazia-{1366,1920,390}.png` |
| Aluno: DVA vigente, sem contatos | `aluno-perfil-vigente-{1366,1920,390}.png` |
| Aluno: DVA vencida, sem contatos | `aluno-perfil-vencida-{1366,1920,390}.png` |
| Aluno: sem DVA | `aluno-perfil-sem-dva-{1366,1920,390}.png` |
| Certidões: edição de opção em diálogo | `certidoes-opcao-aberta-{1366,1920,390}.png` |
| Certidões: filtro Vigente sem resultados | `certidoes-filtro-vigente-{1366,1920,390}.png` |
| Certidões: filtro de pendências preenchido | `certidoes-pendencias-{1366,1920,390}.png` |
| Certidões: fornecedor selecionado com resultado | `certidoes-fornecedor-{1366,1920,390}.png` |
| Certidões: ano 2035 sem resultados | `certidoes-ano-vazio-{1366,1920,390}.png` |
| Certidão: prévia de PDF fictício selecionado | `certidao-pdf-previa-{1366,1920,390}.png` |
| Aluno: ações ao final do formulário | `aluno-cadastro-acoes-{1366,1920,390}.png` |
| Pedido: ações ao final do formulário | `pedido-cadastro-acoes-{1366,1920,390}.png` |
| Certidão: ações ao final do formulário | `certidao-cadastro-acoes-{1366,1920,390}.png` |
| Arquivo Passivo: ações ao final do formulário | `passivo-cadastro-acoes-{1366,1920,390}.png` |
| Pedido: observação da segunda nota | `nota-observacao-{1366,1920,390}.png` |
| Aluno: erro do servidor com dados preservados | `aluno-erro-servidor-{1366,1920,390}.png` |
| Usuário: erro do servidor com dados preservados | `usuario-erro-servidor-{1366,1920,390}.png` |
| Arquivo Passivo: erro do servidor com dados preservados | `passivo-erro-servidor-{1366,1920,390}.png` |
| Certidão: erro do servidor com dados preservados | `certidao-erro-servidor-{1366,1920,390}.png` |

As imagens adicionais `passivo-lote-*`, `contrato-*` e `perfil-*` registram a suíte autenticada permanente. A identificação das notas, o histórico do navegador, as ações com teclado e o foco após fechar o diálogo foram exercitados em dados descartáveis. Os telefones fictícios formatados são exibidos integralmente; seus links de chamada contêm apenas dígitos.

## Ajustes de apresentação verificados

Foram ajustados espaçamento e dimensões do login, títulos e campos dos cadastros, indicadores e cartões, ações de edição de pedido, filtro do arquivo de certidões e os dois pesos de ícones 400/900. O painel voltou a exibir a faixa de aniversário do dia e a idade. As tabelas de DVA usam cores por situação e acomodam as três colunas no celular. O estado Somente pendências usa realce azul. A matriz em tela cheia termina no conteúdo e mantém a rolagem para conjuntos maiores.

Meu Perfil usa cartão, borda, preenchimento e botão azul consistentes com a edição de usuários. O diálogo de fornecedores/tipos mantém foco inicial, Escape, retorno ao acionador e formulário disponível sem JavaScript. Salvar preserva CSRF, revisão e situação da opção.

## Controles e diferenças de estado preservados

- O painel e as consultas padrão contam registros ativos. Inativos e excluídos ficam nas consultas próprias; sua ausência dos indicadores não significa perda de dados.
- O Arquivo Passivo mostra pendências de localização e oferece filtro, ordenação e consulta de excluídos. Esses campos ocupam espaço real no formulário; os indicadores permanecem em linha no desktop e quebram no celular.
- Importação CSV e enumeração mostram prévia e confirmação. A primeira é aditiva; a segunda preenche somente posições vazias. As capturas de prévia não confirmam gravações.
- A renovação de DVA é separada da edição dos dados pessoais para preservar versões e autoria. Observações continuam disponíveis em controle expansível, registrado aberto.
- Meu Perfil exige senha atual. A mudança de e-mail encerra sessões; a troca de senha tem fluxo próprio e política vigente. O formulário menor e os avisos correspondem a esses controles.
- Fornecedor e recuperação da tentativa anterior permanecem na edição de pedido; notas, unidades, motivos, revisões, histórico e estoque físico permanecem nos fluxos correspondentes. Esses controles explicam somente o espaço que efetivamente ocupam.
- Certidões distinguem corrente, arquivada e excluída. Edição altera dados; substituição documental exige renovação. PDF ausente mostra Sem PDF; seleção válida oferece prévia, nome e remoção da seleção. Limite e orientações de upload são visíveis.
- O catálogo mantém situação ativa/inativa e revisão no diálogo. Esses campos permanecem disponíveis para proteger vínculos históricos e alterações concorrentes.
- A pesquisa do painel anuncia ausência de resultados e oculta seções sem correspondência. O filtro de ano 2035 retorna zero registros; o seletor exibe Todos os Anos quando esse ano não consta entre as opções disponíveis.
- A validação nativa foi acionada no formulário correto e confirmou campo inválido, foco e mensagem do navegador. A apresentação da bolha nativa depende do navegador; foco e validação são registrados no estado. Erros do servidor usam classe/mensagem de erro e mantêm o formulário. O caso de usuário usa e-mail duplicado com os demais campos válidos e não cria conta.

## Impressão e exportações

|Documento|Conferência atual|
|---|---|
|Pedido de três notas|Uma página, todas as notas, produtos, totais e observações presentes|
|Nota individual 2|Uma página, somente Caneta, retorno à segunda nota preservado|
|Pedido de 80 produtos|Quatro páginas, 001–080 em ordem e exatamente uma vez, produtos já na primeira página e última nota preservada|
|Relatório de 500 alunos|14 páginas, todos os nomes filtrados em ordem, cabeçalho repetido e primeira/intermediária/última página revisadas|

Exemplos: pedido completo (`evidencias/2026-10-06/impressao-pedido-pagina-1.png`), continuação do pedido longo (`evidencias/2026-10-06/impressao-80-produtos-pagina-3.png`), última nota (`evidencias/2026-10-06/impressao-80-produtos-pagina-4.png`), início dos 500 alunos (`evidencias/2026-10-06/relatorio-500-alunos-pagina-01.png`), página intermediária (`evidencias/2026-10-06/relatorio-500-alunos-pagina-08.png`) e última página (`evidencias/2026-10-06/relatorio-500-alunos-pagina-14.png`). Permanecem os limites 500 no PDF e 10.000 na exportação total.

A conferência técnica cobre os estados registrados. Permanecem o aceite institucional, a avaliação com usuários e leitores de tela e a execução do fluxo de integração contínua no ambiente remoto. Assets de bibliotecas mantêm licenças e créditos verdadeiros; a autorização institucional do logo não foi presumida.

Manifestos: estados e URIs observados (`evidencias/2026-10-06/estados.json`), arquivos/dimensões/SHA-256 (`evidencias/2026-10-06/manifesto.json`) e validação final (`evidencias/2026-10-06/validacao.json`). As evidências anteriores de 05/10 permanecem preservadas.
