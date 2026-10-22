-- Migration 20: annual processing, preserving all existing student history.
CREATE TABLE IF NOT EXISTS alunos_encerramento_turmas (
    ano INTEGER NOT NULL CHECK(ano BETWEEN 2000 AND 9999),
    id_turma INTEGER NOT NULL REFERENCES turmas(id) ON DELETE RESTRICT,
    retidos_json TEXT NOT NULL,
    confirmado_por INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE RESTRICT,
    confirmado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(ano,id_turma)
);
CREATE TABLE IF NOT EXISTS turmas_progressao_destinos (
    origem_id INTEGER PRIMARY KEY REFERENCES turmas(id) ON DELETE RESTRICT,
    destino_id INTEGER NOT NULL REFERENCES turmas(id) ON DELETE RESTRICT,
    CHECK(origem_id<>destino_id)
);
CREATE TABLE IF NOT EXISTS alunos_progressao_anos (
    ano INTEGER PRIMARY KEY CHECK(ano BETWEEN 2000 AND 9999),
    lote_id INTEGER NOT NULL UNIQUE REFERENCES alunos_movimentacao_lotes(id) ON DELETE RESTRICT,
    realizado_por INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE RESTRICT,
    realizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
