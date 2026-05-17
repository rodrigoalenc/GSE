# GSE - Documentação Técnica

Este documento apresenta a descrição técnica da implementação atual do sistema GSE (Gestor de Secretaria Escolar), abordando arquitetura, conexão com banco de dados, entidades e validação de software por meio de testes automatizados.

---

# 1. Implementação da Arquitetura

O sistema GSE utiliza uma arquitetura monolítica desenvolvida em PHP. A organização atual possui uma estrutura parcial inspirada no padrão MVC (Model-View-Controller), com ponto de entrada, roteador, camada Core e Models. No estado atual do código-fonte, não existem diretórios ou arquivos de Controllers e Views implementados.

A estrutura principal do projeto está organizada da seguinte forma:

```text
public/
└── index.php

src/
├── Core/
│   ├── Database.php
│   ├── Helpers.php
│   ├── Model.php
│   └── Router.php
└── Model/
    ├── Aluno.php
    ├── Certidao.php
    ├── Painel.php
    ├── Passivo.php
    ├── Pedido.php
    ├── Relatorio.php
    ├── Sistema.php
    └── Usuario.php

database/
└── schema.sql

tests/
├── Integration/
├── Security/
├── Support/
└── Unit/
```

## Front Controller

O arquivo `public/index.php` atua como ponto central de entrada da aplicação, sendo responsável por:

- inicialização do ambiente;
- carregamento das variáveis `.env`;
- gerenciamento de sessão;
- aplicação de headers de segurança;
- validação básica de sessão para rotas privadas;
- validação CSRF em requisições `POST` privadas;
- despacho das requisições para o roteador.

Exemplo:

```php
define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/src/Core/Helpers.php';

if (is_file(ROOT_PATH . '/.env')) {
    carregar_env(ROOT_PATH . '/.env');
}
```

## Core da Aplicação

A pasta `src/Core` centraliza os componentes estruturais:

| Arquivo | Responsabilidade |
|---|---|
| Database.php | Conexão com banco SQLite por meio de PDO |
| Helpers.php | Funções auxiliares, carregamento de ambiente, escape HTML, redirecionamento, CSRF e headers de segurança |
| Model.php | Classe base dos Models e acesso compartilhado à conexão PDO |
| Router.php | Normalização de URL, validação de segmentos e despacho para Controllers esperados |

## Roteamento

O roteamento é implementado em `src/Core/Router.php`. A classe `Router` interpreta a URL no formato `controller/action/parametros`, valida os segmentos e tenta localizar um arquivo em `src/Controllers`.

Como não há Controllers implementados na estrutura atual, o roteador existe como infraestrutura, mas as rotas finais da aplicação ainda não estão materializadas.

## Models

Os Models concentram persistência e parte das regras de negócio associadas aos dados. O sistema utiliza PDO diretamente, sem ORM, Repository, Service Layer ou mecanismo formal de injeção de dependência.

---

# 2. Conexão com o Banco de Dados

O sistema utiliza SQLite como banco de dados principal.

## Configuração

A conexão é realizada pela classe `src\Core\Database`, utilizando PDO. A variável de ambiente usada para localizar o arquivo SQLite é `DB_PATH`.

Exemplo em `.env.example`:

```env
APP_ENV=development
APP_URL=http://localhost:8000
DB_PATH=E:/TCC/database/banco.db
```

## Criação da Conexão

A conexão com o banco de dados SQLite é criada utilizando a classe PDO do PHP. O parâmetro `'sqlite:' . $dbPath` informa o caminho do arquivo do banco de dados. Como o SQLite não utiliza usuário e senha, os parâmetros permanecem como `null`.

A configuração `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION` faz com que erros de banco sejam tratados como exceções. A configuração `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC` define que os resultados das consultas sejam retornados em formato associativo. A configuração `PDO::ATTR_EMULATE_PREPARES => false` desativa a emulação de prepared statements.

```php
self::$connection = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
```

## Configurações Aplicadas

Após a criação da conexão, são aplicadas as seguintes configurações:

```sql
PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;
```

## Persistência

A aplicação utiliza uma conexão compartilhada por meio da classe base `Model`:

```php
protected static $pdo;
```

Os Models acessam essa conexão diretamente e executam consultas SQL com PDO.

## Transações

Alguns Models utilizam transações para operações compostas, como cadastro de aluno com DVA, importação do arquivo passivo, criação de pedidos e manipulação de páginas/produtos.

Exemplo simplificado:

```php
self::$pdo->beginTransaction();

$stmt->execute();

self::$pdo->commit();
```

Rollback em caso de falha:

```php
self::$pdo->rollBack();
```

## Inicialização do Banco

Não foram encontradas migrations versionadas nem seeders. A estrutura do banco é definida pelo script `database/schema.sql`, que também é utilizado pelos testes automatizados para criar bancos SQLite temporários.

---

# 3. Criação de Entidades

