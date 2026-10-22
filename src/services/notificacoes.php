<?php
declare(strict_types=1);

function resumoNotificacoesNaoLidas(PDO $pdo, int $idUsuario, string $perfil): array
{
    $sql = "SELECT
        SUM(CASE WHEN tipo IN ('aviso', 'aviso_adm') THEN 1 ELSE 0 END) AS avisos,
        SUM(CASE WHEN tipo IN ('aviso', 'aviso_adm') THEN 0 ELSE 1 END) AS pedidos
        FROM Notificacao WHERE id_user = :id AND COALESCE(lida, 0) = 0";
    if ($perfil === 'laboratorista') $sql .= " AND tipo IN ('aviso', 'aviso_adm')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $idUsuario]);
    $contagem = $stmt->fetch(PDO::FETCH_ASSOC);
    $avisos = (int) ($contagem['avisos'] ?? 0);
    $pedidos = (int) ($contagem['pedidos'] ?? 0);
    return ['total' => $avisos + $pedidos, 'avisos' => $avisos, 'pedidos' => $pedidos];
}

/** Separa também avisos antigos, que gravavam remetente e texto na mesma linha. */
function separarRemetenteNotificacao(string $mensagem): array
{
    if (preg_match('/\A\s*((?:Admin(?:istrador)?|Laboratorista)\s+[^\r\n]+?\s+te enviou:)\s*(.*)\z/isu', $mensagem, $partes)) {
        return ['remetente' => $partes[1], 'mensagem' => $partes[2]];
    }
    return ['remetente' => '', 'mensagem' => $mensagem];
}
