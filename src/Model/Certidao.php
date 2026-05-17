<?php
require_once ROOT_PATH . '/src/Core/Model.php';

class Certidao extends Model
{
    public function listarVigentes()
    {
        $sql = "SELECT
                    c.*,
                    f.nome AS fornecedor,
                    t.nome AS tipo_certidao
                FROM certidoes c
                JOIN lista_fornecedores f ON f.id = c.id_fornecedor
                JOIN lista_tipos_certidao t ON t.id = c.id_tipo_certidao
                WHERE (c.arquivado = 0 OR c.arquivado IS NULL)
                AND (c.status = 1 OR c.status IS NULL)
                ORDER BY f.nome ASC, c.data_vencimento ASC";
        $stmt = self::$pdo->query($sql);
        return $stmt->fetchAll();
    }

    public function getAnosDisponiveis()
    {
        $sql = "SELECT DISTINCT strftime('%Y', data_vencimento) as ano
                FROM certidoes
                WHERE (arquivado = 1 OR status = 0 OR status = '0')
                ORDER BY ano DESC";
        $stmt = self::$pdo->query($sql);
        $anos = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_filter($anos);
    }

    public function listarPorAno($ano)
    {
        if ($ano === 'todos' || empty($ano)) {
            $sql = "SELECT
                        c.*,
                        f.nome AS fornecedor,
                        t.nome AS tipo_certidao
                    FROM certidoes c
                    JOIN lista_fornecedores f ON f.id = c.id_fornecedor
                    JOIN lista_tipos_certidao t ON t.id = c.id_tipo_certidao
                    WHERE (c.arquivado = 1 OR c.status = 0 OR c.status = '0')
                    ORDER BY c.data_vencimento DESC";

            $stmt = self::$pdo->query($sql);
            return $stmt->fetchAll();
        }

        $sql = "SELECT
                    c.*,
                    f.nome AS fornecedor,
                    t.nome AS tipo_certidao
                FROM certidoes c
                JOIN lista_fornecedores f ON f.id = c.id_fornecedor
                JOIN lista_tipos_certidao t ON t.id = c.id_tipo_certidao
                WHERE strftime('%Y', c.data_vencimento) = ?
                AND (c.arquivado = 1 OR c.status = 0 OR c.status = '0')
                ORDER BY c.data_vencimento DESC";

        $stmt = self::$pdo->prepare($sql);
        $stmt->execute([$ano]);
        return $stmt->fetchAll();
    }

