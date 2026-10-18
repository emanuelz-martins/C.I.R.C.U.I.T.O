<?php
declare(strict_types=1);

/** Registra apenas o método de uma autenticação concluída com sucesso. */
function registrarFormaLogin(PDO $pdo, int $usuarioId, string $forma): void
{
    if (!in_array($forma, ['google', 'teste', 'sigaa'], true)) {
        throw new InvalidArgumentException('Forma de login inválida.');
    }
    $pdo->prepare('UPDATE Usuario SET forma_login = :forma WHERE id_user = :id')
        ->execute(['forma' => $forma, 'id' => $usuarioId]);
}

/** A autenticação identifica o usuário; somente o administrador define seu perfil. */
function sincronizarUsuario(PDO $pdo, ?array $usuario, string $login, string $nome): array
{
    if ($usuario) {
        $pdo->prepare('UPDATE Usuario SET nome = :nome WHERE id_user = :id')
            ->execute(['nome' => $nome, 'id' => $usuario['id_user']]);
        $usuario['nome'] = $nome;
        return $usuario;
    }

    $pdo->prepare(
        "INSERT INTO Usuario (nome, login, hash_senha, tipo_perfil, bloqueado)
         VALUES (:nome, :login, :hash, 'estudante', 0)"
    )->execute([
        'nome' => $nome,
        'login' => $login,
        'hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
    ]);

    return [
        'id_user' => (int) $pdo->lastInsertId(),
        'nome' => $nome,
        'login' => $login,
        'tipo_perfil' => 'estudante',
        'bloqueado' => 0,
    ];
}

/** Atualização e auditoria são gravadas juntas; sessões consultam este valor no próximo acesso. */
function alterarPerfilUsuario(PDO $pdo, int $atorId, int $usuarioId, string $novoPerfil, string $ip): void
{
    if (!in_array($novoPerfil, ['estudante', 'laboratorista', 'admin'], true)) {
        throw new InvalidArgumentException('Selecione um perfil válido.');
    }

    $pdo->beginTransaction();
    try {
        // Serializa mudanças entre administradores e revalida o autor após obter o bloqueio.
        $stmt = $pdo->prepare(
            "SELECT id_user, tipo_perfil, bloqueado FROM Usuario
             WHERE tipo_perfil = 'admin' OR id_user IN (:ator, :usuario)
             ORDER BY id_user FOR UPDATE"
        );
        $stmt->execute(['ator' => $atorId, 'usuario' => $usuarioId]);
        $usuarios = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $usuarios[(int) $row['id_user']] = $row;
        }

        $ator = $usuarios[$atorId] ?? null;
        $usuario = $usuarios[$usuarioId] ?? null;
        if (!$ator || $ator['tipo_perfil'] !== 'admin' || (int) $ator['bloqueado'] !== 0) {
            throw new RuntimeException('Somente administradores ativos podem alterar perfis.');
        }
        if (!$usuario) {
            throw new InvalidArgumentException('Usuário não encontrado.');
        }
        if ($usuario['tipo_perfil'] === $novoPerfil) {
            $pdo->commit();
            return;
        }
        if ($atorId === $usuarioId) {
            throw new InvalidArgumentException('Peça a outro administrador para alterar o seu perfil.');
        }

        $pdo->prepare('UPDATE Usuario SET tipo_perfil = :perfil WHERE id_user = :id')
            ->execute(['perfil' => $novoPerfil, 'id' => $usuarioId]);
        $pdo->prepare(
            "INSERT INTO Log_Auditoria (id_user, origem_ip, acao, tabela_afetada, detalhes_json)
             VALUES (:ator, :ip, 'alterar_perfil', 'Usuario', :detalhes)"
        )->execute([
            'ator' => $atorId,
            'ip' => $ip,
            'detalhes' => json_encode([
                'id_usuario' => $usuarioId,
                'perfil_anterior' => $usuario['tipo_perfil'],
                'perfil_novo' => $novoPerfil,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
