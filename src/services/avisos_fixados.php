<?php

function usuariosFixadosAvisos(PDO $pdo, int $adminId): array
{
    $stmt = $pdo->prepare('SELECT id_user FROM Aviso_Usuario_Fixado WHERE id_admin = ? ORDER BY posicao');
    $stmt->execute([$adminId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function definirUsuarioFixadoAvisos(PDO $pdo, int $adminId, int $usuarioId, bool $fixar): array
{
    $pdo->beginTransaction();
    try {
        // Serializa alterações do mesmo administrador, inclusive entre abas.
        $stmt = $pdo->prepare('SELECT tipo_perfil, bloqueado FROM Usuario WHERE id_user = ? FOR UPDATE');
        $stmt->execute([$adminId]);
        $admin = $stmt->fetch();
        if (!$admin || $admin['tipo_perfil'] !== 'admin' || (int) $admin['bloqueado'] !== 0) {
            throw new DomainException('Somente administradores ativos podem fixar usuários.');
        }
        $stmt = $pdo->prepare("SELECT 1 FROM Usuario WHERE id_user = ? AND tipo_perfil IN ('estudante', 'laboratorista', 'admin')");
        $stmt->execute([$usuarioId]);
        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('Usuário não encontrado.');
        }

        $stmt = $pdo->prepare('SELECT posicao, id_user FROM Aviso_Usuario_Fixado WHERE id_admin = ? ORDER BY posicao');
        $stmt->execute([$adminId]);
        $fixados = $stmt->fetchAll();
        $jaFixado = in_array($usuarioId, array_map('intval', array_column($fixados, 'id_user')), true);
        if ($fixar && !$jaFixado) {
            if (count($fixados) >= 3) {
                throw new LengthException('Você pode fixar até 3 usuários. Desafixe um para fixar outro.');
            }
            $livres = array_diff([1, 2, 3], array_map('intval', array_column($fixados, 'posicao')));
            $pdo->prepare('INSERT INTO Aviso_Usuario_Fixado (id_admin, posicao, id_user) VALUES (?, ?, ?)')
                ->execute([$adminId, min($livres), $usuarioId]);
        } elseif (!$fixar && $jaFixado) {
            $pdo->prepare('DELETE FROM Aviso_Usuario_Fixado WHERE id_admin = ? AND id_user = ?')
                ->execute([$adminId, $usuarioId]);
        }
        $resultado = usuariosFixadosAvisos($pdo, $adminId);
        $pdo->commit();
        return $resultado;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
