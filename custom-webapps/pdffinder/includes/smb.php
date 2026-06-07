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
 * Hostname or IP validation (config/diagnostics only — not from user search input).
 */
function pdf_finder_smb_validate_host(string $host): bool
{
    if ($host === '' || strlen($host) > 253) {
        return false;
    }
    return (bool) preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9.\-]{0,252}[a-zA-Z0-9])?$/', $host);
}

/**
 * Run smbclient against a host (share enumeration) — read-only -L list.
 *
 * @return array{ok: bool, output: string, exit_code: int}
 */
function pdf_finder_smb_run_host_list(string $host, string $username, string $password): array
{
    $smbclient = pdf_finder_smbclient_path();
    if ($smbclient === null) {
        return ['ok' => false, 'output' => 'smbclient is not installed.', 'exit_code' => 127];
    }
    if (!pdf_finder_smb_validate_host($host) || $username === '') {
        return ['ok' => false, 'output' => 'Invalid host or username.', 'exit_code' => 1];
    }

    $target = '//' . $host;
    $auth = $username . '%' . $password;

    $cmd = sprintf(
        '%s -L %s -U %s -m SMB3 2>&1',
        escapeshellarg($smbclient),
        escapeshellarg($target),
        escapeshellarg($auth)
    );

    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    $text = implode("\n", $output);

    $shares = pdf_finder_smb_parse_share_list($text);
    if ($shares !== []) {
        return ['ok' => true, 'output' => $text, 'exit_code' => $exitCode];
    }

    if ($exitCode !== 0 || str_contains($text, 'NT_STATUS_ACCESS_DENIED') || str_contains($text, 'NT_STATUS_LOGON_FAILURE')) {
        return ['ok' => false, 'output' => $text, 'exit_code' => $exitCode];
    }

    return ['ok' => true, 'output' => $text, 'exit_code' => $exitCode];
}

/**
 * Parse smbclient -L (list shares) output.
 *
 * @return list<array{name: string, type: string, comment: string}>
 */
function pdf_finder_smb_parse_share_list(string $output): array
{
    $shares = [];
    $inSection = false;

    foreach (explode("\n", $output) as $line) {
        if (preg_match('/^\s*Sharename\s+Type\s+Comment/i', $line)) {
            $inSection = true;
            continue;
        }
        if (!$inSection) {
            continue;
        }
        if (preg_match('/^\s*-+\s+-+/', $line)) {
            continue;
        }
        $line = trim($line);
        if ($line === '' || str_starts_with($line, 'SMB') || str_starts_with($line, 'Server:')) {
            continue;
        }
        if (preg_match('/^(\S+)\s+(Disk|IPC|Printer)\s*(.*)$/i', $line, $m)) {
            $shares[] = [
                'name' => $m[1],
                'type' => $m[2],
                'comment' => trim($m[3]),
            ];
        }
    }

    return $shares;
}

/**
 * Parse one smbclient ls line (grepable -g, compact -g, or human-readable).
 *
 * @return array{path: string, is_dir: bool, size: int}|null
 */
