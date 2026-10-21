<?php
require_once '../includes/auth_check.php';
checkAccess(['laboratorista', 'admin']);

require_once '../../src/config/database.php';

$authUser = $_SESSION['auth_user'] ?? [];
$laboratoristaId = (int) ($authUser['id'] ?? $authUser['id_user'] ?? 0);
$laboratoristaNome = trim((string) ($authUser['nome'] ?? 'Laboratorista'));

/* ══════════════════════════════════════════
   HANDLER DE AÇÕES (POST — PRG pattern)
══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action        = $_POST['action']        ?? '';
    $id_pedido     = (int) ($_POST['id_pedido'] ?? 0);
    $justificativa = trim($_POST['justificativa'] ?? '');
    $redirectUrl   = './acessar.php';

    if ($id_pedido > 0) {
        try {
            $pdo = db();

            /* Garante colunas de posse do pedido (idempotente) */
            $pdo->exec('ALTER TABLE Pedido ADD COLUMN IF NOT EXISTS id_laboratorista_responsavel INT NULL');
            $pdo->exec('ALTER TABLE Pedido ADD COLUMN IF NOT EXISTS nome_laboratorista_responsavel VARCHAR(150) NULL');
            $pdo->exec('ALTER TABLE Pedido ADD COLUMN IF NOT EXISTS fluxo_livre_laboratoristas TINYINT(1) NOT NULL DEFAULT 0');
            $pdo->exec("CREATE TABLE IF NOT EXISTS Renovacao (
                id_renovacao INT NOT NULL AUTO_INCREMENT,
                id_pedido INT NOT NULL,
                nova_data DATE NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'pendente',
                motivo TEXT NULL,
                PRIMARY KEY (id_renovacao)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec('ALTER TABLE Renovacao ADD COLUMN IF NOT EXISTS prazo_opcao VARCHAR(20) NULL');
            $pdo->exec('ALTER TABLE Renovacao ADD COLUMN IF NOT EXISTS justificativa TEXT NULL');
            $pdo->exec('ALTER TABLE Renovacao ADD COLUMN IF NOT EXISTS data_solicitada DATE NULL');

            $getCols = static function (PDO $pdo, string $table): array {
                $stmt = $pdo->prepare('
                    SELECT COLUMN_NAME
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
                ');
                $stmt->execute(['table' => $table]);
                return $stmt->fetchAll(PDO::FETCH_COLUMN);
            };

            $pedidoCols = $getCols($pdo, 'Pedido');
            $statusCol = in_array('status_pedido', $pedidoCols, true) ? 'status_pedido'
                : (in_array('status', $pedidoCols, true) ? 'status' : null);
            $updatedCol = in_array('data_atualizacao', $pedidoCols, true) ? 'data_atualizacao' : null;
            $motivoCol = in_array('motivo_negacao', $pedidoCols, true) ? 'motivo_negacao' : null;
            $responsavelIdCol = in_array('id_laboratorista_responsavel', $pedidoCols, true) ? 'id_laboratorista_responsavel' : null;
            $responsavelNomeCol = in_array('nome_laboratorista_responsavel', $pedidoCols, true) ? 'nome_laboratorista_responsavel' : null;
            $fluxoLivreCol = in_array('fluxo_livre_laboratoristas', $pedidoCols, true) ? 'fluxo_livre_laboratoristas' : null;

            if ($statusCol === null) {
                throw new RuntimeException('Coluna de status do pedido não encontrada.');
            }

            $notCols = $getCols($pdo, 'Notificacao');

            $itemTable = null;
            $itemCols = [];
            foreach (['Item_Pedido', 'BemPedido'] as $candidate) {
                $cols = $getCols($pdo, $candidate);
                if (!empty($cols)) {
                    $itemTable = $candidate;
                    $itemCols = $cols;
                    break;
                }
            }

            $compCols = $getCols($pdo, 'Componente');
            $compQtdCol = in_array('qtd_disponivel', $compCols, true) ? 'qtd_disponivel' : null;
            $compStatusCol = in_array('status_atual', $compCols, true) ? 'status_atual' : null;

            /* Busca id_user do pedido para criar notificação */
            $getUserId = static function (PDO $pdo, int $id): ?int {
                $s = $pdo->prepare('SELECT id_user FROM Pedido WHERE id_pedido = :id');
                $s->execute(['id' => $id]);
                $row = $s->fetch();
                return $row ? (int) $row['id_user'] : null;
            };

            $getPedidoStatus = static function (PDO $pdo, int $id, string $statusCol): ?string {
                $s = $pdo->prepare("SELECT {$statusCol} AS status_atual FROM Pedido WHERE id_pedido = :id LIMIT 1");
                $s->execute(['id' => $id]);
                $row = $s->fetch();
                return $row ? (string) ($row['status_atual'] ?? '') : null;
            };

            $setStatus = static function (
                PDO $pdo,
                int $id,
                string $status,
                string $statusCol,
                ?string $updatedCol,
                ?string $motivoCol = null,
                ?string $motivo = null
            ): void {
                $sets = ["{$statusCol} = :status"];
                $params = ['status' => $status, 'id' => $id];

                if ($updatedCol !== null) {
                    $sets[] = "{$updatedCol} = NOW()";
                }
                if ($motivoCol !== null && $motivo !== null) {
                    $sets[] = "{$motivoCol} = :motivo";
                    $params['motivo'] = $motivo;
                }

                $sql = 'UPDATE Pedido SET ' . implode(', ', $sets) . ' WHERE id_pedido = :id';
                $pdo->prepare($sql)->execute($params);
            };

            $getResponsavelAtual = static function (PDO $pdo, int $id, ?string $responsavelIdCol): ?int {
                if ($responsavelIdCol === null) {
                    return null;
                }

                $s = $pdo->prepare("SELECT {$responsavelIdCol} AS id_resp FROM Pedido WHERE id_pedido = :id LIMIT 1");
                $s->execute(['id' => $id]);
                $row = $s->fetch();
                if (!$row) {
                    throw new RuntimeException('pedido_nao_encontrado');
                }

                $idResp = isset($row['id_resp']) ? (int) $row['id_resp'] : 0;
                return $idResp > 0 ? $idResp : null;
            };

            $getFluxoLivreAtual = static function (PDO $pdo, int $id, ?string $fluxoLivreCol): bool {
                if ($fluxoLivreCol === null) {
                    return false;
                }

                $s = $pdo->prepare("SELECT {$fluxoLivreCol} AS fluxo_livre FROM Pedido WHERE id_pedido = :id LIMIT 1");
                $s->execute(['id' => $id]);
                $row = $s->fetch();
                if (!$row) {
                    throw new RuntimeException('pedido_nao_encontrado');
                }

                return (int) ($row['fluxo_livre'] ?? 0) === 1;
            };

            /* Cria notificação para o estudante */
            $notify = static function (
                PDO $pdo,
                int $id_pedido,
                int $id_user,
                string $mensagem,
                string $tipo,
                array $notCols,
                string $humor = ''
            ): void {
                if (!in_array('id_user', $notCols, true) || !in_array('mensagem', $notCols, true)) {
                    return;
                }

                $fields = ['id_user', 'mensagem'];
                $values = [':id_user', ':mensagem'];
                $params = [
                    'id_user' => $id_user,
                    'mensagem' => $mensagem,
                ];

                if (in_array('id_pedido', $notCols, true)) {
                    $fields[] = 'id_pedido';
                    $values[] = ':id_pedido';
                    $params['id_pedido'] = $id_pedido;
                }
                if (in_array('titulo', $notCols, true)) {
                    $fields[] = 'titulo';
                    $values[] = ':titulo';
                    $params['titulo'] = 'Atualização do pedido';
                }
                if (in_array('tipo_notif', $notCols, true)) {
                    $fields[] = 'tipo_notif';
                    $values[] = ':tipo_notif';
                    $params['tipo_notif'] = $tipo;
                } elseif (in_array('tipo', $notCols, true)) {
                    $fields[] = 'tipo';
                    $values[] = ':tipo';
                    $params['tipo'] = 'automatica';
                }
                if ($humor !== '' && in_array('humor', $notCols, true)) {
                    $fields[] = 'humor';
                    $values[] = ':humor';
                    $params['humor'] = $humor;
                }
                if (in_array('lida', $notCols, true)) {
                    $fields[] = 'lida';
                    $values[] = '0';
                }
                if (in_array('data_criacao', $notCols, true)) {
                    $fields[] = 'data_criacao';
                    $values[] = 'NOW()';
                } elseif (in_array('data', $notCols, true)) {
                    $fields[] = 'data';
                    $values[] = 'NOW()';
                }

                $sql = sprintf(
                    'INSERT INTO Notificacao (%s) VALUES (%s)',
                    implode(', ', $fields),
                    implode(', ', $values)
                );
                $pdo->prepare($sql)->execute($params);
            };

            $responsavelAtualId = $getResponsavelAtual($pdo, $id_pedido, $responsavelIdCol);
            $fluxoLivreAtual = $getFluxoLivreAtual($pdo, $id_pedido, $fluxoLivreCol);
            if (!in_array($action, ['assumir', 'liberar'], true)
                && !$fluxoLivreAtual
                && ($responsavelAtualId === null || $responsavelAtualId !== $laboratoristaId)
            ) {
                throw new RuntimeException('pedido_bloqueado');
            }

            switch ($action) {

                case 'assumir':
                    if ($responsavelIdCol === null) {
                        throw new RuntimeException('schema_responsavel');
                    }
                    if ($laboratoristaId <= 0) {
                        throw new RuntimeException('sessao_invalida');
                    }

                    $pdo->beginTransaction();

                    $selectLockAssumir = "SELECT {$responsavelIdCol} AS id_resp";
                    if ($fluxoLivreCol !== null) {
                        $selectLockAssumir .= ", {$fluxoLivreCol} AS fluxo_livre";
                    }
                    $selectLockAssumir .= ' FROM Pedido WHERE id_pedido = :id FOR UPDATE';

                    $stmtLockPedido = $pdo->prepare($selectLockAssumir);
                    $stmtLockPedido->execute(['id' => $id_pedido]);
                    $pedidoLock = $stmtLockPedido->fetch();

                    if (!$pedidoLock) {
                        throw new RuntimeException('pedido_nao_encontrado');
                    }

                    $idRespAtual = ((int) ($pedidoLock['id_resp'] ?? 0)) > 0 ? (int) $pedidoLock['id_resp'] : null;
                    $fluxoLivreLock = (int) ($pedidoLock['fluxo_livre'] ?? 0) === 1;
                    if ($fluxoLivreLock) {
                        if ($pdo->inTransaction()) {
                            $pdo->commit();
                        }
                        break;
                    }
                    if ($idRespAtual !== null && $idRespAtual !== $laboratoristaId) {
                        throw new RuntimeException('pedido_bloqueado');
                    }

                    $sets = ["{$responsavelIdCol} = :id_lab"];
                    $params = ['id_lab' => $laboratoristaId, 'id' => $id_pedido];

                    if ($responsavelNomeCol !== null) {
                        $sets[] = "{$responsavelNomeCol} = :nome_lab";
                        $params['nome_lab'] = $laboratoristaNome;
                    }
                    if ($updatedCol !== null) {
                        $sets[] = "{$updatedCol} = NOW()";
                    }

                    $sqlAssumir = 'UPDATE Pedido SET ' . implode(', ', $sets) . ' WHERE id_pedido = :id';
                    $pdo->prepare($sqlAssumir)->execute($params);

                    $uid = $getUserId($pdo, $id_pedido);
                    if ($uid && $idRespAtual === null) {
                        $notify(
                            $pdo,
                            $id_pedido,
                            $uid,
                            $laboratoristaNome . ' aceitou seu pedido.',
                            'pedido-aceito',
                            $notCols,
                            'feliz'
                        );
                    }

                    if ($pdo->inTransaction()) {
                        $pdo->commit();
                    }
                    break;

                case 'liberar':
                    if ($responsavelIdCol === null) {
                        throw new RuntimeException('schema_responsavel');
                    }

                    $pdo->beginTransaction();

                    $selectLockLiberar = "SELECT {$responsavelIdCol} AS id_resp";
                    if ($fluxoLivreCol !== null) {
                        $selectLockLiberar .= ", {$fluxoLivreCol} AS fluxo_livre";
                    }
                    $selectLockLiberar .= ' FROM Pedido WHERE id_pedido = :id FOR UPDATE';

                    $stmtLockPedido = $pdo->prepare($selectLockLiberar);
                    $stmtLockPedido->execute(['id' => $id_pedido]);
                    $pedidoLock = $stmtLockPedido->fetch();

                    if (!$pedidoLock) {
                        throw new RuntimeException('pedido_nao_encontrado');
                    }

                    $idRespAtual = ((int) ($pedidoLock['id_resp'] ?? 0)) > 0 ? (int) $pedidoLock['id_resp'] : null;
                    if ($idRespAtual === null || $idRespAtual !== $laboratoristaId) {
                        throw new RuntimeException('pedido_bloqueado');
                    }

                    $sets = ["{$responsavelIdCol} = 0"];
                    $params = ['id' => $id_pedido];

                    if ($responsavelNomeCol !== null) {
                        $sets[] = "{$responsavelNomeCol} = NULL";
                    }
                    if ($fluxoLivreCol !== null) {
                        $sets[] = "{$fluxoLivreCol} = 1";
                    }
                    if ($updatedCol !== null) {
                        $sets[] = "{$updatedCol} = NOW()";
                    }

                    $sqlLiberar = 'UPDATE Pedido SET ' . implode(', ', $sets) . ' WHERE id_pedido = :id';
                    $pdo->prepare($sqlLiberar)->execute($params);

                    $selectConfere = "SELECT {$responsavelIdCol} AS id_resp";
                    if ($fluxoLivreCol !== null) {
                        $selectConfere .= ", {$fluxoLivreCol} AS fluxo_livre";
                    }
                    $selectConfere .= ' FROM Pedido WHERE id_pedido = :id LIMIT 1';

                    $stmtConfereLiberacao = $pdo->prepare($selectConfere);
                    $stmtConfereLiberacao->execute(['id' => $id_pedido]);
                    $rowConf = $stmtConfereLiberacao->fetch();
                    $idRespDepois = ((int) ($rowConf['id_resp'] ?? 0));
                    $fluxoLivreDepois = (int) ($rowConf['fluxo_livre'] ?? 0) === 1;
                    if ($idRespDepois > 0 || ($fluxoLivreCol !== null && !$fluxoLivreDepois)) {
                        throw new RuntimeException('falha_liberar');
                    }

                    if ($pdo->inTransaction()) {
                        $pdo->commit();
                    }
                    break;

                case 'aprovar':
                    /* Aprovação → muda status para em-separacao e notifica o estudante */
                    $statusAtualPedido = $getPedidoStatus($pdo, $id_pedido, $statusCol);

                    if ($statusAtualPedido === 'renovacao-solicitada') {
                        $stmtRen = $pdo->prepare('SELECT id_renovacao, prazo_opcao, data_solicitada FROM Renovacao WHERE id_pedido = :id_pedido AND status = "pendente" ORDER BY id_renovacao DESC LIMIT 1');
                        $stmtRen->execute(['id_pedido' => $id_pedido]);
                        $ren = $stmtRen->fetch();

                        $novaData = null;
                        if ($ren) {
                            $hoje = (new DateTimeImmutable('today'))->format('Y-m-d');
                            $prazoOpcao = (string) ($ren['prazo_opcao'] ?? '');
                            $dataSolicitada = trim((string) ($ren['data_solicitada'] ?? ''));
                            if ($prazoOpcao === '7+' && $dataSolicitada !== '' && $dataSolicitada >= $hoje) {
                                $novaData = $dataSolicitada;
                            } else {
                                $diasMapa = ['1-3' => 3, '3-5' => 5, '3-7' => 7, '7+' => 7];
                                $diasExtra = $diasMapa[$prazoOpcao] ?? 3;
                                $novaData = (new DateTimeImmutable('today +' . $diasExtra . ' days'))->format('Y-m-d');
                            }
                        }

                        $setsRen = ["{$statusCol} = 'em-andamento'"];
                        $paramsRen = ['id' => $id_pedido];
                        if ($updatedCol !== null) {
                            $setsRen[] = "{$updatedCol} = NOW()";
                        }
                        if ($novaData !== null && in_array('data_devolucao_prevista', $pedidoCols, true)) {
                            $setsRen[] = 'data_devolucao_prevista = :nova_data';
                            $paramsRen['nova_data'] = $novaData;
                        }

                        $pdo->prepare('UPDATE Pedido SET ' . implode(', ', $setsRen) . ' WHERE id_pedido = :id')->execute($paramsRen);

                        if ($ren) {
                            $pdo->prepare('UPDATE Renovacao SET status = "aprovada" WHERE id_renovacao = :id_renovacao')
                                ->execute(['id_renovacao' => (int) ($ren['id_renovacao'] ?? 0)]);
                        }

                        $uid = $getUserId($pdo, $id_pedido);
                        if ($uid) {
                            $msgAprovada = $novaData !== null
                                ? ('Sua proposta de extensão foi aprovada. Nova data sugerida: ' . date('d/m/Y', strtotime($novaData)) . '.')
                                : 'Sua proposta de extensão foi aprovada.';
                            $notify($pdo, $id_pedido, $uid, $msgAprovada, 'aprovado', $notCols, 'feliz');
                        }
                    } else {
                        if ($itemTable === null || $compQtdCol === null) {
                            throw new RuntimeException('schema_estoque');
                        }

                        $itemPedidoCol = in_array('id_pedido', $itemCols, true) ? 'id_pedido' : null;
                        $itemCompCol = in_array('id_comp', $itemCols, true) ? 'id_comp'
                            : (in_array('id_componente', $itemCols, true) ? 'id_componente' : null);
                        $itemQtdCol = in_array('qtd_solicitada', $itemCols, true) ? 'qtd_solicitada'
                            : (in_array('quantidade', $itemCols, true) ? 'quantidade' : null);

                        if ($itemPedidoCol === null || $itemCompCol === null || $itemQtdCol === null) {
                            throw new RuntimeException('schema_estoque');
                        }

                        $stmtItens = $pdo->prepare(
                            "SELECT {$itemCompCol} AS id_comp, SUM({$itemQtdCol}) AS qtd FROM {$itemTable} WHERE {$itemPedidoCol} = :id GROUP BY {$itemCompCol}"
                        );
                        $stmtItens->execute(['id' => $id_pedido]);
                        $itensPedido = $stmtItens->fetchAll();

                        if (empty($itensPedido)) {
                            throw new RuntimeException('estoque_insuficiente');
                        }

                        $pdo->beginTransaction();

                        $stmtLockComp = $pdo->prepare("SELECT {$compQtdCol} AS qtd_disp FROM Componente WHERE id_comp = :id FOR UPDATE");
                        $stmtUpdateComp = $pdo->prepare(
                            $compStatusCol !== null
                                ? "UPDATE Componente SET {$compQtdCol} = {$compQtdCol} - :qtd, {$compStatusCol} = CASE WHEN ({$compQtdCol} - :qtd_status) <= 0 THEN 'indisponivel' ELSE {$compStatusCol} END WHERE id_comp = :id"
                                : "UPDATE Componente SET {$compQtdCol} = {$compQtdCol} - :qtd WHERE id_comp = :id"
                        );

                        foreach ($itensPedido as $itemPed) {
                            $idComp = (int) ($itemPed['id_comp'] ?? 0);
                            $qtdSolicitada = (int) ($itemPed['qtd'] ?? 0);

                            if ($idComp <= 0 || $qtdSolicitada <= 0) {
                                throw new RuntimeException('estoque_insuficiente');
                            }

                            $stmtLockComp->execute(['id' => $idComp]);
                            $compRow = $stmtLockComp->fetch();
                            $qtdDisponivel = (int) ($compRow['qtd_disp'] ?? 0);

                            if (!$compRow || $qtdDisponivel < $qtdSolicitada) {
                                throw new RuntimeException('estoque_insuficiente');
                            }

                            $paramsUpdate = ['id' => $idComp, 'qtd' => $qtdSolicitada];
                            if ($compStatusCol !== null) {
                                $paramsUpdate['qtd_status'] = $qtdSolicitada;
                            }
                            $stmtUpdateComp->execute($paramsUpdate);
                        }

                        $setStatus($pdo, $id_pedido, 'em-separacao', $statusCol, $updatedCol);

                        $uid = $getUserId($pdo, $id_pedido);
                        if ($uid) {
                            $notify($pdo, $id_pedido, $uid, 'Pedido aprovado!', 'aprovado', $notCols, 'feliz');
                        }
                    }

                    if ($pdo->inTransaction()) {
                        $pdo->commit();
                    }
                    break;

                case 'negar':
                    /* Negação → justificativa obrigatória */
                    $statusAtualPedido = $getPedidoStatus($pdo, $id_pedido, $statusCol);
                    if ($statusAtualPedido === 'renovacao-solicitada') {
                        if ($justificativa === '') {
                            $justificativa = 'Proposta de extensão não aprovada.';
                        }
                        $setStatus($pdo, $id_pedido, 'em-andamento', $statusCol, $updatedCol);

                        $stmtRen = $pdo->prepare('SELECT id_renovacao FROM Renovacao WHERE id_pedido = :id_pedido AND status = "pendente" ORDER BY id_renovacao DESC LIMIT 1');
                        $stmtRen->execute(['id_pedido' => $id_pedido]);
                        $idRen = (int) ($stmtRen->fetchColumn() ?: 0);
                        if ($idRen > 0) {
                            $pdo->prepare('UPDATE Renovacao SET status = "negada", motivo = :motivo WHERE id_renovacao = :id_renovacao')
                                ->execute(['motivo' => $justificativa, 'id_renovacao' => $idRen]);
                        }

                        $uid = $getUserId($pdo, $id_pedido);
                        if ($uid) {
                            $notify(
                                $pdo,
                                $id_pedido,
                                $uid,
                                'Sua proposta de extensão foi negada. Justificativa: ' . $justificativa,
                                'negado',
                                $notCols,
                                'triste'
                            );
                        }
                    } else {
                        if ($justificativa === '') {
                            header('Location: ./acessar.php?erro=justificativa');
                            exit;
                        }

                        $setStatus($pdo, $id_pedido, 'negado', $statusCol, $updatedCol, $motivoCol, $justificativa);

                        $uid = $getUserId($pdo, $id_pedido);
                        if ($uid) {
                            $notify($pdo, $id_pedido, $uid,
                                'Pedido negado. Justificativa: ' . $justificativa, 'negado', $notCols, 'triste');
                        }
                    }
                    break;

                case 'pronto-para-retirada':
                    /* Pacote preparado fisicamente → notifica estudante para buscar */
                    $setStatus($pdo, $id_pedido, 'pronto-para-retirada', $statusCol, $updatedCol);

                    $uid = $getUserId($pdo, $id_pedido);
                    if ($uid) {
                        $notify($pdo, $id_pedido, $uid,
                            'Seu pedido está pronto para retirada! Dirija-se ao laboratório para buscar.',
                            'pronto-para-retirada', $notCols, 'feliz');
                    }
                    break;

                case 'em-andamento':
                    /* Estudante retirou o pacote — prazo de 1 semana para devolução */
                    $setStatus($pdo, $id_pedido, 'em-andamento', $statusCol, $updatedCol);
                    break;

                case 'finalizar':
                    $setStatus($pdo, $id_pedido, 'finalizado', $statusCol, $updatedCol);
                    break;
            }

        } catch (Throwable $e) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (in_array($e->getMessage(), ['estoque_insuficiente', 'schema_estoque'], true)) {
                $redirectUrl = './acessar.php?erro=estoque';
            } elseif (in_array($e->getMessage(), ['pedido_bloqueado', 'schema_responsavel', 'sessao_invalida', 'falha_liberar'], true)) {
                $redirectUrl = './acessar.php?erro=bloqueado';
            }
            /* BD indisponível — falha silenciosa */
        }
    }

    header('Location: ' . $redirectUrl);
    exit;
}