    public function cadastrar($fornecedor, $tipo, $emissao, $vencimento, $obs, $arquivoPdf)
    {
        try {
            $sql = "INSERT INTO certidoes (
                        id_fornecedor,
                        id_tipo_certidao,
                        data_emissao,
                        data_vencimento,
                        observacao,
                        arquivo_pdf,
                        arquivado,
                        status
                    ) VALUES (?, ?, ?, ?, ?, ?, 0, 1)";
            $stmt = self::$pdo->prepare($sql);
            $stmt->execute([$fornecedor, $tipo, $emissao, $vencimento, $obs, $arquivoPdf]);
            return true;
        } catch (Exception $e) {
            error_log("Erro ao cadastrar certidao: " . $e->getMessage());
            return false;
        }
    }

    public function buscarPorId($id)
    {
        $sql = "SELECT
                    c.*,
                    f.nome AS fornecedor,
                    t.nome AS tipo_certidao
                FROM certidoes c
                JOIN lista_fornecedores f ON f.id = c.id_fornecedor
                JOIN lista_tipos_certidao t ON t.id = c.id_tipo_certidao
                WHERE c.id = ?";

        $stmt = self::$pdo->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function atualizar($id, $fornecedor, $tipo, $emissao, $vencimento, $obs, $arquivoPdf)
    {
        try {
            $sql = "UPDATE certidoes SET
                        id_fornecedor = ?,
                        id_tipo_certidao = ?,
                        data_emissao = ?,
                        data_vencimento = ?,
                        observacao = ?,
                        arquivo_pdf = ?
                    WHERE id = ?";

            $stmt = self::$pdo->prepare($sql);
            $stmt->execute([$fornecedor, $tipo, $emissao, $vencimento, $obs, $arquivoPdf, $id]);
            return true;
        } catch (Exception $e) {
            error_log("Erro ao atualizar certidao ID $id: " . $e->getMessage());
            return false;
        }
    }

    public function excluir($id)
    {
        try {
            $stmt = self::$pdo->prepare("SELECT
                        c.arquivo_pdf,
                        f.nome AS fornecedor,
                        t.nome AS tipo_certidao
                    FROM certidoes c
                    JOIN lista_fornecedores f ON f.id = c.id_fornecedor
                    JOIN lista_tipos_certidao t ON t.id = c.id_tipo_certidao
                    WHERE c.id = ?");
            $stmt->execute([$id]);
            $dados = $stmt->fetch();

            if ($dados) {
                $del = self::$pdo->prepare("DELETE FROM certidoes WHERE id = ?");
                $del->execute([$id]);
                return $dados;
            }
            return false;
        } catch (Exception $e) {
            error_log("Erro ao excluir certidao ID $id: " . $e->getMessage());
            return false;
        }
    }

    public function alternarArquivo($id, $status_novo)
    {
        try {
            $st_db = ($status_novo == 1) ? 0 : 1;

            $sql = "UPDATE certidoes SET arquivado = ?, status = ? WHERE id = ?";
            $stmt = self::$pdo->prepare($sql);
            return $stmt->execute([$status_novo, $st_db, $id]);
        } catch (Exception $e) {
            error_log("Erro ao arquivar/desarquivar certidao ID $id: " . $e->getMessage());
            return false;
        }
    }

    public function buscarVencendoProximosDias($dias = 30)
    {
        $hoje = date('Y-m-d');
        $limite = date('Y-m-d', strtotime("+$dias days"));

        $sql = "SELECT
                    c.*,
                    f.nome AS fornecedor,
                    t.nome AS tipo_certidao
                FROM certidoes c
                JOIN lista_fornecedores f ON f.id = c.id_fornecedor
                JOIN lista_tipos_certidao t ON t.id = c.id_tipo_certidao
                WHERE c.data_vencimento >= ?
                AND c.data_vencimento <= ?
                AND (c.arquivado = 0 OR c.arquivado IS NULL)
                AND (c.status = 1 OR c.status IS NULL)
                ORDER BY c.data_vencimento ASC";

        $stmt = self::$pdo->prepare($sql);
        $stmt->execute([$hoje, $limite]);
        return $stmt->fetchAll();
    }

    public function listarFornecedores()
    {
        $sql = "SELECT * FROM lista_fornecedores ORDER BY nome ASC";
        $stmt = self::$pdo->query($sql);
        return $stmt->fetchAll();
    }

    public function listarTiposCertidao($apenasNomes = false)
    {
        $sql = "SELECT " . ($apenasNomes ? "nome" : "*") . " FROM lista_tipos_certidao ORDER BY nome ASC";
        $stmt = self::$pdo->query($sql);

        if ($apenasNomes) {
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        return $stmt->fetchAll();
    }

    public function adicionarOpcaoLista($tipoLista, $nome)
    {
        $tabela = $this->validarTabelaConfiguracao($tipoLista);
        if (!$tabela) {
            return false;
        }

        $stmt = self::$pdo->prepare("INSERT INTO {$tabela} (nome) VALUES (?)");
        return $stmt->execute([$nome]);
    }

    public function atualizarOpcaoLista($tipoLista, $id, $nome)
    {
        $tabela = $this->validarTabelaConfiguracao($tipoLista);
        if (!$tabela) {
            return false;
        }

        $stmt = self::$pdo->prepare("UPDATE {$tabela} SET nome = ? WHERE id = ?");
        return $stmt->execute([$nome, $id]);
    }

    public function excluirOpcaoLista($tipoLista, $id)
    {
        $tabela = $this->validarTabelaConfiguracao($tipoLista);
        if (!$tabela) {
            return false;
        }

        $stmt = self::$pdo->prepare("DELETE FROM {$tabela} WHERE id = ?");
        return $stmt->execute([$id]);
    }

    private function validarTabelaConfiguracao($tipoLista)
    {
        $permitidas = ['lista_fornecedores', 'lista_tipos_certidao'];
        return in_array($tipoLista, $permitidas, true) ? $tipoLista : null;
    }
}