As entidades atualmente implementadas estão definidas no arquivo `database/schema.sql` e são manipuladas pelos Models em `src/Model`. Não há mapeamento ORM.

## Usuário

Tabela: `usuarios`

| Campo | Tipo | Restrição |
|---|---|---|
| id | INTEGER | PK, autoincremento |
| nome | TEXT | obrigatório |
| email | TEXT | obrigatório, único |
| senha | TEXT | obrigatório |
| tipo | TEXT | obrigatório |
| criado_em | TEXT | padrão `CURRENT_TIMESTAMP` |

O Model `Usuario` é responsável pela persistência de usuários e credenciais. Ele implementa cadastro, busca por e-mail, busca por ID, listagem, atualização, atualização de perfil e exclusão. As senhas são armazenadas com `password_hash`.

Não há, no estado atual, Controller de login ou fluxo completo de autenticação implementado.

---

## Turma

Tabela: `turmas`

| Campo | Tipo | Restrição |
|---|---|---|
| id | INTEGER | PK, autoincremento |
| nome_turma | TEXT | obrigatório, único |

Relacionamento: uma turma pode possuir vários alunos. Em `alunos`, a chave estrangeira `id_turma` utiliza `ON DELETE SET NULL`.

---

## Aluno

Tabela: `alunos`

| Campo | Tipo | Restrição |
|---|---|---|
| id | INTEGER | PK, autoincremento |
| nome_completo | TEXT | obrigatório |
| data_nascimento | TEXT | obrigatório |
| id_turma | INTEGER | FK opcional para `turmas.id` |
| telefone_aluno | TEXT | opcional |
| telefone_responsavel | TEXT | opcional |
| criado_em | TEXT | padrão `CURRENT_TIMESTAMP` |

Relacionamentos:

- um aluno pode pertencer a uma turma;
- uma turma pode possuir vários alunos;
- um aluno pode possuir registro de DVA.

O Model `Aluno` implementa contagem, listagem, busca por ID, verificação de existência, cadastro, atualização, exclusão, listagem de alunos sem DVA e consultas de aniversariantes.

---

## DVA

Tabela: `dvas`

A DVA representa a Declaração de Vacina Atualizada associada ao aluno, conforme a terminologia utilizada no TCC.

| Campo | Tipo | Restrição |
|---|---|---|
| id | INTEGER | PK, autoincremento |
| id_aluno | INTEGER | FK obrigatória para `alunos.id` |
| id_usuario_registro | INTEGER | FK opcional para `usuarios.id` |
| data_vencimento | TEXT | obrigatório |
| observacao | TEXT | opcional |
| criado_em | TEXT | padrão `CURRENT_TIMESTAMP` |

Relacionamentos:

- uma DVA pertence a um aluno;
- ao excluir um aluno, suas DVAs são excluídas por `ON DELETE CASCADE`;
- o usuário de registro é opcional e usa `ON DELETE SET NULL`.

---

## Fornecedor

Tabela: `lista_fornecedores`

| Campo | Tipo | Restrição |
|---|---|---|
| id | INTEGER | PK, autoincremento |
| nome | TEXT | obrigatório, único |

Essa tabela é utilizada pelo Model `Certidao` como lista auxiliar de fornecedores.

---

## Tipo de Certidão

Tabela: `lista_tipos_certidao`

| Campo | Tipo | Restrição |
|---|---|---|
| id | INTEGER | PK, autoincremento |
| nome | TEXT | obrigatório, único |

Essa tabela é utilizada pelo Model `Certidao` como lista auxiliar de tipos de certidão.

---

## Certidão

Tabela: `certidoes`

| Campo | Tipo | Restrição |
|---|---|---|
| id | INTEGER | PK, autoincremento |
| id_fornecedor | INTEGER | FK obrigatória para `lista_fornecedores.id` |
| id_tipo_certidao | INTEGER | FK obrigatória para `lista_tipos_certidao.id` |
| data_emissao | TEXT | obrigatório |
| data_vencimento | TEXT | obrigatório |
| observacao | TEXT | opcional |
| arquivo_pdf | TEXT | opcional |
| arquivado | INTEGER | padrão 0 |
| status | INTEGER | padrão 1 |

Relacionamentos:

- um fornecedor pode possuir várias certidões;
- um tipo de certidão pode estar associado a várias certidões.

O Model `Certidao` implementa cadastro, listagem de vigentes, listagem por ano, atualização, exclusão, arquivamento/desarquivamento, consulta de certidões próximas do vencimento e manutenção das listas auxiliares.

---

## Arquivo Passivo

Tabela: `alunos_passivo`

| Campo | Tipo | Restrição |
|---|---|---|
| id | INTEGER | PK, autoincremento |
| nome_completo | TEXT | obrigatório |
| data_nascimento | TEXT | opcional |
| numero | TEXT | opcional |
| caixa | TEXT | opcional |
| nome_sort | TEXT | obrigatório |

