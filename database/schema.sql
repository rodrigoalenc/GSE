PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS usuarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    senha TEXT NOT NULL,
    tipo TEXT NOT NULL,
    criado_em TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS turmas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome_turma TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS alunos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome_completo TEXT NOT NULL,
    data_nascimento TEXT NOT NULL,
    id_turma INTEGER NULL,
    telefone_aluno TEXT NULL,
    telefone_responsavel TEXT NULL,
    criado_em TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_turma) REFERENCES turmas(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS dvas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    id_aluno INTEGER NOT NULL,
    id_usuario_registro INTEGER NULL,
    data_vencimento TEXT NOT NULL,
    observacao TEXT NULL,
    criado_em TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_aluno) REFERENCES alunos(id) ON DELETE CASCADE,
    FOREIGN KEY (id_usuario_registro) REFERENCES usuarios(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS lista_fornecedores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS lista_tipos_certidao (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS certidoes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    id_fornecedor INTEGER NOT NULL,
    id_tipo_certidao INTEGER NOT NULL,
    data_emissao TEXT NOT NULL,
    data_vencimento TEXT NOT NULL,
    observacao TEXT NULL,
    arquivo_pdf TEXT NULL,
    arquivado INTEGER DEFAULT 0,
    status INTEGER DEFAULT 1,
    FOREIGN KEY (id_fornecedor) REFERENCES lista_fornecedores(id),
    FOREIGN KEY (id_tipo_certidao) REFERENCES lista_tipos_certidao(id)
);

CREATE TABLE IF NOT EXISTS alunos_passivo (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome_completo TEXT NOT NULL,
    data_nascimento TEXT NULL,
    numero TEXT NULL,
    caixa TEXT NULL,
    nome_sort TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS pedidos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    titulo TEXT NOT NULL,
    valor_total REAL NOT NULL DEFAULT 0,
    qtd_paginas INTEGER NOT NULL DEFAULT 1,
    criado_em TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS pedido_paginas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    id_pedido INTEGER NOT NULL,
    numero_pagina INTEGER NOT NULL,
    valor_pagina REAL NOT NULL DEFAULT 0,
    observacao TEXT NULL,
    data_faturamento TEXT NULL,
    FOREIGN KEY (id_pedido) REFERENCES pedidos(id) ON DELETE CASCADE,
    UNIQUE (id_pedido, numero_pagina)
);

CREATE TABLE IF NOT EXISTS pedido_produtos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    id_pedido INTEGER NOT NULL,
    numero_pagina INTEGER NOT NULL,
    nome_produto TEXT NOT NULL,
    marca TEXT NULL,
    unidade TEXT NOT NULL,
    quantidade REAL NOT NULL,
    valor_unitario REAL NOT NULL,
    valor_total REAL NOT NULL,
    FOREIGN KEY (id_pedido) REFERENCES pedidos(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    data_hora TEXT DEFAULT CURRENT_TIMESTAMP,
    usuario TEXT NULL,
    acao TEXT NOT NULL,
    detalhes TEXT NULL
);

