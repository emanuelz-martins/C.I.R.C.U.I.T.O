<?php
//SERVIDOR LOCAL TIPO XAMP E NOSSOS AMIGOS
declare(strict_types=1);

require_once __DIR__ . '/database_always.php';

if (!function_exists('env')) {
	function env(string $key, ?string $default = null): ?string
	{
		$value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

		if ($value === false || $value === null || $value === '') {
			return $default;
		}

		return (string) $value;
	}
}

if (!function_exists('loadEnv')) {
	function loadEnv(string $path): void
	{
		if (!is_readable($path)) {
			return;
		}

		$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

		if ($lines === false) {
			return;
		}

		foreach ($lines as $line) {
			$line = trim($line);

			if ($line === '' || substr($line, 0, 1) === '#') {
				continue;
			}

			[$key, $value] = array_pad(explode('=', $line, 2), 2, '');
			$key = trim($key);
			$value = trim($value, " \t\n\r\0\x0B\"");

			if ($key === '' || isset($_ENV[$key])) {
				continue;
			}

			$_ENV[$key] = $value;
			$_SERVER[$key] = $value;
			putenv("{$key}={$value}");
		}
	}
}

if (!function_exists('isAlwaysDataHost')) {
	function isAlwaysDataHost(): bool
	{
		$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));

		if ($host === '') {
			return false;
		}

		return str_contains($host, 'alwaysdata.net');
	}
}

if (!function_exists('dbXampp')) {
	function dbXampp(): PDO
	{
		static $pdoXampp = null;

		if ($pdoXampp instanceof PDO) {
			return $pdoXampp;
		}

		loadEnv(dirname(__DIR__, 2) . '/.env');

		$host = env('DB_HOST', '127.0.0.1');
		$port = env('DB_PORT', '3306');
		$database = env('DB_DATABASE', 'circuito');
		$username = env('DB_USERNAME', 'root');
		$password = env('DB_PASSWORD', '');

		$dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

		$pdoXampp = new PDO(
			$dsn,
			$username,
			$password,
			[
				PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_EMULATE_PREPARES   => false,
			]
		);

		return $pdoXampp;
	}
}

if (!function_exists('db')) {
	function db(): PDO
	{
		loadEnv(dirname(__DIR__, 2) . '/.env');

		$defaultProfile = isAlwaysDataHost() ? 'alwaysdata' : 'xampp';
		$profile = strtolower((string) env('DB_PROFILE', $defaultProfile));

		if ($profile === 'alwaysdata') {
			return dbAlwaysData();
		}

		return dbXampp();
	}
}
