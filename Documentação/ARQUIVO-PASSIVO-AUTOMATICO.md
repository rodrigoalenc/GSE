# Novo Registro — Arquivo Passivo

Implementação realizada na branch `main`.

## Funcionamento

O usuário escolhe uma caixa existente ou a próxima caixa automática e depois informa nome e nascimento. A caixa nova só é criada junto com o primeiro aluno, ao salvar. A próxima caixa considera o maior identificador existente, incluindo caixas de registros inativos e prefixos históricos como `Caixa 12` e `CX-12`.

Os alunos são ordenados por caixa e nome normalizado, sem distinguir acentos ou maiúsculas, com desempate pelo ID. Seus números formam uma sequência global desde 1. A inclusão numa caixa anterior desloca os números das seguintes. A ordenação das caixas é a mesma na numeração, na consulta e na navegação.

IDs, nomes, nascimento, origem, status, histórico e caixas existentes são preservados. Apenas números e metadados dos registros que precisam de ajuste são atualizados. Registros inativos também participam da sequência, evitando reutilização de seus números. Legado sem caixa e sem número continua pendente; se já possuir número, participa antes das caixas sem receber localização inventada.

A seleção é revalidada dentro de `BEGIN IMMEDIATE`. Liberação das posições alteradas, inclusão, renumeração, auditoria e recibo idempotente são confirmados ou revertidos juntos. Dois cadastros simultâneos de nova caixa resolvem números distintos sob o bloqueio. Repetir o mesmo envio retorna o registro original. Os formulários mantêm estados separados por fluxo e preservam os dados ao trocar de caixa.

Os contratos existentes de edição, CSV e movimentação escolar foram mantidos. A reorganização global descrita aqui ocorre ao salvar em **Novo Registro**. Não é necessária migração de esquema.

## Arquivos

| Arquivo | Alteração |
|---|---|
| `src/Model/Passivo.php` | Próxima caixa, validação, cadastro transacional, sequência global e ordenação coerente das caixas. |
| `src/Controllers/PassivoController.php` | Cadastro em duas etapas, validação e recuperação de dados por fluxo. |
| `src/Views/passivo/escolher-caixa.php` | Nova etapa de escolha da caixa. |
| `src/Views/passivo/form.php` | Número automático, destino escolhido e troca de caixa preservando os dados. |
| `public/assets/js/usabilidade.js` | Respeita `formnovalidate` ao trocar de caixa com preenchimento incompleto. |
| `tests/Integration/PassivoAutomaticoTest.php` | Regras automáticas, exemplos, limites, rollback, idempotência e concorrência. |
| `tests/Integration/PassivoTest.php` | Expectativas de ordenação de caixas com rótulos históricos. |
| `tests/fixtures/passivo-create-race.php` | Dois processos concorrentes sobre banco temporário. |
| `tests/http-passivo-cadastro.php` | Testes HTTP do novo fluxo e persistência após atualização da página. |
| `tests/http-smoke.php` | Adaptação do cadastro e inclusão dos novos cenários HTTP. |
| `tests/browser-passivo-cadastro.mjs` | Interface desktop/celular, troca de caixa, validação, abas e cancelamento. |
| `composer.json` | Inclui o novo teste de navegador no comando existente. |
| `README.md`, `Documentação/MANUAL-USUARIO.md` | Orientações técnicas e de uso atualizadas. |
| `Documentação/ARQUIVO-PASSIVO-AUTOMATICO.md` | Este registro da implementação e da validação. |

## Validação local

- PHP 8.4.27, SQLite 3.53.4, esquema v22.
- PHPUnit: 761 casos, 7.053 verificações, sem falhas; 3 casos ignorados por condições do ambiente. Dois avisos vieram da limpeza de diretórios no teste existente de backups com symlink no Windows.
- A execução integral pelo Composer atingiu seu limite de 300 segundos. A suíte foi concluída diretamente pelo PHPUnit em três partes: Unit/Security (221), primeira metade de Integration (255) e segunda metade (285).
- Filtro final do Arquivo Passivo: 45 testes e 563 verificações, incluindo duas gravações reais concorrentes, índice único global, falha de atualização/auditoria e paginação com mais de dez alunos.
- Integração HTTP: 740 verificações aprovadas.
- Novo navegador: 31 verificações aprovadas em desktop e celular. Regressão de passivo/lote: 104 verificações aprovadas; regressão de rascunhos e usabilidade também aprovada.
- PHPStan sem erros, sintaxe PHP e JavaScript válidas, Composer válido e `git diff --check` aprovado.

Os testes usam bancos temporários. A demonstração local usa outro banco isolado com 90 registros fictícios e três caixas; nenhum banco institucional foi utilizado.
