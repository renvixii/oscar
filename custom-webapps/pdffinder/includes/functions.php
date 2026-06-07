<?php
/**
 * PDF Finder — shared helpers
 */

declare(strict_types=1);

/**
 * Load and cache settings from config.php.
 *
 * @return array{directories: list<string>, index_file: string}
 */
function pdf_finder_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $PDF_DIRECTORIES = [];
    $INDEX_FILE = '';

    require dirname(__DIR__) . '/config.php';

    if (!is_string($INDEX_FILE) || $INDEX_FILE === '') {
        throw new RuntimeException('Set $INDEX_FILE in config.php');
    }

    $config = [
        'directories' => is_array($PDF_DIRECTORIES) ? array_values($PDF_DIRECTORIES) : [],
        'index_file'  => $INDEX_FILE,
    ];

    return $config;
}

/**
 * Return index file path from config.
 */
function pdf_finder_index_path(): string
{
    return pdf_finder_config()['index_file'];
}

/**
 * Load configured PDF directories.
 *
 * @return list<string>
 */
function pdf_finder_directories(): array
{
    return pdf_finder_config()['directories'];
}

/**
 * HTML-escape output.
 */
function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Ensure the storage directory exists.
 */
function pdf_finder_ensure_storage_dir(string $indexPath): void
{
    $dir = dirname($indexPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

/**
 * Load the index from disk. Returns null if missing or invalid.
 *
 * @return array{built_at?: string, count?: int, files?: list<array<string, mixed>>}|null
 */
function pdf_finder_load_index(): ?array
{
    $path = pdf_finder_index_path();
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
 * Save index to disk.
 *
 * @param list<array<string, mixed>> $files
 */
function pdf_finder_save_index(array $files): bool
{
    $path = pdf_finder_index_path();
    pdf_finder_ensure_storage_dir($path);

    $payload = [
        'built_at' => date('Y-m-d H:i:s'),
        'count'    => count($files),
        'files'    => $files,
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents($path, $json, LOCK_EX) !== false;
}

/**
 * Stable ID for an indexed file (used in URLs, not raw paths).
 */
function pdf_finder_file_id(string $fullPath): string
{
    return hash('sha256', $fullPath);
}

/**
 * Find one indexed entry by ID.
 *
 * @return array<string, mixed>|null
 */
function pdf_finder_find_by_id(string $id): ?array
{
    if ($id === '' || !preg_match('/^[a-f0-9]{64}$/i', $id)) {
        return null;
    }

    $index = pdf_finder_load_index();
    if ($index === null) {
        return null;
    }

    foreach ($index['files'] as $file) {
        if (isset($file['id']) && hash_equals((string) $file['id'], strtolower($id))) {
            return $file;
        }
    }

    return null;
}

/**
 * Validate that an indexed file still exists, is a PDF, and is not a traversal attack.
 */
function pdf_finder_validate_entry(array $entry): bool
{
    if (empty($entry['path']) || !is_string($entry['path'])) {
        return false;
    }

    $path = $entry['path'];

    // Must be a .pdf file (case-insensitive).
    if (!preg_match('/\.pdf$/i', $path)) {
        return false;
    }

    if (!is_file($path) || !is_readable($path)) {
        return false;
    }

    $real = realpath($path);
    if ($real === false) {
        return false;
    }

    // File must live under one of the configured scan roots.
    $allowed = false;
    foreach (pdf_finder_directories() as $root) {
        $rootReal = realpath($root);
        if ($rootReal === false) {
            continue;
        }
        $rootReal = rtrim(str_replace('\\', '/', $rootReal), '/');
        $fileReal = str_replace('\\', '/', $real);
        if ($fileReal === $rootReal || str_starts_with($fileReal, $rootReal . '/')) {
            $allowed = true;
            break;
        }
    }

    return $allowed;
}

/**
 * Recursively scan directories and build index entries.
 *
 * @return list<array<string, mixed>>
 */
function pdf_finder_build_index(): array
{
    $files = [];
    $seen  = [];

    foreach (pdf_finder_directories() as $directory) {
        if (!is_dir($directory) || !is_readable($directory)) {
            continue;
        }

        $rootReal = realpath($directory);
        if ($rootReal === false) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $rootReal,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $pathname = $item->getPathname();
            if (!preg_match('/\.pdf$/i', $pathname)) {
                continue;
            }

            $real = realpath($pathname);
            if ($real === false) {
                continue;
            }

            if (isset($seen[$real])) {
                continue;
            }
            $seen[$real] = true;

            $mtime = @filemtime($real);
            $size  = @filesize($real);

            $files[] = [
                'id'        => pdf_finder_file_id($real),
                'filename'  => basename($real),
                'path'      => $real,
                'directory' => dirname($real),
                'size'      => $size !== false ? (int) $size : 0,
                'modified'  => $mtime !== false ? (int) $mtime : 0,
            ];
        }
    }

    usort($files, static fn(array $a, array $b): int => strcasecmp($a['filename'], $b['filename']));

    return $files;
}

/**
 * Valid search source keys (dynamic: OSCAR + SMB sources when enabled).
 *
 * @return list<string>
 */
function pdf_finder_search_source_options(): array
{
    $options = ['all', 'pdffinder'];

    if (is_file(__DIR__ . '/oscar_config.php')) {
        require_once __DIR__ . '/oscar_config.php';
        if (pdf_finder_oscar_enabled()) {
            $options[] = 'oscar_db';
            $options[] = 'oscar_document';
        }
    }

    if (is_file(__DIR__ . '/smb_config.php')) {
        require_once __DIR__ . '/smb_config.php';
        if (pdf_finder_smb_enabled()) {
            $options[] = 'smb';
            foreach (pdf_finder_smb_enabled_sources() as $source) {
                $options[] = 'smb:' . $source['id'];
            }
        }
    }

    return $options;
}

/**
 * Human label for a search source dropdown value.
 */
function pdf_finder_search_source_label(string $source): string
{
    if ($source === 'all') {
        return 'All sources';
    }
    if ($source === 'pdffinder') {
        return 'PDF Finder folders only';
    }
    if ($source === 'oscar_db') {
        return 'OSCAR database';
    }
    if ($source === 'oscar_document') {
        return 'OscarDocument PDF files';
    }
    if ($source === 'smb') {
        return 'All SMB shares';
    }
    if (str_starts_with($source, 'smb:')) {
        $id = substr($source, 4);
        require_once __DIR__ . '/smb_config.php';
        $src = pdf_finder_smb_source_by_id($id);
        return $src !== null ? $src['label'] . ' (SMB)' : 'SMB';
    }
    return $source;
}

function pdf_finder_extended_sources_enabled(): bool
{
    $oscar = false;
    if (is_file(__DIR__ . '/oscar_config.php')) {
        require_once __DIR__ . '/oscar_config.php';
        $oscar = pdf_finder_oscar_enabled();
    }
    $smb = false;
    if (is_file(__DIR__ . '/smb_config.php')) {
        require_once __DIR__ . '/smb_config.php';
        $smb = pdf_finder_smb_enabled();
    }
    return $oscar || $smb;
}

function pdf_finder_search_available(): bool
{
    if (pdf_finder_extended_sources_enabled()) {
        return true;
    }
    return pdf_finder_load_index() !== null;
}

/**
 * Normalize search source parameter (preserve SMB source id case).
 */
function pdf_finder_normalize_search_source(string $source): string
{
    $source = trim($source);
    if (str_starts_with($source, 'smb:')) {
        return 'smb:' . substr($source, 4);
    }
    return strtolower($source);
}

/**
 * Unified search across pdffinder index and optional OSCAR sources.
 *
 * @return array{results: list<array<string, mixed>>, warnings: list<string>}
 */
function pdf_finder_search_unified(string $query, string $source = 'all'): array
{
    $query = trim($query);
    $warnings = [];
    $results = [];

    if ($query === '') {
        return ['results' => [], 'warnings' => []];
    }

    $source = pdf_finder_normalize_search_source($source);
    if (!in_array($source, pdf_finder_search_source_options(), true)) {
        $source = 'all';
    }

    require_once __DIR__ . '/oscar.php';
    require_once __DIR__ . '/smb.php';

    $pdffinderRows = [];
    if ($source === 'all' || $source === 'pdffinder') {
        foreach (pdf_finder_search($query) as $file) {
            $file['source'] = 'pdffinder';
            $file['patient_name'] = '';
            $file['demographic_no'] = '';
            $file['document_title'] = '';
            $pdffinderRows[] = $file;
        }
    }

    $oscarRows = [];
    if (pdf_finder_oscar_enabled() && ($source === 'all' || $source === 'oscar_db' || $source === 'oscar_document')) {
        if ($source === 'oscar_db' || $source === 'all') {
            if (!pdf_finder_oscar_db_available()) {
                $err = pdf_finder_oscar_last_error();
                $warnings[] = $err !== '' ? $err : 'OSCAR database search is unavailable.';
            } else {
                $oscarRows = array_merge($oscarRows, pdf_finder_oscar_db_search($query));
            }
        }
        if ($source === 'oscar_document' || $source === 'all') {
            $oscarRows = array_merge($oscarRows, pdf_finder_oscar_document_search($query));
        }
    } elseif ($source === 'oscar_db' || $source === 'oscar_document') {
        $warnings[] = 'OSCAR integration is disabled. Enable it in config.oscar.local.php or environment variables.';
    }

    $smbRows = [];
    if (pdf_finder_smb_enabled() && ($source === 'all' || $source === 'smb' || str_starts_with($source, 'smb:'))) {
        $smbFilter = null;
        if (str_starts_with($source, 'smb:') && $source !== 'smb') {
            $smbFilter = substr($source, 4);
            if (!pdf_finder_smb_valid_source_id($smbFilter)) {
                $warnings[] = 'Invalid SMB source selected.';
                $smbFilter = null;
            }
        }
        $smbRows = pdf_finder_smb_search($query, $smbFilter);
        if ($smbRows === [] && ($source === 'smb' || str_starts_with($source, 'smb:'))) {
            $hasIndex = false;
            foreach (pdf_finder_smb_enabled_sources() as $src) {
                if ($smbFilter !== null && $src['id'] !== $smbFilter) {
                    continue;
                }
                if (pdf_finder_smb_load_index($src['id']) !== null) {
                    $hasIndex = true;
                    break;
                }
            }
            if (!$hasIndex) {
                $warnings[] = 'No SMB index found. Rebuild SMB indexes on the Rebuild Index page.';
            }
        }
    } elseif ($source === 'smb' || str_starts_with($source, 'smb:')) {
        $warnings[] = 'SMB integration is disabled. Configure sources in config.smb.local.php.';
    }

    if ($source === 'all') {
        $results = pdf_finder_merge_all_results($pdffinderRows, $oscarRows, $smbRows);
    } elseif ($source === 'pdffinder') {
        $results = $pdffinderRows;
    } elseif ($source === 'smb' || str_starts_with($source, 'smb:')) {
        $results = $smbRows;
    } else {
        $results = $oscarRows;
    }

    return ['results' => $results, 'warnings' => $warnings];
}

/**
 * Merge pdffinder, OSCAR, and SMB results; dedupe local paths where applicable.
 *
 * @param list<array<string, mixed>> $pdffinder
 * @param list<array<string, mixed>> $oscar
 * @param list<array<string, mixed>> $smb
 * @return list<array<string, mixed>>
 */
function pdf_finder_merge_all_results(array $pdffinder, array $oscar, array $smb): array
{
    $merged = pdf_finder_merge_results($pdffinder, $oscar);
    $ids = [];
    foreach ($merged as $row) {
        if (!empty($row['id'])) {
            $ids[(string) $row['id']] = true;
        }
    }
    foreach ($smb as $row) {
        $id = (string) ($row['id'] ?? '');
        if ($id !== '' && isset($ids[$id])) {
            continue;
        }
        if ($id !== '') {
            $ids[$id] = true;
        }
        $merged[] = $row;
    }
    return $merged;
}

/**
 * View URL for a search result row.
 */
function pdf_finder_result_view_url(array $entry, string $query = ''): string
{
    $id = (string) ($entry['id'] ?? '');
    $q = $query !== '' ? '&q=' . rawurlencode($query) : '';

    if (str_starts_with($id, 'smb:')) {
        return 'view-smb.php?id=' . rawurlencode($id) . $q;
    }
    if (str_starts_with($id, 'oscar:')) {
        return 'view-oscar.php?id=' . rawurlencode($id) . $q;
    }

    return 'view.php?id=' . rawurlencode($id) . $q;
}

/**
 * Download URL for a search result row.
 */
function pdf_finder_result_download_url(array $entry): string
{
    $id = (string) ($entry['id'] ?? '');

    if (str_starts_with($id, 'smb:')) {
        return 'download-smb.php?id=' . rawurlencode($id);
    }
    if (str_starts_with($id, 'oscar:')) {
        return 'download-oscar.php?id=' . rawurlencode($id);
    }

    return 'download.php?id=' . rawurlencode($id);
}

/**
 * Label for result source column.
 */
function pdf_finder_result_source_label(array $entry): string
{
    $source = (string) ($entry['source'] ?? 'pdffinder');
    if ($source === 'pdffinder') {
        return 'Local';
    }
    if (str_starts_with($source, 'smb')) {
        require_once __DIR__ . '/smb.php';
        return pdf_finder_smb_source_label($entry);
    }
    require_once __DIR__ . '/oscar.php';
    return pdf_finder_oscar_source_label($source);
}

/**
 * Case-insensitive search against filename and full path.
 *
 * @return list<array<string, mixed>>
 */
function pdf_finder_search(string $query): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $index = pdf_finder_load_index();
    if ($index === null) {
        return [];
    }

    $needle = pdf_finder_strtolower($query);
    $results = [];

    foreach ($index['files'] as $file) {
        $haystack = pdf_finder_strtolower(
            ($file['filename'] ?? '') . ' ' . ($file['path'] ?? '')
        );
        if (pdf_finder_str_contains($haystack, $needle)) {
            $results[] = $file;
        }
    }

    return $results;
}

/**
 * Case-insensitive string compare helpers (mbstring when available).
 */
function pdf_finder_strtolower(string $value): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

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

/**
 * Human-readable file size.
 */
function pdf_finder_format_size(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    if ($bytes < 1073741824) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    return round($bytes / 1073741824, 2) . ' GB';
}

/**
 * Format Unix timestamp for display.
 */
function pdf_finder_format_date(int $timestamp): string
{
    if ($timestamp <= 0) {
        return '—';
    }
    return date('Y-m-d H:i', $timestamp);
}

/**
 * OSCAR and SMB integration flags for UI.
 *
 * @return array{oscar: bool, smb: bool}
 */
function pdf_finder_integration_flags(): array
{
    $oscar = false;
    $smb = false;
    if (is_file(__DIR__ . '/oscar_config.php')) {
        require_once __DIR__ . '/oscar_config.php';
        $oscar = pdf_finder_oscar_enabled();
    }
    if (is_file(__DIR__ . '/smb_config.php')) {
        require_once __DIR__ . '/smb_config.php';
        $smb = pdf_finder_smb_enabled();
    }

    return ['oscar' => $oscar, 'smb' => $smb];
}

/**
 * Combined OSCAR + SMB status line for page body.
 */
function pdf_finder_render_integration_status(string $class = 'meta integration-status'): void
{
    $flags = pdf_finder_integration_flags();
    if (!$flags['oscar'] && !$flags['smb']) {
        return;
    }
    ?>
    <p class="<?= h($class) ?>">
        <?php if ($flags['oscar'] && $flags['smb']): ?>
            <strong>OSCAR</strong> and <strong>SMB</strong> integrations: <strong>on</strong>
            <span class="integration-status-detail">— OSCAR live search; SMB read-only via local indexes.</span>
        <?php elseif ($flags['oscar']): ?>
            <strong>OSCAR</strong> integration: <strong>on</strong>
            <span class="integration-status-detail">— read-only database and OscarDocument search.</span>
        <?php else: ?>
            <strong>SMB</strong> integration: <strong>on</strong>
            <span class="integration-status-detail">— read-only NAS search via local indexes.</span>
        <?php endif; ?>
    </p>
    <?php
}

/**
 * Combined OSCAR + SMB badge for site header.
 */
function pdf_finder_render_integration_badges(): void
{
    $flags = pdf_finder_integration_flags();
    if (!$flags['oscar'] && !$flags['smb']) {
        return;
    }

    $parts = [];
    if ($flags['oscar']) {
        $parts[] = 'OSCAR';
    }
    if ($flags['smb']) {
        $parts[] = 'SMB';
    }
    $title = implode(' and ', $parts) . ' integration' . (count($parts) === 1 ? '' : 's') . ' enabled';
    ?>
    <span class="badge badge-integrations" title="<?= h($title) ?>"><?= h(implode(' · ', $parts)) ?> on</span>
    <?php
}

/**
 * Render page header (shared layout).
 */
function pdf_finder_header(string $title, string $active = ''): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?> — PDF Finder</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <header class="site-header">
        <div class="container">
            <h1 class="logo"><a href="index.php">PDF Finder</a></h1>
            <nav class="nav">
                <a href="index.php" class="<?= $active === 'search' ? 'is-active' : '' ?>">Search</a>
                <a href="rebuild-index.php" class="<?= $active === 'index' ? 'is-active' : '' ?>">Rebuild Index</a>
            </nav>
            <?php pdf_finder_render_integration_badges(); ?>
        </div>
    </header>
    <main class="container main-content">
    <?php
}

/**
 * Render page footer.
 */
function pdf_finder_footer(): void
{
    ?>
    </main>
    <footer class="site-footer">
        <div class="container">
            <p>PDF Finder — local PDF search by file name</p>
        </div>
    </footer>
</body>
</html>
    <?php
}
