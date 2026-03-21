<?php

declare(strict_types=1);

/*
 * TESTE DE CONEXÃO COM BANCO (XAMPP / MySQL)
 *
 * O que deve acontecer ao executar este arquivo:
 * 1) Ele carrega `src/config/database.php`.
 * 2) Chama a função `db()` para abrir a conexão PDO.
 * 3) Executa `SELECT 1` só para validar se o banco responde.
 * 4) Se tudo der certo, mostra mensagem de sucesso.
 * 5) Se falhar, mostra a mensagem de erro da conexão.
 */

require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = db();
    $resultado = $pdo->query('SELECT 1 AS ok')->fetch();

    echo '<h2>✅ Conexão com o banco realizada com sucesso.</h2>';
    echo '<p>Banco respondeu ao teste SELECT 1.</p>';
    echo '<pre>' . htmlspecialchars(print_r($resultado, true), ENT_QUOTES, 'UTF-8') . '</pre>';
} catch (Throwable $e) {
    echo '<h2>❌ Falha na conexão com o banco.</h2>';
    echo '<p>Verifique arquivo .env, MySQL do XAMPP e existência do banco <strong>circuito</strong>.</p>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
}
