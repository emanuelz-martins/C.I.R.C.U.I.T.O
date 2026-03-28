<?php
require_once '../includes/auth_check.php';
checkAccess(['laboratorista', 'admin']);

require_once '../../src/config/database.php';

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

            /* Cria notificação para o estudante */
            $notify = static function (
                PDO $pdo,
                int $id_pedido,
                int $id_user,
                string $mensagem,
                string $tipo,
                array $notCols
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

            switch ($action) {

                case 'aprovar':
                    /* Aprovação → muda status para em-separacao e notifica o estudante */
                    $statusAtualPedido = $getPedidoStatus($pdo, $id_pedido, $statusCol);

                    if ($statusAtualPedido !== 'renovacao-solicitada') {
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
                    }

                    $setStatus($pdo, $id_pedido, 'em-separacao', $statusCol, $updatedCol);

                    $uid = $getUserId($pdo, $id_pedido);
                    if ($uid) {
                        $notify($pdo, $id_pedido, $uid, 'Pedido aprovado!', 'aprovado', $notCols);
                    }

                    if ($pdo->inTransaction()) {
                        $pdo->commit();
                    }
                    break;

                case 'negar':
                    /* Negação → justificativa obrigatória */
                    if ($justificativa === '') {
                        header('Location: ./acessar.php?erro=justificativa');
                        exit;
                    }
                    $setStatus($pdo, $id_pedido, 'negado', $statusCol, $updatedCol, $motivoCol, $justificativa);

                    $uid = $getUserId($pdo, $id_pedido);
                    if ($uid) {
                        $notify($pdo, $id_pedido, $uid,
                            'Pedido negado. Justificativa: ' . $justificativa, 'negado', $notCols);
                    }
                    break;

                case 'pronto-para-retirada':
                    /* Pacote preparado fisicamente → notifica estudante para buscar */
                    $setStatus($pdo, $id_pedido, 'pronto-para-retirada', $statusCol, $updatedCol);

                    $uid = $getUserId($pdo, $id_pedido);
                    if ($uid) {
                        $notify($pdo, $id_pedido, $uid,
                            'Seu pedido está pronto para retirada! Dirija-se ao laboratório para buscar.',
                            'pronto-para-retirada', $notCols);
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

    $usuarioCols   = $getCols($pdo, 'Usuario');
    $fotoPerfilSql = in_array('foto_perfil', $usuarioCols, true) ? 'u.foto_perfil' : 'NULL';

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

    $orderBy = $dataAtualizacaoCol !== null ? 'p.' . $dataAtualizacaoCol . ' DESC, p.id_pedido DESC' : 'p.id_pedido DESC';

    $stmt = $pdo->prepare("
        SELECT
            p.id_pedido,
            {$selectNumero},
            {$selectStatus},
            {$selectData},
            u.nome AS nome_estudante,
            {$fotoPerfilSql} AS foto_perfil_estudante
        FROM Pedido p
        JOIN Usuario u ON u.id_user = p.id_user
        {$w}
        ORDER BY {$orderBy}
    ");
    $stmt->execute($params);
    $pedidos = $stmt->fetchAll();
    $db_ok   = true;

    /* ── Itens por pedido (para o pop-up de preview) ─ */
    $itens_por_pedido = [];
    if (!empty($pedidos)) {
        $ids          = array_column($pedidos, 'id_pedido');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $stmtItens = $pdo->prepare("
                SELECT ip.id_pedido, ip.qtd_solicitada, c.id_comp, c.nome AS nome_comp, c.imagem_url
                FROM Item_Pedido ip
                JOIN Componente c ON c.id_comp = ip.id_comp
                WHERE ip.id_pedido IN ({$placeholders})
            ");
            $stmtItens->execute($ids);
            foreach ($stmtItens->fetchAll() as $row) {
                $itens_por_pedido[(int) $row['id_pedido']][] = $row;
            }
        } catch (Throwable) { /* ignora se tabelas não existem */ }
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
        color: #ffffff;
        line-height: 1.1;
    }

    .btn-back {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background-color: #2a2a2a;
        border: 1px solid #3a3a3a;
        color: #ffffff;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        transition: background-color 0.15s;
        flex-shrink: 0;
    }

    .btn-back:hover { background-color: #333; }
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
        background-color: #1e1e1e;
        border: 1.5px solid #333;
        border-radius: 50px;
        color: #ffffff;
        font-size: 0.9rem;
        outline: none;
        transition: border-color 0.2s;
    }

    .search-wrap input::placeholder { color: #666; }
    .search-wrap input:focus        { border-color: #555; }

    .search-wrap button {
        position: absolute;
        right: 16px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: #aaa;
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
        border: 1.5px solid #ffffff;
        border-radius: 50px;
        color: #ffffff;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        white-space: nowrap;
        transition: background-color 0.15s;
    }

    .btn-filtrar:hover { background-color: #1e1e1e; }

    /* Painel de filtros */
    .filtros-panel {
        display: none;
        background-color: #1c1c1c;
        border: 1px solid #2e2e2e;
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
        color: #aaa;
        margin-right: 4px;
    }

    .filtros-panel select {
        padding: 8px 14px;
        background-color: #2a2a2a;
        border: 1px solid #3a3a3a;
        border-radius: 8px;
        color: #ffffff;
        font-size: 0.85rem;
        cursor: pointer;
        outline: none;
    }

    .filtros-panel .btn-limpar {
        padding: 8px 18px;
        background: none;
        border: 1px solid #444;
        border-radius: 8px;
        color: #aaa;
        font-size: 0.85rem;
        cursor: pointer;
        text-decoration: none;
        transition: border-color 0.15s, color 0.15s;
    }

    .filtros-panel .btn-limpar:hover { border-color: #888; color: #fff; }

    /* ── Alerta de erro ───────────────────── */
    .alert-erro {
        background-color: #1c0a0a;
        border: 1px solid #7f1d1d;
        border-radius: 12px;
        padding: 14px 20px;
        color: #fca5a5;
        font-size: 0.9rem;
        margin-bottom: 24px;
    }

    /* ── Subtítulo seção ──────────────────── */
    .section-title {
        font-size: 1.4rem;
        font-weight: 700;
        color: #ffffff;
        margin-bottom: 20px;
    }

    /* ── Cards de pedido ──────────────────── */
    .pedidos-list {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }

    .pedido-card {
        background-color: #1c1c1c;
        border: 1px solid #2a2a2a;
        border-radius: 16px;
        padding: 22px 28px;
        display: flex;
        align-items: center;
        gap: 20px;
        transition: border-color 0.15s;
    }

    .pedido-card:hover { border-color: #3a3a3a; }

    .pedido-info {
        flex: 1;
        min-width: 0;
    }

    .pedido-numero {
        font-size: 1.1rem;
        font-weight: 700;
        color: #ffffff;
        margin-bottom: 4px;
    }

    .pedido-meta {
        font-size: 0.82rem;
        color: #888;
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

    .status-badge.pendente             { background-color: #78350f; color: #fde68a; }
    .status-badge.em-separacao         { background-color: #4c1d95; color: #ddd6fe; }
    .status-badge.pronto-para-retirada { background-color: #78350f; color: #fed7aa; }
    .status-badge.em-andamento         { background-color: #1e3a8a; color: #bfdbfe; }
    .status-badge.negado               { background-color: #7f1d1d; color: #fecaca; }
    .status-badge.cancelado            { background-color: #7f1d1d; color: #fecaca; }
    .status-badge.finalizado           { background-color: #14532d; color: #bbf7d0; }
    .status-badge.renovacao-solicitada { background-color: #713f12; color: #fef08a; }

    /* ── Botão de menu (3 risquinhos) ──────── */
    .btn-menu {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        background-color: #2a2a2a;
        border: 1px solid #3a3a3a;
        color: #ffffff;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        position: relative;
        transition: background-color 0.15s;
    }

    .btn-menu:hover { background-color: #333; }
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
        background-color: #1e1e1e;
        border: 1px solid #2e2e2e;
        border-radius: 12px;
        min-width: 210px;
        overflow: hidden;
        box-shadow: 0 8px 32px rgba(0,0,0,0.6);
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
        border-bottom: 1px solid #2a2a2a;
        color: #ffffff;
        font-size: 0.88rem;
        cursor: pointer;
        text-align: left;
        transition: background-color 0.15s;
        font-family: inherit;
    }

    .menu-item:last-child { border-bottom: none; }
    .menu-item:hover      { background-color: #2a2a2a; }

    .menu-item svg { width: 16px; height: 16px; flex-shrink: 0; }

    .menu-item.danger       { color: #f87171; }
    .menu-item.danger:hover { background-color: #2a1414; }

    .menu-item.success       { color: #4ade80; }
    .menu-item.success:hover { background-color: #0d2018; }

    /* ── Estado vazio / erro BD ───────────── */
    .empty-state {
        text-align: center;
        padding: 60px 0;
        color: #555;
        font-size: 1rem;
    }

    .db-error {
        background-color: #1c0a0a;
        border: 1px solid #7f1d1d;
        border-radius: 16px;
        padding: 40px;
        text-align: center;
        color: #aaa;
    }

    .db-error h2 { color: #ef4444; margin-bottom: 8px; }

    /* ══════════════════════════════════════════
       MODAL (negação com justificativa)
    ══════════════════════════════════════════ */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background-color: rgba(0,0,0,0.75);
        z-index: 200;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .modal-overlay.open { display: flex; }

    .modal-box {
        background-color: #1e1e1e;
        border: 1px solid #333;
        border-radius: 20px;
        padding: 36px 40px;
        width: 100%;
        max-width: 480px;
    }

    .modal-title {
        font-size: 1.4rem;
        font-weight: 800;
        color: #ffffff;
        margin-bottom: 8px;
    }

    .modal-desc {
        font-size: 0.88rem;
        color: #aaa;
        margin-bottom: 24px;
        line-height: 1.6;
    }

    .modal-desc strong { color: #ffffff; }

    .modal-label {
        display: block;
        font-size: 0.85rem;
        font-weight: 600;
        color: #cccccc;
        margin-bottom: 8px;
    }

    .modal-textarea {
        width: 100%;
        min-height: 110px;
        padding: 12px 16px;
        background-color: #141414;
        border: 1.5px solid #333;
        border-radius: 10px;
        color: #ffffff;
        font-size: 0.9rem;
        font-family: inherit;
        resize: vertical;
        outline: none;
        transition: border-color 0.2s;
        margin-bottom: 6px;
    }

    .modal-textarea:focus { border-color: #555; }

    .modal-hint {
        font-size: 0.78rem;
        color: #666;
        margin-bottom: 24px;
    }

    .modal-hint.error { color: #f87171; }

    .modal-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }

    .btn-cancelar-modal {
        padding: 11px 24px;
        background: none;
        border: 1px solid #444;
        border-radius: 10px;
        color: #aaa;
        font-size: 0.88rem;
        cursor: pointer;
        font-family: inherit;
        transition: border-color 0.15s, color 0.15s;
    }

    .btn-cancelar-modal:hover { border-color: #888; color: #fff; }

    .btn-confirmar-negar {
        padding: 11px 24px;
        background-color: #7f1d1d;
        border: none;
        border-radius: 10px;
        color: #ffffff;
        font-size: 0.88rem;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
        transition: background-color 0.15s;
    }

    .btn-confirmar-negar:hover { background-color: #991b1b; }

    /* ── Botão olho (preview) ─────────────── */
    .btn-eye {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        background-color: #18191a;
        border: 1px solid #34373a;
        color: #ffffff;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: background-color 0.15s;
    }
    .btn-eye:hover { background-color: #2d2d2d; }
    .btn-eye svg   { width: 18px; height: 18px; pointer-events: none; }

    /* ── Modal preview do pedido ─────────── */
    .preview-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background-color: rgba(0,0,0,0.78);
        z-index: 300;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .preview-overlay.open { display: flex; }

    .preview-box {
        background-color: #1a1a1a;
        border: 1px solid #2e2e2e;
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
        border-bottom: 1px solid #2a2a2a;
        background-color: #141414;
    }

    .preview-avatar {
        width: 72px;
        height: 72px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #3a3a3a;
        background-color: #2a2a2a;
    }

    .preview-avatar-placeholder {
        width: 72px;
        height: 72px;
        border-radius: 50%;
        background-color: #2a2a2a;
        border: 2px solid #3a3a3a;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #666;
        flex-shrink: 0;
    }

    .preview-nome {
        font-size: 1rem;
        font-weight: 700;
        color: #ffffff;
    }

    .preview-body {
        padding: 20px 28px 4px;
        max-height: 360px;
        overflow-y: auto;
    }

    .preview-subtitle {
        font-size: 0.75rem;
        font-weight: 600;
        color: #666;
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
        background-color: #222;
        border: 1px solid #2e2e2e;
        border-radius: 12px;
        padding: 12px 16px;
    }

    .preview-item-img {
        width: 52px;
        height: 52px;
        border-radius: 8px;
        object-fit: cover;
        background-color: #2a2a2a;
        flex-shrink: 0;
    }

    .preview-item-placeholder {
        width: 52px;
        height: 52px;
        border-radius: 8px;
        background-color: #2a2a2a;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #555;
        flex-shrink: 0;
    }

    .preview-item-info  { flex: 1; min-width: 0; }

    .preview-item-nome {
        font-size: 0.9rem;
        font-weight: 600;
        color: #ffffff;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .preview-item-qtd {
        font-size: 0.8rem;
        color: #888;
        margin-top: 3px;
    }

    .preview-empty {
        text-align: center;
        color: #555;
        font-size: 0.9rem;
        padding: 20px 0;
    }

    .preview-close {
        display: flex;
        justify-content: flex-end;
        padding: 0 28px 22px;
    }

    .btn-fechar-preview {
        padding: 9px 22px;
        background: none;
        border: 1px solid #333;
        border-radius: 10px;
        color: #aaa;
        font-size: 0.85rem;
        cursor: pointer;
        font-family: inherit;
        transition: border-color 0.15s, color 0.15s;
    }
    .btn-fechar-preview:hover { border-color: #777; color: #fff; }

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
<div class="preview-overlay" id="modalPreview" role="dialog" aria-modal="true" aria-label="Detalhes do pedido">
    <div class="preview-box">
        <div class="preview-header" id="previewHeader">
            <!-- populado pelo JS -->
        </div>
        <div class="preview-body">
            <p class="preview-subtitle">Itens solicitados</p>
            <div class="preview-items-list" id="previewItems">
                <!-- populado pelo JS -->
            </div>
        </div>
        <div class="preview-close">
            <button class="btn-fechar-preview" onclick="fecharPreview()">Fechar</button>
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
        ?>
        <div class="pedido-card" id="card-<?= (int) $p['id_pedido'] ?>">

            <!-- Info -->
            <div class="pedido-info">
                <p class="pedido-numero">Pedido #<?= htmlspecialchars($numFmt) ?></p>
                <div class="pedido-meta">
                    <span>Pedido feito por: <strong style="color:#ccc"><?= htmlspecialchars($p['nome_estudante']) ?></strong></span>
                    <span>Data da última atualização: <?= htmlspecialchars($p['data_fmt'] ?? '—') ?></span>
                </div>
            </div>

            <!-- Ações -->
            <div class="pedido-actions">

                <!-- Badge de status -->
                <span class="status-badge <?= htmlspecialchars($statusInfo['class']) ?>">
                    <?= htmlspecialchars($statusInfo['label']) ?>
                </span>

                <!-- Olho: ver itens do pedido -->
                <button
                    class="btn-eye"
                    onclick="abrirPreview(this)"
                    data-itens="<?= $itensJson ?>"
                    data-foto="<?= $fotoEstud ?>"
                    data-nome="<?= $nomeEstud ?>"
                    aria-label="Ver itens do pedido"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                </button>

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

                <?php if (!empty($acoes)): ?>
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
    function abrirPreview(btn) {
        const itens = JSON.parse(btn.getAttribute('data-itens') || '[]');
        const foto = btn.getAttribute('data-foto') || '';
        const nome = btn.getAttribute('data-nome') || 'Sem nome';

        /* Monta o header */
        const headerHtml = foto
            ? `<img src="${foto}" alt="${nome}" class="preview-avatar">`
            : '<div class="preview-avatar-placeholder">👤</div>';

        document.getElementById('previewHeader').innerHTML = 
            headerHtml + `<p class="preview-nome">${escapeHtml(nome)}</p>`;

        /* Monta os itens */
        let itensHtml = '';
        if (itens.length > 0) {
            itensHtml = itens.map(item => {
                const imgHtml = item.imagem_url
                    ? `<img src="${escapeHtml(item.imagem_url)}" alt="${escapeHtml(item.nome_comp)}" class="preview-item-img">`
                    : '<div class="preview-item-placeholder">📦</div>';
                return `
                    <a href="./catalogo.php?id=${item.id_comp}" class="preview-item" style="text-decoration: none; color: inherit; cursor: pointer;">
                        ${imgHtml}
                        <div class="preview-item-info">
                            <p class="preview-item-nome">${escapeHtml(item.nome_comp)}</p>
                            <p class="preview-item-qtd">Quantidade: ${item.qtd_solicitada}</p>
                        </div>
                    </a>
                `;
            }).join('');
        } else {
            itensHtml = '<p class="preview-empty">Nenhum item solicitado.</p>';
        }

        document.getElementById('previewItems').innerHTML = itensHtml;
        document.getElementById('modalPreview').classList.add('open');
    }

    function fecharPreview() {
        document.getElementById('modalPreview').classList.remove('open');
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /* Fecha preview ao clicar no overlay */
    document.getElementById('modalPreview').addEventListener('click', function (e) {
        if (e.target === this) fecharPreview();
    });
</script>

</body>
</html>
