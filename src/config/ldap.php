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

/**
 * Autentica usuário em servidor LDAP institucional.
 *
 * Retorna true quando usuário/senha estão corretos.
 * Retorna false em qualquer falha (sem vazar detalhes sensíveis).
 */
if (!function_exists('authLdap')) {
	function authLdap(string $user, string $pass): bool
	{
		loadEnv(dirname(__DIR__, 2) . '/.env');

		$host = env('LDAP_HOST', 'ldap://127.0.0.1');
		$port = (int) env('LDAP_PORT', '389');

		// Exemplo: CN=v,DC=w,DC=x,DC=y,DC=z
		$baseDn = env('LDAP_BASE_DN', 'CN=v,DC=w,DC=x,DC=y,DC=z');
		$bindPattern = env('LDAP_BIND_PATTERN', 'CN=%s,' . $baseDn);

		if ($user === '' || $pass === '') {
			return false;
		}

		if (!function_exists('ldap_connect')) {
			return false;
		}

		$ldapcon = @ldap_connect($host, $port);

		if ($ldapcon === false) {
			return false;
		}

		ldap_set_option($ldapcon, LDAP_OPT_PROTOCOL_VERSION, 3);
		ldap_set_option($ldapcon, LDAP_OPT_REFERRALS, 0);

		$bindDn = sprintf($bindPattern, $user);
		$bind = @ldap_bind($ldapcon, $bindDn, $pass);

		ldap_close($ldapcon);

		return (bool) $bind;
	}
}
