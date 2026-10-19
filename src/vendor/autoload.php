<?php
// PSR-4 da dependência versionada firebase/php-jwt. Nenhum download em produção.
spl_autoload_register(static function (string $classe): void {
    $prefixo = 'Firebase\\JWT\\';
    if (str_starts_with($classe, $prefixo)) {
        $arquivo = __DIR__ . '/php-jwt/src/' . substr($classe, strlen($prefixo)) . '.php';
        if (is_file($arquivo)) require_once $arquivo;
    }
});
