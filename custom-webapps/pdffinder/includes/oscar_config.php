<?php
/**
 * OSCAR / OpenOSP integration settings (read-only).
 * Credentials are server-side only — never sent to the browser.
 */

declare(strict_types=1);

/**
 * @return array{
 *   enabled: bool,
 *   db_host: string,
 *   db_port: int,
 *   db_name: string,
 *   db_user: string,
 *   db_password: string,
 *   document_path: string,
 *   document_subpath: string
 * }
 */
function pdf_finder_oscar_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $localFile = dirname(__DIR__) . '/config.oscar.local.php';
    if (is_file($localFile)) {
        require $localFile;
    }

    // Fallback: read MYSQL_ROOT_PASSWORD from OpenOSP local.env (pdffinder-only convenience).
    $openOspEnv = dirname(__DIR__, 3) . '/local.env';
    if (is_file($openOspEnv)) {
        $envVars = pdf_finder_load_dotenv_file($openOspEnv);
        if (!isset($OSCAR_DB_PASSWORD) || $OSCAR_DB_PASSWORD === '' || $OSCAR_DB_PASSWORD === 'your-mysql-password') {
            if (!empty($envVars['MYSQL_ROOT_PASSWORD'])) {
                $OSCAR_DB_PASSWORD = $envVars['MYSQL_ROOT_PASSWORD'];
            }
        }
    }
    if (!isset($OSCAR_DB_PASSWORD) || $OSCAR_DB_PASSWORD === '' || $OSCAR_DB_PASSWORD === 'your-mysql-password') {
        $fromEnv = getenv('MYSQL_ROOT_PASSWORD');
        if ($fromEnv !== false && $fromEnv !== '') {
            $OSCAR_DB_PASSWORD = (string) $fromEnv;
        }
    }

    $enabled = pdf_finder_oscar_env_bool('OSCAR_INTEGRATION_ENABLED', $OSCAR_INTEGRATION_ENABLED ?? false);

    $documentPath = pdf_finder_oscar_env_string(
        'OSCAR_DOCUMENT_PATH',
        $OSCAR_DOCUMENT_PATH ?? (dirname(__DIR__, 3) . '/volumes/OscarDocument')
    );
    $documentPath = pdf_finder_oscar_resolve_document_root($documentPath);

    $dbHost = pdf_finder_oscar_env_string(
        'OSCAR_DB_HOST',
        isset($OSCAR_DB_HOST) ? (string) $OSCAR_DB_HOST : pdf_finder_oscar_default_db_host()
    );
    $dbHost = pdf_finder_oscar_resolve_db_host($dbHost);

    $config = [
        'enabled' => $enabled,
        'db_host' => $dbHost,
        'db_port' => pdf_finder_oscar_env_int('OSCAR_DB_PORT', $OSCAR_DB_PORT ?? 3306),
        'db_name' => pdf_finder_oscar_env_string('OSCAR_DB_NAME', $OSCAR_DB_NAME ?? 'oscar'),
        'db_user' => pdf_finder_oscar_env_string('OSCAR_DB_USER', $OSCAR_DB_USER ?? 'root'),
        'db_password' => pdf_finder_oscar_env_string('OSCAR_DB_PASSWORD', $OSCAR_DB_PASSWORD ?? ''),
        'document_path' => rtrim($documentPath, '/\\'),
        'document_subpath' => 'oscar/document',
    ];

    return $config;
}

function pdf_finder_oscar_enabled(): bool
{
    return pdf_finder_oscar_config()['enabled'];
}

function pdf_finder_oscar_env_string(string $key, string $default): string
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return (string) $value;
    }
    return $default;
}

function pdf_finder_oscar_env_int(string $key, int $default): int
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return (int) $value;
    }
    return $default;
}

function pdf_finder_oscar_env_bool(string $key, bool $default): bool
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

/**
 * @return array<string, string>
 */
function pdf_finder_oscar_is_in_docker(): bool
{
    return is_file('/.dockerenv');
}

function pdf_finder_oscar_default_db_host(): string
{
    return pdf_finder_oscar_is_in_docker() ? 'db' : '127.0.0.1';
}

/**
 * Inside OpenOSP Docker, 127.0.0.1 is the pdffinder container — use service name "db".
 */
function pdf_finder_oscar_resolve_db_host(string $host): string
{
    if (!pdf_finder_oscar_is_in_docker()) {
        return $host;
    }
    if ($host === '127.0.0.1' || $host === 'localhost') {
        return 'db';
    }
    return $host;
}

function pdf_finder_oscar_resolve_document_root(string $path): string
{
    if (is_dir($path)) {
        return rtrim($path, '/\\');
    }
    if (pdf_finder_oscar_is_in_docker() && is_dir('/var/lib/OscarDocument')) {
        return '/var/lib/OscarDocument';
    }
    $fallback = dirname(__DIR__, 3) . '/volumes/OscarDocument';
    return is_dir($fallback) ? $fallback : rtrim($path, '/\\');
}

function pdf_finder_oscar_debug(): bool
{
    return pdf_finder_oscar_env_bool('OSCAR_DEBUG', false);
}

function pdf_finder_load_dotenv_file(string $path): array
{
    $vars = [];
    if (!is_readable($path)) {
        return $vars;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $val] = explode('=', $line, 2);
        $vars[trim($key)] = trim($val, " \t\"'");
    }
    return $vars;
}