function pdf_finder_smb_parse_ls_line(string $line): ?array
{
    $line = trim($line);
    if ($line === '' || str_starts_with($line, 'NT_STATUS_') || str_starts_with($line, 'cd ')) {
        return null;
    }
    if (str_starts_with($line, 'Server:') || str_starts_with($line, 'Domain:') || str_starts_with($line, 'Block size:')) {
        return null;
    }

    // Quoted grepable: "path/to/file.pdf" size 12345 ...
    if (preg_match('/^"([^"]+)"\s+size\s+(\d+)/i', $line, $m)) {
        $path = pdf_finder_smb_normalize_ls_path($m[1]);
        return [
            'path' => rtrim($path, '/'),
            'is_dir' => str_ends_with($m[1], '/') || str_ends_with($m[1], '\\'),
            'size' => (int) $m[2],
        ];
    }

    // Quoted without "size" keyword: "file.pdf" 12345 ...
    if (preg_match('/^"([^"]+\.pdf)"\s+(\d+)/i', $line, $m)) {
        return [
            'path' => pdf_finder_smb_normalize_ls_path($m[1]),
            'is_dir' => false,
            'size' => (int) $m[2],
        ];
    }

    // Quoted directory: "dirname/" ...
    if (preg_match('/^"([^"]+\/)"\s+/i', $line, $m)) {
        return [
            'path' => rtrim(pdf_finder_smb_normalize_ls_path($m[1]), '/'),
            'is_dir' => true,
            'size' => 0,
        ];
    }

    // Compact / human smbclient ls: name  D|A|...  size  Weekday Month day time year
    // e.g. UNIT 1 - 2026-06-01 1434h D 0 Sun May  4 07:03:16 2026
    // e.g. . D 0 Sun Mar  8 07:03:16 2026
    if (preg_match(
        '/^(.+)\s+([DAHSR]+)\s+(\d+)\s+[A-Za-z]{3}\s+[A-Za-z]{3}\s+\d+\s+\d{2}:\d{2}:\d{2}\s+\d{4}\s*$/',
        $line,
        $m
    )) {
        $name = trim($m[1]);
        if ($name === '.' || $name === '..') {
            return null;
        }
        $path = pdf_finder_smb_normalize_ls_path($name);
        return [
            'path' => $path,
            'is_dir' => str_contains($m[2], 'D'),
            'size' => (int) $m[3],
        ];
    }

    // Recursive path prefix: \folder\sub\file.pdf A 1234 date...
    if (preg_match(
        '/^(.+\.pdf)\s+([DAHSR]+)\s+(\d+)\s+[A-Za-z]{3}\s+[A-Za-z]{3}\s+\d+\s+\d{2}:\d{2}:\d{2}\s+\d{4}\s*$/i',
        $line,
        $m
    )) {
        return [
            'path' => pdf_finder_smb_normalize_ls_path($m[1]),
            'is_dir' => false,
            'size' => (int) $m[3],
        ];
    }

    return null;
}

function pdf_finder_smb_normalize_ls_path(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    return ltrim($path, '/');
}

/**
 * Parse smbclient "ls" output into folders and files at the current level.
 *
 * @return array{directories: list<string>, files: list<string>, pdfs: list<string>}
 */
function pdf_finder_smb_parse_ls_entries(string $output): array
{
    $directories = [];
    $files = [];
    $pdfs = [];

    foreach (explode("\n", $output) as $line) {
        $entry = pdf_finder_smb_parse_ls_line($line);
        if ($entry === null) {
            continue;
        }
        if ($entry['is_dir']) {
            if ($entry['path'] !== '' && $entry['path'] !== '.' && $entry['path'] !== '..') {
                $directories[] = $entry['path'];
            }
            continue;
        }
        $files[] = $entry['path'];
        if (preg_match('/\.pdf$/i', $entry['path'])) {
            $pdfs[] = basename($entry['path']);
        }
    }

    sort($directories, SORT_NATURAL | SORT_FLAG_CASE);
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    return [
        'directories' => $directories,
        'files' => $files,
        'pdfs' => $pdfs,
    ];
}

/**
 * List top-level folders/files on a share (read-only ls, not recursive).
 *
 * @return array{ok: bool, message: string, path: string, directories: list<string>, files: list<string>, pdfs: list<string>, hint_directories: list<string>}
 */
function pdf_finder_smb_list_share_directory(array $source, string $subdir = ''): array
{
    $empty = [
        'ok' => false,
        'message' => '',
        'path' => '/',
        'directories' => [],
        'files' => [],
        'pdfs' => [],
        'hint_directories' => [],
    ];

    $subdir = pdf_finder_smb_normalize_subdir($subdir);
    if ($subdir !== '' && (str_contains($subdir, '..') || str_contains($subdir, "\0") || str_contains($subdir, '"'))) {
        $empty['message'] = 'Invalid subdirectory.';
        $empty['path'] = $subdir;
        return $empty;
    }

    $cmd = $subdir !== ''
        ? 'cd "' . pdf_finder_smb_quote_remote_path($subdir) . '"; ls'
        : 'ls';

    $result = pdf_finder_smb_run($source, $cmd);
    $raw = $result['raw_output'] ?? $result['output'];

    if (!$result['ok']) {
        $hintDirs = [];
        if ($subdir !== '' && pdf_finder_smb_output_cd_failed($raw)) {
            $parsed = pdf_finder_smb_parse_ls_entries($raw);
            $hintDirs = array_slice($parsed['directories'], 0, 5);
        }
        return [
            'ok' => false,
            'message' => pdf_finder_smb_format_error($raw, $source),
            'path' => $subdir === '' ? '/' : $subdir,
            'directories' => [],
            'files' => [],
            'pdfs' => [],
            'hint_directories' => $hintDirs,
        ];
    }

    $parsed = pdf_finder_smb_parse_ls_entries($result['output']);

    return [
        'ok' => true,
        'message' => 'OK',
        'path' => $subdir === '' ? '/' : $subdir,
        'directories' => $parsed['directories'],
        'files' => $parsed['files'],
        'pdfs' => $parsed['pdfs'],
        'hint_directories' => [],
    ];
}

