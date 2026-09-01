# Validação manual pendente — Módulo 3

Este roteiro deve ser executado antes da implantação. Ele não registra homologação já concluída.

## Registro da execução

- Data: ____________________
- Responsável: ____________________
- Ambiente/commit: ____________________
- Evidências (links ou arquivos): ____________________
- Resultado geral: [ ] aprovado  [ ] reprovado  [ ] aprovado com ressalvas
- Observações: ____________________

## Navegadores e dimensões

Execute cada fluxo em Chrome, Edge e Firefox, tanto em desktop quanto em uma largura de celular. Em cada combinação, registre resultado e evidência.

| Cenário | Chrome desktop/celular | Edge desktop/celular | Firefox desktop/celular | Evidência |
|---|---|---|---|---|
| Sidebar, hero, cards e contagens | [ ] / [ ] | [ ] / [ ] | [ ] / [ ] | |
| Busca, filtros e navegação entre caixas | [ ] / [ ] | [ ] / [ ] | [ ] / [ ] | |
| Tabela com rolagem segura | [ ] / [ ] | [ ] / [ ] | [ ] / [ ] | |
| Foco visível e navegação por teclado | [ ] / [ ] | [ ] / [ ] | [ ] / [ ] | |
| Estados Ativo, Revisão pendente e Inativo | [ ] / [ ] | [ ] / [ ] | [ ] / [ ] | |

Resultado esperado: a interface mantém a identidade azul dos Módulos 1 e 2, não corta ações ou conteúdo, permite uso por teclado e apresenta “Revisão pendente” em amarelo/laranja com texto explícito.

## Fluxos funcionais

| Fluxo | Resultado esperado | Resultado | Evidência |
|---|---|---|---|
| Cadastro e edição | PRG, validações claras e dados preservados | | |
| Pesquisa, filtros e paginação | resultados determinísticos e curingas literais | | |
| Importação CSV | prévia, confirmação única, limites e acervo aditivo | | |
| Enumeração | somente registros sem número da caixa escolhida | | |
| Exportação TXT | POST/CSRF, formato `Número - Nome` e arquivo seguro | | |
| Inativação e restauração | mudança lógica sem exclusão física | | |
| Envio de aluno inativo | vínculo criado e histórico de DVA preservado | | |

## Permissões

Valide com contas fictícias separadas de funcionário e administrador.

| Operação | Funcionário esperado | Administrador esperado | Resultado/evidência |
|---|---|---|---|
| Consultar, pesquisar, filtrar e detalhar | permitido | permitido | |
| Cadastrar e editar | permitido | permitido | |
| Exportar TXT | permitido | permitido | |
| Inativar e restaurar | 403 | permitido | |
| Importar CSV | 403 | permitido | |
| Enumerar caixas | 403 | permitido | |
| Enviar aluno inativo ao passivo | 403 | permitido | |

## Migração de cópia anonimizada

- [ ] cópia anonimizada criada e identificada;
- [ ] versões de PHP, SQLite e `ext-intl` registradas;
- [ ] backup preventivo aberto com `integrity_check=ok`;
- [ ] contagens, IDs, valores, relacionamentos e `sqlite_sequence` comparados;
- [ ] colisões legadas preservadas;
- [ ] ausências de caixa preservadas com `localizacao_pendente=1`;
- [ ] `foreign_key_check` vazio e `integrity_check=ok` após a v12;
- [ ] nenhuma tabela `alunos_passivo_v12` remanescente;
- [ ] rollback restaurado e validado em ensaio separado.

Resultado esperado: nenhuma exclusão, mesclagem ou renumeração silenciosa; banco migrado íntegro e reversível pelo backup validado.
