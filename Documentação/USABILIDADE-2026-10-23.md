# Melhorias de usabilidade — 23/10/2026

Este documento descreve o conteúdo proposto para o próximo commit. Implementação iniciada em 23/10/2026, com verificação final em 24/10/2026. Nenhum commit ou envio foi autorizado nesta etapa sem a apresentação prévia do conteúdo ao responsável. As alterações foram feitas na main e usam a estrutura PHP/SQLite existente.

## Comportamentos implementados

| Área | Mudança |
|---|---|
| Linguagem | Pedidos/nota na navegação, formulários, mensagens e auditoria; códigos e descrições históricas continuam reconhecidos. |
| Preenchimento | Aviso de saída com alterações e rascunhos temporários para aluno, DVA, novo pedido, passivo e certidão. Recuperação explícita, limite de 10 rascunhos/64 KiB por rascunho/30 minutos; sem senha, arquivo ou token de autorização. |
| Privacidade | Rascunhos na sessão autenticada do servidor, separados por usuário e formulário/registro. Sair ou expirar a sessão elimina a recuperação. Contextos de gravação são invalidados após sucesso para impedir que uma requisição atrasada ressuscite o rascunho. |
| Edição concorrente | Aluno mostra dados atuais e tentativa lado a lado e exige conferência. Recuperar um rascunho anterior não promove silenciosamente os campos para a revisão atual. |
| Busca | Painel e lista consultam a base completa com a mesma normalização de acentos, filtros e paginação. Resultados distinguem homônimos por turma, nascimento e situação. |
| Pedidos | Três etapas: dados, notas/produtos e conferência. Validação abre a etapa correspondente; o formulário continua completo sem JavaScript. Notas/produtos extras são recuperados; adicionar/remover/desfazer atualiza o rascunho. |
| Dinheiro | Formatos brasileiros `1250,00` e `1.250,00`, agrupamento estrito, até duas casas e centavos inteiros; limites e regras de orçamento preservados. |
| Pendências | Contagens e links para DVA, certidões e localização do passivo. Estoque informa que o atalho abre a lista completa. |
| Vínculo escolar | Transferência administrativa entre turmas ativas da mesma série/ano, com prévia e confirmação. Histórico próprio preserva progressões e DVAs. |
| Retorno escolar | Retorno administrativo do passivo vinculado ao aluno original, com retirada do vínculo passivo ativo e reativação em uma transação, revisão, recibo e auditoria. |
| Navegação | Filtros de retorno guardados no servidor; posição da lista no histórico da aba, sem dados pessoais em localStorage/sessionStorage. Menu com nomes visíveis por padrão e menu de funções no celular. |
| Acessibilidade | Atalho para o conteúdo, foco e mensagens, campos opcionais mais claros, explicação DVA junto à data, botões de toque e conferência de reflow. |
| Documentação | Manual atualizado e roteiro de avaliação ampliado, sem presumir participação ou aceite de funcionários reais. |

## Arquivos centrais

- `src/Core/FormDraft.php`, `NavigationContext.php`, `Controller.php`, `Router.php` e `AuditPresentation.php`.
- `src/Controllers/FormDraftController.php`, `AlunoController.php`, `DashboardController.php`, `ContratoController.php` e `VinculoEscolarController.php`.
- `src/Services/VinculoEscolar.php`, `src/Model/Aluno.php`, `Painel.php` e `Contrato.php`.
- `src/Views/layouts/app.php`, formulários/perfil de alunos, painel, pedidos e detalhes do passivo; novas telas de vínculo/histórico escolar.
- `public/assets/js/usabilidade.js`, `pedidos-usabilidade.js`, `app.js` e CSS correspondente.
- `database/student-school-links.sql` e registro da migração 22 em `DatabaseInitializer.php`.
- Testes novos e adaptações das expectativas de esquema, mensagens e navegação; scripts de navegador registrados no Composer.

## Banco e implantação posterior

A versão do esquema sobe de 21 para 22 para criar o histórico de vínculos escolares, com restrições e proteção do histórico. A migração é aditiva. Não houve execução contra a base institucional ou cópia do .env de desenvolvimento para o servidor.

Somente depois da aprovação e do envio do código: manter cópia recuperável de banco, PDFs e chave separada; parar escritores e site; atualizar main/dependências pelo lockfile; aplicar a migração como o proprietário dos arquivos. No servidor já diagnosticado, os comandos que acessam banco/chaves usam `sudo -u www-data php ...`. Não abrir HTTP com esquema antigo. Seguir os procedimentos de compatibilidade e recuperação existentes, e testar os fluxos no ambiente institucional antes do aceite.

## Verificação e limites

As verificações usam banco/chaves descartáveis e pessoas fictícias:

- Suíte integrada: saída 0; 676 testes, 6.147 asserções e dois testes ignorados no Windows por condições do sistema operacional; 626 verificações HTTP aprovadas.
- Sintaxe aprovada em 265 arquivos PHP, PHPStan sem erros, Composer válido e auditoria sem avisos de vulnerabilidades conhecidos.
- Modo DELETE: 18 testes e 151 asserções de vínculos escolares, migração e edição concorrente aprovados.
- Navegador: as oito suítes registradas em `composer browser-test` terminaram com saída 0. Incluem movimentação (109 verificações), autenticação/senhas (44), notas e produtos (71), assistente de pedidos (47), recuperação/privacidade, telas reais/teclado/reflow (22), passivo/pedidos autenticados (104) e prévias/downloads (83). Conferência visual em desktop/celular e reflow em 320 pixels CSS, com dados fictícios.
- Revisão independente: seis problemas P2 reproduzidos e corrigidos com testes; a segunda revisão confirmou a resolução, sem outro P1/P2 introduzido pelas correções.

Registros passivos manuais sem aluno original não são automaticamente mesclados ou retornados. Rascunhos não sobrevivem ao encerramento/expiração da sessão e PDFs precisam ser selecionados novamente. A revisão em navegador e os testes de teclado/reflow não representam certificação WCAG nem avaliação com usuários reais ou leitores de tela; o roteiro institucional permanece pendente de aplicação.

Uma execução anterior do teste de relatórios encontrou duas abas durante cliques repetidos; a repetição isolada e a rodada completa final passaram. O bloqueio temporário de repetição continua dependendo do tempo entre cliques. Não foi presumida a ausência de toda condição de concorrência possível.

## Proposta de commit

```text
feat: melhora usabilidade e fluxos escolares do GSE

- padroniza Pedidos e facilita busca, navegação e acompanhamento de pendências
- protege preenchimento com rascunhos de sessão e revisão de edições concorrentes
- organiza pedidos em etapas e aceita valores no formato brasileiro
- adiciona transferência de turma e retorno do passivo com histórico e auditoria
- melhora uso no celular, teclado, campos e mensagens
- inclui migração 22, testes e documentação dos novos fluxos
```
