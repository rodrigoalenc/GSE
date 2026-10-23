# Plano de correções do diagnóstico GSE

Data: 22/10/2026. Base: 598803b. Branch: codex/correcoes-auditoria-completa.

Objetivo autorizado: resolver os 19 achados da análise completa, mantendo o MVC PHP/SQLite e tornando as telas compreensíveis para a secretaria.

## Restrições

- Não migrar nem abrir banco institucional durante desenvolvimento/testes.
- Preservar históricos, permissões e controles de segurança; SMTP desligado nos ensaios.
- Dependências pelo lockfile; runtime PHP corrigido de 64 bits e SQLite com correção WAL.
- Sem deploy, publicação ou envio de mensagens institucionais.
- Não declarar aceite de usuários ou homologação de infraestrutura que não foram observados.

## Entregas e verificações

- [x] Ambiente: dependências completas; runtime privado atualizado; verificação reprodutível de PHP/SQLite/extensões; launcher e configuração de CI usam requisitos atuais (A01/A05/A06/A07).
- [x] Integridade: revisões em edições de aluno/turma/passivo e vínculo consistente ativo/passivo; migração exclusiva/revalidada; índices com plano de consulta (A02/A03/A11/A12).
- [x] Núcleo: CSRF preservado na renovação periódica, classificação DVA SQL/PHP compartilhada, painel limitado e log causal sem dados pessoais (A04/A10/A13/A19).
- [x] Interface: valor inválido explicitamente recusado, desfazer remoção, rótulos permanentes, associação campo/erro, datas e paginação uniformes, menu fixável, mensagens simples (A08/A09/A14/A15/A16/A17).
- [x] Operação/documentação: esquema atual único, manual de tarefas, roteiro de usabilidade, verificação de implantação sem mutação e procedimento de restauração conjunta banco/PDF/chave (A18 e pendências operacionais).
- [x] Verificação final local: Composer, lint, PHPStan, PHPUnit, HTTP, browser desktop/mobile, inspeção visual e revisão independente do diff. Limites de rede, Windows e homologação estão registrados no relatório de validação.

## Regras de aceite

Edição com revisão antiga é recusada sem alterar cadastro/histórico; reativação não mantém passivo ativo; formulários continuam válidos durante rotação de sessão; valor inválido nunca gera saldo aparentemente válido; remoção preenchida é reversível; datas históricas/futuras não causam erro; limites do painel não alteram totais; logs não incluem mensagens ou segredos; documentos e teste de restauração abrangem SQLite/PDF/chaves de forma coerente.

## Registro de decisões

- Autorização de implementação: mensagem do usuário após leitura do relatório, solicitando resolver todos os problemas.
- Trabalho em branch local no checkout existente, preservando o contexto da aplicação e permitindo revisão do diff; não há worktree separado.
- Correções executadas em frentes de arquivos independentes segundo a skill dispatching-parallel-agents; integração e testes completos pelo agente principal.
- Atualização PHP privada ao GSE concluída, sem substituir o PHP global de outros projetos.
- O aceite de profissionais da escola será preparado com roteiro verificável; sua execução real continua exigindo participantes e infraestrutura institucionais.

## Encerramento técnico em 23/10/2026

Os 19 achados receberam correções com evidências em VALIDACAO-2026-10-22.md. A revisão independente levou ainda ao bloqueio da edição de turma com revisão atual após movimentação e à reprovação de PDFs legados no ensaio de restauração. A instalação de desenvolvimento foi recuperada por backup cifrado, restauração e migração de cópia até a v21; o original e as credenciais foram preservados, e o banco ativo passou a armazenamento privado fora do OneDrive.

Resultado integrado: 577 testes/5.543 asserções, 604 verificações HTTP e 406 verificações de navegador; lint, PHPStan e auditoria de dependências aprovados. Dois testes específicos de SO foram ignorados no Windows. CI remoto, servidor/SMTP institucional, cópia externa de contingência e aceite com usuários não foram simulados como aprovados.
