<?php
/**
 * SMB read-only PDF listing, indexing, search, and streaming via smbclient.
 *
 * Security:
 * - All shell arguments use escapeshellarg(); user queries never enter shell commands.
 * - Remote paths come only from the local index after ID lookup — not from raw query strings.
 * - No write/delete/rename commands are ever sent to smbclient.
 * - Passwords are never logged or returned to the browser.
 */

declare(strict_types=1);

require_once __DIR__ . '/smb_config.php';

function pdf_finder_smbclient_path(): ?string
{
    static $path = null;
    if ($path !== null) {
        return $path !== '' ? $path : null;
    }
    $path = trim((string) shell_exec('command -v smbclient 2>/dev/null'));
    return $path !== '' ? $path : null;
}

function pdf_finder_smbclient_available(): bool
{
    return pdf_finder_smbclient_path() !== null;
}

/**
 * Build //host/share target from a configured source.
 */
function pdf_finder_smb_target(array $source): string
{
    return '//' . $source['host'] . '/' . $source['share'];
}

/**
 * Validate remote path from index — blocks traversal and non-PDF files.
 */
function pdf_finder_smb_validate_remote_path(string $path): bool
{
    if ($path === '' || str_contains($path, "\0")) {
        return false;
    }
    if (str_contains($path, '..')) {
        return false;
    }
    if (!preg_match('/\.pdf$/i', $path)) {
        return false;
    }
    // Allow typical SMB path characters only.
    if (!preg_match('/^[a-zA-Z0-9\/._\- ()+\[\]]+$/', $path)) {
        return false;
    }
    return true;
}

/**
 * Escape a path for use inside smbclient -c 'get "path" -' (path already validated).
 */
function pdf_finder_smb_quote_remote_path(string $path): string
{
    return str_replace('"', '\\"', $path);
}

/**
 * Run smbclient with a fixed command string (built by pdffinder, not user input).
 *
 * @return array{ok: bool, output: string, exit_code: int}
 */
function pdf_finder_smb_run(array $source, string $smbCommand): array
{
    $smbclient = pdf_finder_smbclient_path();
    if ($smbclient === null) {
        return ['ok' => false, 'output' => 'smbclient is not installed.', 'exit_code' => 127];
    }

    if ($source['host'] === '' || $source['share'] === '' || $source['username'] === '') {
        return ['ok' => false, 'output' => 'SMB source is incomplete.', 'exit_code' => 1];
    }

    $target = pdf_finder_smb_target($source);
    $auth = $source['username'] . '%' . $source['password'];

    $cmd = sprintf(
        '%s %s -U %s -m SMB3 -g -c %s 2>&1',
        escapeshellarg($smbclient),
        escapeshellarg($target),
        escapeshellarg($auth),
        escapeshellarg($smbCommand)
    );

    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    $text = implode("\n", $output);

    if ($exitCode !== 0 || str_contains($text, 'NT_STATUS_')) {
        return ['ok' => false, 'output' => $text, 'exit_code' => $exitCode];
    }

    return ['ok' => true, 'output' => $text, 'exit_code' => $exitCode];
}

/**
 * Build smbclient list command for recursive PDF listing.
 */
function pdf_finder_smb_list_command(array $source): string
{
    $subdir = pdf_finder_smb_normalize_subdir($source['subdirectory'] ?? '');
    if ($subdir !== '') {
        if (str_contains($subdir, '..') || str_contains($subdir, "\0") || str_contains($subdir, '"')) {
            return 'recurse; ls *.pdf';
        }
        return 'cd "' . pdf_finder_smb_quote_remote_path($subdir) . '"; recurse; ls *.pdf';
    }
    return 'recurse; ls *.pdf';
}

/**
 * Parse smbclient -g lines for PDF files.
 *
 * @return list<array{remote_path: string, filename: string, size: int, modified: int}>
 */
