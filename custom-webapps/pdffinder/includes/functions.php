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
 * Valid search source keys when OSCAR integration is enabled.
 *
 * @return list<string>
 */
function pdf_finder_search_source_options(): array
{
    return ['all', 'pdffinder', 'oscar_db', 'oscar_document'];
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

    $source = strtolower($source);
    if (!in_array($source, pdf_finder_search_source_options(), true)) {
        $source = 'all';
    }

    require_once __DIR__ . '/oscar.php';

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

    if ($source === 'all') {
        $results = pdf_finder_merge_results($pdffinderRows, $oscarRows);
    } elseif ($source === 'pdffinder') {
        $results = $pdffinderRows;
    } else {
        $results = $oscarRows;
    }

    return ['results' => $results, 'warnings' => $warnings];
}

/**
 * View URL for a search result row.
 */
function pdf_finder_result_view_url(array $entry, string $query = ''): string
{
    $id = (string) ($entry['id'] ?? '');
    $source = (string) ($entry['source'] ?? 'pdffinder');
    $q = $query !== '' ? '&q=' . rawurlencode($query) : '';

    if ($source === 'pdffinder' || !str_starts_with($id, 'oscar:')) {
        return 'view.php?id=' . rawurlencode($id) . $q;
    }

    return 'view-oscar.php?id=' . rawurlencode($id) . $q;
}

/**
 * Download URL for a search result row.
 */
function pdf_finder_result_download_url(array $entry): string
{
    $id = (string) ($entry['id'] ?? '');
    $source = (string) ($entry['source'] ?? 'pdffinder');

    if ($source === 'pdffinder' || !str_starts_with($id, 'oscar:')) {
        return 'download.php?id=' . rawurlencode($id);
    }

    return 'download-oscar.php?id=' . rawurlencode($id);
}

/**
 * Label for result source column.
 */
function pdf_finder_result_source_label(array $entry): string
{
    $source = (string) ($entry['source'] ?? 'pdffinder');
    if ($source === 'pdffinder') {
        return 'PDF Finder';
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
 * Render page header (shared layout).
 */
function pdf_finder_header(string $title, string $active = ''): void
{
    $oscarOn = false;
    if (is_file(__DIR__ . '/oscar_config.php')) {
        require_once __DIR__ . '/oscar_config.php';
        $oscarOn = pdf_finder_oscar_enabled();
    }
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
            <?php if ($oscarOn): ?>
                <span class="badge badge-oscar" title="OSCAR integration enabled">OSCAR on</span>
            <?php endif; ?>
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