/**
 * Discover shares on a host and top-level folders for configured sources (diagnostics).
 *
 * @return array{
 *   ok: bool,
 *   message: string,
 *   host: string,
 *   username: string,
 *   shares: list<array{name: string, type: string, comment: string}>,
 *   disk_shares: list<string>,
 *   sources: list<array<string, mixed>>
 * }
 */
function pdf_finder_smb_discover_host(string $host, string $username, string $password, array $sourcesOnHost): array
{
    if (!pdf_finder_smb_validate_host($host)) {
        return [
            'ok' => false,
            'message' => 'Invalid host.',
            'host' => $host,
            'username' => $username,
            'shares' => [],
            'disk_shares' => [],
            'sources' => [],
        ];
    }

    $listResult = pdf_finder_smb_run_host_list($host, $username, $password);
    $shares = pdf_finder_smb_parse_share_list($listResult['output']);
    $diskShares = [];
    foreach ($shares as $share) {
        if (strcasecmp($share['type'], 'Disk') === 0) {
            $diskShares[] = $share['name'];
        }
    }

    $sourceReports = [];
    foreach ($sourcesOnHost as $source) {
        $configuredShare = $source['share'];
        $shareMatch = pdf_finder_smb_match_disk_share($configuredShare, $diskShares);

        $rootListing = pdf_finder_smb_list_share_directory($source, '');
        $subdirListing = null;
        $configWarnings = pdf_finder_smb_config_warnings($source);
        if ($source['subdirectory'] !== '' && $configWarnings === []) {
            $subdirListing = pdf_finder_smb_list_share_directory($source, $source['subdirectory']);
        }

        $sourceReports[] = [
            'id' => $source['id'],
            'label' => $source['label'],
            'enabled' => $source['enabled'],
            'configured_share' => $configuredShare,
            'share_visible' => $shareMatch['visible'],
            'share_canonical' => $shareMatch['canonical'],
            'subdirectory' => $source['subdirectory'],
            'config_warnings' => $configWarnings,
            'root' => $rootListing,
            'subdirectory_listing' => $subdirListing,
        ];
    }

    return [
        'ok' => $listResult['ok'] || $shares !== [],
        'message' => $listResult['ok'] || $shares !== []
            ? 'Share list retrieved.'
            : (trim($listResult['output']) ?: 'Could not list shares on host.'),
        'host' => $host,
        'username' => $username,
        'shares' => $shares,
        'disk_shares' => $diskShares,
        'sources' => $sourceReports,
        'raw_error' => $listResult['ok'] ? '' : pdf_finder_smb_extract_status_lines($listResult['output']),
    ];
}

/**
 * Group configured SMB sources by host + username for discovery (one -L per group).
 *
 * @return list<array{host: string, username: string, password: string, sources: list<array<string, mixed>>}>
 */
function pdf_finder_smb_host_groups(): array
{
    $groups = [];
    foreach (pdf_finder_smb_sources_raw() as $source) {
        $key = $source['host'] . '|' . $source['username'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'host' => $source['host'],
                'username' => $source['username'],
                'password' => $source['password'],
                'sources' => [],
            ];
        }
        $groups[$key]['sources'][] = $source;
    }
    return array_values($groups);
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
    // Allow typical SMB path characters (incl. spaces, hyphens in UNIT folder names).
    if (!preg_match('/^[a-zA-Z0-9\/._\- ()+\[\]#,&\']+$/', $path)) {
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
        return [
            'ok' => false,
            'output' => pdf_finder_smb_format_error($text, $source),
            'exit_code' => $exitCode,
            'raw_output' => $text,
        ];
    }

    return ['ok' => true, 'output' => $text, 'exit_code' => $exitCode, 'raw_output' => $text];
}

/**
 * Plain-language hint for common smbclient / NT_STATUS errors.
 */
