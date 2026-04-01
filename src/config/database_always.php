<?php
//ALWEYSDATA CONEXÃO
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

if (!function_exists('dbAlwaysData')) {
	function dbAlwaysData(): PDO
	{
		static $pdoAlways = null;

		if ($pdoAlways instanceof PDO) {
			return $pdoAlways;
		}

		loadEnv(dirname(__DIR__, 2) . '/.env');

		$host = env('ALWAYSDATA_DB_HOST', env('DB_HOST', 'ftp-circuito.alwaysdata.net'));
		$port = env('ALWAYSDATA_DB_PORT', env('DB_PORT', '3306'));
		$database = env('ALWAYSDATA_DB_DATABASE', env('DB_DATABASE', 'circuito-circuito'));
		$username = env('ALWAYSDATA_DB_USERNAME', env('DB_USERNAME', 'circuito'));
		$password = env('ALWAYSDATA_DB_PASSWORD', env('DB_PASSWORD', ''));

		$dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

		$pdoAlways = new PDO(
			$dsn,
			$username,
			$password,
			[
				PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_EMULATE_PREPARES   => false,
			]
		);

		return $pdoAlways;
	}
}
