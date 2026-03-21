<?php

declare(strict_types=1);

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

			if ($line === '' || str_starts_with($line, '#')) {
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

if (!function_exists('db')) {
	function db(): PDO
	{
		static $pdo = null;

		if ($pdo instanceof PDO) {
			return $pdo;
		}

		loadEnv(dirname(__DIR__, 2) . '/.env');

		$host = env('DB_HOST', '127.0.0.1');
		$port = env('DB_PORT', '3306');
		$database = env('DB_DATABASE', 'circuito');
		$username = env('DB_USERNAME', 'root');
		$password = env('DB_PASSWORD', '');

		$dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

		$pdo = new PDO(
			$dsn,
			$username,
			$password,
			[
				PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_EMULATE_PREPARES   => false,
			]
		);

		return $pdo;
	}
}
