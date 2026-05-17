<?php
require_once ROOT_PATH . '/src/Core/Model.php';

class Passivo extends Model
{
    public function getListaCaixas()
    {
        try {
            $sql = "SELECT DISTINCT caixa FROM alunos_passivo
                    WHERE caixa IS NOT NULL AND caixa != ''
                    ORDER BY LENGTH(caixa) ASC, caixa ASC";
            return self::$pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            error_log("Erro no Model Passivo (getListaCaixas): " . $e->getMessage());
            return [];
        }
    }

    public function getResumoCaixas()
    {
        try {
            $sql = "SELECT caixa, COUNT(id) as total
                    FROM alunos_passivo
                    WHERE caixa IS NOT NULL AND caixa != ''
                    GROUP BY caixa
                    ORDER BY caixa ASC";
            return self::$pdo->query($sql)->fetchAll();
        } catch (Exception $e) {
            error_log("Erro no Model Passivo (getResumoCaixas): " . $e->getMessage());
            return [];
        }
    }

    public function buscar($termo = '', $caixaFiltro = '')
    {
        try {
            $sql = "SELECT * FROM alunos_passivo WHERE 1=1 ";
            $params = [];

            if (!empty($caixaFiltro)) {
                $sql .= " AND caixa = ? ";
                $params[] = $caixaFiltro;
            }

            if (!empty($termo)) {
                $termo_limpo = $this->criarNomeSort($termo);
                $sql .= " AND (nome_sort LIKE ? OR numero LIKE ?) ";
                $params[] = "%$termo_limpo%";
                $params[] = "%$termo%";
            }

            if (!empty($caixaFiltro) && empty($termo)) {
                $sql .= " ORDER BY CAST(numero AS INTEGER) ASC, nome_sort ASC";
            } else {
                $sql .= " ORDER BY nome_sort ASC LIMIT 100";
            }

            $stmt = self::$pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Erro no Model Passivo (buscar): " . $e->getMessage());
            return [];
        }
    }

    public function cadastrar($nome, $nasc, $numero, $caixa)
    {
        try {
            $nome_sort = $this->criarNomeSort($nome);
            $sql = "INSERT INTO alunos_passivo (nome_completo, data_nascimento, numero, caixa, nome_sort)
                    VALUES (?, ?, ?, ?, ?)";
            $stmt = self::$pdo->prepare($sql);
            $stmt->execute([$nome, $nasc, $numero, $caixa, $nome_sort]);
            return self::$pdo->lastInsertId();
        } catch (Exception $e) {
            error_log("Erro no Model Passivo (cadastrar): " . $e->getMessage());
            return false;
        }
    }

    public function importarCSV($caminhoArquivo)
    {
        if (($handle = fopen($caminhoArquivo, "r")) !== false) {
            try {
                self::$pdo->beginTransaction();
                self::$pdo->exec("DELETE FROM alunos_passivo");

                fgetcsv($handle, 1000, ";", '"', "\\");

                $sql = "INSERT INTO alunos_passivo (nome_completo, data_nascimento, numero, caixa, nome_sort)
                        VALUES (?, ?, ?, ?, ?)";
                $stmt = self::$pdo->prepare($sql);

                while (($dados = fgetcsv($handle, 1000, ";", '"', "\\")) !== false) {
                    $nome = $dados[0] ?? '';
                    $nasc = $dados[1] ?? '';
                    $num = $dados[2] ?? '';
                    $caixa = $dados[3] ?? '';

                    if (!empty($nome)) {
                        $nome_sort = $this->criarNomeSort($nome);
                        $stmt->execute([$nome, $nasc, $num, $caixa, $nome_sort]);
                    }
                }

                self::$pdo->commit();
                fclose($handle);
                return true;
            } catch (Exception $e) {
                self::$pdo->rollBack();
                fclose($handle);
                error_log("Erro no Model Passivo (importarCSV): " . $e->getMessage());
                return false;
            }
        }

        return false;
    }

    public function buscarPorId($id)
    {
        try {
            $stmt = self::$pdo->prepare("SELECT * FROM alunos_passivo WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("Erro no Model Passivo (buscarPorId): " . $e->getMessage());
            return false;
        }
    }

    public function atualizar($id, $nome, $nasc, $numero, $caixa)
    {
        try {
            $nome_sort = $this->criarNomeSort($nome);
            $sql = "UPDATE alunos_passivo SET nome_completo=?, data_nascimento=?, numero=?, caixa=?, nome_sort=? WHERE id=?";
            $stmt = self::$pdo->prepare($sql);
            $stmt->execute([$nome, $nasc, $numero, $caixa, $nome_sort, $id]);
            return true;
        } catch (Exception $e) {
            error_log("Erro no Model Passivo (atualizar ID {$id}): " . $e->getMessage());
            return false;
        }
    }

    public function excluir($id)
    {
        try {
            return self::$pdo->prepare("DELETE FROM alunos_passivo WHERE id=?")->execute([$id]);
        } catch (Exception $e) {
            error_log("Erro no Model Passivo (excluir ID {$id}): " . $e->getMessage());
            return false;
        }
    }

    public function enumerarCaixa($caixa)
    {
        try {
            $ultimo = self::$pdo->query("SELECT MAX(CAST(numero AS INTEGER)) FROM alunos_passivo")->fetchColumn();
            $proximo = $ultimo ? $ultimo + 1 : 1;

            $stmt = self::$pdo->prepare("SELECT id FROM alunos_passivo WHERE caixa = ? AND (numero IS NULL OR numero = 0 OR numero = '') ORDER BY nome_sort ASC");
            $stmt->execute([$caixa]);
            $lista = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($lista) > 0) {
                self::$pdo->beginTransaction();
                $up = self::$pdo->prepare("UPDATE alunos_passivo SET numero = ? WHERE id = ?");

                foreach ($lista as $id) {
                    $up->execute([$proximo++, $id]);
                }

                self::$pdo->commit();
                return count($lista);
            }

            return 0;
        } catch (Exception $e) {
            if (self::$pdo->inTransaction()) {
                self::$pdo->rollBack();
            }
            error_log("Erro no Model Passivo (enumerarCaixa): " . $e->getMessage());
            return false;
        }
    }

    public function listarParaTxt($caixa)
    {
        try {
            $stmt = self::$pdo->prepare("SELECT numero, nome_completo FROM alunos_passivo WHERE caixa = ? ORDER BY CAST(numero AS INTEGER) ASC");
            $stmt->execute([$caixa]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Erro no Model Passivo (listarParaTxt): " . $e->getMessage());
            return [];
        }
    }

    private function criarNomeSort($str)
    {
        $str = mb_strtolower($str, 'UTF-8');
        $normalizado = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);

        if ($normalizado !== false) {
            $str = $normalizado;
        }

        return strtoupper($str);
    }
}
