-- Migration 22: school links are independent from annual progression balances.
CREATE TABLE IF NOT EXISTS alunos_vinculos_historico (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo TEXT NOT NULL CHECK(tipo IN ('transferencia','retorno')),
    aluno_id INTEGER NOT NULL REFERENCES alunos(id) ON DELETE RESTRICT,
    passivo_id INTEGER NULL REFERENCES alunos_passivo(id) ON DELETE RESTRICT,
    origem_id INTEGER NULL REFERENCES turmas(id) ON DELETE RESTRICT,
    origem_nome TEXT NULL,
    origem_ano INTEGER NULL,
    origem_serie INTEGER NULL CHECK(origem_serie BETWEEN 1 AND 12),
    destino_id INTEGER NOT NULL REFERENCES turmas(id) ON DELETE RESTRICT,
    destino_nome TEXT NOT NULL,
    destino_ano INTEGER NOT NULL CHECK(destino_ano BETWEEN 2000 AND 9999),
    destino_serie INTEGER NOT NULL CHECK(destino_serie BETWEEN 1 AND 12),
    realizado_por INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE RESTRICT,
    realizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK((tipo='retorno' AND passivo_id IS NOT NULL) OR
        (tipo='transferencia' AND passivo_id IS NULL AND origem_id IS NOT NULL AND origem_id<>destino_id
         AND origem_serie IS NOT NULL AND origem_serie=destino_serie
         AND origem_ano IS NOT NULL AND origem_ano=destino_ano))
);
CREATE INDEX IF NOT EXISTS idx_vinculos_aluno ON alunos_vinculos_historico(aluno_id,id);
CREATE TRIGGER IF NOT EXISTS trg_vinculos_historico_immutable_update
    BEFORE UPDATE ON alunos_vinculos_historico BEGIN SELECT RAISE(ABORT,'school_history_immutable'); END;
CREATE TRIGGER IF NOT EXISTS trg_vinculos_historico_immutable_delete
    BEFORE DELETE ON alunos_vinculos_historico BEGIN SELECT RAISE(ABORT,'school_history_immutable'); END;