function pdf_finder_smb_parse_listing(string $output): array
{
    $files = [];
    foreach (explode("\n", $output) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, 'NT_STATUS_')) {
            continue;
        }
        // -g format: "path/to/file.pdf" size 12345 Mon Jan  1 12:00:00 2024
        if (!preg_match('/^"([^"]+\.pdf)"\s+size\s+(\d+)/i', $line, $m)) {
            continue;
        }
        $remotePath = str_replace('\\', '/', $m[1]);
        if (!pdf_finder_smb_validate_remote_path($remotePath)) {
            continue;
        }
        $size = (int) $m[2];
        $modified = 0;
        if (preg_match('/\s([A-Za-z]{3}\s+[A-Za-z]{3}\s+\d+\s+\d{2}:\d{2}:\d{2}\s+\d{4})\s*$/', $line, $dm)) {
            $ts = strtotime($dm[1]);
            if ($ts !== false) {
                $modified = (int) $ts;
            }
        }
        $files[] = [
            'remote_path' => $remotePath,
            'filename' => basename($remotePath),
            'size' => $size,
            'modified' => $modified,
        ];
    }
    return $files;
}

function pdf_finder_smb_file_id(string $sourceId, string $remotePath): string
{
    return 'smb:' . $sourceId . ':' . hash('sha256', $sourceId . '|' . $remotePath);
}

/**
 * @return array<string, mixed>|null
 */
function pdf_finder_smb_parse_result_id(string $id): ?array
{
    if (!preg_match('/^smb:([a-z0-9][a-z0-9_-]{0,63}):([a-f0-9]{64})$/i', $id, $m)) {
        return null;
    }
    return [
        'source_id' => $m[1],
        'hash' => strtolower($m[2]),
    ];
}

/**
 * Display folder for UI — relative path only, no host/share/credentials.
 */
function pdf_finder_smb_display_directory(string $remotePath): string
{
    $dir = dirname(str_replace('\\', '/', $remotePath));
    return $dir === '.' ? '' : $dir;
}

/**
 * @param list<array{remote_path: string, filename: string, size: int, modified: int}> $listed
 * @return list<array<string, mixed>>
 */
function pdf_finder_smb_entries_from_listing(array $source, array $listed): array
{
    $entries = [];
    $seen = [];
    foreach ($listed as $row) {
        $remotePath = $row['remote_path'];
        if (isset($seen[$remotePath])) {
            continue;
        }
        $seen[$remotePath] = true;

        $entries[] = pdf_finder_smb_result_entry([
            'source_id' => $source['id'],
            'source_label' => $source['label'],
            'remote_path' => $remotePath,
            'filename' => $row['filename'],
            'size' => $row['size'],
            'modified' => $row['modified'],
        ]);
    }

    usort($entries, static fn(array $a, array $b): int => strcasecmp($a['filename'], $b['filename']));
    return $entries;
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function pdf_finder_smb_result_entry(array $data): array
{
    $sourceId = (string) ($data['source_id'] ?? '');
    $remotePath = (string) ($data['remote_path'] ?? '');
    $label = (string) ($data['source_label'] ?? $sourceId);

    return [
        'id' => pdf_finder_smb_file_id($sourceId, $remotePath),
        'source' => 'smb:' . $sourceId,
        'source_id' => $sourceId,
        'source_label' => $label,
        'filename' => (string) ($data['filename'] ?? basename($remotePath)),
        'remote_path' => $remotePath,
        'directory' => pdf_finder_smb_display_directory($remotePath),
        'path' => '',
        'patient_name' => '',
        'demographic_no' => '',
        'document_title' => '',
        'modified' => (int) ($data['modified'] ?? 0),
        'size' => (int) ($data['size'] ?? 0),
    ];
}

/**
 * Load SMB index for one source.
 *
 * @return array{built_at?: string, count?: int, source_id?: string, files?: list<array<string, mixed>>}|null
 */
function pdf_finder_smb_load_index(string $sourceId): ?array
{
    $source = pdf_finder_smb_source_by_id($sourceId);
    if ($source === null) {
        return null;
    }

    $path = $source['index_file'];
    if (!is_file($path)) {
        return null;
    }

    $json = file_get_contents($path);
    if ($json === false || $json === '') {
        return null;
    }

    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['files']) || !is_array($data['files'])) {
        return null;
    }

    return $data;
}

/**
 * @param list<array<string, mixed>> $files
 */
