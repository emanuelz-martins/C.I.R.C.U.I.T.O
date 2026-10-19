<?php
declare(strict_types=1);

/** Testa o schema somente em bancos temporários no MySQL local do XAMPP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/config/database.php';
loadEnv(dirname(__DIR__) . '/.env');
if (env('APP_ENV') !== 'local' || env('DB_PROFILE') !== 'xampp'
    || !in_array(env('DB_HOST'), ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Execute somente no MySQL local do XAMPP.');
}
$pdo = new PDO('mysql:host=' . env('DB_HOST') . ';port=' . env('DB_PORT', '3306') . ';charset=utf8mb4', env('DB_USERNAME'), env('DB_PASSWORD', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$schema = file_get_contents(__DIR__ . '/../src/database/seeds/schema.sql');
$bancos = [];
$checks = 0;
function conferirSchema(bool $ok, string $descricao): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FALHOU: ' . $descricao);
    $checks++;
    echo "OK: $descricao\n";
}
function executarSchema(PDO $pdo, string $sql): void {
    foreach (preg_split('/;[\t ]*(?:\r?\n|\z)/', $sql) as $comando) {
        if (trim($comando) === '') continue;
        $stmt = $pdo->query($comando);
        $stmt->closeCursor();
    }
}
try {
    foreach (['novo', 'legado'] as $cenario) {
        $banco = 'circuito_schema_teste_' . bin2hex(random_bytes(6));
        $pdo->exec("CREATE DATABASE `$banco` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $bancos[] = $banco;
        $pdo->exec("USE `$banco`");
        $originais = [];
        if ($cenario === 'legado') {
            $pdo->exec("CREATE TABLE Usuario (
                id_user INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                nome VARCHAR(150) NOT NULL, login VARCHAR(100) NOT NULL UNIQUE,
                hash_senha VARCHAR(255) NOT NULL, matricula VARCHAR(50) NULL,
                tipo_perfil VARCHAR(30) NOT NULL DEFAULT 'laboratorista',
                bloqueado TINYINT(1) NOT NULL DEFAULT 0,
                foto_perfil VARCHAR(255) NULL, email VARCHAR(150) NULL,
                preferencias_notific VARCHAR(255) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            foreach (['admin', 'laboratorista', 'estudante'] as $indice => $perfil) {
                $pdo->prepare('INSERT INTO Usuario(nome,login,hash_senha,tipo_perfil,foto_perfil,email) VALUES(?,?,?,?,?,?)')->execute(['Conta ' . $perfil, '000.000.000-0' . $indice, 'hash-preservado-' . $indice, $perfil, 'foto-preservada.png', 'teste' . $indice . '@example.invalid']);
            }
            $originais = $pdo->query('SELECT id_user,nome,login,hash_senha,tipo_perfil,foto_perfil,email FROM Usuario ORDER BY id_user')->fetchAll();
        }
        executarSchema($pdo, $schema);
        conferirSchema(true, 'schema executa em banco ' . $cenario);
        $esperados = [
            'Usuario' => ['forma_login','google_sub','foto_perfil','email','turma','descricao','pre_bloqueado_manual','pre_bloqueio_motivo','pre_bloqueado_por_nome','pre_bloqueado_por_tipo','pre_bloqueado_em'],
            'Notificacao' => ['tipo','humor','id_pedido','id_nota_atraso','requer_resposta','resposta_pendente'],
            'Pedido' => ['id_laboratorista_responsavel','nome_laboratorista_responsavel','fluxo_livre_laboratoristas'],
        ];
        foreach ($esperados as $tabela => $colunas) {
            $existentes = $pdo->query("SHOW COLUMNS FROM `$tabela`")->fetchAll(PDO::FETCH_COLUMN);
            conferirSchema(!array_diff($colunas, $existentes), "$cenario: $tabela contém todas as colunas desta versão");
        }
        $colGoogle = $pdo->query("SHOW FULL COLUMNS FROM Usuario LIKE 'google_sub'")->fetch();
        conferirSchema($colGoogle['Collation'] === 'ascii_bin', "$cenario: identidade Google distingue maiúsculas e minúsculas");
        conferirSchema($pdo->query("SHOW COLUMNS FROM Usuario LIKE 'foto_perfil'")->fetch()['Type'] === 'text', "$cenario: foto aceita URLs longas");
        conferirSchema(!$pdo->query("SHOW COLUMNS FROM Usuario LIKE 'cpf'")->fetch(), "$cenario: não cria coluna cpf");
        conferirSchema($pdo->query("SHOW COLUMNS FROM Usuario LIKE 'tipo_perfil'")->fetch()['Default'] === 'estudante', "$cenario: perfil padrão é estudante");
        $indice = $pdo->query("SHOW INDEX FROM Usuario WHERE Key_name='uq_usuario_google_sub'")->fetch();
        conferirSchema($indice && (int) $indice['Non_unique'] === 0, "$cenario: identidade Google possui índice único");
        conferirSchema($pdo->query('SELECT COUNT(*) FROM Aviso_Usuario_Fixado')->fetchColumn() == 0, "$cenario: tabela de fixações disponível sem dados de teste");
        executarSchema($pdo, $schema);
        conferirSchema(true, "$cenario: segunda execução não falha nem duplica colunas/índices");
        $linhas = $pdo->query('SELECT id_user,nome,login,hash_senha,tipo_perfil,foto_perfil,email FROM Usuario ORDER BY id_user')->fetchAll();
        conferirSchema($linhas === $originais, "$cenario: dados e perfis anteriores preservados");
        $pdo->prepare("INSERT INTO Usuario(nome,login,hash_senha,google_sub,forma_login,foto_perfil) VALUES('Conta Google', ?, 'hash-teste', 'google-identidade-teste', 'google', ?)")->execute(['google-identificador', 'https://lh3.googleusercontent.com/' . str_repeat('a', 350)]);
        conferirSchema($pdo->query("SELECT tipo_perfil FROM Usuario WHERE google_sub='google-identidade-teste'")->fetchColumn() === 'estudante', "$cenario: nova conta Google começa como aluno");
        $duplicadoRecusado = false;
        try {
            $pdo->exec("INSERT INTO Usuario(nome,login,hash_senha,google_sub) VALUES('Duplicada','outra-conta','hash','google-identidade-teste')");
        } catch (PDOException $e) { $duplicadoRecusado = $e->getCode() === '23000'; }
        conferirSchema($duplicadoRecusado, "$cenario: banco recusa identidade Google duplicada");
    }
} finally {
    $pdo->exec('USE information_schema');
    foreach ($bancos as $banco) {
        if (!preg_match('/^circuito_schema_teste_[a-f0-9]{12}$/D', $banco)) throw new RuntimeException('Nome de banco temporário inválido.');
        $pdo->exec("DROP DATABASE `$banco`");
    }
}
echo "\n$checks verificações do schema passaram; bancos temporários removidos.\n";
