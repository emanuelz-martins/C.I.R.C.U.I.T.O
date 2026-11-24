<?php
// Autoload das dependências versionadas JWT e PHPMailer. Nenhum download em produção.
spl_autoload_register(static function (string $classe): void {
    $mailPrefixo = 'PHPMailer\\PHPMailer\\';
    if (str_starts_with($classe, $mailPrefixo)) {
        $arquivo = __DIR__ . '/phpmailer/src/' . substr($classe, strlen($mailPrefixo)) . '.php';
        if (is_file($arquivo)) require_once $arquivo;
        return;
    }
    $prefixo = 'Firebase\\JWT\\';
    if (str_starts_with($classe, $prefixo)) {
        $arquivo = __DIR__ . '/php-jwt/src/' . substr($classe, strlen($prefixo)) . '.php';
        if (is_file($arquivo)) require_once $arquivo;
    }
});