function pdf_finder_smb_save_index(array $source, array $files): bool
{
    pdf_finder_ensure_storage_dir($source['index_file']);

    $payload = [
        'built_at' => date('Y-m-d H:i:s'),
        'count' => count($files),
        'source_id' => $source['id'],
        'source_label' => $source['label'],
        'files' => $files,
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents($source['index_file'], $json, LOCK_EX) !== false;
}

/**
 * Build index for one SMB source (read-only remote listing).
 *
 * @return array{ok: bool, files: list<array<string, mixed>>, message: string}
 */
function pdf_finder_smb_build_index(array $source): array
{
    if (!$source['enabled']) {
        return ['ok' => false, 'files' => [], 'message' => 'Source is disabled.'];
    }
    if (!pdf_finder_smbclient_available()) {
        return ['ok' => false, 'files' => [], 'message' => 'smbclient is not installed.'];
    }

    $result = pdf_finder_smb_run($source, pdf_finder_smb_list_command($source));
    if (!$result['ok']) {
        $msg = trim($result['output']);
        if ($msg === '') {
            $msg = 'SMB listing failed.';
        }
        return ['ok' => false, 'files' => [], 'message' => $msg];
    }

    $listed = pdf_finder_smb_parse_listing($result['output']);
    $files = pdf_finder_smb_entries_from_listing($source, $listed);

    if (!pdf_finder_smb_save_index($source, $files)) {
        return ['ok' => false, 'files' => [], 'message' => 'Could not write local SMB index file.'];
    }

    return [
        'ok' => true,
        'files' => $files,
        'message' => 'Indexed ' . count($files) . ' PDF(s).',
    ];
}

/**
 * @return array<string, mixed>|null
 */
function pdf_finder_smb_find_by_id(string $id): ?array
{
    $parsed = pdf_finder_smb_parse_result_id($id);
    if ($parsed === null) {
        return null;
    }

    $sourceId = $parsed['source_id'];
    $hash = $parsed['hash'];

    $index = pdf_finder_smb_load_index($sourceId);
    if ($index === null) {
        return null;
    }

    foreach ($index['files'] as $file) {
        if (!isset($file['id']) || !is_string($file['id'])) {
            continue;
        }
        if (hash_equals(strtolower($file['id']), strtolower($id))) {
            return $file;
        }
        // Backward compatibility if id stored differently but hash matches remote path
        if (isset($file['remote_path']) && hash_equals(
            hash('sha256', $sourceId . '|' . (string) $file['remote_path']),
            $hash
        )) {
            return $file;
        }
    }

    return null;
}

function pdf_finder_smb_validate_entry(array $entry): bool
{
    if (empty($entry['remote_path']) || !is_string($entry['remote_path'])) {
        return false;
    }
    if (empty($entry['source_id']) || !is_string($entry['source_id'])) {
        return false;
    }
    if (!pdf_finder_smb_validate_remote_path($entry['remote_path'])) {
        return false;
    }

    $source = pdf_finder_smb_source_by_id($entry['source_id']);
    if ($source === null || !$source['enabled']) {
        return false;
    }

    // Must still exist in the local index (prevents arbitrary path fetch).
    $indexed = pdf_finder_smb_find_by_id((string) ($entry['id'] ?? ''));
    return $indexed !== null;
}

/**
 * Search local SMB indexes (fast — no live SMB scan per query).
 *
 * @return list<array<string, mixed>>
 */
function pdf_finder_smb_search(string $query, ?string $sourceIdFilter = null): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $needle = pdf_finder_strtolower($query);
    $results = [];

    foreach (pdf_finder_smb_enabled_sources() as $source) {
        if ($sourceIdFilter !== null && $source['id'] !== $sourceIdFilter) {
            continue;
        }

        $index = pdf_finder_smb_load_index($source['id']);
        if ($index === null) {
            continue;
        }

        foreach ($index['files'] as $file) {
            $haystack = pdf_finder_strtolower(
                ($file['filename'] ?? '') . ' '
                . ($file['directory'] ?? '') . ' '
                . ($file['source_label'] ?? '')
            );
            if (pdf_finder_str_contains($haystack, $needle)) {
                $results[] = $file;
            }
        }
    }

    return $results;
}

