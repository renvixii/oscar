<?php
/**
 * SMB source settings — credentials stay server-side only.
 */

declare(strict_types=1);

/**
 * @return list<array{
 *   id: string,
 *   enabled: bool,
 *   label: string,
 *   host: string,
 *   share: string,
 *   username: string,
 *   password: string,
 *   subdirectory: string,
 *   index_file: string
 * }>
 */
function pdf_finder_smb_sources_raw(): array
{
    static $sources = null;
    if ($sources !== null) {
        return $sources;
    }

    $SMB_SOURCES = [];

    $localFile = dirname(__DIR__) . '/config.smb.local.php';
    if (is_file($localFile)) {
        require $localFile;
    }

    if (!isset($SMB_SOURCES) || !is_array($SMB_SOURCES)) {
        $sources = [];
        return $sources;
    }

    $normalized = [];
    foreach ($SMB_SOURCES as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = isset($row['id']) ? trim((string) $row['id']) : '';
        if (!pdf_finder_smb_valid_source_id($id)) {
            continue;
        }

        $indexFile = isset($row['index_file']) && is_string($row['index_file']) && $row['index_file'] !== ''
            ? $row['index_file']
            : dirname(__DIR__) . '/storage/smb_index_' . $id . '.json';

        $normalized[] = [
            'id' => $id,
            'enabled' => pdf_finder_smb_bool($row['enabled'] ?? false),
            'label' => trim((string) ($row['label'] ?? $id)) ?: $id,
            'host' => trim((string) ($row['host'] ?? '')),
            'share' => trim((string) ($row['share'] ?? '')),
            'username' => (string) ($row['username'] ?? ''),
            'password' => (string) ($row['password'] ?? ''),
            'subdirectory' => pdf_finder_smb_normalize_subdir((string) ($row['subdirectory'] ?? '')),
            'index_file' => $indexFile,
        ];
    }

    $sources = $normalized;
    return $sources;
}

function pdf_finder_smb_valid_source_id(string $id): bool
{
    return $id !== '' && (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/i', $id);
}

function pdf_finder_smb_bool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function pdf_finder_smb_normalize_subdir(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    return trim($path, '/');
}

function pdf_finder_smb_enabled(): bool
{
    foreach (pdf_finder_smb_sources_raw() as $source) {
        if ($source['enabled']) {
            return true;
        }
    }
    return false;
}

/**
 * Enabled sources only (includes credentials — never send to browser).
 *
 * @return list<array<string, mixed>>
 */
function pdf_finder_smb_enabled_sources(): array
{
    $out = [];
    foreach (pdf_finder_smb_sources_raw() as $source) {
        if ($source['enabled']) {
            $out[] = $source;
        }
    }
    return $out;
}

/**
 * @return array<string, mixed>|null
 */
function pdf_finder_smb_source_by_id(string $id): ?array
{
    if (!pdf_finder_smb_valid_source_id($id)) {
        return null;
    }
    foreach (pdf_finder_smb_sources_raw() as $source) {
        if ($source['id'] === $id) {
            return $source;
        }
    }
    return null;
}

/**
 * Public-safe source info for UI/diagnostics (no password).
 *
 * @return list<array<string, mixed>>
 */
function pdf_finder_smb_sources_public(): array
{
    $out = [];
    foreach (pdf_finder_smb_sources_raw() as $source) {
        $out[] = [
            'id' => $source['id'],
            'enabled' => $source['enabled'],
            'label' => $source['label'],
            'host' => $source['host'],
            'share' => $source['share'],
            'username' => $source['username'],
            'subdirectory' => $source['subdirectory'],
            'index_file' => basename($source['index_file']),
            'target' => '//' . $source['host'] . '/' . $source['share'],
        ];
    }
    return $out;
}

function pdf_finder_smb_mask_password(string $password): string
{
    $len = strlen($password);
    if ($len === 0) {
        return '(empty)';
    }
    return str_repeat('*', min($len, 12));
}