/* ══════════════════════════════════════════
   BUSCA PEDIDOS DO BD
══════════════════════════════════════════ */
$page_title = 'Pedidos';
require_once '../includes/header.php';

$pedidos = [];
$db_ok   = false;
$busca   = trim($_GET['q'] ?? '');
$filtro  = trim($_GET['status'] ?? '');
$erro    = $_GET['erro'] ?? '';

try {
    $pdo = db();

    /* Garante colunas de posse do pedido (idempotente) */
    $pdo->exec('ALTER TABLE Pedido ADD COLUMN IF NOT EXISTS id_laboratorista_responsavel INT NULL');
    $pdo->exec('ALTER TABLE Pedido ADD COLUMN IF NOT EXISTS nome_laboratorista_responsavel VARCHAR(150) NULL');
    $pdo->exec('ALTER TABLE Pedido ADD COLUMN IF NOT EXISTS fluxo_livre_laboratoristas TINYINT(1) NOT NULL DEFAULT 0');
    $pdo->exec("CREATE TABLE IF NOT EXISTS Renovacao (
        id_renovacao INT NOT NULL AUTO_INCREMENT,
        id_pedido INT NOT NULL,
        nova_data DATE NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pendente',
        motivo TEXT NULL,
        PRIMARY KEY (id_renovacao)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec('ALTER TABLE Renovacao ADD COLUMN IF NOT EXISTS prazo_opcao VARCHAR(20) NULL');
    $pdo->exec('ALTER TABLE Renovacao ADD COLUMN IF NOT EXISTS justificativa TEXT NULL');
    $pdo->exec('ALTER TABLE Renovacao ADD COLUMN IF NOT EXISTS data_solicitada DATE NULL');

    $getCols = static function (PDO $pdo, string $table): array {
        $stmt = $pdo->prepare('
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
        ');
        $stmt->execute(['table' => $table]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    };

    $pedidoCols = $getCols($pdo, 'Pedido');
    $statusCol = in_array('status_pedido', $pedidoCols, true) ? 'status_pedido'
        : (in_array('status', $pedidoCols, true) ? 'status' : null);
    $numeroCol = in_array('numero_pedido', $pedidoCols, true) ? 'numero_pedido' : null;
    $dataCriacaoCol = in_array('data_criacao', $pedidoCols, true) ? 'data_criacao' : null;
    $dataAtualizacaoCol = in_array('data_atualizacao', $pedidoCols, true) ? 'data_atualizacao' : null;
    $devolucaoPrevistaCol = in_array('data_devolucao_prevista', $pedidoCols, true) ? 'data_devolucao_prevista' : null;
    $obsLaboratoristaCol = in_array('obs_laboratorista', $pedidoCols, true) ? 'obs_laboratorista' : null;
    $responsavelIdCol = in_array('id_laboratorista_responsavel', $pedidoCols, true) ? 'id_laboratorista_responsavel' : null;
    $responsavelNomeCol = in_array('nome_laboratorista_responsavel', $pedidoCols, true) ? 'nome_laboratorista_responsavel' : null;
    $fluxoLivreCol = in_array('fluxo_livre_laboratoristas', $pedidoCols, true) ? 'fluxo_livre_laboratoristas' : null;

    $usuarioCols   = $getCols($pdo, 'Usuario');
    $fotoPerfilSql = in_array('foto_perfil', $usuarioCols, true) ? 'u.foto_perfil' : 'NULL';
    $matriculaSql = in_array('matricula', $usuarioCols, true) ? 'u.matricula' : 'NULL';
    $turmaSql = in_array('turma', $usuarioCols, true) ? 'u.turma' : 'NULL';
    $selectDatasPreview = [];
    foreach (['data_retirada_prevista', 'data_retirada_real', 'data_devolucao_real'] as $colunaData) {
        $selectDatasPreview[] = (in_array($colunaData, $pedidoCols, true) ? 'p.' . $colunaData : 'NULL') . ' AS ' . $colunaData;
    }
    $selectDatasPreview = implode(', ', $selectDatasPreview);

    if ($statusCol === null) {
        throw new RuntimeException('Coluna de status do pedido não encontrada.');
    }

    $where  = [];
    $params = [];

    if ($busca !== '') {
        $where[]         = 'u.nome LIKE :busca';
        $params['busca'] = '%' . $busca . '%';
    }
    if ($filtro !== '') {
        $where[]          = 'p.' . $statusCol . ' = :status';
        $params['status'] = $filtro;
    }

    /* Exclui pedidos arquivados pelo laboratorista */
    if (in_array('arquivado_lab', $pedidoCols, true)) {
        $where[] = '(p.arquivado_lab IS NULL OR p.arquivado_lab = 0)';
    }

    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $selectNumero = ($numeroCol !== null ? 'p.' . $numeroCol : 'p.id_pedido') . ' AS numero_pedido';
    $selectStatus = 'p.' . $statusCol . ' AS status_pedido';
    if ($dataAtualizacaoCol !== null && $dataCriacaoCol !== null) {
        $selectData = "DATE_FORMAT(COALESCE(p.{$dataAtualizacaoCol}, p.{$dataCriacaoCol}), '%d/%m/%Y') AS data_fmt";
    } elseif ($dataAtualizacaoCol !== null) {
        $selectData = "DATE_FORMAT(p.{$dataAtualizacaoCol}, '%d/%m/%Y') AS data_fmt";
    } elseif ($dataCriacaoCol !== null) {
        $selectData = "DATE_FORMAT(p.{$dataCriacaoCol}, '%d/%m/%Y') AS data_fmt";
    } else {
        $selectData = 'NULL AS data_fmt';
    }
    $selectDataCriacaoRaw = $dataCriacaoCol !== null ? 'p.' . $dataCriacaoCol . ' AS data_criacao_raw' : 'NULL AS data_criacao_raw';

    $orderBy = $dataAtualizacaoCol !== null ? 'p.' . $dataAtualizacaoCol . ' DESC, p.id_pedido DESC' : 'p.id_pedido DESC';

    $joinResponsavel = $responsavelIdCol !== null ? 'LEFT JOIN Usuario ulab ON ulab.id_user = p.' . $responsavelIdCol : '';
    $selectResponsavelId = $responsavelIdCol !== null ? 'p.' . $responsavelIdCol . ' AS id_laboratorista_responsavel' : 'NULL AS id_laboratorista_responsavel';
    if ($responsavelIdCol !== null && $responsavelNomeCol !== null) {
        $selectResponsavelNome = 'COALESCE(p.' . $responsavelNomeCol . ', ulab.nome) AS nome_laboratorista_responsavel';
    } elseif ($responsavelIdCol !== null) {
        $selectResponsavelNome = 'ulab.nome AS nome_laboratorista_responsavel';
    } else {
        $selectResponsavelNome = 'NULL AS nome_laboratorista_responsavel';
    }
    $selectObsAluno = $obsLaboratoristaCol !== null ? 'p.' . $obsLaboratoristaCol . ' AS mensagem_aluno' : 'NULL AS mensagem_aluno';
    $selectFluxoLivre = $fluxoLivreCol !== null ? 'p.' . $fluxoLivreCol . ' AS fluxo_livre_laboratoristas' : '0 AS fluxo_livre_laboratoristas';
    $selectDataDevolucaoPrevista = $devolucaoPrevistaCol !== null ? 'p.' . $devolucaoPrevistaCol . ' AS data_devolucao_prevista' : 'NULL AS data_devolucao_prevista';

    $stmt = $pdo->prepare("
        SELECT
            p.id_pedido,
            p.id_user,
            {$selectNumero},
            {$selectStatus},
            {$selectData},
            {$selectDataCriacaoRaw},
            u.nome AS nome_estudante,
            {$fotoPerfilSql} AS foto_perfil_estudante,
            {$matriculaSql} AS matricula_estudante,
            {$turmaSql} AS turma_estudante,
            {$selectDatasPreview},
            {$selectResponsavelId},
            {$selectResponsavelNome},
            {$selectObsAluno},
            {$selectFluxoLivre},
            {$selectDataDevolucaoPrevista}
        FROM Pedido p
        JOIN Usuario u ON u.id_user = p.id_user
        {$joinResponsavel}
        {$w}
        ORDER BY {$orderBy}
    ");
    $stmt->execute($params);
    $pedidos = $stmt->fetchAll();
    $db_ok   = true;

    /* ── Itens por pedido (para o pop-up de preview) ─ */
    $itens_por_pedido = [];
    $renovacao_por_pedido = [];
    if (!empty($pedidos)) {
        $ids          = array_column($pedidos, 'id_pedido');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $qtdAprovadaSql = in_array('qtd_aprovada', $getCols($pdo, 'Item_Pedido'), true) ? 'ip.qtd_aprovada' : 'NULL';
            $stmtItens = $pdo->prepare("
                SELECT ip.id_pedido, ip.qtd_solicitada, {$qtdAprovadaSql} AS qtd_aprovada, c.id_comp, c.nome AS nome_comp, c.imagem_url
                FROM Item_Pedido ip
                JOIN Componente c ON c.id_comp = ip.id_comp
                WHERE ip.id_pedido IN ({$placeholders})
            ");
            $stmtItens->execute($ids);
            foreach ($stmtItens->fetchAll() as $row) {
                $itens_por_pedido[(int) $row['id_pedido']][] = $row;
            }
        } catch (Throwable) { /* ignora se tabelas não existem */ }

        try {
            $stmtRen = $pdo->prepare("SELECT r.id_pedido, r.prazo_opcao, r.data_solicitada, r.justificativa
                FROM Renovacao r
                INNER JOIN (
                    SELECT id_pedido, MAX(id_renovacao) AS max_id
                    FROM Renovacao
                    WHERE status = 'pendente' AND id_pedido IN ({$placeholders})
                    GROUP BY id_pedido
                ) m ON m.max_id = r.id_renovacao");
            $stmtRen->execute($ids);
            foreach ($stmtRen->fetchAll() as $rr) {
                $renovacao_por_pedido[(int) ($rr['id_pedido'] ?? 0)] = $rr;
            }
        } catch (Throwable) { /* ignora se tabela não existe */ }
    }

} catch (Throwable) {
    /* BD indisponível */
}

/* ── Mapa de status → label + classe CSS ─ */
$status_map = [
    'pendente'             => ['label' => 'Pendente',             'class' => 'pendente'],
    'em-separacao'         => ['label' => 'Em separação',         'class' => 'em-separacao'],
    'pronto-para-retirada' => ['label' => 'Pronto para retirada', 'class' => 'pronto-para-retirada'],
    'em-andamento'         => ['label' => 'Em andamento',         'class' => 'em-andamento'],
    'negado'               => ['label' => 'Negado',               'class' => 'negado'],
    'cancelado'            => ['label' => 'Cancelado',            'class' => 'cancelado'],
    'finalizado'           => ['label' => 'Finalizado',           'class' => 'finalizado'],
    'renovacao-solicitada' => ['label' => 'Renovação solicitada', 'class' => 'renovacao-solicitada'],
];
?>

<style>
    /* ══════════════════════════════════════════
       CONTEÚDO PRINCIPAL
    ══════════════════════════════════════════ */
    .main {
        padding: 48px 48px 80px;
        max-width: 1300px;
        margin: 0 auto;
    }

    /* ── Cabeçalho da página ──────────────── */
    .page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 36px;
    }

    .page-title {
        font-size: 3rem;
        font-weight: 800;
        color: var(--texto-principal);
        line-height: 1.1;
    }

    .btn-back {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background-color: var(--bg-controle);
        border: 1px solid var(--borda-controle);
        color: var(--texto-principal);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        transition: background-color 0.15s;
        flex-shrink: 0;
    }

    .btn-back:hover { background-color: var(--bg-controle-hover); }
    .btn-back svg { width: 20px; height: 20px; }

    /* ── Barra de busca + filtro ──────────── */
    .toolbar {
        display: flex;
        gap: 16px;
        align-items: center;
        margin-bottom: 40px;
    }

    .search-wrap {
        flex: 1;
        position: relative;
        max-width: 560px;
    }

    .search-wrap input {
        width: 100%;
        padding: 12px 48px 12px 20px;
        background-color: var(--bg-modal);
        border: 1.5px solid var(--borda-padrao);
        border-radius: 50px;
        color: var(--texto-principal);
        font-size: 0.9rem;
        outline: none;
        transition: border-color 0.2s;
    }

    .search-wrap input::placeholder { color: var(--texto-auxiliar); }
    .search-wrap input:focus        { border-color: var(--borda-foco); }

    .search-wrap button {
        position: absolute;
        right: 16px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: var(--texto-suave);
        cursor: pointer;
        display: flex;
        align-items: center;
    }

    .btn-filtrar {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 12px 24px;
        background-color: transparent;
        border: 1.5px solid var(--borda-destaque);
        border-radius: 50px;
        color: var(--texto-principal);
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        white-space: nowrap;
        transition: background-color 0.15s;
    }

    .btn-filtrar:hover { background-color: var(--bg-modal); }

    /* Painel de filtros */
    .filtros-panel {
        display: none;
        background-color: var(--bg-superficie);
        border: 1px solid var(--borda-padrao);
        border-radius: 16px;
        padding: 20px 24px;
        margin-bottom: 28px;
        gap: 12px;
        flex-wrap: wrap;
        align-items: center;
    }

    .filtros-panel.open { display: flex; }

    .filtros-panel label {
        font-size: 0.85rem;
        color: var(--texto-suave);
        margin-right: 4px;
    }

    .filtros-panel select {
        padding: 8px 14px;
        background-color: var(--bg-campo);
        border: 1px solid var(--borda-controle);
        border-radius: 8px;
        color: var(--texto-principal);
        font-size: 0.85rem;
        cursor: pointer;
        outline: none;
    }

    .filtros-panel .btn-limpar {
        padding: 8px 18px;
        background: none;
        border: 1px solid var(--borda-forte);
        border-radius: 8px;
        color: var(--texto-suave);
        font-size: 0.85rem;
        cursor: pointer;
        text-decoration: none;
        transition: border-color 0.15s, color 0.15s;
    }

    .filtros-panel .btn-limpar:hover { border-color: var(--borda-foco); color: var(--texto-principal); }

    /* ── Alerta de erro ───────────────────── */
    .alert-erro {
        background-color: var(--bg-erro-sutil);
        border: 1px solid var(--borda-erro);
        border-radius: 12px;
        padding: 14px 20px;
        color: var(--texto-erro);
        font-size: 0.9rem;
        margin-bottom: 24px;
    }

    /* ── Subtítulo seção ──────────────────── */
    .section-title {
        font-size: 1.4rem;
        font-weight: 700;
        color: var(--texto-principal);
        margin-bottom: 20px;
    }

    /* ── Cards de pedido ──────────────────── */
    .pedidos-list {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }

    .pedido-card {
        background-color: var(--bg-superficie);
        border: 1px solid var(--borda-card);
        border-radius: 16px;
        padding: 22px 28px;
        display: flex;
        align-items: center;
        gap: 20px;
        transition: border-color 0.15s;
    }

    .pedido-card:hover { border-color: var(--borda-controle); }

    .pedido-info {
        flex: 1;
        min-width: 0;
    }

    .pedido-numero {
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--texto-principal);
        margin-bottom: 4px;
    }

    .pedido-meta {
        font-size: 0.82rem;
        color: var(--texto-secundario);
        line-height: 1.7;
    }

    .pedido-meta span { display: block; }

    /* ── Ações do card ────────────────────── */
    .pedido-actions {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-shrink: 0;
    }

    .atendimento-wrap {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .atendimento-pergunta {
        font-size: 0.82rem;
        color: var(--texto-secundario);
        font-weight: 600;
    }

    .btn-atendimento {
        border: none;
        border-radius: 10px;
        height: 38px;
        padding: 0 12px;
        color: var(--texto-principal);
        font-size: 0.82rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        font-family: inherit;
        text-decoration: none;
        transition: filter 0.15s;
    }

    .btn-atendimento:hover { filter: brightness(1.08); }
    .btn-atendimento svg { width: 15px; height: 15px; }

    .btn-atendimento.verde { background-color: var(--cor-sucesso-forte); }

    .pedido-bloqueado {
        background-color: var(--bg-controle);
        border: 1px solid var(--borda-controle);
        color: var(--texto-secundario);
        border-radius: 10px;
        height: 38px;
        padding: 0 14px;
        display: inline-flex;
        align-items: center;
        font-size: 0.82rem;
        font-weight: 700;
        white-space: nowrap;
    }

    /* ── Status badges ────────────────────── */
    .status-badge {
        padding: 9px 18px;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 700;
        white-space: nowrap;
        text-align: center;
        min-width: 110px;
    }

    .status-badge.pendente             { background-color: var(--status-pendente-bg); color: var(--status-pendente-texto); }
    .status-badge.em-separacao         { background-color: var(--status-separacao-bg); color: var(--status-separacao-texto); }
    .status-badge.pronto-para-retirada { background-color: var(--status-pronto-bg); color: var(--status-pronto-texto); }
    .status-badge.em-andamento         { background-color: var(--status-andamento-bg); color: var(--status-andamento-texto); }
    .status-badge.negado               { background-color: var(--status-cancelado-bg); color: var(--status-cancelado-texto); }
    .status-badge.cancelado            { background-color: var(--status-cancelado-bg); color: var(--status-cancelado-texto); }
    .status-badge.finalizado           { background-color: var(--status-finalizado-bg); color: var(--status-finalizado-texto); }
    .status-badge.renovacao-solicitada { background-color: var(--status-pendente-bg); color: var(--status-pendente-texto); }
    .status-badge.em-atendimento-lab   { background-color: var(--status-atendimento-bg); color: var(--status-atendimento-texto); }

    /* ── Botão de menu (3 risquinhos) ──────── */
    .btn-menu {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        background-color: var(--bg-controle);
        border: 1px solid var(--borda-controle);
        color: var(--texto-principal);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        position: relative;
        transition: background-color 0.15s;
    }

    .btn-menu:hover { background-color: var(--bg-controle-hover); }
    .btn-menu svg   { width: 18px; height: 18px; pointer-events: none; }

    /* ── Dropdown de ações ────────────────── */
    .menu-wrap {
        position: relative;
    }

    .menu-dropdown {
        display: none;
        position: absolute;
        top: calc(100% + 8px);
        right: 0;
        background-color: var(--bg-modal);
        border: 1px solid var(--borda-padrao);
        border-radius: 12px;
        min-width: 210px;
        overflow: hidden;
        box-shadow: var(--sombra-modal);
        z-index: 50;
    }

    .menu-dropdown.open { display: block; }

    .menu-item {
        display: flex;
        align-items: center;
        gap: 10px;
        width: 100%;
        padding: 13px 18px;
        background: none;
        border: none;
        border-bottom: 1px solid var(--borda-card);
        color: var(--texto-principal);
        font-size: 0.88rem;
        cursor: pointer;
        text-align: left;
        transition: background-color 0.15s;
        font-family: inherit;
    }

    .menu-item:last-child { border-bottom: none; }
    .menu-item:hover      { background-color: var(--bg-controle); }

    .menu-item svg { width: 16px; height: 16px; flex-shrink: 0; }

    .menu-item.danger       { color: var(--cor-erro-claro); }
    .menu-item.danger:hover { background-color: var(--bg-erro-hover); }

    .menu-item.success       { color: var(--cor-sucesso); }
    .menu-item.success:hover { background-color: var(--bg-sucesso-sutil); }

    /* ── Estado vazio / erro BD ───────────── */
    .empty-state {
        text-align: center;
        padding: 60px 0;
        color: var(--texto-placeholder);
        font-size: 1rem;
    }

    .db-error {
        background-color: var(--bg-erro-sutil);
        border: 1px solid var(--borda-erro);
        border-radius: 16px;
        padding: 40px;
        text-align: center;
        color: var(--texto-suave);
    }

    .db-error h2 { color: var(--cor-erro); margin-bottom: 8px; }

    /* ══════════════════════════════════════════
       MODAL (negação com justificativa)
    ══════════════════════════════════════════ */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background-color: var(--overlay-modal);
        z-index: 200;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .modal-overlay.open { display: flex; }

    .modal-box {
        background-color: var(--bg-modal);
        border: 1px solid var(--borda-padrao);
        border-radius: 20px;
        padding: 36px 40px;
        width: 100%;
        max-width: 480px;
    }

    .modal-title {
        font-size: 1.4rem;
        font-weight: 800;
        color: var(--texto-principal);
        margin-bottom: 8px;
    }

    .modal-desc {
        font-size: 0.88rem;
        color: var(--texto-suave);
        margin-bottom: 24px;
        line-height: 1.6;
    }

    .modal-desc strong { color: var(--texto-principal); }

    .modal-label {
        display: block;
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--texto-medio);
        margin-bottom: 8px;
    }

    .modal-textarea {
        width: 100%;
        min-height: 110px;
        padding: 12px 16px;
        background-color: var(--bg-subpainel);
        border: 1.5px solid var(--borda-padrao);
        border-radius: 10px;
        color: var(--texto-principal);
        font-size: 0.9rem;
        font-family: inherit;
        resize: vertical;
        outline: none;
        transition: border-color 0.2s;
        margin-bottom: 6px;
    }

    .modal-textarea:focus { border-color: var(--borda-foco); }

    .modal-hint {
        font-size: 0.78rem;
        color: var(--texto-auxiliar);
        margin-bottom: 24px;
    }

    .modal-hint.error { color: var(--cor-erro-claro); }

    .modal-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }

    .btn-cancelar-modal {
        padding: 11px 24px;
        background: none;
        border: 1px solid var(--borda-forte);
        border-radius: 10px;
        color: var(--texto-suave);
        font-size: 0.88rem;
        cursor: pointer;
        font-family: inherit;
        transition: border-color 0.15s, color 0.15s;
    }

    .btn-cancelar-modal:hover { border-color: var(--borda-foco); color: var(--texto-principal); }

    .btn-confirmar-negar {
        padding: 11px 24px;
        background-color: var(--cor-erro-forte);
        border: none;
        border-radius: 10px;
        color: #ffffff;
        font-size: 0.88rem;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
        transition: background-color 0.15s;
    }

    .btn-confirmar-negar:hover { background-color: var(--cor-erro-hover); }

    /* ── Botão olho (preview) ─────────────── */
    .btn-eye {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        background-color: var(--bg-controle-alt);
        border: 1px solid var(--borda-controle);
        color: var(--texto-principal);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: background-color 0.15s;
    }
    .btn-eye:hover { background-color: var(--bg-controle); }
    .btn-eye svg   { width: 18px; height: 18px; pointer-events: none; }

    .btn-extensao {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        background-color: var(--bg-alerta-sutil);
        border: 1px solid var(--borda-alerta);
        color: var(--cor-alerta);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: background-color 0.15s;
    }
    .btn-extensao:hover { background-color: var(--bg-alerta-badge); }
    .btn-extensao svg { width: 18px; height: 18px; pointer-events: none; }

    .btn-atrasado {
        height: 42px;
        border-radius: 10px;
        border: 1px solid var(--borda-erro);
        background-color: var(--bg-erro-card);
        color: var(--texto-erro);
        padding: 0 12px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 0.8rem;
        font-weight: 700;
        cursor: pointer;
        transition: background-color 0.15s, border-color 0.15s;
    }

    .btn-atrasado:hover {
        background-color: var(--bg-erro-hover);
        border-color: var(--borda-erro);
    }

    .btn-atrasado.neutro {
        border-color: var(--borda-controle);
        background-color: var(--bg-controle-alt);
        color: var(--texto-medio);
    }

    .btn-atrasado.neutro:hover {
        background-color: var(--bg-controle-alt);
        border-color: var(--borda-forte);
    }

    .btn-atrasado.neutro .alerta {
        background-color: var(--texto-secundario);
    }

    .btn-atrasado .alerta {
        width: 18px;
        height: 18px;
        border-radius: 50%;
        background-color: var(--cor-erro-forte);
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        line-height: 1;
        font-weight: 800;
    }

    /* ── Modal preview do pedido ─────────── */
    .preview-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background-color: var(--overlay-modal);
        z-index: 300;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .preview-overlay.open { display: flex; }

    .preview-box {
        background-color: var(--bg-superficie-tabela);
        border: 1px solid var(--borda-padrao);
        border-radius: 22px;
        width: 100%;
        max-width: 420px;
        overflow: hidden;
    }

    .preview-header {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 10px;
        padding: 28px 28px 20px;
        border-bottom: 1px solid var(--borda-card);
        background-color: var(--bg-subpainel);
    }

    .preview-avatar {
        width: 72px;
        height: 72px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid var(--borda-controle);
        background-color: var(--bg-controle);
    }

    .preview-avatar-placeholder {
        width: 72px;
        height: 72px;
        border-radius: 50%;
        background-color: var(--bg-controle);
        border: 2px solid var(--borda-controle);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--texto-auxiliar);
        flex-shrink: 0;
    }

    .preview-nome {
        font-size: 1rem;
        font-weight: 700;
        color: var(--texto-principal);
    }

    .preview-body {
        padding: 20px 28px 4px;
        max-height: 360px;
        overflow-y: auto;
    }

    .preview-subtitle {
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--texto-auxiliar);
        text-transform: uppercase;
        letter-spacing: 0.06em;
        margin-bottom: 14px;
    }

    .preview-items-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
        padding-bottom: 20px;
    }

    .preview-item {
        display: flex;
        align-items: center;
        gap: 14px;
        background-color: var(--bg-cabecalho-tabela);
        border: 1px solid var(--borda-padrao);
        border-radius: 12px;
        padding: 12px 16px;
    }

    .preview-item-img {
        width: 52px;
        height: 52px;
        border-radius: 8px;
        object-fit: cover;
        background-color: var(--bg-controle);
        flex-shrink: 0;
    }

    .preview-item-placeholder {
        width: 52px;
        height: 52px;
        border-radius: 8px;
        background-color: var(--bg-controle);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--texto-placeholder);
        flex-shrink: 0;
    }

    .preview-item-info  { flex: 1; min-width: 0; }

    .preview-item-nome {
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--texto-principal);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .preview-item-qtd {
        font-size: 0.8rem;
        color: var(--texto-secundario);
        margin-top: 3px;
    }

    .preview-empty {
        text-align: center;
        color: var(--texto-placeholder);
        font-size: 0.9rem;
        padding: 20px 0;
    }

    .preview-msg-wrap {
        border-top: 1px solid var(--borda-card);
        margin-top: 6px;
        padding-top: 14px;
        padding-bottom: 16px;
    }

    .preview-msg-title {
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--texto-auxiliar);
        text-transform: uppercase;
        letter-spacing: 0.06em;
        margin-bottom: 8px;
    }

    .preview-msg-box {
        background-color: var(--bg-cabecalho-tabela);
        border: 1px solid var(--borda-padrao);
        border-radius: 10px;
        padding: 10px 12px;
        color: var(--texto-medio);
        font-size: 0.84rem;
        line-height: 1.5;
        white-space: pre-wrap;
        word-break: break-word;
    }

    .preview-msg-empty {
        color: var(--texto-auxiliar);
        font-size: 0.82rem;
    }

    .preview-date-highlight {
        margin-top: 10px;
        border: 1px solid var(--borda-info);
        background-color: var(--bg-info-badge);
        color: var(--texto-info);
        border-radius: 10px;
        padding: 10px 12px;
        font-size: 0.9rem;
        font-weight: 700;
        line-height: 1.35;
        display: none;
    }

    .preview-close {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 10px;
        padding: 0 28px 22px;
    }

    .btn-fechar-preview {
        padding: 9px 22px;
        background: none;
        border: 1px solid var(--borda-padrao);
        border-radius: 10px;
        color: var(--texto-suave);
        font-size: 0.85rem;
        cursor: pointer;
        font-family: inherit;
        transition: border-color 0.15s, color 0.15s;
    }
    .btn-fechar-preview:hover { border-color: var(--borda-foco); color: var(--texto-principal); }

    /* Detalhes do pedido: cabeçalho e rodapé fixos, conteúdo com rolagem. */
    body.preview-pedido-aberto { overflow: hidden; }
    #modalPreview .preview-box {
        max-width: 660px;
        max-height: calc(100vh - 40px);
        max-height: calc(100dvh - 40px);
        display: flex;
        flex-direction: column;
    }
    #modalPreview .preview-header {
        align-items: stretch;
        gap: 20px;
        padding: 24px;
        flex-shrink: 0;
    }
    .preview-title-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px;
    }
    .preview-eyebrow {
        font-size: 0.78rem;
        color: var(--texto-suave);
        margin-bottom: 5px;
    }
    .preview-title { font-size: 1.3rem; font-weight: 700; color: var(--texto-principal); margin: 0; }
    .preview-requester { display: flex; align-items: center; gap: 14px; min-width: 0; }
    .preview-requester-info { min-width: 0; }
    #modalPreview .preview-avatar,
    #modalPreview .preview-avatar-placeholder { width: 48px; height: 48px; flex-shrink: 0; }
    #modalPreview .preview-nome { margin: 0; overflow-wrap: anywhere; }
    .preview-cadastro { color: var(--texto-suave); font-size: 0.8rem; margin-top: 5px; overflow-wrap: anywhere; }
    #modalPreview [hidden] { display: none !important; }
    #modalPreview .preview-body {
        max-height: none;
        min-height: 0;
        padding: 24px;
        overflow-y: auto;
        overscroll-behavior: contain;
        scrollbar-width: thin;
        scrollbar-color: var(--borda-foco) var(--bg-superficie-tabela);
        color-scheme: inherit;
    }
    #modalPreview .preview-body::-webkit-scrollbar { width: 6px; }
    #modalPreview .preview-body::-webkit-scrollbar-thumb { background: var(--borda-foco); border-radius: 6px; }
    #modalPreview .preview-body::-webkit-scrollbar-track { background: var(--bg-superficie-tabela); }
    .preview-section + .preview-section { border-top: 1px solid var(--borda-padrao); padding-top: 22px; margin-top: 22px; }
    #modalPreview .preview-subtitle {
        color: var(--texto-secundario);
        font-size: 0.75rem;
        margin: 0 0 14px;
    }
    .preview-summary {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 18px 24px;
        margin: 0;
    }
    .preview-summary dt { color: var(--texto-suave); font-size: 0.79rem; margin-bottom: 6px; }
    .preview-summary dd { color: var(--texto-forte); font-size: 0.9rem; line-height: 1.5; margin: 0; overflow-wrap: anywhere; }
    .preview-section-heading { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; }
    .preview-item-count { color: var(--texto-suave); font-size: 0.78rem; text-align: right; }
    #modalPreview .preview-items-list { padding-bottom: 0; }
    #modalPreview .preview-item { padding: 14px; gap: 14px; }
    #modalPreview a.preview-item { text-decoration: none; color: inherit; }
    #modalPreview a.preview-item:hover { border-color: var(--borda-foco); }
    #modalPreview .preview-item-nome {
        white-space: normal;
        overflow: visible;
        text-overflow: clip;
        overflow-wrap: anywhere;
        line-height: 1.5;
        margin: 0;
    }
    #modalPreview .preview-item-qtd { color: var(--texto-suave); font-size: 0.8rem; line-height: 1.5; margin-top: 6px; }
    #modalPreview .preview-msg-box { padding: 14px; font-size: 0.9rem; line-height: 1.65; }
    #modalPreview .preview-empty { color: var(--texto-suave); padding: 12px 0; }
    #modalPreview .preview-close {
        padding: 16px 24px;
        border-top: 1px solid var(--borda-padrao);
        background: var(--bg-subpainel);
        flex-shrink: 0;
    }
    #modalPreview :focus-visible { outline: 2px solid var(--borda-destaque); outline-offset: 3px; }
    @media (max-width: 480px) {
        #modalPreview { padding: 12px; }
        #modalPreview .preview-box { max-height: calc(100dvh - 24px); border-radius: 18px; }
        #modalPreview .preview-header,
        #modalPreview .preview-body { padding: 20px; }
        #modalPreview .preview-close { padding: 14px 20px; }
        .preview-summary { grid-template-columns: 1fr; gap: 16px; }
        #modalPreview .preview-item { align-items: flex-start; padding: 12px; gap: 12px; }
        #modalPreview .preview-item-img,
        #modalPreview .preview-item-placeholder { width: 44px; height: 44px; }
    }

    .btn-confirmar-renov,
    .btn-discordar-renov {
        padding: 9px 18px;
        border-radius: 10px;
        border: none;
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--texto-principal);
        cursor: pointer;
        font-family: inherit;
        transition: filter 0.15s;
    }

    .btn-confirmar-renov {
        background-color: var(--cor-sucesso-forte);
    }

    .btn-discordar-renov {
        background-color: var(--cor-erro-forte);
    }

    .btn-confirmar-renov:hover,
    .btn-discordar-renov:hover {
        filter: brightness(1.08);
    }

    .nota-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background-color: var(--overlay-modal);
        z-index: 350;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .nota-overlay.open { display: flex; }

    .nota-box {
        width: 100%;
        max-width: 560px;
        background-color: var(--bg-superficie-alt);
        border: 1px solid var(--borda-card);
        border-radius: 18px;
        overflow: hidden;
    }

    .nota-header {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 18px 20px;
        border-bottom: 1px solid var(--borda-card);
        background-color: var(--bg-subpainel);
    }

    .nota-avatar {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        object-fit: cover;
        background-color: var(--bg-controle);
        border: 1px solid var(--borda-controle);
        flex-shrink: 0;
    }

    .nota-avatar-placeholder {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: var(--bg-controle);
        border: 1px solid var(--borda-controle);
        color: var(--texto-auxiliar);
        flex-shrink: 0;
    }

    .nota-header-text { min-width: 0; }

    .nota-nome {
        color: var(--texto-principal);
        font-size: 0.95rem;
        font-weight: 700;
        margin-bottom: 2px;
    }

    .nota-prazo {
        color: var(--texto-erro);
        font-size: 0.8rem;
        font-weight: 600;
    }

    .nota-chat {
        max-height: 260px;
        overflow-y: auto;
        padding: 14px 20px;
        display: flex;
        flex-direction: column;
        gap: 10px;
        border-bottom: 1px solid var(--borda-card);
    }

    .nota-empty {
        color: var(--texto-auxiliar);
        font-size: 0.84rem;
        text-align: center;
        padding: 12px 0;
    }

    .baloon {
        max-width: 85%;
        border-radius: 12px;
        padding: 10px 12px;
        font-size: 0.84rem;
        line-height: 1.5;
        white-space: pre-wrap;
        word-break: break-word;
    }

    .baloon.lab {
        align-self: flex-end;
        background-color: var(--chat-aluno-bg);
        border: 1px solid var(--chat-aluno-borda);
        color: var(--chat-aluno-texto);
    }

    .baloon-wrap {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
    }

    .btn-msg-apagar {
        width: 26px;
        height: 26px;
        border-radius: 8px;
        border: 1px solid var(--borda-controle);
        background-color: var(--bg-controle);
        color: var(--texto-medio);
        cursor: pointer;
        font-size: 0.72rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .btn-msg-apagar:hover {
        border-color: var(--cor-erro);
        color: var(--cor-erro);
    }

    .baloon.aluno {
        align-self: flex-start;
        background-color: var(--bg-erro-hover);
        border: 1px solid var(--borda-erro);
        color: var(--texto-erro-claro);
    }

    .nota-form {
        padding: 14px 20px 18px;
    }

    .nota-form label {
        display: block;
        color: var(--texto-medio);
        font-size: 0.82rem;
        font-weight: 600;
        margin-bottom: 8px;
    }

    .nota-textarea {
        width: 100%;
        min-height: 90px;
        border-radius: 10px;
        border: 1.5px solid var(--borda-padrao);
        background-color: var(--bg-campo-profundo);
        color: var(--texto-principal);
        font-size: 0.88rem;
        padding: 10px 12px;
        resize: vertical;
        outline: none;
        font-family: inherit;
    }

    .nota-textarea:focus { border-color: var(--borda-foco); }

    .nota-erro {
        margin-top: 8px;
        color: var(--cor-erro-claro);
        font-size: 0.8rem;
        min-height: 16px;
    }

    .nota-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 12px;
    }

    .btn-nota-cancelar,
    .btn-nota-enviar {
        border-radius: 10px;
        padding: 10px 16px;
        font-size: 0.84rem;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
    }

    .btn-nota-cancelar {
        background: none;
        color: var(--texto-suave);
        border: 1px solid var(--borda-forte);
    }

    .btn-nota-cancelar:hover { border-color: var(--borda-foco); color: var(--texto-principal); }

    .btn-nota-enviar {
        background-color: var(--cor-erro-forte);
        color: #ffffff;
        border: none;
    }

    .btn-nota-enviar:hover { background-color: var(--cor-erro-hover); }

    /* ── Responsivo ───────────────────────── */
    /* ── Animação de arquivar card ───────── */
    .pedido-card.arquivando {
        opacity: 0;
        transform: translateX(20px);
        transition: opacity 0.3s ease, transform 0.3s ease;
    }

    /* ── Responsivo ───────────────────────── */
    @media (max-width: 860px) {
        .main { padding: 32px 20px 60px; }
        .pedido-card { flex-wrap: wrap; }
        .toolbar { flex-wrap: wrap; }
        .search-wrap { max-width: 100%; }
    }

    @media (max-width: 520px) {
        .pedido-actions { flex-direction: column; align-items: flex-end; }
    }