function pdf_finder_smb_explain_error(string $output, ?array $source = null): string
{
    if (str_contains($output, 'NT_STATUS_OBJECT_NAME_NOT_FOUND')) {
        $lines = [
            'NT_STATUS_OBJECT_NAME_NOT_FOUND — the share name or folder path does not exist on the NAS.',
        ];
        if ($source !== null) {
            $lines[] = 'Configured target: //' . $source['host'] . '/' . $source['share'];
            $subdir = pdf_finder_smb_normalize_subdir((string) ($source['subdirectory'] ?? ''));
            if ($subdir !== '') {
                $lines[] = 'Configured subdirectory: ' . $subdir;
                if (strcasecmp($subdir, (string) $source['share']) === 0) {
                    $lines[] = "Fix: subdirectory must NOT repeat the share name — use '' and pick a folder like 'UNIT 1 - …' if needed.";
                }
            } else {
                $lines[] = 'subdirectory is empty (correct if PDFs live directly on the share).';
            }
            $lines[] = 'Use test-smb-connection.php [dir] samples for a valid subdirectory name.';
        } else {
            $lines[] = 'Check host IP, share name, and optional subdirectory in config.smb.local.php.';
        }
        return implode("\n", $lines);
    }
    if (str_contains($output, 'NT_STATUS_LOGON_FAILURE')) {
        return "NT_STATUS_LOGON_FAILURE — wrong username or password.\nCheck credentials in config.smb.local.php.";
    }
    if (str_contains($output, 'NT_STATUS_ACCESS_DENIED')) {
        return "NT_STATUS_ACCESS_DENIED — user cannot access this share or folder.\nUse a read-only account with permission to the share.";
    }
    if (str_contains($output, 'NT_STATUS_NO_SUCH_FILE')) {
        return "NT_STATUS_NO_SUCH_FILE — path or file pattern not found (often a wrong subdirectory or empty glob).\n"
            . 'List the share root on test-smb-connection.php and set subdirectory to a [dir] that exists, or leave it empty.';
    }
    return trim($output);
}

function pdf_finder_smb_format_error(string $output, ?array $source): string
{
    $explained = pdf_finder_smb_explain_error($output, $source);
    $extra = pdf_finder_smb_extract_status_lines($output, 3);
    if ($extra !== '') {
        return $explained . "\n" . $extra;
    }
    return $explained !== '' ? $explained : 'SMB command failed.';
}

/**
 * Keep only NT_STATUS / cd error lines — never dump full directory listings.
 */
function pdf_finder_smb_extract_status_lines(string $output, int $maxLines = 3): string
{
    $lines = [];
    foreach (explode("\n", $output) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (
            str_contains($line, 'NT_STATUS_')
            || preg_match('/^cd\s+/i', $line)
            || preg_match('/^tree connect/i', $line)
        ) {
            $lines[] = $line;
        }
        if (count($lines) >= $maxLines) {
            break;
        }
    }
    return implode("\n", $lines);
}

/**
 * True when a compound "cd …; ls" command failed on cd (ls may still have run at share root).
 */
function pdf_finder_smb_output_cd_failed(string $output): bool
{
    return (bool) preg_match('/cd\s+.*NT_STATUS_(OBJECT_NAME_NOT_FOUND|NO_SUCH_FILE)/i', $output);
}

/**
 * Config mistakes that commonly cause NT_STATUS_OBJECT_NAME_NOT_FOUND.
 */
function pdf_finder_smb_config_warnings(array $source): array
{
    $warnings = [];
    $share = trim((string) ($source['share'] ?? ''));
    $subdir = pdf_finder_smb_normalize_subdir((string) ($source['subdirectory'] ?? ''));

    if ($subdir !== '' && $share !== '' && strcasecmp($subdir, $share) === 0) {
        $warnings[] = "subdirectory is '{$subdir}' but that is already the share name — set subdirectory to '' (empty). "
            . 'Folders inside the share (e.g. UNIT 1 - …) go in subdirectory, not the share name again.';
    }

    return $warnings;
}

/**
 * Case-insensitive match of configured share against names from smbclient -L.
 *
 * @param list<string> $diskShares
 * @return array{visible: bool, canonical: string|null}
 */
function pdf_finder_smb_match_disk_share(string $configured, array $diskShares): array
{
    if ($diskShares === []) {
        return ['visible' => true, 'canonical' => null];
    }
    foreach ($diskShares as $name) {
        if ($name === $configured) {
            return ['visible' => true, 'canonical' => $name];
        }
    }
    foreach ($diskShares as $name) {
        if (strcasecmp($name, $configured) === 0) {
            return ['visible' => true, 'canonical' => $name];
        }
    }
    return ['visible' => false, 'canonical' => null];
}

/**
 * Build smbclient list command for recursive PDF listing.
 * Uses "recurse ON; ls" (not "ls *.pdf") — globs often cause NT_STATUS_OBJECT_NAME_NOT_FOUND on NAS.
 */
