# Registro institucional de privacidade e operação

Modelo a preencher e aprovar pela escola. Os campos vazios não constituem aprovação. Esta documentação acompanha os controles técnicos de 30/10/2026 e não certifica conformidade institucional.

## Responsabilidades e finalidades

| Decisão | Registro e responsável da escola |
|---|---|
| Controlador, operador e responsáveis por hospedagem/e-mail | A preencher |
| Contato de privacidade e substituto | A preencher |
| Finalidades e fundamentos para cadastro, DVA, passivo, certidões, notificações e relatórios | A preencher por operação, distinguindo informação de saúde |
| Análise de necessidade e melhor interesse dos alunos | A preencher, incluindo riscos, campos mínimos e alternativas |
| Destinatários e compartilhamentos autorizados | A preencher, incluindo provedor, caixas institucionais e eventual transferência internacional |
| Aprovação e revisão independente | Referência, responsáveis e data a preencher |

## Matriz de acesso

O padrão técnico permite a funcionários o cadastro básico; administradores têm acesso aos dados operacionais restritos. A escola deve revisar também as contas administrativas. Não compartilhe credenciais.

Concessões a funcionários são nominativas e temporárias: `ACCESS_HEALTH_STAFF_IDS` permite DVA; `ACCESS_CONTACT_STAFF_IDS`, telefones; `ACCESS_EXPORT_STAFF_IDS`, relatórios/exportações (também requer saúde); `ACCESS_MANAGE_STAFF_IDS`, edição de cadastro (também requer contatos). Todas exigem `ACCESS_APPROVAL_REFERENCE` e `ACCESS_APPROVAL_VALID_UNTIL`. A ausência ou expiração da aprovação recusa a concessão no backend. Os IDs devem corresponder a contas individuais; reveja afastamentos, desligamentos e mudança de função.

Este mecanismo concede capacidades sobre a base escolar compartilhada. Não representa segregação automática por turma ou unidade. Se a escola exigir essa segregação, mantenha a capacidade restrita até implementar e testar o escopo institucional aprovado.

## Aviso aos alunos e responsáveis

Complete e divulgue em canal acessível da escola antes do uso institucional:

> A [ESCOLA/CONTROLADOR] utiliza [CATEGORIAS MÍNIMAS] para [FINALIDADES]. O acesso é permitido a [FUNÇÕES AUTORIZADAS]. Os dados são compartilhados com [DESTINATÁRIOS E FINALIDADES], conservados segundo [CRITÉRIOS DE GUARDA] e protegidos por [MEDIDAS EFETIVAS]. Alunos e responsáveis podem solicitar informações e exercer os direitos aplicáveis pelo canal [CONTATO]. Consulte [LOCAL DO AVISO COMPLETO] para saber como confirmar sua identidade e acompanhar a solicitação.

Use linguagem compreensível aos alunos. Confirme legitimidade do responsável, guarda e restrições de proteção antes de divulgar dados ou contatar a família. Telefone cadastrado não comprova representação legal. Não inclua informação clínica desnecessária em campos livres, mensagens ou materiais públicos.

## Atendimento e incidentes

Registre solicitações em meio privado, com número de protocolo, verificação de identidade/legitimidade, escopo, responsável, prazos aplicáveis, resposta e encerramento. Evite copiar o cadastro completo para esse registro. Encaminhe questões de guarda obrigatória e pedidos incompatíveis com obrigações escolares aos responsáveis competentes.

Em incidente: preserve evidências com acesso restrito; contenha a exposição; revogue credenciais comprometidas; identifique titulares, dados, duração e alcance; acione controlador e responsáveis; registre a avaliação de risco e a decisão fundamentada de comunicação. Confirme os requisitos e prazos legais vigentes com o responsável institucional. Registre comunicação e recuperação, teste as ações e revise o procedimento. O log técnico não substitui o registro institucional do incidente.

## Guarda, descarte e restauração

Aprove uma tabela por categoria: finalidade/fundamento, início da contagem, prazo, exceções, impedimentos de descarte, destino, backups, temporários e responsável. Documentos escolares de guarda obrigatória continuam preservados. Não escolha um prazo único para todos os dados.

`bin/privacy-retention.php` oferece prévia sem mutação e execução separada para telefones e observações operacionais de DVA de alunos inativos. Exige política aprovada, revisão independente, dois administradores ativos, plano íntegro recente e confirmação. Não elimina identidade, nascimento, datas da DVA, vínculos ou documentos do arquivo escolar. O exemplo `config/privacy-retention.example.json` vem intencionalmente sem aprovação e sem data.

O plano v2 é autenticado pelo keyring e vinculado ao caminho do banco e ao estado dos registros. Alteração dos dados ou mudança de local da base exige nova prévia. O operador de CLI informa os dois IDs administrativos e as referências da política: o registro técnico não comprova, sozinho, a participação de duas pessoas. A escola deve manter as aprovações independentes no seu processo e proteger o acesso ao sistema operacional. No Windows, se não for possível conferir ACLs pelo sistema operacional, a rotina recusa o arquivo em vez de assumir que está privado.

`student_ids` permite restringir um lote a até 1.000 IDs aprovados; vazio considera todos os elegíveis com dados remanescentes, respeitando o limite do lote. A seleção nunca inclui aluno ativo, fora do corte ou sob impedimento. Registros já tratados deixam de ocupar o lote seguinte. Campos desconhecidos são recusados para evitar que um erro de nome amplie a seleção. Corte e validade são avaliados em UTC; registre esse critério na aprovação.

Registre a operação em um controle protegido de descartes. Ao restaurar cópias antigas, consulte esse controle e reaplique as medidas aprovadas antes de liberar o acesso. O conjunto de backup não é alterado retroativamente por esta rotina. Categorias não contempladas continuam dependendo de processo específico aprovado.

## Armazenamento e liberação

Verifique criptografia do volume e permissões de banco, sidecars, PDFs, sessões, logs, chaves, temporários e backups. Guarde chaves separadas das cópias de dados. Proteja também o destino dos downloads e os dispositivos autorizados.

`bin/preflight.php --storage-protection` verifica evidência do sistema operacional e reprova caminhos ausentes, não comprovados ou inseguros. Use os caminhos explícitos `--database`, `--storage`, `--sessions`, `--logs`, `--keys`, `--temporary` e `--backups`. Não confunda ausência de comprovação com certeza de que o disco está em claro. Criptografia de volume e ACLs precisam ser configuradas pela infraestrutura; o verificador não altera o sistema operacional.

O aceite deve registrar versão do código, evidências dos testes, volume/ACL, restauração conjunta, tarefas periódicas, responsáveis, aviso publicado, matriz de acesso, política de guarda e simulação de incidentes. Nenhuma dessas aprovações foi preenchida automaticamente.