</style>
</head>
<body>

<!-- ══════════════════ NAVBAR ══════════════════ -->
<nav class="navbar">

    <a href="./index.php" class="nav-logo">C.I.R.C.U.I.T.O.</a>

    <div class="nav-actions">

        <!-- Usuário + dropdown -->
        <div class="nav-user" id="navUser">
            <button class="nav-user-btn" onclick="toggleUserDropdown()" aria-haspopup="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
                <div class="greeting">
                    <span>Bem vindo,</span>
                    <strong><?= htmlspecialchars($usuario_nome) ?>!</strong>
                </div>
            </button>

            <div class="dropdown" role="menu">
                <?php require __DIR__ . '/../includes/menu_perfil.php'; ?>
                <a href="../logout.php" role="menuitem">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                        <polyline points="16 17 21 12 16 7"/>
                        <line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                    Logout
                </a>
            </div>
        </div>

    </div>
</nav>

<!-- ══════════════════ MODAL — NEGAR PEDIDO ══════════════════ -->
<div class="modal-overlay" id="modalNegar" role="dialog" aria-modal="true" aria-labelledby="modalNegarTitulo">
    <div class="modal-box">
        <h2 class="modal-title" id="modalNegarTitulo">Negar pedido</h2>
        <p class="modal-desc">
            Informe o motivo da negação. <strong>O estudante será notificado</strong> com a justificativa.
        </p>

        <form method="POST" action="./acessar.php" id="formNegar">
            <input type="hidden" name="action" value="negar">
            <input type="hidden" name="id_pedido" id="modalNegarId" value="">

            <label class="modal-label" for="justificativaInput">Justificativa *</label>
            <textarea
                class="modal-textarea"
                id="justificativaInput"
                name="justificativa"
                placeholder="Ex.: Item indisponível no estoque no momento..."
                maxlength="1000"
            ></textarea>
            <p class="modal-hint" id="modalHint">Obrigatório para prosseguir.</p>

            <div class="modal-actions">
                <button type="button" class="btn-cancelar-modal" onclick="fecharModalNegar()">Cancelar</button>
                <button type="submit" class="btn-confirmar-negar">Confirmar negação</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════ MODAL — PREVIEW PEDIDO ══════════════════ -->
