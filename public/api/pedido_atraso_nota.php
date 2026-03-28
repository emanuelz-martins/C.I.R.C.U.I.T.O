<?php
require_once '../includes/auth_check.php';
checkAccess(['laboratorista', 'admin', 'estudante']);

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

$parsePrazoSolicitado = static function (?string $obs, ?string $devolucao): string {
    $obs = trim((string) $obs);
    if (preg_match('/Prazo solicitado pelo estudante:\s*([^\.]+)(?:\.|$)/u', $obs, $m)) {
        return trim($m[1]);
    }

    if (!empty($devolucao)) {
        try {
            $dt = new DateTimeImmutable($devolucao);
            return 'Até ' . $dt->format('d/m/Y');
        } catch (Throwable) {
            return 'Não informado';
        }
    }

    return 'Não informado';
};

$inferPrazoDias = static function (?string $obs): int {
    $obs = trim((string) $obs);
    if (!preg_match('/Prazo solicitado pelo estudante:\s*([^\.]+)(?:\.|$)/u', $obs, $m)) {
        return 0;
    }

    $prazoTxt = mb_strtolower(trim((string) ($m[1] ?? '')));
    if (str_contains($prazoTxt, '1 a 3')) {
        return 3;
    }
    if (str_contains($prazoTxt, '3 a 5')) {
        return 5;
    }
    if (str_contains($prazoTxt, '3 a 7')) {
        return 7;
    }
    if (str_contains($prazoTxt, 'teste')) {
        return -1;
    }

    return 0;
};

$buildNotificacaoInsert = static function (
    PDO $pdo,
    array $notCols,
    int $idUser,
    int $idPedido,
    int $idNota,
    string $mensagem
): void {
    if (!in_array('id_user', $notCols, true) || !in_array('mensagem', $notCols, true)) {
        throw new RuntimeException('schema_notificacao');
    }

    $fields = ['id_user', 'mensagem'];
    $values = [':id_user', ':mensagem'];
    $params = [
        'id_user' => $idUser,
        'mensagem' => $mensagem,
    ];

    if (in_array('titulo', $notCols, true)) {
        $fields[] = 'titulo';
        $values[] = ':titulo';
        $params['titulo'] = 'Pedido atrasado';
    }

    if (in_array('tipo', $notCols, true)) {
        $fields[] = 'tipo';
        $values[] = ':tipo';
        $params['tipo'] = 'aviso';
    }

    if (in_array('humor', $notCols, true)) {
        $fields[] = 'humor';
        $values[] = ':humor';
        $params['humor'] = 'triste';
    }

    if (in_array('id_pedido', $notCols, true)) {
        $fields[] = 'id_pedido';
        $values[] = ':id_pedido';
        $params['id_pedido'] = $idPedido;
    }

    if (in_array('id_nota_atraso', $notCols, true)) {
        $fields[] = 'id_nota_atraso';
        $values[] = ':id_nota_atraso';
        $params['id_nota_atraso'] = $idNota;
    }

    if (in_array('requer_resposta', $notCols, true)) {
        $fields[] = 'requer_resposta';
        $values[] = '1';
    }

    if (in_array('resposta_pendente', $notCols, true)) {
        $fields[] = 'resposta_pendente';
        $values[] = '1';
    }

    if (in_array('lida', $notCols, true)) {
        $fields[] = 'lida';
        $values[] = '0';
    }

    if (in_array('data', $notCols, true)) {
        $fields[] = 'data';
        $values[] = 'NOW()';
    } elseif (in_array('data_criacao', $notCols, true)) {
        $fields[] = 'data_criacao';
        $values[] = 'NOW()';
    }

    $sql = sprintf(
        'INSERT INTO Notificacao (%s) VALUES (%s)',
        implode(', ', $fields),
        implode(', ', $values)
    );

    $pdo->prepare($sql)->execute($params);
};

