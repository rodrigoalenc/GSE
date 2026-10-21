-- Migration 19: additive metadata and history; existing classes and students remain intact.
CREATE TABLE IF NOT EXISTS turmas_series (
    id_turma INTEGER PRIMARY KEY REFERENCES turmas(id) ON DELETE RESTRICT,
    serie INTEGER NOT NULL CHECK (serie BETWEEN 1 AND 12)
);
CREATE TABLE IF NOT EXISTS alunos_conferencias (
    ano INTEGER PRIMARY KEY CHECK (ano BETWEEN 2000 AND 9999),
    alunos_json TEXT NOT NULL,
    fingerprint TEXT NOT NULL,
    confirmado_por INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE RESTRICT,
    confirmado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS alunos_movimentacao_lotes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo TEXT NOT NULL CHECK (tipo IN ('progressao', 'regressao')),
    ano INTEGER NOT NULL CHECK (ano BETWEEN 2000 AND 9999),
    realizado_por INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE RESTRICT,
    realizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS alunos_movimentacoes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lote_id INTEGER NOT NULL REFERENCES alunos_movimentacao_lotes(id) ON DELETE RESTRICT,
    aluno_id INTEGER NOT NULL REFERENCES alunos(id) ON DELETE RESTRICT,
    origem_id INTEGER NOT NULL REFERENCES turmas(id) ON DELETE RESTRICT,
    destino_id INTEGER NOT NULL REFERENCES turmas(id) ON DELETE RESTRICT,
    origem_nome TEXT NOT NULL,
    destino_nome TEXT NOT NULL,
    origem_ano INTEGER NULL,
    destino_ano INTEGER NULL,
    origem_serie INTEGER NOT NULL CHECK (origem_serie BETWEEN 1 AND 12),
    destino_serie INTEGER NOT NULL CHECK (destino_serie BETWEEN 1 AND 12),
    UNIQUE (lote_id, aluno_id),
    CHECK (ABS(destino_serie - origem_serie) = 1)
);
CREATE INDEX IF NOT EXISTS idx_movimentacoes_aluno ON alunos_movimentacoes(aluno_id, id);
