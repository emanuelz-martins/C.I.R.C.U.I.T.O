<?php

function aluno_pre_bloqueio_status(PDO $pdo, int $idUsuario): array
{
    if ($idUsuario <= 0) {
        return [
            'pre_bloqueado' => false,
            'motivo' => '',
            'dias_atraso' => 0,
        ];
    }

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

    $pedidoCols = $getCols($pdo, 'Pedido');
    $usuarioCols = $getCols($pdo, 'Usuario');
    if (empty($pedidoCols)) {
        return [
            'pre_bloqueado' => false,
            'motivo' => '',
            'dias_atraso' => 0,
        ];
    }

    $preBloqueioManual = false;
    $motivoManual = '';
    if (!empty($usuarioCols) && in_array('pre_bloqueado_manual', $usuarioCols, true)) {
        try {
            $selectManual = 'pre_bloqueado_manual AS pre_manual';
            if (in_array('pre_bloqueio_motivo', $usuarioCols, true)) {
                $selectManual .= ', pre_bloqueio_motivo AS motivo_manual';
            } else {
                $selectManual .= ', NULL AS motivo_manual';
            }

            $stmtManual = $pdo->prepare('SELECT ' . $selectManual . ' FROM Usuario WHERE id_user = :id_user LIMIT 1');
            $stmtManual->execute(['id_user' => $idUsuario]);
            $rowManual = $stmtManual->fetch();

            if ($rowManual) {
                $preBloqueioManual = (int) ($rowManual['pre_manual'] ?? 0) === 1;
                $motivoManual = trim((string) ($rowManual['motivo_manual'] ?? ''));
            }
        } catch (Throwable) {
            $preBloqueioManual = false;
            $motivoManual = '';
        }
    }

    $statusCol = in_array('status_pedido', $pedidoCols, true) ? 'status_pedido'
        : (in_array('status', $pedidoCols, true) ? 'status' : null);
    $devolucaoCol = in_array('data_devolucao_prevista', $pedidoCols, true) ? 'data_devolucao_prevista' : null;
    $createdCol = in_array('data_criacao', $pedidoCols, true) ? 'data_criacao'
        : (in_array('data', $pedidoCols, true) ? 'data' : null);
    $obsCol = in_array('obs_laboratorista', $pedidoCols, true) ? 'obs_laboratorista' : null;

    if ($statusCol === null) {
        return [
            'pre_bloqueado' => false,
            'motivo' => '',
            'dias_atraso' => 0,
        ];
    }

    $selectCols = [
        'id_pedido',
        $statusCol . ' AS status_pedido',
        ($devolucaoCol !== null ? $devolucaoCol : 'NULL') . ' AS data_devolucao_prevista',
        ($createdCol !== null ? $createdCol : 'NULL') . ' AS data_criacao_raw',
        ($obsCol !== null ? $obsCol : 'NULL') . ' AS obs_laboratorista',
    ];

    $stmtPedidos = $pdo->prepare('
        SELECT ' . implode(', ', $selectCols) . '
        FROM Pedido
        WHERE id_user = :id_user
          AND (' . $statusCol . ' = "em-andamento" OR ' . $statusCol . ' = "em-atraso")
    ');
    $stmtPedidos->execute(['id_user' => $idUsuario]);
    $pedidos = $stmtPedidos->fetchAll();

    $hoje = new DateTimeImmutable('today');
    $maxDiasAtraso = 0;
    $emAtraso = false;

    foreach ($pedidos as $pedido) {
        $status = (string) ($pedido['status_pedido'] ?? '');
        if ($status === 'em-atraso') {
            $emAtraso = true;
            continue;
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

        if ($limite !== null && $hoje > $limite) {
            $emAtraso = true;
            $dias = (int) $limite->diff($hoje)->days;
            if ($dias > $maxDiasAtraso) {
                $maxDiasAtraso = $dias;
            }
        }
    }

    $temTabelaNota = false;
    try {
        $stmtTbl = $pdo->prepare('
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table
            LIMIT 1
        ');
        $stmtTbl->execute(['table' => 'Pedido_Atraso_Nota']);
        $temTabelaNota = (bool) $stmtTbl->fetchColumn();
    } catch (Throwable) {
        $temTabelaNota = false;
    }

    $pendenciaNota = false;
    if ($temTabelaNota) {
        try {
            $stmtNota = $pdo->prepare('
                SELECT 1
                FROM Pedido_Atraso_Nota pan
                INNER JOIN Pedido p ON p.id_pedido = pan.id_pedido
                WHERE pan.id_user = :id_user
                  AND pan.obrigatoria = 1
                  AND pan.status = "aguardando-aluno"
                  AND p.' . $statusCol . ' IN ("em-andamento", "em-atraso")
                LIMIT 1
            ');
            $stmtNota->execute(['id_user' => $idUsuario]);
            $pendenciaNota = (bool) $stmtNota->fetchColumn();
        } catch (Throwable) {
            $pendenciaNota = false;
        }
    }

    $preBloqueado = $emAtraso || $pendenciaNota || $preBloqueioManual;
    $motivo = '';
    if ($preBloqueioManual && $motivoManual !== '') {
        $motivo = $motivoManual;
    } elseif ($preBloqueado) {
        $motivo = 'Você está pré-bloqueado, resolva sua situação com um superior, abra suas notificações e entenda mais...';
    }

    return [
        'pre_bloqueado' => $preBloqueado,
        'motivo' => $motivo,
        'dias_atraso' => $maxDiasAtraso,
    ];
}