<div class="preview-overlay" id="modalPreview" role="dialog" aria-modal="true" aria-labelledby="previewTitulo" aria-hidden="true">
    <div class="preview-box">
        <div class="preview-header">
            <div class="preview-title-row">
                <div>
                    <p class="preview-eyebrow">Detalhes do pedido</p>
                    <h2 class="preview-title" id="previewTitulo">Pedido</h2>
                </div>
                <span class="status-badge" id="previewStatus"></span>
            </div>
            <div class="preview-requester">
                <div id="previewFoto"></div>
                <div class="preview-requester-info">
                    <p class="preview-eyebrow">Solicitado por</p>
                    <p class="preview-nome" id="previewNome"></p>
                    <p class="preview-cadastro" id="previewCadastro" hidden></p>
                </div>
            </div>
        </div>
        <div class="preview-body">
            <section class="preview-section" aria-labelledby="previewResumoTitulo">
                <h3 class="preview-subtitle" id="previewResumoTitulo">Resumo do pedido</h3>
                <dl class="preview-summary">
                    <div><dt>Solicitado em</dt><dd id="previewCriacao"></dd></div>
                    <div><dt>Prazo solicitado</dt><dd id="previewPrazo"></dd></div>
                    <div><dt>Devolução prevista</dt><dd id="previewDevolucao"></dd></div>
                    <div><dt>Laboratorista responsável</dt><dd id="previewResponsavel"></dd></div>
                    <div id="previewDataPropostaWrap" hidden><dt>Data proposta pelo estudante</dt><dd id="previewDataProposta"></dd></div>
                    <div id="previewRetiradaPrevistaWrap" hidden><dt>Retirada prevista</dt><dd id="previewRetiradaPrevista"></dd></div>
                    <div id="previewRetiradaRealWrap" hidden><dt>Retirado em</dt><dd id="previewRetiradaReal"></dd></div>
                    <div id="previewDevolucaoRealWrap" hidden><dt>Devolvido em</dt><dd id="previewDevolucaoReal"></dd></div>
                </dl>
            </section>
            <section class="preview-section" aria-labelledby="previewItensTitulo">
                <div class="preview-section-heading">
                    <h3 class="preview-subtitle" id="previewItensTitulo">Itens solicitados</h3>
                    <span class="preview-item-count" id="previewItensTotal"></span>
                </div>
                <div class="preview-items-list" id="previewItems"></div>
            </section>
            <section class="preview-section" id="previewJustificativaWrap" aria-labelledby="previewJustificativaTitulo" hidden>
                <h3 class="preview-subtitle" id="previewJustificativaTitulo">Justificativa do prazo</h3>
                <div id="previewJustificativa" class="preview-msg-box"></div>
            </section>
            <section class="preview-section" id="previewMsgWrap" aria-labelledby="previewMensagemTitulo" hidden>
                <h3 class="preview-subtitle" id="previewMensagemTitulo">Observações do pedido</h3>
                <div id="previewMensagemAluno" class="preview-msg-box"></div>
            </section>
        </div>
        <div class="preview-close">
            <button type="button" class="btn-fechar-preview" id="previewFechar" onclick="fecharPreview()">Fechar</button>
        </div>
    </div>
