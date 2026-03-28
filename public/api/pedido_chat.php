<?php
require_once '../includes/auth_check.php';
checkAccess(['estudante', 'admin', 'laboratorista']);

require_once '../../src/config/database.php';

header('Content-Type: application/json');

$authUser = $_SESSION['auth_user'] ?? [];
$idUsuario = (int) ($authUser['id'] ?? $authUser['id_user'] ?? 0);
$perfil = (string) ($authUser['perfil'] ?? $authUser['tipo_perfil'] ?? '');

if ($idUsuario <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Não autenticado']);
    exit;
}

$acao = trim((string) ($_POST['acao'] ?? $_GET['acao'] ?? ''));
$idPedido = (int) ($_POST['id_pedido'] ?? $_GET['id_pedido'] ?? 0);
$mensagem = trim((string) ($_POST['mensagem'] ?? ''));
$decisao = trim((string) ($_POST['decisao'] ?? ''));
$motivo = trim((string) ($_POST['motivo'] ?? ''));

$isLab = in_array($perfil, ['laboratorista', 'admin'], true);

$getCols = static function (PDO $pdo, string $table): array {
    $stmt = $pdo->prepare('
        SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
    ');
    $stmt->execute(['table' => $table]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
};

$inferPrazoDias = static function (string $obs): int {
    if (!preg_match('/Prazo solicitado pelo estudante:\s*([^\.]+)(?:\.|$)/u', $obs, $m)) {
        return 0;
    }

    $prazoTxt = mb_strtolower(trim((string) ($m[1] ?? '')));
    if (str_contains($prazoTxt, '1 a 3')) return 3;
    if (str_contains($prazoTxt, '3 a 5')) return 5;
    if (str_contains($prazoTxt, '3 a 7')) return 7;
    if (str_contains($prazoTxt, 'teste')) return -1;
    return 0;
};

$calcularAtraso = static function (array $pedido, string $statusCol, ?string $devolucaoCol, ?string $criadoCol, ?string $obsCol) use ($inferPrazoDias): array {
    $status = (string) ($pedido['status_pedido'] ?? '');
    if ($status !== 'em-andamento' && $status !== 'em-atraso') {
        return ['atrasado' => false, 'dias' => 0];
    }

    if ($status === 'em-atraso') {
        return ['atrasado' => true, 'dias' => 0];
    }

    $limite = null;
    $devolucaoRaw = trim((string) ($pedido['data_devolucao_prevista'] ?? ''));
    if ($devolucaoRaw !== '') {
        try {
            $limite = new DateTimeImmutable($devolucaoRaw);
        } catch (Throwable) {
            $limite = null;
        }
    }

    if ($limite === null) {
        $diasPrazo = $inferPrazoDias((string) ($pedido['obs_laboratorista'] ?? ''));
        $criadoRaw = trim((string) ($pedido['data_criacao_raw'] ?? ''));
        if ($diasPrazo !== 0 && $criadoRaw !== '') {
            try {
                $limite = (new DateTimeImmutable($criadoRaw))->modify('+' . $diasPrazo . ' days');
            } catch (Throwable) {
                $limite = null;
            }
        }
    }

    if ($limite === null) {
        return ['atrasado' => false, 'dias' => 0];
    }

    $hoje = new DateTimeImmutable('today');
    if ($hoje > $limite) {
        return ['atrasado' => true, 'dias' => (int) $limite->diff($hoje)->days];
    }

    return ['atrasado' => false, 'dias' => 0];
};

try {
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS Pedido_Chat (
        id_chat INT NOT NULL AUTO_INCREMENT,
        id_pedido INT NOT NULL,
        id_user INT NOT NULL,
        id_laboratorista INT NULL,
        status_renovacao VARCHAR(20) NOT NULL DEFAULT 'nenhuma',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id_chat),
        UNIQUE KEY uk_pedido_chat (id_pedido),
        KEY idx_chat_user (id_user)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS Pedido_Chat_Mensagem (
        id_msg INT NOT NULL AUTO_INCREMENT,
        id_chat INT NOT NULL,
        autor_tipo VARCHAR(20) NOT NULL,
        tipo_evento VARCHAR(40) NOT NULL DEFAULT 'mensagem',
        mensagem TEXT NOT NULL,
        metadata_json TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_msg),
        KEY idx_pcm_chat_data (id_chat, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if ($idPedido <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'erro' => 'Pedido inválido']);
        exit;
    }

    $pedidoCols = $getCols($pdo, 'Pedido');
    $statusCol = in_array('status_pedido', $pedidoCols, true) ? 'status_pedido'
        : (in_array('status', $pedidoCols, true) ? 'status' : null);
    $respCol = in_array('id_laboratorista_responsavel', $pedidoCols, true) ? 'id_laboratorista_responsavel' : null;
    $devolucaoCol = in_array('data_devolucao_prevista', $pedidoCols, true) ? 'data_devolucao_prevista' : null;
    $criadoCol = in_array('data_criacao', $pedidoCols, true) ? 'data_criacao' : (in_array('data', $pedidoCols, true) ? 'data' : null);
    $obsCol = in_array('obs_laboratorista', $pedidoCols, true) ? 'obs_laboratorista' : null;

    if ($statusCol === null) {
        throw new RuntimeException('schema_pedido');
    }

    $selectParts = [
        'p.id_pedido',
        'p.id_user',
        'u.nome AS nome_aluno',
        'u.foto_perfil AS foto_aluno',
        'p.' . $statusCol . ' AS status_pedido',
        ($respCol !== null ? 'p.' . $respCol : 'NULL') . ' AS id_laboratorista_responsavel',
        ($devolucaoCol !== null ? 'p.' . $devolucaoCol : 'NULL') . ' AS data_devolucao_prevista',
        ($criadoCol !== null ? 'p.' . $criadoCol : 'NULL') . ' AS data_criacao_raw',
        ($obsCol !== null ? 'p.' . $obsCol : 'NULL') . ' AS obs_laboratorista',
    ];

    $stmtPedido = $pdo->prepare('SELECT ' . implode(', ', $selectParts) . ' FROM Pedido p JOIN Usuario u ON u.id_user = p.id_user WHERE p.id_pedido = :id LIMIT 1');
    $stmtPedido->execute(['id' => $idPedido]);
    $pedido = $stmtPedido->fetch();

    if (!$pedido) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'erro' => 'Pedido não encontrado']);
        exit;
    }

    $idAluno = (int) ($pedido['id_user'] ?? 0);
    if (!$isLab && $idAluno !== $idUsuario) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'erro' => 'Sem permissão']);
        exit;
    }

    $stmtChat = $pdo->prepare('SELECT * FROM Pedido_Chat WHERE id_pedido = :id_pedido LIMIT 1');
    $stmtChat->execute(['id_pedido' => $idPedido]);
    $chat = $stmtChat->fetch();

    if (!$chat) {
        $stmtNew = $pdo->prepare('INSERT INTO Pedido_Chat (id_pedido, id_user, id_laboratorista) VALUES (:id_pedido, :id_user, :id_lab)');
        $stmtNew->execute([
            'id_pedido' => $idPedido,
            'id_user' => $idAluno,
            'id_lab' => ((int) ($pedido['id_laboratorista_responsavel'] ?? 0)) ?: null,
        ]);
        $idChat = (int) $pdo->lastInsertId();

        $stmtBoot = $pdo->prepare('INSERT INTO Pedido_Chat_Mensagem (id_chat, autor_tipo, tipo_evento, mensagem) VALUES (:id_chat, "sistema", "status", :mensagem)');
        $stmtBoot->execute([
            'id_chat' => $idChat,
            'mensagem' => 'Pedido criado e acompanhamento iniciado.',
        ]);

        $stmtChat->execute(['id_pedido' => $idPedido]);
        $chat = $stmtChat->fetch();
    }

    $idChat = (int) ($chat['id_chat'] ?? 0);
    $statusRenovacao = (string) ($chat['status_renovacao'] ?? 'nenhuma');

    if ($acao === 'listar') {
        $stmtMsgs = $pdo->prepare('SELECT id_msg, autor_tipo, tipo_evento, mensagem, created_at FROM Pedido_Chat_Mensagem WHERE id_chat = :id_chat ORDER BY created_at ASC, id_msg ASC');
        $stmtMsgs->execute(['id_chat' => $idChat]);
        $mensagens = $stmtMsgs->fetchAll();

        $atraso = $calcularAtraso($pedido, $statusCol, $devolucaoCol, $criadoCol, $obsCol);

        echo json_encode([
            'ok' => true,
            'pedido' => [
                'id' => (int) $pedido['id_pedido'],
                'status' => (string) ($pedido['status_pedido'] ?? ''),
                'atrasado' => (bool) ($atraso['atrasado'] ?? false),
                'dias_atraso' => (int) ($atraso['dias'] ?? 0),
            ],
            'aluno' => [
                'id' => $idAluno,
                'nome' => (string) ($pedido['nome_aluno'] ?? 'Aluno'),
                'foto' => (string) ($pedido['foto_aluno'] ?? ''),
            ],
            'chat' => [
                'id_chat' => $idChat,
                'status_renovacao' => $statusRenovacao,
                'pode_solicitar_renovacao' => !$isLab && (string) ($pedido['status_pedido'] ?? '') === 'em-andamento' && $statusRenovacao !== 'solicitada',
                'renovacao_pendente_laboratorio' => $isLab && $statusRenovacao === 'solicitada',
            ],
            'mensagens' => $mensagens,
        ]);
        exit;
    }

    if ($acao === 'enviar_mensagem') {
        if ($mensagem === '' || mb_strlen($mensagem) > 2000) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'erro' => 'Mensagem inválida']);
            exit;
        }

        $autorTipo = $isLab ? 'laboratorista' : 'aluno';
        $stmtSend = $pdo->prepare('INSERT INTO Pedido_Chat_Mensagem (id_chat, autor_tipo, tipo_evento, mensagem) VALUES (:id_chat, :autor, "mensagem", :mensagem)');
        $stmtSend->execute(['id_chat' => $idChat, 'autor' => $autorTipo, 'mensagem' => $mensagem]);

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($acao === 'solicitar_renovacao') {
        if ($isLab) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'erro' => 'Ação exclusiva do aluno']);
            exit;
        }

        if ((string) ($pedido['status_pedido'] ?? '') !== 'em-andamento') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'erro' => 'Renovação disponível apenas em andamento']);
            exit;
        }

        if ($statusRenovacao === 'solicitada') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'erro' => 'Já existe solicitação pendente']);
            exit;
        }

        $mensagemRenov = $mensagem !== '' ? $mensagem : 'Solicito renovação do prazo de devolução deste pedido.';

        $pdo->beginTransaction();
        $pdo->prepare('UPDATE Pedido_Chat SET status_renovacao = "solicitada", updated_at = NOW() WHERE id_chat = :id_chat')
            ->execute(['id_chat' => $idChat]);
        $pdo->prepare('INSERT INTO Pedido_Chat_Mensagem (id_chat, autor_tipo, tipo_evento, mensagem) VALUES (:id_chat, "aluno", "renovacao-solicitada", :mensagem)')
            ->execute(['id_chat' => $idChat, 'mensagem' => $mensagemRenov]);
        $pdo->commit();

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($acao === 'decidir_renovacao') {
        if (!$isLab) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'erro' => 'Ação exclusiva do laboratorista']);
            exit;
        }

        if ($statusRenovacao !== 'solicitada') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'erro' => 'Não há solicitação pendente']);
            exit;
        }

        if (!in_array($decisao, ['aceitar', 'recusar'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'erro' => 'Decisão inválida']);
            exit;
        }

        if ($decisao === 'recusar' && $motivo === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'erro' => 'Informe o motivo da recusa']);
            exit;
        }

        $mensagemSistema = '';
        if ($decisao === 'aceitar') {
            $mensagemSistema = 'Renovação aprovada pelo laboratorista.';

            if ($devolucaoCol !== null) {
                $pdo->exec('ALTER TABLE Pedido ADD COLUMN IF NOT EXISTS data_devolucao_prevista DATE NULL');
                $stmtExt = $pdo->prepare('UPDATE Pedido SET ' . $devolucaoCol . ' = DATE_ADD(COALESCE(' . $devolucaoCol . ', CURDATE()), INTERVAL 7 DAY) WHERE id_pedido = :id_pedido');
                $stmtExt->execute(['id_pedido' => $idPedido]);
            }
        } else {
            $mensagemSistema = 'Renovação recusada. Motivo: ' . $motivo;
        }

        $novoStatus = $decisao === 'aceitar' ? 'aprovada' : 'recusada';

        $pdo->beginTransaction();
        $pdo->prepare('UPDATE Pedido_Chat SET status_renovacao = :status, id_laboratorista = :id_lab, updated_at = NOW() WHERE id_chat = :id_chat')
            ->execute(['status' => $novoStatus, 'id_lab' => $idUsuario, 'id_chat' => $idChat]);
        $pdo->prepare('INSERT INTO Pedido_Chat_Mensagem (id_chat, autor_tipo, tipo_evento, mensagem) VALUES (:id_chat, "sistema", :tipo_evento, :mensagem)')
            ->execute([
                'id_chat' => $idChat,
                'tipo_evento' => $decisao === 'aceitar' ? 'renovacao-aprovada' : 'renovacao-recusada',
                'mensagem' => $mensagemSistema,
            ]);
        $pdo->commit();

        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'Ação desconhecida']);

} catch (Throwable) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro interno']);
}
