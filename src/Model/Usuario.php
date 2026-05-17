<?php
require_once ROOT_PATH . '/src/Core/Model.php';

class Usuario extends Model
{
    public function __construct()
    {
        parent::__construct();
    }

    public function buscarPorEmail($email)
    {
        try {
            $stmt = self::$pdo->prepare("SELECT * FROM usuarios WHERE email = ?");
            $stmt->execute([$email]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("Erro ao buscar usuário por email: " . $e->getMessage());
            return false;
        }
    }

    public function buscarPorId($id)
    {
        try {
            $stmt = self::$pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("Erro ao buscar usuário por ID: " . $e->getMessage());
            return false;
        }
    }

    public function listar()
    {
        try {
            return self::$pdo->query("SELECT * FROM usuarios ORDER BY nome")->fetchAll();
        } catch (Exception $e) {
            error_log("Erro ao listar usuários: " . $e->getMessage());
            return [];
        }
    }

    public function cadastrar($nome, $email, $senha, $tipo)
    {
        try {
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $sql = "INSERT INTO usuarios (nome, email, senha, tipo) VALUES (?, ?, ?, ?)";
            $stmt = self::$pdo->prepare($sql);
            return $stmt->execute([$nome, $email, $hash, $tipo]);
        } catch (Exception $e) {
            error_log("Erro ao cadastrar usuário: " . $e->getMessage());
            return false;
        }
    }

    public function atualizar($id, $nome, $email, $tipo, $novaSenha = null)
    {
        try {
            $sql = "UPDATE usuarios SET nome=?, email=?, tipo=?";
            $params = [$nome, $email, $tipo];

            if (!empty($novaSenha)) {
                $sql .= ", senha=?";
                $params[] = password_hash($novaSenha, PASSWORD_DEFAULT);
            }

            $sql .= " WHERE id=?";
            $params[] = $id;

            $stmt = self::$pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (Exception $e) {
            error_log("Erro ao atualizar usuário: " . $e->getMessage());
            return false;
        }
    }

    public function atualizarPerfil($id, $nome, $email, $novaSenha = null)
    {
        try {
            $sql = "UPDATE usuarios SET nome=?, email=?";
            $params = [$nome, $email];

            if (!empty($novaSenha)) {
                $sql .= ", senha=?";
                $params[] = password_hash($novaSenha, PASSWORD_DEFAULT);
            }

            $sql .= " WHERE id=?";
            $params[] = $id;

            return self::$pdo->prepare($sql)->execute($params);
        } catch (Exception $e) {
            error_log("Erro ao atualizar perfil: " . $e->getMessage());
            return false;
        }
    }

    public function excluir($id)
    {
        try {
            $stmt = self::$pdo->prepare("DELETE FROM usuarios WHERE id=?");
            return $stmt->execute([$id]);
        } catch (PDOException $e) {
            if ($e->getCode() == '23000' || strpos($e->getMessage(), 'FOREIGN KEY') !== false) {
                return 'tem_registros';
            }
            error_log("Erro ao excluir usuário: " . $e->getMessage());
            return false;
        }
    }
}