</div>

<div class="preview-overlay" id="modalRenovacaoPedido" role="dialog" aria-modal="true" aria-label="Proposta de extensão">
    <div class="preview-box">
        <div class="preview-header" id="renovHeader"></div>
        <div class="preview-body">
            <p class="preview-subtitle">Itens solicitados</p>
            <div class="preview-items-list" id="renovItems"></div>

            <div class="preview-msg-wrap">
                <p class="preview-msg-title">Intervenção de data</p>
                <div id="renovIntervencao" class="preview-msg-box"></div>
                <div id="renovDataSeteMais" class="preview-date-highlight"></div>
            </div>

            <div class="preview-msg-wrap">
                <p class="preview-msg-title">Proposta</p>
                <div id="renovProposta" class="preview-msg-box"></div>
            </div>
        </div>
        <div class="preview-close">
            <form method="POST" action="./acessar.php" id="formConcordarRenovacao" style="margin:0;">
                <input type="hidden" name="action" value="aprovar">
                <input type="hidden" name="id_pedido" id="renovConcordarId" value="">
                <button type="submit" class="btn-confirmar-renov">Concordar</button>
            </form>
            <form method="POST" action="./acessar.php" id="formDiscordarRenovacao" style="margin:0;">
                <input type="hidden" name="action" value="negar">
                <input type="hidden" name="id_pedido" id="renovDiscordarId" value="">
                <input type="hidden" name="justificativa" value="Proposta de extensão não aprovada.">
                <button type="submit" class="btn-discordar-renov">Discordar</button>
            </form>
            <button class="btn-fechar-preview" onclick="fecharRenovacaoPedido()">Fechar</button>
        </div>
    </div>
