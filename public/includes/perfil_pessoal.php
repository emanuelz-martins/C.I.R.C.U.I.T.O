<?php
declare(strict_types=1);

const PERFIL_DESCRICAO_LIMITE = 500;

function tokenPerfilPessoal(): string
{
    if (empty($_SESSION['csrf_perfil_pessoal'])) {
        $_SESSION['csrf_perfil_pessoal'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_perfil_pessoal'];
}

function validarCsrfPerfilPessoal(): void
{
    $token = $_POST['csrf_perfil'] ?? '';
    if (!is_string($token) || empty($_SESSION['csrf_perfil_pessoal'])
        || !hash_equals($_SESSION['csrf_perfil_pessoal'], $token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'erro' => 'Sessão expirada. Atualize a página e tente novamente.']);
        exit;
    }
}