try {
    $pdo = db();

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS Pedido_Atraso_Nota (
            id_nota INT NOT NULL AUTO_INCREMENT,
            id_pedido INT NOT NULL,
            id_user INT NOT NULL,
            id_laboratorista INT NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT "aguardando-aluno",
            obrigatoria TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id_nota),
            KEY idx_pan_pedido_status (id_pedido, status),
            KEY idx_pan_user_status (id_user, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS Pedido_Atraso_Mensagem (
            id_msg INT NOT NULL AUTO_INCREMENT,
            id_nota INT NOT NULL,
            autor_tipo VARCHAR(20) NOT NULL,
            mensagem TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_msg),
            KEY idx_pam_nota_data (id_nota, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');

    $pdo->exec('ALTER TABLE Notificacao ADD COLUMN IF NOT EXISTS id_pedido INT NULL');
    $pdo->exec('ALTER TABLE Notificacao ADD COLUMN IF NOT EXISTS id_nota_atraso INT NULL');
    $pdo->exec('ALTER TABLE Notificacao ADD COLUMN IF NOT EXISTS requer_resposta TINYINT(1) NOT NULL DEFAULT 0');
    $pdo->exec('ALTER TABLE Notificacao ADD COLUMN IF NOT EXISTS resposta_pendente TINYINT(1) NOT NULL DEFAULT 0');

    $pedidoCols = $getCols($pdo, 'Pedido');
    $statusCol = in_array('status_pedido', $pedidoCols, true) ? 'status_pedido'
        : (in_array('status', $pedidoCols, true) ? 'status' : null);
    $respCol = in_array('id_laboratorista_responsavel', $pedidoCols, true) ? 'id_laboratorista_responsavel' : null;
    $fluxoCol = in_array('fluxo_livre_laboratoristas', $pedidoCols, true) ? 'fluxo_livre_laboratoristas' : null;
    $obsCol = in_array('obs_laboratorista', $pedidoCols, true) ? 'obs_laboratorista' : null;
    $dataCriacaoCol = in_array('data_criacao', $pedidoCols, true) ? 'data_criacao'
        : (in_array('data', $pedidoCols, true) ? 'data' : null);

    $devolucaoCol = null;
    foreach (['data_devolucao_prevista', 'data_retirada_prevista', 'data_entrega'] as $candidate) {
        if (in_array($candidate, $pedidoCols, true)) {
            $devolucaoCol = $candidate;
            break;
        }
    }

    if ($statusCol === null || $idPedido <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'erro' => 'Parâmetros inválidos']);
        exit;
    }

    $selectParts = [
        'p.id_pedido',
        'p.id_user',
        'u.nome AS nome_aluno',
        'u.foto_perfil AS foto_aluno',
        'p.' . $statusCol . ' AS status_pedido',
    ];

    if ($respCol !== null) {
        $selectParts[] = 'p.' . $respCol . ' AS id_laboratorista_responsavel';
    } else {
        $selectParts[] = 'NULL AS id_laboratorista_responsavel';
    }

    if ($fluxoCol !== null) {
        $selectParts[] = 'p.' . $fluxoCol . ' AS fluxo_livre_laboratoristas';
    } else {
        $selectParts[] = '0 AS fluxo_livre_laboratoristas';
    }

    if ($obsCol !== null) {
        $selectParts[] = 'p.' . $obsCol . ' AS obs_laboratorista';
    } else {
        $selectParts[] = 'NULL AS obs_laboratorista';
    }

    if ($devolucaoCol !== null) {
        $selectParts[] = 'p.' . $devolucaoCol . ' AS data_devolucao_prevista';
    } else {
        $selectParts[] = 'NULL AS data_devolucao_prevista';
    }

    if ($dataCriacaoCol !== null) {
        $selectParts[] = 'p.' . $dataCriacaoCol . ' AS data_criacao_raw';
    } else {
        $selectParts[] = 'NULL AS data_criacao_raw';
    }

    $stmtPedido = $pdo->prepare('
        SELECT ' . implode(', ', $selectParts) . '
        FROM Pedido p
        JOIN Usuario u ON u.id_user = p.id_user
        WHERE p.id_pedido = :id
        LIMIT 1
    ');
    $stmtPedido->execute(['id' => $idPedido]);
    $pedido = $stmtPedido->fetch();

    if (!$pedido) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'erro' => 'Pedido não encontrado']);
        exit;
    }

    $statusPedido = (string) ($pedido['status_pedido'] ?? '');
    $dataPrevistaRaw = trim((string) ($pedido['data_devolucao_prevista'] ?? ''));
    $dataCriacaoRaw = trim((string) ($pedido['data_criacao_raw'] ?? ''));
    $obsPrazo = (string) ($pedido['obs_laboratorista'] ?? '');
    $atrasado = false;
    $diasAtraso = 0;
    $dataLimite = null;

    if ($statusPedido === 'em-andamento' && $dataPrevistaRaw !== '') {
        try {
            $dataLimite = new DateTimeImmutable($dataPrevistaRaw);
        } catch (Throwable) {
            $dataLimite = null;
        }
    }

    if ($statusPedido === 'em-andamento' && $dataLimite === null && $dataCriacaoRaw !== '') {
        $diasPrazo = $inferPrazoDias($obsPrazo);
        if ($diasPrazo !== 0) {
            try {
                $dataLimite = (new DateTimeImmutable($dataCriacaoRaw))->modify('+' . $diasPrazo . ' days');
            } catch (Throwable) {
                $dataLimite = null;
            }
        }
    }

    if ($statusPedido === 'em-andamento' && $dataLimite !== null) {
        try {
            $hoje = new DateTimeImmutable('today');
            if ($hoje > $dataLimite) {
                $atrasado = true;
                $diasAtraso = (int) $dataLimite->diff($hoje)->days;
            }
        } catch (Throwable) {
            $atrasado = false;
        }
    }

    $prazoSolicitado = $parsePrazoSolicitado(
        $pedido['obs_laboratorista'] ?? null,
        $pedido['data_devolucao_prevista'] ?? null
    );

    $stmtNotaAtual = $pdo->prepare('
        SELECT *
        FROM Pedido_Atraso_Nota
        WHERE id_pedido = :id_pedido
        ORDER BY id_nota DESC
        LIMIT 1
    ');
    $stmtNotaAtual->execute(['id_pedido' => $idPedido]);
    $notaAtual = $stmtNotaAtual->fetch();

    if ($acao === 'buscar') {
        if (!$isLab) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'erro' => 'Sem permissão']);
            exit;
        }

        $mensagens = [];
        if ($notaAtual) {
            $stmtMensagens = $pdo->prepare('
                SELECT id_msg, autor_tipo, mensagem, created_at
                FROM Pedido_Atraso_Mensagem
                WHERE id_nota = :id_nota
                ORDER BY created_at ASC, id_msg ASC
            ');
            $stmtMensagens->execute(['id_nota' => (int) $notaAtual['id_nota']]);
            $mensagens = $stmtMensagens->fetchAll();
        }

        echo json_encode([
            'ok' => true,
            'atrasado' => $atrasado,
            'dias_atraso' => $diasAtraso,
            'aluno' => [
                'id' => (int) $pedido['id_user'],
                'nome' => (string) ($pedido['nome_aluno'] ?? 'Aluno'),
                'foto' => (string) ($pedido['foto_aluno'] ?? ''),
            ],
            'prazo_solicitado' => $prazoSolicitado,
            'data_devolucao' => $dataLimite !== null
                ? $dataLimite->format('d/m/Y')
                : 'Não informada',
            'status_nota' => (string) ($notaAtual['status'] ?? ''),
            'mensagens' => $mensagens,
        ]);
        exit;
    }

    if ($acao === 'enviar_lab') {
        if (!$isLab) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'erro' => 'Sem permissão']);
            exit;
        }

        if ($mensagem === '' || mb_strlen($mensagem) > 2000) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'erro' => 'Mensagem inválida']);
            exit;
        }

        $idResp = (int) ($pedido['id_laboratorista_responsavel'] ?? 0);
        $fluxoLivre = (int) ($pedido['fluxo_livre_laboratoristas'] ?? 0) === 1;
        if (!$fluxoLivre && $idResp > 0 && $idResp !== $idUsuario) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'erro' => 'Pedido em atendimento por outro laboratorista']);
            exit;
        }

        if (!$atrasado) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'erro' => 'Pedido não está atrasado']);
            exit;
        }

        $notCols = $getCols($pdo, 'Notificacao');

        $pdo->beginTransaction();

        if (!$notaAtual) {
            $stmtNovaNota = $pdo->prepare('
                INSERT INTO Pedido_Atraso_Nota (id_pedido, id_user, id_laboratorista, status, obrigatoria)
                VALUES (:id_pedido, :id_user, :id_laboratorista, "aguardando-aluno", 1)
            ');
            $stmtNovaNota->execute([
                'id_pedido' => $idPedido,
                'id_user' => (int) $pedido['id_user'],
                'id_laboratorista' => $idUsuario,
            ]);
            $idNota = (int) $pdo->lastInsertId();
        } else {
            $idNota = (int) $notaAtual['id_nota'];
            $pdo->prepare('
                UPDATE Pedido_Atraso_Nota
                SET status = "aguardando-aluno", updated_at = NOW(), id_laboratorista = :id_laboratorista
                WHERE id_nota = :id_nota
            ')->execute([
                'id_laboratorista' => $idUsuario,
                'id_nota' => $idNota,
            ]);
        }

        $pdo->prepare('
            INSERT INTO Pedido_Atraso_Mensagem (id_nota, autor_tipo, mensagem)
            VALUES (:id_nota, "laboratorista", :mensagem)
        ')->execute([
            'id_nota' => $idNota,
            'mensagem' => $mensagem,
        ]);

        $buildNotificacaoInsert(
            $pdo,
            $notCols,
            (int) $pedido['id_user'],
            $idPedido,
            $idNota,
            $mensagem
        );

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