function pdf_finder_smb_source_label(array $entry): string
{
    $label = trim((string) ($entry['source_label'] ?? ''));
    if ($label !== '') {
        return $label;
    }
    $sourceId = (string) ($entry['source_id'] ?? '');
    $source = pdf_finder_smb_source_by_id($sourceId);
    return $source !== null ? $source['label'] : 'SMB';
}

/**
 * Test SMB connectivity for diagnostics (lists root, does not modify remote).
 *
 * @return array{ok: bool, message: string, pdf_count: int, sample: list<string>}
 */
function pdf_finder_smb_test_connection(array $source): array
{
    if (!$source['enabled']) {
        return ['ok' => false, 'message' => 'Source disabled.', 'pdf_count' => 0, 'sample' => []];
    }

    $ping = pdf_finder_smb_run($source, 'ls');
    if (!$ping['ok']) {
        return [
            'ok' => false,
            'message' => trim($ping['output']) ?: 'Connection failed.',
            'pdf_count' => 0,
            'sample' => [],
        ];
    }

    $list = pdf_finder_smb_run($source, pdf_finder_smb_list_command($source));
    if (!$list['ok']) {
        return [
            'ok' => false,
            'message' => trim($list['output']) ?: 'PDF listing failed.',
            'pdf_count' => 0,
            'sample' => [],
        ];
    }

    $parsed = pdf_finder_smb_parse_listing($list['output']);
    $sample = [];
    foreach (array_slice($parsed, 0, 5) as $row) {
        $sample[] = $row['filename'];
    }

    return [
        'ok' => true,
        'message' => 'Connected.',
        'pdf_count' => count($parsed),
        'sample' => $sample,
    ];
}

/**
 * Stream a PDF from SMB through PHP (read-only get command).
 */
function pdf_finder_smb_stream_file(array $entry, bool $inline): void
{
    if (!pdf_finder_smb_validate_entry($entry)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'PDF not found or not allowed.';
        exit;
    }

    $source = pdf_finder_smb_source_by_id($entry['source_id']);
    if ($source === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'SMB source not found.';
        exit;
    }

    $smbclient = pdf_finder_smbclient_path();
    if ($smbclient === null) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'smbclient is not installed.';
        exit;
    }

    $remotePath = (string) $entry['remote_path'];
    $filename = basename(str_replace(["\r", "\n", '"'], '', (string) ($entry['filename'] ?? 'document.pdf')));

    $getCmd = 'get "' . pdf_finder_smb_quote_remote_path($remotePath) . '" -';
    $target = pdf_finder_smb_target($source);
    $auth = $source['username'] . '%' . $source['password'];

    $cmd = sprintf(
        '%s %s -U %s -m SMB3 -c %s',
        escapeshellarg($smbclient),
        escapeshellarg($target),
        escapeshellarg($auth),
        escapeshellarg($getCmd)
    );

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Could not start smbclient.';
        exit;
    }

    fclose($pipes[0]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');

    $size = (int) ($entry['size'] ?? 0);
    if ($size > 0) {
        header('Content-Length: ' . (string) $size);
    }

    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 8192);
        if ($chunk === false) {
            break;
        }
        echo $chunk;
    }
    fclose($pipes[1]);

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        // Headers may already be sent; best-effort only.
        error_log('pdffinder SMB stream failed (exit ' . $exitCode . ')');
    }
    exit;
}

/**
 * Case-insensitive helpers (shared with functions.php when loaded).
 */
if (!function_exists('pdf_finder_strtolower')) {
    function pdf_finder_strtolower(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}

if (!function_exists('pdf_finder_str_contains')) {
    function pdf_finder_str_contains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        if (function_exists('mb_strpos')) {
            return mb_strpos($haystack, $needle, 0, 'UTF-8') !== false;
        }
        return str_contains($haystack, $needle);
    }
}

if (!function_exists('pdf_finder_ensure_storage_dir')) {
    function pdf_finder_ensure_storage_dir(string $indexPath): void
    {
        $dir = dirname($indexPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