O Model `Passivo` implementa cadastro, busca, atualização, exclusão, importação CSV, resumo de caixas, enumeração por caixa e listagem para TXT.

---

## Pedido

Tabela: `pedidos`

| Campo | Tipo | Restrição |
|---|---|---|
| id | INTEGER | PK, autoincremento |
| titulo | TEXT | obrigatório |
| valor_total | REAL | obrigatório, padrão 0 |
| qtd_paginas | INTEGER | obrigatório, padrão 1 |
| criado_em | TEXT | padrão `CURRENT_TIMESTAMP` |

O Model `Pedido` implementa operações sobre pedidos, páginas e produtos. Embora o TCC preveja contratos e estoque, a estrutura atual do código está implementada como pedidos, páginas e produtos, não como módulo completo de contratos e estoque.

---

## Página do Pedido

Tabela: `pedido_paginas`

| Campo | Tipo | Restrição |
|---|---|---|
| id | INTEGER | PK, autoincremento |
| id_pedido | INTEGER | FK obrigatória para `pedidos.id` |
| numero_pagina | INTEGER | obrigatório |
| valor_pagina | REAL | obrigatório, padrão 0 |
| observacao | TEXT | opcional |
| data_faturamento | TEXT | opcional |

Restrições:

- `UNIQUE (id_pedido, numero_pagina)`;
- exclusão em cascata quando o pedido é removido.

---

## Produto do Pedido

Tabela: `pedido_produtos`

| Campo | Tipo | Restrição |
|---|---|---|
| id | INTEGER | PK, autoincremento |
| id_pedido | INTEGER | FK obrigatória para `pedidos.id` |
| numero_pagina | INTEGER | obrigatório |
| nome_produto | TEXT | obrigatório |
| marca | TEXT | opcional |
| unidade | TEXT | obrigatório |
| quantidade | REAL | obrigatório |
| valor_unitario | REAL | obrigatório |
| valor_total | REAL | obrigatório |

Relacionamento: um pedido pode possuir vários produtos. A exclusão do pedido remove os produtos por `ON DELETE CASCADE`.

---

## Log

Tabela: `logs`

| Campo | Tipo | Restrição |
|---|---|---|
| id | INTEGER | PK, autoincremento |
| data_hora | TEXT | padrão `CURRENT_TIMESTAMP` |
| usuario | TEXT | opcional |
| acao | TEXT | obrigatório |
| detalhes | TEXT | opcional |

O Model `Sistema` implementa listagem de logs, limpeza de logs antigos, criação de backup manual e listagem de backups. Não foi encontrada integração automática dos demais Models com geração de logs de auditoria.

---

# 4. Validação de Software utilizando Testes Unitários e Testes de Integração

O projeto utiliza PHPUnit, configurado em `phpunit.xml`.

## Estrutura

```xml
<testsuite name="Unit">
    <directory>tests/Unit</directory>
</testsuite>

<testsuite name="Integration">
    <directory>tests/Integration</directory>
</testsuite>

<testsuite name="Security">
    <directory>tests/Security</directory>
</testsuite>
```

---

## Testes Unitários

O diretório `tests/Unit` contém o teste `UsuarioTest.php`, focado no Model `Usuario`.

Exemplo:

```php
$this->assertTrue(
    $usuario->cadastrar(
        'Maria Silva',
        'maria@example.com',
        'segredo123',
        'admin'
    )
);
```

Validações observadas:

- cadastro de usuário;
- busca por e-mail;
- busca por ID;
- listagem;
- atualização;
- atualização de perfil;
- verificação de hash de senha com `password_verify`;
- exclusão.

---

## Testes de Integração

Os testes de integração validam:

- comunicação com SQLite;
- persistência real;
- integração entre Models;
- regras de negócio implementadas na camada de Models.

Principais testes encontrados:

| Teste | Objetivo |
|---|---|
| AlunoPainelTest | Alunos, turmas, DVA e consultas de painel |
| PassivoTest | Arquivo passivo, caixas, importação CSV e enumeração |
| CertidaoTest | Certidões, fornecedores, tipos de certidão e arquivamento |
| PedidoTest | Pedidos, páginas e produtos |
| RelatorioTest | Relatórios por turma e situação da DVA |
| SistemaTest | Logs e backups |

---

## Testes de Segurança

A suíte `Security` valida:

- geração e validação de token CSRF;
- escape HTML;
- validação de segmentos do roteador;
- singularização de rotas.

Exemplo:

```php
$this->assertTrue(csrf_valido($token));
```

---

# Ambiente de Testes

Os testes utilizam bancos SQLite temporários criados automaticamente durante a execução. O arquivo `tests/Support/DatabaseTestCase.php` cria o banco em diretório temporário, configura `DB_PATH`, aplica o schema de `database/schema.sql` e remove os arquivos temporários ao final.

Essa abordagem garante isolamento na validação da persistência e das regras de negócio implementadas nos Models.