</div>

<!-- ══════════════════ MODAL — NOTA DE ATRASO ══════════════════ -->
<div class="nota-overlay" id="modalNotaAtraso" role="dialog" aria-modal="true" aria-label="Nota de atraso">
    <div class="nota-box">
        <div class="nota-header" id="notaHeader">
            <!-- preenchido via JS -->
        </div>

        <div class="nota-chat" id="notaChat">
            <p class="nota-empty">Carregando nota...</p>
        </div>

        <div class="nota-form" id="formNotaAtraso">
            <p class="nota-erro" id="notaErro"></p>
            <div class="nota-actions">
                <button type="button" class="btn-nota-cancelar" onclick="fecharNotaAtraso()">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════ CONTEÚDO ══════════════════ -->
<main class="main">

    <!-- Cabeçalho -->
    <div class="page-header">
        <h1 class="page-title">Pedidos</h1>
        <div style="display:flex;align-items:center;gap:10px">
            <a href="./arquivados.php" class="btn-back" title="Ver pedidos arquivados"
               style="width:auto;border-radius:10px;padding:0 14px;font-size:0.83rem;font-weight:600;gap:7px;text-decoration:none;white-space:nowrap">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="21 8 21 21 3 21 3 8"/>
                    <rect x="1" y="3" width="22" height="5"/>
                    <line x1="10" y1="12" x2="14" y2="12"/>
                </svg>
                Arquivados
            </a>
            <a href="./index.php" class="btn-back" aria-label="Voltar">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
            </a>
        </div>
    </div>

    <!-- Alerta de erro: justificativa ausente -->
    <?php if ($erro === 'justificativa'): ?>
    <div class="alert-erro">
        Justificativa obrigatória ao negar um pedido. Por favor, preencha o campo antes de confirmar.
    </div>
    <?php elseif ($erro === 'estoque'): ?>
    <div class="alert-erro">
        Não foi possível aprovar o pedido porque o estoque de um ou mais componentes é insuficiente.
    </div>
    <?php elseif ($erro === 'bloqueado'): ?>
    <div class="alert-erro">
        Este pedido já está em atendimento por outro laboratorista ou sua sessão não possui permissão para alterar este item.
    </div>
    <?php endif; ?>

    <!-- Barra de busca + filtro -->
    <form method="GET" action="./acessar.php">
        <div class="toolbar">
            <div class="search-wrap">
                <input
                    type="text"
                    name="q"
                    placeholder="Pesquisar por usuário"
                    value="<?= htmlspecialchars($busca) ?>"
                    autocomplete="off"
                >
                <button type="submit" aria-label="Buscar">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2.5"
                         stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </button>
            </div>

            <button type="button" class="btn-filtrar" onclick="toggleFiltros()">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2.5"
                     stroke-linecap="round" stroke-linejoin="round">
                    <line x1="4" y1="6"  x2="20" y2="6"/>
                    <line x1="8" y1="12" x2="16" y2="12"/>
                    <line x1="12" y1="18" x2="12" y2="18"/>
                </svg>
                Filtrar
            </button>
        </div>

        <!-- Painel de filtros -->
        <div class="filtros-panel <?= $filtro !== '' ? 'open' : '' ?>" id="filtrosPanel">
            <label for="filtroStatus">Status:</label>
            <select name="status" id="filtroStatus" onchange="this.form.submit()">
                <option value="">Todos</option>
                <?php foreach ($status_map as $key => $info): ?>
                    <option value="<?= htmlspecialchars($key) ?>"
                            <?= $filtro === $key ? 'selected' : '' ?>>
                        <?= htmlspecialchars($info['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <a href="./acessar.php" class="btn-limpar">Limpar filtros</a>
        </div>
    </form>

    <!-- Lista de pedidos -->
    <h2 class="section-title">Histórico de pedidos</h2>

    <?php if (!$db_ok): ?>
    <div class="db-error">
        <h2>Banco de dados indisponível</h2>
        <p>Não foi possível carregar os pedidos. Verifique a conexão com o MySQL.</p>
    </div>

    <?php elseif (empty($pedidos)): ?>
    <p class="empty-state">Nenhum pedido encontrado<?= $busca !== '' || $filtro !== '' ? ' para os filtros aplicados' : '' ?>.</p>

    <?php else: ?>
    <div class="pedidos-list">
        <?php foreach ($pedidos as $p):
            $status     = $p['status_pedido'] ?? 'pendente';
            $statusInfo = $status_map[$status] ?? ['label' => ucfirst($status), 'class' => 'pendente'];
            $numFmt     = sprintf('%03d', (int) ($p['numero_pedido'] ?? 0));
            $itensCard  = $itens_por_pedido[(int) $p['id_pedido']] ?? [];
            $itensJson  = htmlspecialchars(json_encode($itensCard, JSON_HEX_QUOT | JSON_HEX_APOS), ENT_QUOTES);
            $fotoEstud  = htmlspecialchars($p['foto_perfil_estudante'] ?? '');
            $nomeEstud  = htmlspecialchars($p['nome_estudante'] ?? '');
            $msgAluno   = htmlspecialchars((string) ($p['mensagem_aluno'] ?? ''), ENT_QUOTES);
            $idLabResp  = (int) ($p['id_laboratorista_responsavel'] ?? 0);
            $fluxoLivre = (int) ($p['fluxo_livre_laboratoristas'] ?? 0) === 1;
            $nomeLabRespRaw = trim((string) ($p['nome_laboratorista_responsavel'] ?? ''));
            $nomeLabResp = $nomeLabRespRaw !== '' ? $nomeLabRespRaw : 'Outro laboratorista';
            $pedidoPego = !$fluxoLivre && $idLabResp > 0;
            $pedidoMeu = $pedidoPego && $laboratoristaId > 0 && $idLabResp === $laboratoristaId;
            $pedidoOutro = $pedidoPego && !$pedidoMeu;
            $dataPrevistaRaw = trim((string) ($p['data_devolucao_prevista'] ?? ''));
            $dataCriacaoRaw = trim((string) ($p['data_criacao_raw'] ?? ''));
            $obsPrazo = (string) ($p['mensagem_aluno'] ?? '');
            $prazoCarrinho = '';
            $dataCarrinhoFmt = '';
            $propostaCarrinho = '';
            $resumoPreview = htmlspecialchars(json_encode([
                'numero' => $numFmt,
                'status' => $statusInfo['label'],
                'status_classe' => $statusInfo['class'],
                'matricula' => $p['matricula_estudante'] ?? '',
                'turma' => $p['turma_estudante'] ?? '',
                'data_criacao' => $dataCriacaoRaw,
                'data_devolucao_prevista' => $dataPrevistaRaw,
                'data_retirada_prevista' => $p['data_retirada_prevista'] ?? '',
                'data_retirada_real' => $p['data_retirada_real'] ?? '',
                'data_devolucao_real' => $p['data_devolucao_real'] ?? '',
                'responsavel' => $nomeLabRespRaw,
            ], JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES);
            if (preg_match('/Prazo solicitado pelo estudante:\s*([^\.]+)(?:\.|$)/u', $obsPrazo, $mPrazoCarrinho)) {
                $prazoCarrinho = trim((string) ($mPrazoCarrinho[1] ?? ''));
            }
            if (preg_match('/Data proposta:\s*([^\.]+)(?:\.|$)/u', $obsPrazo, $mDataCarrinho)) {
                $dataCarrinhoRaw = trim((string) ($mDataCarrinho[1] ?? ''));
                if ($dataCarrinhoRaw !== '') {
                    try {
                        $dataCarrinhoFmt = (new DateTimeImmutable($dataCarrinhoRaw))->format('d/m/Y');
                    } catch (Throwable) {
                        $dataCarrinhoFmt = $dataCarrinhoRaw;
                    }
                }
            }
            if (preg_match('/Proposta do aluno:\s*(.+)$/us', $obsPrazo, $mPropostaCarrinho)) {
                $propostaCarrinho = trim((string) ($mPropostaCarrinho[1] ?? ''));
            }
            $pedidoAtrasado = false;
            $diasAtraso = 0;
            $dataLimiteAtraso = null;
            if ($status === 'em-andamento' && $dataPrevistaRaw !== '') {
                try {
                    $dataLimiteAtraso = new DateTimeImmutable($dataPrevistaRaw);
                } catch (Throwable) {
                    $dataLimiteAtraso = null;
                }
            }
            if ($status === 'em-andamento' && $dataLimiteAtraso === null && $dataCriacaoRaw !== '') {
                $diasPrazo = 0;
                if (preg_match('/Prazo solicitado pelo estudante:\s*([^\.]+)(?:\.|$)/u', $obsPrazo, $m)) {
                    $prazoTxt = mb_strtolower(trim((string) ($m[1] ?? '')));
                    if (str_contains($prazoTxt, '1 a 3')) {
                        $diasPrazo = 3;
                    } elseif (str_contains($prazoTxt, '3 a 5')) {
                        $diasPrazo = 5;
                    } elseif (str_contains($prazoTxt, '3 a 7')) {
                        $diasPrazo = 7;
                    } elseif (str_contains($prazoTxt, 'teste')) {
                        $diasPrazo = -1;
                    }
                }

                if ($diasPrazo !== 0) {
                    try {
                        $dataLimiteAtraso = (new DateTimeImmutable($dataCriacaoRaw))->modify('+' . $diasPrazo . ' days');
                    } catch (Throwable) {
                        $dataLimiteAtraso = null;
                    }
                }
            }
            if ($status === 'em-andamento' && $dataLimiteAtraso !== null) {
                try {
                    $hoje = new DateTimeImmutable('today');
                    if ($hoje > $dataLimiteAtraso) {
                        $pedidoAtrasado = true;
                        $diasAtraso = (int) $dataLimiteAtraso->diff($hoje)->days;
                    }
                } catch (Throwable) {
                    $pedidoAtrasado = false;
                }
            }
            $mostrarOpcaoAtraso = $status === 'em-andamento' && ($pedidoMeu || $fluxoLivre);
            $renovacaoInfo = $renovacao_por_pedido[(int) $p['id_pedido']] ?? null;
            $temRenovacaoPendente = is_array($renovacaoInfo) && !empty($renovacaoInfo);
            $prazoRenov = (string) ($renovacaoInfo['prazo_opcao'] ?? '');
            $prazoTexto = [
                '1-3' => '1-3 dias',
                '3-5' => '3-5 dias',
                '3-7' => '3-7 dias',
                '7+' => '7+ dias',
            ][$prazoRenov] ?? 'tempo extra';
            $dataRenovRaw = trim((string) ($renovacaoInfo['data_solicitada'] ?? ''));
            $dataRenovFmt = '';
            if ($dataRenovRaw !== '') {
                try {
                    $dataRenovFmt = (new DateTimeImmutable($dataRenovRaw))->format('d/m/Y');
                } catch (Throwable) {
                    $dataRenovFmt = $dataRenovRaw;
                }
            }
            $justRenov = trim((string) ($renovacaoInfo['justificativa'] ?? ''));
            $atendimentoInfo = '';
            if ($pedidoMeu) {
                $atendimentoInfo = 'Em atendimento com você';
            } elseif ($pedidoOutro) {
                $atendimentoInfo = 'Em atendimento com ' . $nomeLabResp;
            }
        ?>
        <div class="pedido-card" id="card-<?= (int) $p['id_pedido'] ?>">

            <!-- Info -->
            <div class="pedido-info">
                <p class="pedido-numero">Pedido #<?= htmlspecialchars($numFmt) ?></p>
                <div class="pedido-meta">
                    <span>Pedido feito por: <strong style="color:var(--texto-medio)"><?= htmlspecialchars($p['nome_estudante']) ?></strong></span>
                    <span>Data da última atualização: <?= htmlspecialchars($p['data_fmt'] ?? '—') ?></span>
                    <?php if ($atendimentoInfo !== ''): ?>
                        <span>Atendimento: <?= htmlspecialchars($atendimentoInfo) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Ações -->
            <div class="pedido-actions">

                <!-- Badge de status -->
                <span class="status-badge <?= htmlspecialchars($statusInfo['class']) ?>">
                    <?= htmlspecialchars($statusInfo['label']) ?>
                </span>

                <!-- Menu de 3 risquinhos -->
                <?php
                /* Define quais ações são exibidas conforme o status atual */
                $acoes = [];
                if ($status === 'pendente') {
                    $acoes = [
                        ['action' => 'aprovar', 'label' => 'Aprovar pedido',  'type' => 'success', 'icon' => 'check'],
                        ['action' => 'negar',   'label' => 'Negar pedido',    'type' => 'danger',  'icon' => 'x'],
                    ];
                } elseif ($status === 'em-separacao') {
                    $acoes = [
                        ['action' => 'pronto-para-retirada', 'label' => 'Pronto para retirada', 'type' => 'normal', 'icon' => 'package'],
                    ];
                } elseif ($status === 'pronto-para-retirada') {
                    $acoes = [
                        ['action' => 'em-andamento', 'label' => 'Em andamento', 'type' => 'normal', 'icon' => 'truck'],
                    ];
                } elseif ($status === 'em-andamento') {
                    $acoes = [
                        ['action' => 'finalizar', 'label' => 'Finalizar pedido', 'type' => 'success', 'icon' => 'check'],
                    ];
                } elseif ($status === 'renovacao-solicitada') {
                    $acoes = [
                        ['action' => 'aprovar', 'label' => 'Aprovar renovação', 'type' => 'success', 'icon' => 'check'],
                        ['action' => 'negar',   'label' => 'Negar renovação',   'type' => 'danger',  'icon' => 'x'],
                    ];
                } elseif (in_array($status, ['finalizado', 'negado', 'cancelado'], true)) {
                    $acoes = [
                        ['action' => 'arquivar_lab', 'label' => 'Arquivar pedido', 'type' => 'normal', 'icon' => 'archive'],
                    ];
                }
                ?>

                <?php if (!$fluxoLivre && !$pedidoPego): ?>
                    <div class="atendimento-wrap" id="atendimento-wrap-<?= (int) $p['id_pedido'] ?>">
                        <span class="atendimento-pergunta">Deseja atender esse pedido?</span>

                        <form method="POST" action="./acessar.php" style="margin:0">
                            <input type="hidden" name="action" value="assumir">
                            <input type="hidden" name="id_pedido" value="<?= (int) $p['id_pedido'] ?>">
                            <button type="submit" class="btn-atendimento verde" aria-label="Atender pedido">
                                <?= svgIcon('check') ?>
                                Sim
                            </button>
                        </form>
                    </div>
                <?php elseif ($pedidoOutro): ?>
                    <span class="pedido-bloqueado">
                        Pedido pego por <?= htmlspecialchars($nomeLabResp) ?>
                    </span>
                <?php endif; ?>

                <!-- Olho: ver itens do pedido -->
                <button
                    type="button"
                    class="btn-eye"
                    onclick="abrirPreview(this)"
                    data-resumo="<?= $resumoPreview ?>"
                    data-itens="<?= $itensJson ?>"
                    data-foto="<?= $fotoEstud ?>"
                    data-nome="<?= $nomeEstud ?>"
                    data-msg="<?= $msgAluno ?>"
                    data-prazo-carrinho="<?= htmlspecialchars($prazoCarrinho, ENT_QUOTES) ?>"
                    data-data-carrinho="<?= htmlspecialchars($dataCarrinhoFmt, ENT_QUOTES) ?>"
                    data-proposta-carrinho="<?= htmlspecialchars($propostaCarrinho, ENT_QUOTES) ?>"
                    aria-label="Ver detalhes do pedido #<?= htmlspecialchars($numFmt) ?>"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                </button>

                <?php if ($temRenovacaoPendente): ?>
                <button
                    class="btn-extensao"
                    onclick="abrirRenovacaoPedido(this)"
                    data-pedido="<?= (int) $p['id_pedido'] ?>"
                    data-itens="<?= $itensJson ?>"
                    data-foto="<?= $fotoEstud ?>"
                    data-nome="<?= $nomeEstud ?>"
                    data-proposta="<?= htmlspecialchars($prazoTexto, ENT_QUOTES) ?>"
                    data-opcao="<?= htmlspecialchars($prazoRenov, ENT_QUOTES) ?>"
                    data-data="<?= htmlspecialchars($dataRenovFmt, ENT_QUOTES) ?>"
                    data-justificativa="<?= htmlspecialchars($justRenov, ENT_QUOTES) ?>"
                    aria-label="Proposta de extensão"
                    title="Proposta de extensão da data"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                    </svg>
                </button>
                <?php endif; ?>

                <?php if ($mostrarOpcaoAtraso && $pedidoAtrasado): ?>
                <button
                    class="btn-atrasado"
                    onclick="abrirNotaAtraso(<?= (int) $p['id_pedido'] ?>)"
                    aria-label="Produto atrasado"
                    title="Produto atrasado há <?= (int) $diasAtraso ?> dia(s)"
                >
                    <span class="alerta">!</span>
                    Produto atrasado
                </button>
                <?php endif; ?>

                <?php if ($pedidoMeu || $fluxoLivre): ?>
                <div class="menu-wrap" id="wrap-<?= (int) $p['id_pedido'] ?>">
                    <button
                        class="btn-menu"
                        onclick="toggleMenu(<?= (int) $p['id_pedido'] ?>, event)"
                        aria-label="Ações do pedido"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="8" y1="6"  x2="21" y2="6"/>
                            <line x1="8" y1="12" x2="21" y2="12"/>
                            <line x1="8" y1="18" x2="21" y2="18"/>
                            <line x1="3" y1="6"  x2="3.01" y2="6"/>
                            <line x1="3" y1="12" x2="3.01" y2="12"/>
                            <line x1="3" y1="18" x2="3.01" y2="18"/>
                        </svg>
                    </button>

                    <div class="menu-dropdown" id="menu-<?= (int) $p['id_pedido'] ?>">
                        <?php if ($pedidoMeu): ?>
                        <form method="POST" action="./acessar.php" style="margin:0">
                            <input type="hidden" name="action" value="liberar">
                            <input type="hidden" name="id_pedido" value="<?= (int) $p['id_pedido'] ?>">
                            <button type="submit" class="menu-item normal">
                                <?= svgIcon('share') ?>
                                Liberar para todos
                            </button>
                        </form>
                        <?php endif; ?>

                        <?php if (!empty($acoes) && ($pedidoMeu || $fluxoLivre)): ?>
                        <?php if ($mostrarOpcaoAtraso && !$pedidoAtrasado): ?>
                            <button
                                type="button"
                                class="menu-item normal"
                                onclick="abrirNotaAtraso(<?= (int) $p['id_pedido'] ?>)"
                            >
                                <?= svgIcon('eye') ?>
                                Pedido dentro do prazo
                            </button>
                        <?php endif; ?>
                        <?php foreach ($acoes as $acao): ?>
                            <?php if ($acao['action'] === 'arquivar_lab'): ?>
                            <button
                                type="button"
                                class="menu-item normal"
                                onclick="arquivarPedido(this, <?= (int) $p['id_pedido'] ?>)"
                            >
                                <?= svgIcon('archive') ?>
                                <?= htmlspecialchars($acao['label']) ?>
                            </button>

                            <?php elseif ($acao['action'] === 'negar'): ?>
                            <!-- Negar → abre modal com justificativa -->
                            <button
                                type="button"
                                class="menu-item danger"
                                onclick="abrirModalNegar(<?= (int) $p['id_pedido'] ?>)"
                            >
                                <?= svgIcon($acao['icon']) ?>
                                <?= htmlspecialchars($acao['label']) ?>
                            </button>

                            <?php else: ?>
                            <!-- Outras ações → POST direto -->
                            <form method="POST" action="./acessar.php" style="margin:0">
                                <input type="hidden" name="action"    value="<?= htmlspecialchars($acao['action']) ?>">
                                <input type="hidden" name="id_pedido" value="<?= (int) $p['id_pedido'] ?>">
                                <button
                                    type="submit"
                                    class="menu-item <?= htmlspecialchars($acao['type']) ?>"
                                >
                                    <?= svgIcon($acao['icon']) ?>
                                    <?= htmlspecialchars($acao['label']) ?>
                                </button>
                            </form>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</main>

<?php
/* Helper: retorna SVG inline por nome de ícone */
function svgIcon(string $name): string {
    $icons = [
        'check'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
        'x'       => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        'eye'     => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
        'share'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>',
        'package' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
        'truck'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
        'archive' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>',
    ];
    return $icons[$name] ?? '';
}
?>

<script>
    /* ── User dropdown ───────────────────── */
    function toggleUserDropdown() {
        document.getElementById('navUser').classList.toggle('open');
    }

    document.addEventListener('click', function (e) {
        const navUser = document.getElementById('navUser');
        if (navUser && !navUser.contains(e.target)) navUser.classList.remove('open');
    });

    /* ── Painel de filtros ───────────────── */
    function toggleFiltros() {
        document.getElementById('filtrosPanel').classList.toggle('open');
    }

    /* ── Menus de 3 risquinhos ───────────── */
    let menuAberto = null;

    function toggleMenu(id, e) {
        e.stopPropagation();
        const menu = document.getElementById('menu-' + id);
        if (!menu) return;

        const jaAberto = menu.classList.contains('open');

        /* Fecha qualquer menu aberto */
        if (menuAberto && menuAberto !== menu) {
            menuAberto.classList.remove('open');
        }

        menu.classList.toggle('open', !jaAberto);
        menuAberto = jaAberto ? null : menu;
    }

    /* Clique fora fecha o menu */
    document.addEventListener('click', function () {
        document.querySelectorAll('.menu-dropdown.open')
                .forEach(function (el) { el.classList.remove('open'); });
        menuAberto = null;
    });

    /* ── Modal de negação ────────────────── */
    function abrirModalNegar(id_pedido) {
        /* Fecha o dropdown antes de abrir o modal */
        document.querySelectorAll('.menu-dropdown.open')
                .forEach(function (el) { el.classList.remove('open'); });

        document.getElementById('modalNegarId').value = id_pedido;
        document.getElementById('justificativaInput').value = '';
        document.getElementById('modalHint').textContent = 'Obrigatório para prosseguir.';
        document.getElementById('modalHint').classList.remove('error');
        document.getElementById('modalNegar').classList.add('open');
        setTimeout(function () { document.getElementById('justificativaInput').focus(); }, 100);
    }

    function fecharModalNegar() {
        document.getElementById('modalNegar').classList.remove('open');
    }

    /* Fecha modal ao clicar no overlay */
    document.getElementById('modalNegar').addEventListener('click', function (e) {
        if (e.target === this) fecharModalNegar();
    });

    /* Fecha modais com Escape */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            fecharModalNegar();
            fecharPreview();
            fecharNotaAtraso();
        }
    });

    /* Validação client-side antes de submeter */
    document.getElementById('formNegar').addEventListener('submit', function (e) {
        const val = document.getElementById('justificativaInput').value.trim();
        if (val === '') {
            e.preventDefault();
            const hint = document.getElementById('modalHint');
            hint.textContent = 'Por favor, preencha a justificativa antes de confirmar.';
            hint.classList.add('error');
            document.getElementById('justificativaInput').focus();
        }
    });

    /* ── Arquivar pedido (AJAX) ──────────── */
    async function arquivarPedido(btn, id) {
        btn.disabled = true;
        document.querySelectorAll('.menu-dropdown.open')
                .forEach(function (el) { el.classList.remove('open'); });

        const fd = new FormData();
        fd.append('id', id);
        fd.append('acao', 'arquivar');

        try {
            const res  = await fetch('../api/pedido_arquivar_lab.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (!data.ok) { btn.disabled = false; return; }

            const card = document.getElementById('card-' + id);
            if (card) {
                card.classList.add('arquivando');
                card.addEventListener('transitionend', function () { card.remove(); }, { once: true });
            }
        } catch (_) {
            btn.disabled = false;
        }
    }

    /* ── Modal preview de itens ──────────── */
    let previewTrigger = null;

    function abrirPreview(btn) {
        const itens = JSON.parse(btn.getAttribute('data-itens') || '[]');
        const resumo = JSON.parse(btn.getAttribute('data-resumo') || '{}');
        const foto = btn.getAttribute('data-foto') || '';
        const nome = btn.getAttribute('data-nome') || 'Sem nome';
        const msgAluno = (btn.getAttribute('data-msg') || '').trim();
        const prazoCarrinho = (btn.getAttribute('data-prazo-carrinho') || '').trim();
        const dataCarrinho = (btn.getAttribute('data-data-carrinho') || '').trim();
        const propostaCarrinho = (btn.getAttribute('data-proposta-carrinho') || '').trim();

        document.getElementById('previewTitulo').textContent = resumo.numero ? `Pedido #${resumo.numero}` : 'Pedido';
        const statusEl = document.getElementById('previewStatus');
        statusEl.textContent = resumo.status || 'Não informado';
        statusEl.className = 'status-badge ' + (resumo.status_classe || 'pendente');
        document.getElementById('previewNome').textContent = nome;
        document.getElementById('previewFoto').innerHTML = foto
            ? `<img src="${escapeHtml(foto)}" alt="" class="preview-avatar">`
            : '<div class="preview-avatar-placeholder" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg></div>';

        const cadastro = [resumo.matricula ? `Matrícula: ${resumo.matricula}` : '', resumo.turma ? `Turma: ${resumo.turma}` : ''].filter(Boolean);
        document.getElementById('previewCadastro').textContent = cadastro.join(' · ');
        document.getElementById('previewCadastro').hidden = cadastro.length === 0;

        document.getElementById('previewCriacao').textContent = formatDateBr(resumo.data_criacao) || 'Não informada';
        document.getElementById('previewPrazo').textContent = prazoCarrinho || 'Não informado';
        document.getElementById('previewDevolucao').textContent = formatDateBr(resumo.data_devolucao_prevista) || 'A definir';
        document.getElementById('previewResponsavel').textContent = resumo.responsavel || 'Aguardando atendimento';

        const datasOpcionais = {
            previewDataProposta: formatDateBr(dataCarrinho) !== formatDateBr(resumo.data_devolucao_prevista) ? dataCarrinho : '',
            previewRetiradaPrevista: resumo.data_retirada_prevista,
            previewRetiradaReal: resumo.data_retirada_real,
            previewDevolucaoReal: resumo.data_devolucao_real,
        };
        Object.entries(datasOpcionais).forEach(([id, valor]) => {
            const data = formatDateBr(valor);
            document.getElementById(id).textContent = data;
            document.getElementById(id + 'Wrap').hidden = data === '';
        });

        /* Monta os itens */
        let itensHtml = '';
        if (itens.length > 0) {
            itensHtml = itens.map(item => {
                const imgHtml = item.imagem_url
                    ? `<img src="${escapeHtml(item.imagem_url)}" alt="${escapeHtml(item.nome_comp)}" class="preview-item-img">`
                    : '<div class="preview-item-placeholder">📦</div>';
                return `
                    <a href="./catalogo.php?id=${encodeURIComponent(item.id_comp)}" class="preview-item">
                        ${imgHtml}
                        <div class="preview-item-info">
                            <p class="preview-item-nome">${escapeHtml(item.nome_comp)}</p>
                            <p class="preview-item-qtd">Quantidade solicitada: ${escapeHtml(String(item.qtd_solicitada))}${item.qtd_aprovada != null ? ` · Aprovada: ${escapeHtml(String(item.qtd_aprovada))}` : ''}</p>
                        </div>
                    </a>
                `;
            }).join('');
        } else {
            itensHtml = '<p class="preview-empty">Nenhum item solicitado.</p>';
        }

        document.getElementById('previewItems').innerHTML = itensHtml;
        document.getElementById('previewItensTotal').textContent = `${itens.length} ${itens.length === 1 ? 'material' : 'materiais'}`;

        const temPrazoEstruturado = prazoCarrinho !== '' || dataCarrinho !== '' || propostaCarrinho !== '';
        const mostrarJustificativa = propostaCarrinho !== '' || /7\s*\+/.test(prazoCarrinho);
        document.getElementById('previewJustificativaWrap').hidden = !mostrarJustificativa;
        document.getElementById('previewJustificativa').textContent = propostaCarrinho || 'Não informada pelo estudante.';
        document.getElementById('previewMsgWrap').hidden = temPrazoEstruturado || msgAluno === '';
        document.getElementById('previewMensagemAluno').textContent = msgAluno;

        previewTrigger = btn;
        const modal = document.getElementById('modalPreview');
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        modal.querySelector('.preview-body').scrollTop = 0;
        document.body.classList.add('preview-pedido-aberto');
        document.getElementById('previewFechar').focus();
    }

    function fecharPreview() {
        const modal = document.getElementById('modalPreview');
        if (!modal.classList.contains('open')) return;
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('preview-pedido-aberto');
        if (previewTrigger) previewTrigger.focus();
    }

    document.getElementById('modalPreview').addEventListener('keydown', function (e) {
        if (e.key !== 'Tab') return;
        const controles = this.querySelectorAll('a[href], button');
        const primeiro = controles[0];
        const ultimo = controles[controles.length - 1];
        if (e.shiftKey && document.activeElement === primeiro) {
            e.preventDefault();
            ultimo.focus();
        } else if (!e.shiftKey && document.activeElement === ultimo) {
            e.preventDefault();
            primeiro.focus();
        }
    });

    function abrirRenovacaoPedido(btn) {
        const idPedido = Number(btn.getAttribute('data-pedido') || '0');
        const itens = JSON.parse(btn.getAttribute('data-itens') || '[]');
        const foto = btn.getAttribute('data-foto') || '';
        const nome = btn.getAttribute('data-nome') || 'Aluno';
        const opcaoPrazo = (btn.getAttribute('data-opcao') || '').trim();
        const proposta = (btn.getAttribute('data-proposta') || '').trim();
        const dataSug = (btn.getAttribute('data-data') || '').trim();
        const justificativa = (btn.getAttribute('data-justificativa') || '').trim();

        const headerHtml = foto
            ? `<img src="${foto}" alt="${nome}" class="preview-avatar">`
            : '<div class="preview-avatar-placeholder">👤</div>';

        document.getElementById('renovHeader').innerHTML =
            headerHtml + `<p class="preview-nome">${escapeHtml(nome)}</p>`;

        let itensHtml = '';
        if (itens.length > 0) {
            itensHtml = itens.map(item => {
                const imgHtml = item.imagem_url
                    ? `<img src="${escapeHtml(item.imagem_url)}" alt="${escapeHtml(item.nome_comp)}" class="preview-item-img">`
                    : '<div class="preview-item-placeholder">📦</div>';
                return `
                    <div class="preview-item">
                        ${imgHtml}
                        <div class="preview-item-info">
                            <p class="preview-item-nome">${escapeHtml(item.nome_comp)}</p>
                            <p class="preview-item-qtd">Quantidade: ${item.qtd_solicitada}</p>
                        </div>
                    </div>
                `;
            }).join('');
        } else {
            itensHtml = '<p class="preview-empty">Nenhum item solicitado.</p>';
        }
        document.getElementById('renovItems').innerHTML = itensHtml;

        document.getElementById('renovConcordarId').value = idPedido > 0 ? String(idPedido) : '';
        document.getElementById('renovDiscordarId').value = idPedido > 0 ? String(idPedido) : '';

        const intervencao = `O aluno, ${nome} está querendo ${proposta}.`;
        document.getElementById('renovIntervencao').innerHTML = escapeHtml(intervencao);

        const dataSeteMais = document.getElementById('renovDataSeteMais');
        if (opcaoPrazo === '7+' && dataSug !== '') {
            const dataBr = formatDateBr(dataSug);
            dataSeteMais.style.display = 'block';
            dataSeteMais.textContent = `DATA QUE O ALUNO QUER: ${dataBr}`;
        } else {
            dataSeteMais.style.display = 'none';
            dataSeteMais.textContent = '';
        }

        const textoProposta = justificativa !== ''
            ? `Segue a proposta do aluno ${nome}, para aumento da data: ${justificativa}`
            : `Segue a proposta do aluno ${nome}, para aumento da data: sem justificativa informada.`;
        document.getElementById('renovProposta').innerHTML = escapeHtml(textoProposta);

        document.getElementById('modalRenovacaoPedido').classList.add('open');
    }

    function fecharRenovacaoPedido() {
        document.getElementById('modalRenovacaoPedido').classList.remove('open');
    }

    let pedidoNotaAtual = null;

    async function abrirNotaAtraso(idPedido) {
        pedidoNotaAtual = idPedido;

        const modal = document.getElementById('modalNotaAtraso');
        const chat = document.getElementById('notaChat');
        const header = document.getElementById('notaHeader');
        const erro = document.getElementById('notaErro');

        chat.innerHTML = '<p class="nota-empty">Carregando nota...</p>';
        header.innerHTML = '';
        erro.textContent = '';
        modal.classList.add('open');

        try {
            const params = new URLSearchParams({ acao: 'buscar', id_pedido: String(idPedido) });
            const res = await fetch('../api/pedido_atraso_nota.php?' + params.toString());
            const data = await res.json();

            if (!data.ok) {
                erro.textContent = data.erro || 'Não foi possível carregar a nota.';
                chat.innerHTML = '<p class="nota-empty">Não foi possível carregar as mensagens.</p>';
                return;
            }

            const aluno = data.aluno || {};
            const foto = aluno.foto || '';
            const nome = aluno.nome || 'Aluno';
            const email = (aluno.email || '').trim();
            const atrasoDias = Number(data.dias_atraso || 0);
            const msgAutomatica = `${nome}, ${atrasoDias} dia(s) de atraso! Caso fique mais tempo sem devolver, vamos ter que bloquear seu acesso. Você está pré-bloqueado, no momento. Devolva o pacote ou contate alguém da CTI para mais informações.`;

            header.innerHTML = `
                ${foto ? `<img src="${escapeHtml(foto)}" alt="${escapeHtml(nome)}" class="nota-avatar">` : '<div class="nota-avatar-placeholder">👤</div>'}
                <div class="nota-header-text">
                    <p class="nota-nome">${escapeHtml(nome)}</p>
                    <p class="nota-prazo">${atrasoDias > 0 ? `${escapeHtml(nome)}, ${atrasoDias} dia(s) de atraso!` : 'Pedido dentro do prazo nos 3 riscos.'}</p>
                </div>
            `;

            const emailSeguro = email !== '' ? escapeHtml(email) : 'email não informado';
            const emailLink = email !== ''
                ? `<a href="mailto:${encodeURIComponent(email)}" style="color:var(--link-cor);text-decoration:underline;">Contate esse e-mail clicando aqui</a>`
                : 'E-mail do aluno não informado.';

            chat.innerHTML = `
                <div class="baloon lab" style="max-width:100%;align-self:stretch;">
                    Mensagem automática enviada:<br>
                    <strong>${escapeHtml(msgAutomatica)}</strong><br><br>
                    Caso queira saber mais informações sobre esse usuário, acesse o e-mail dele: <strong>${emailSeguro}</strong><br>
                    ${emailLink}
                </div>
            `;

            if (data.atrasado) {
                erro.textContent = 'Aluno em atraso! A notificação automática já foi enviada. Para continuar o contato, use o e-mail do aluno.';
            } else {
                erro.textContent = 'Pedido dentro do prazo.';
            }
        } catch (_) {
            erro.textContent = 'Falha de comunicação com o servidor.';
            chat.innerHTML = '<p class="nota-empty">Não foi possível carregar as mensagens.</p>';
        }
    }

    function fecharNotaAtraso() {
        document.getElementById('modalNotaAtraso').classList.remove('open');
    }

    document.getElementById('modalNotaAtraso').addEventListener('click', function (e) {
        if (e.target === this) fecharNotaAtraso();
    });

    

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatDateBr(rawDate) {
        const txt = String(rawDate || '').trim();
        if (!txt || txt.startsWith('0000-00-00')) return '';

        const isoMatch = txt.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T]\d{2}:\d{2}:\d{2})?$/);
        if (isoMatch) {
            return `${isoMatch[3]}/${isoMatch[2]}/${isoMatch[1]}`;
        }

        const brMatch = txt.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
        if (brMatch) {
            return txt;
        }

        return txt;
    }

    /* Fecha preview ao clicar no overlay */
    document.getElementById('modalPreview').addEventListener('click', function (e) {
        if (e.target === this) fecharPreview();
    });

    document.getElementById('modalRenovacaoPedido').addEventListener('click', function (e) {
        if (e.target === this) fecharRenovacaoPedido();
    });
</script>

</body>
</html>