function pdf_finder_smb_list_command(array $source): string
{
    $subdir = pdf_finder_smb_normalize_subdir($source['subdirectory'] ?? '');
    if ($subdir !== '') {
        if (str_contains($subdir, '..') || str_contains($subdir, "\0") || str_contains($subdir, '"')) {
            return 'recurse ON; ls';
        }
        return 'cd "' . pdf_finder_smb_quote_remote_path($subdir) . '"; recurse ON; ls';
    }
    return 'recurse ON; ls';
}

/**
 * Parse smbclient -g lines for PDF files.
 *
 * @return list<array{remote_path: string, filename: string, size: int, modified: int}>
 */
function pdf_finder_smb_parse_listing(string $output): array
{
    $files = [];
    $seen = [];

    foreach (explode("\n", $output) as $line) {
        $entry = pdf_finder_smb_parse_ls_line($line);
        if ($entry === null || $entry['is_dir']) {
            continue;
        }
        $remotePath = $entry['path'];
        if (!preg_match('/\.pdf$/i', $remotePath)) {
            continue;
        }
        if (!pdf_finder_smb_validate_remote_path($remotePath)) {
            continue;
        }
        if (isset($seen[$remotePath])) {
            continue;
        }
        $seen[$remotePath] = true;

        $modified = 0;
        if (preg_match('/\s([A-Za-z]{3}\s+[A-Za-z]{3}\s+\d+\s+\d{2}:\d{2}:\d{2}\s+\d{4})\s*$/', trim($line), $dm)) {
            $ts = strtotime($dm[1]);
            if ($ts !== false) {
                $modified = (int) $ts;
            }
        }

        $files[] = [
            'remote_path' => $remotePath,
            'filename' => basename($remotePath),
            'size' => $entry['size'],
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
 * Quick connectivity check for diagnostics — no full recursive scan (fast).
 *
 * @return array{ok: bool, message: string, pdf_count: int|null, pdf_count_note: string, sample: list<string>}
 */
function pdf_finder_smb_test_connection_quick(array $source, int $sampleLimit = 5): array
{
    $sampleLimit = max(1, min($sampleLimit, 20));

    if (!$source['enabled']) {
        return [
            'ok' => false,
            'message' => 'Source disabled.',
            'pdf_count' => null,
            'pdf_count_note' => '',
            'sample' => [],
        ];
    }

    $ping = pdf_finder_smb_run($source, 'ls');
    if (!$ping['ok']) {
        return [
            'ok' => false,
            'message' => $ping['output'],
            'pdf_count' => null,
            'pdf_count_note' => '',
            'sample' => [],
        ];
    }

    $cached = pdf_finder_smb_load_index($source['id']);
    if ($cached !== null && isset($cached['files']) && is_array($cached['files'])) {
        $files = $cached['files'];
        $sample = [];
        foreach (array_slice($files, 0, $sampleLimit) as $file) {
            $sample[] = (string) ($file['filename'] ?? basename((string) ($file['remote_path'] ?? 'document.pdf')));
        }
        return [
            'ok' => true,
            'message' => 'Connected (counts from local cached index).',
            'pdf_count' => (int) ($cached['count'] ?? count($files)),
            'pdf_count_note' => 'from cached index — rebuild index for a fresh scan',
            'sample' => $sample,
        ];
    }

    $listing = pdf_finder_smb_list_share_directory($source, $source['subdirectory'] ?? '');
    if (!$listing['ok']) {
        return [
            'ok' => false,
            'message' => $listing['message'],
            'pdf_count' => null,
            'pdf_count_note' => '',
            'sample' => [],
        ];
    }

    $sample = array_slice($listing['pdfs'], 0, $sampleLimit);
    $dirCount = count($listing['directories']);
    $pdfAtLevel = count($listing['pdfs']);

    return [
        'ok' => true,
        'message' => 'Connected (top-level listing only — no full recursive scan).',
        'pdf_count' => $pdfAtLevel,
        'pdf_count_note' => $dirCount . ' folder(s) at this level; rebuild index for full recursive PDF count',
        'sample' => $sample,
    ];
}

/**
 * Depth stats for a recursive PDF listing (diagnostics).
 *
 * @param list<array{remote_path: string, filename: string, size: int, modified: int}> $parsed
 * @return array{at_root: int, in_subfolders: int, max_depth: int, depths_with_pdfs: list<int>}
 */
function pdf_finder_smb_recursive_stats(array $parsed): array
{
    $atRoot = 0;
    $inSubfolders = 0;
    $maxDepth = 0;
    $depths = [];

    foreach ($parsed as $row) {
        $path = str_replace('\\', '/', (string) ($row['remote_path'] ?? ''));
        $depth = $path === '' ? 0 : substr_count($path, '/');
        $depths[$depth] = true;
        if ($depth > $maxDepth) {
            $maxDepth = $depth;
        }
        if ($depth === 0) {
            $atRoot++;
        } else {
            $inSubfolders++;
        }
    }

    $depthList = array_keys($depths);
    sort($depthList, SORT_NUMERIC);

    return [
        'at_root' => $atRoot,
        'in_subfolders' => $inSubfolders,
        'max_depth' => $maxDepth,
        'depths_with_pdfs' => $depthList,
    ];
}

/**
 * Sample paths from a recursive listing — prefer one PDF per folder depth when possible.
 *
 * @param list<array{remote_path: string, filename: string, size: int, modified: int}> $parsed
 * @return list<string>
 */
function pdf_finder_smb_recursive_samples(array $parsed, int $sampleLimit): array
{
    $sampleLimit = max(1, min($sampleLimit, 20));
    if ($parsed === []) {
        return [];
    }

    $byDepth = [];
    foreach ($parsed as $row) {
        $path = str_replace('\\', '/', (string) ($row['remote_path'] ?? ''));
        $depth = $path === '' ? 0 : substr_count($path, '/');
        if (!isset($byDepth[$depth])) {
            $byDepth[$depth] = $path;
        }
    }
    ksort($byDepth, SORT_NUMERIC);

    $samples = array_values($byDepth);
    foreach ($parsed as $row) {
        if (count($samples) >= $sampleLimit) {
            break;
        }
        $path = str_replace('\\', '/', (string) ($row['remote_path'] ?? ''));
        if (!in_array($path, $samples, true)) {
            $samples[] = $path;
        }
    }

    return array_slice($samples, 0, $sampleLimit);
}

function pdf_finder_smb_empty_recursive_stats(): array
{
    return [
        'at_root' => 0,
        'in_subfolders' => 0,
        'max_depth' => 0,
        'depths_with_pdfs' => [],
    ];
}

/**
 * Full recursive PDF scan for diagnostics / index rebuild.
 *
 * @return array{
 *   ok: bool,
 *   message: string,
 *   pdf_count: int,
 *   sample: list<string>,
 *   recursive: array{at_root: int, in_subfolders: int, max_depth: int, depths_with_pdfs: list<int>}
 * }
 */
function pdf_finder_smb_test_connection(array $source, int $sampleLimit = 5): array
{
    $emptyRecursive = pdf_finder_smb_empty_recursive_stats();

    if (!$source['enabled']) {
        return [
            'ok' => false,
            'message' => 'Source disabled.',
            'pdf_count' => 0,
            'sample' => [],
            'recursive' => $emptyRecursive,
        ];
    }

    $ping = pdf_finder_smb_run($source, 'ls');
    if (!$ping['ok']) {
        return [
            'ok' => false,
            'message' => $ping['output'],
            'pdf_count' => 0,
            'sample' => [],
            'recursive' => $emptyRecursive,
        ];
    }

    $list = pdf_finder_smb_run($source, pdf_finder_smb_list_command($source));
    if (!$list['ok']) {
        return [
            'ok' => false,
            'message' => $list['output'],
            'pdf_count' => 0,
            'sample' => [],
            'recursive' => $emptyRecursive,
        ];
    }

    $parsed = pdf_finder_smb_parse_listing($list['output']);
    $stats = pdf_finder_smb_recursive_stats($parsed);
    $sample = pdf_finder_smb_recursive_samples($parsed, $sampleLimit);

    $recursiveNote = 'recursive scan OK';
    if ($stats['in_subfolders'] === 0 && $stats['at_root'] > 0) {
        $recursiveNote = 'PDFs found only at share root (no subfolders with PDFs)';
    } elseif ($stats['in_subfolders'] > 0) {
        $recursiveNote = 'PDFs found in subfolders (depth up to ' . $stats['max_depth'] . ')';
    } elseif ($stats['at_root'] === 0 && $stats['in_subfolders'] === 0) {
        $recursiveNote = 'no PDFs found';
    }

    return [
        'ok' => true,
        'message' => 'Connected — ' . $recursiveNote . '.',
        'pdf_count' => count($parsed),
        'sample' => $sample,
        'recursive' => $stats,
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
