<?php
/**
 * OSCAR / OpenOSP read-only search and secure PDF access helpers.
 */

declare(strict_types=1);

require_once __DIR__ . '/oscar_config.php';

/**
 * Canonical Oscar document directory (.../OscarDocument/oscar/document).
 */
function pdf_finder_oscar_document_dir(): ?string
{
    if (!pdf_finder_oscar_enabled()) {
        return null;
    }
    $cfg = pdf_finder_oscar_config();
    $base = realpath($cfg['document_path']);
    if ($base === false) {
        return null;
    }
    $doc = realpath($base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cfg['document_subpath']));
    if ($doc === false || !is_dir($doc)) {
        // Allow base itself if subpath missing (misconfiguration fallback — still under OscarDocument)
        $fallback = realpath($base . DIRECTORY_SEPARATOR . 'oscar' . DIRECTORY_SEPARATOR . 'document');
        return ($fallback !== false && is_dir($fallback)) ? $fallback : null;
    }
    return $doc;
}

/**
 * PDO connection or null on failure (errors captured for UI).
 */
function pdf_finder_oscar_pdo(): ?PDO
{
    static $pdo = null;
    static $failed = false;
    static $error = '';

    if (!pdf_finder_oscar_enabled()) {
        return null;
    }
    if ($failed) {
        return null;
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = pdf_finder_oscar_config();
    if ($cfg['db_password'] === '' && getenv('OSCAR_DB_PASSWORD') === false) {
        $failed = true;
        $error = 'OSCAR database password is not configured.';
        pdf_finder_oscar_set_last_error($error);
        return null;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8',
        $cfg['db_host'],
        $cfg['db_port'],
        $cfg['db_name']
    );

    try {
        $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        $failed = true;
        $error = 'Could not connect to OSCAR database.';
        if (pdf_finder_oscar_debug()) {
            $error .= ' ' . $e->getMessage();
        }
        pdf_finder_oscar_set_last_error($error);
        pdf_finder_oscar_set_last_pdo_exception($e);
        return null;
    }

    return $pdo;
}

function pdf_finder_oscar_set_last_error(string $message): void
{
    $GLOBALS['pdf_finder_oscar_last_error'] = $message;
}

function pdf_finder_oscar_last_error(): string
{
    return (string) ($GLOBALS['pdf_finder_oscar_last_error'] ?? '');
}

function pdf_finder_oscar_last_pdo_exception(): ?PDOException
{
    $ex = $GLOBALS['pdf_finder_oscar_last_pdo_exception'] ?? null;
    return $ex instanceof PDOException ? $ex : null;
}

function pdf_finder_oscar_set_last_pdo_exception(PDOException $e): void
{
    $GLOBALS['pdf_finder_oscar_last_pdo_exception'] = $e;
}

function pdf_finder_oscar_db_available(): bool
{
    $pdo = pdf_finder_oscar_pdo();
    if ($pdo === null) {
        return false;
    }
    try {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('document','ctl_document','demographic')"
        );
        $count = (int) $stmt->fetchColumn();
        return $count >= 3;
    } catch (PDOException $e) {
        pdf_finder_oscar_set_last_error('OSCAR schema check failed.');
        return false;
    }
}

/**
 * @return list<array<string, mixed>>
 */
function pdf_finder_oscar_db_search(string $query): array
{
    if (!pdf_finder_oscar_enabled() || trim($query) === '') {
        return [];
    }
    if (!pdf_finder_oscar_db_available()) {
        return [];
    }

    $pdo = pdf_finder_oscar_pdo();
    if ($pdo === null) {
        return [];
    }

    $like = '%' . pdf_finder_oscar_escape_like(trim($query)) . '%';

    $sql = "
        SELECT
            d.document_no,
            d.docdesc,
            d.docfilename,
            d.observationdate,
            d.updatedatetime,
            dem.demographic_no,
            dem.first_name,
            dem.last_name,
            dem.chart_no
        FROM document d
        INNER JOIN ctl_document c
            ON c.document_no = d.document_no
            AND c.module = 'demographic'
            AND c.status = 'A'
        INNER JOIN demographic dem
            ON dem.demographic_no = c.module_id
        WHERE (
            dem.first_name LIKE :q1
            OR dem.last_name LIKE :q2
            OR dem.chart_no LIKE :q3
            OR CONCAT(dem.first_name, ' ', dem.last_name) LIKE :q4
            OR d.docdesc LIKE :q5
            OR d.docfilename LIKE :q6
        )
        AND LOWER(d.docfilename) LIKE '%.pdf'
        ORDER BY d.updatedatetime DESC, d.document_no DESC
        LIMIT 150
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':q1' => $like,
            ':q2' => $like,
            ':q3' => $like,
            ':q4' => $like,
            ':q5' => $like,
            ':q6' => $like,
        ]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        pdf_finder_oscar_set_last_error('OSCAR database search failed.');
        return [];
    }

    $results = [];
    $docRoot = pdf_finder_oscar_document_dir();

    foreach ($rows as $row) {
        $filename = (string) ($row['docfilename'] ?? '');
        if ($filename === '' || !preg_match('/\.pdf$/i', $filename)) {
            continue;
        }

        $resolved = pdf_finder_oscar_resolve_document_path($filename, $docRoot);
        if ($resolved === null) {
            continue;
        }

        $demoNo = (string) ($row['demographic_no'] ?? '');
        $first = (string) ($row['first_name'] ?? '');
        $last = (string) ($row['last_name'] ?? '');
        $patientName = trim($first . ' ' . $last);

        $dateRaw = $row['observationdate'] ?? $row['updatedatetime'] ?? '';
        $mtime = is_string($dateRaw) && $dateRaw !== '' ? strtotime($dateRaw) : false;

        $results[] = pdf_finder_oscar_result_entry([
            'source' => 'oscar_db',
            'id' => pdf_finder_oscar_result_id('doc', (string) $row['document_no']),
            'filename' => basename($resolved),
            'path' => $resolved,
            'directory' => dirname($resolved),
            'patient_name' => $patientName,
            'demographic_no' => $demoNo,
            'document_title' => (string) ($row['docdesc'] ?? ''),
            'chart_no' => (string) ($row['chart_no'] ?? ''),
            'document_no' => (int) $row['document_no'],
            'modified' => $mtime !== false ? (int) $mtime : 0,
            'size' => is_file($resolved) ? (int) filesize($resolved) : 0,
        ]);
    }

    return $results;
}

/**
 * Search PDF files under OscarDocument (filename/path match only).
 *
 * @return list<array<string, mixed>>
 */
function pdf_finder_oscar_document_search(string $query): array
{
    if (!pdf_finder_oscar_enabled() || trim($query) === '') {
        return [];
    }

    $docRoot = pdf_finder_oscar_document_dir();
    if ($docRoot === null) {
        pdf_finder_oscar_set_last_error('OSCAR document directory is not available.');
        return [];
    }

    $needle = pdf_finder_strtolower(trim($query));
    $results = [];
    $seen = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $docRoot,
            FilesystemIterator::SKIP_DOTS
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
        if ($real === false || isset($seen[$real])) {
            continue;
        }
        if (!pdf_finder_oscar_path_allowed($real)) {
            continue;
        }
        $seen[$real] = true;

        $haystack = pdf_finder_strtolower($real . ' ' . basename($real));
        if (!pdf_finder_str_contains($haystack, $needle)) {
            continue;
        }

        $mtime = @filemtime($real);
        $results[] = pdf_finder_oscar_result_entry([
            'source' => 'oscar_document',
            'id' => pdf_finder_oscar_result_id('file', $real),
            'filename' => basename($real),
            'path' => $real,
            'directory' => dirname($real),
            'patient_name' => pdf_finder_oscar_guess_patient_from_filename(basename($real)),
            'demographic_no' => '',
            'document_title' => '',
            'chart_no' => '',
            'document_no' => null,
            'modified' => $mtime !== false ? (int) $mtime : 0,
            'size' => (int) filesize($real),
        ]);

        if (count($results) >= 150) {
            break;
        }
    }

    return $results;
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function pdf_finder_oscar_result_entry(array $data): array
{
    return [
        'id' => (string) ($data['id'] ?? ''),
        'source' => (string) ($data['source'] ?? 'oscar_document'),
        'filename' => (string) ($data['filename'] ?? ''),
        'path' => (string) ($data['path'] ?? ''),
        'directory' => (string) ($data['directory'] ?? ''),
        'patient_name' => (string) ($data['patient_name'] ?? ''),
        'demographic_no' => (string) ($data['demographic_no'] ?? ''),
        'document_title' => (string) ($data['document_title'] ?? ''),
        'chart_no' => (string) ($data['chart_no'] ?? ''),
        'document_no' => $data['document_no'] ?? null,
        'modified' => (int) ($data['modified'] ?? 0),
        'size' => (int) ($data['size'] ?? 0),
    ];
}

function pdf_finder_oscar_result_id(string $type, string $value): string
{
    if ($type === 'doc') {
        return 'oscar:doc:' . (string) (int) $value;
    }
    return 'oscar:file:' . hash('sha256', $value);
}

/**
 * @return array<string, mixed>|null
 */
function pdf_finder_oscar_find_by_id(string $id): ?array
{
    if (preg_match('/^oscar:doc:(\d+)$/i', $id, $m)) {
        return pdf_finder_oscar_find_doc_by_no((int) $m[1]);
    }
    if (preg_match('/^oscar:file:([a-f0-9]{64})$/i', $id, $m)) {
        return pdf_finder_oscar_find_file_by_hash(strtolower($m[1]));
    }
    return null;
}

/**
 * @return array<string, mixed>|null
 */
function pdf_finder_oscar_find_doc_by_no(int $documentNo): ?array
{
    if ($documentNo <= 0 || !pdf_finder_oscar_db_available()) {
        return null;
    }
    $pdo = pdf_finder_oscar_pdo();
    if ($pdo === null) {
        return null;
    }

    $sql = "
        SELECT document_no, docdesc, docfilename, observationdate, updatedatetime
        FROM document
        WHERE document_no = :doc
        LIMIT 1
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':doc' => $documentNo]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }

    if (!$row) {
        return null;
    }

    $filename = (string) ($row['docfilename'] ?? '');
    $resolved = pdf_finder_oscar_resolve_document_path($filename, pdf_finder_oscar_document_dir());
    if ($resolved === null) {
        return null;
    }

    $demo = pdf_finder_oscar_demographic_for_document($documentNo);

    return pdf_finder_oscar_result_entry([
        'source' => 'oscar_db',
        'id' => pdf_finder_oscar_result_id('doc', (string) $documentNo),
        'filename' => basename($resolved),
        'path' => $resolved,
        'directory' => dirname($resolved),
        'patient_name' => $demo['patient_name'] ?? '',
        'demographic_no' => $demo['demographic_no'] ?? '',
        'document_title' => (string) ($row['docdesc'] ?? ''),
        'chart_no' => $demo['chart_no'] ?? '',
        'document_no' => $documentNo,
        'modified' => pdf_finder_oscar_row_timestamp($row),
        'size' => is_file($resolved) ? (int) filesize($resolved) : 0,
    ]);
}

/**
 * @return array{patient_name?: string, demographic_no?: string, chart_no?: string}|array<string, string>
 */
function pdf_finder_oscar_demographic_for_document(int $documentNo): array
{
    $pdo = pdf_finder_oscar_pdo();
    if ($pdo === null) {
        return [];
    }

    $sql = "
        SELECT dem.demographic_no, dem.first_name, dem.last_name, dem.chart_no
        FROM ctl_document c
        INNER JOIN demographic dem ON dem.demographic_no = c.module_id
        WHERE c.document_no = :doc
          AND c.module = 'demographic'
        LIMIT 1
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':doc' => $documentNo]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        return [];
    }

    if (!$row) {
        return [];
    }

    return [
        'patient_name' => trim((string) $row['first_name'] . ' ' . (string) $row['last_name']),
        'demographic_no' => (string) $row['demographic_no'],
        'chart_no' => (string) ($row['chart_no'] ?? ''),
    ];
}

/**
 * @return array<string, mixed>|null
 */
function pdf_finder_oscar_find_file_by_hash(string $hash): ?array
{
    $docRoot = pdf_finder_oscar_document_dir();
    if ($docRoot === null) {
        return null;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($docRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $item) {
        if (!$item->isFile() || !preg_match('/\.pdf$/i', $item->getFilename())) {
            continue;
        }
        $real = realpath($item->getPathname());
        if ($real === false) {
            continue;
        }
        if (pdf_finder_oscar_result_id('file', $real) !== 'oscar:file:' . $hash) {
            continue;
        }
        if (!pdf_finder_oscar_path_allowed($real)) {
            return null;
        }
        $mtime = @filemtime($real);
        return pdf_finder_oscar_result_entry([
            'source' => 'oscar_document',
            'id' => 'oscar:file:' . $hash,
            'filename' => basename($real),
            'path' => $real,
            'directory' => dirname($real),
            'patient_name' => pdf_finder_oscar_guess_patient_from_filename(basename($real)),
            'demographic_no' => '',
            'document_title' => '',
            'chart_no' => '',
            'document_no' => null,
            'modified' => $mtime !== false ? (int) $mtime : 0,
            'size' => (int) filesize($real),
        ]);
    }

    return null;
}

function pdf_finder_oscar_validate_entry(array $entry): bool
{
    if (empty($entry['path']) || !is_string($entry['path'])) {
        return false;
    }
    $path = $entry['path'];
    if (!preg_match('/\.pdf$/i', $path)) {
        return false;
    }
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }
    return pdf_finder_oscar_path_allowed($path);
}

function pdf_finder_oscar_path_allowed(string $path): bool
{
    $real = realpath($path);
    if ($real === false) {
        return false;
    }

    $docRoot = pdf_finder_oscar_document_dir();
    if ($docRoot === null) {
        return false;
    }

    $fileNorm = str_replace('\\', '/', $real);
    $docNorm = str_replace('\\', '/', $docRoot);

    // View/search only under .../OscarDocument/oscar/document (not arbitrary paths).
    return $fileNorm === $docNorm || str_starts_with($fileNorm, $docNorm . '/');
}

function pdf_finder_oscar_resolve_document_path(string $docfilename, ?string $docRoot): ?string
{
    if ($docfilename === '' || $docRoot === null) {
        return null;
    }

    // docfilename is usually a bare filename in Oscar document table
    $basename = basename(str_replace('\\', '/', $docfilename));
    if ($basename === '' || str_contains($basename, '..')) {
        return null;
    }

    $candidate = $docRoot . DIRECTORY_SEPARATOR . $basename;
    if (is_file($candidate)) {
        $real = realpath($candidate);
        return ($real !== false && pdf_finder_oscar_path_allowed($real)) ? $real : null;
    }

    return null;
}

function pdf_finder_oscar_escape_like(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

function pdf_finder_oscar_guess_patient_from_filename(string $filename): string
{
    $name = preg_replace('/\.pdf$/i', '', $filename) ?? $filename;
    return trim(str_replace(['_', '-'], ' ', $name));
}

/**
 * @param array<string, mixed> $row
 */
function pdf_finder_oscar_row_timestamp(array $row): int
{
    $raw = $row['observationdate'] ?? $row['updatedatetime'] ?? '';
    if (is_string($raw) && $raw !== '') {
        $ts = strtotime($raw);
        return $ts !== false ? (int) $ts : 0;
    }
    return 0;
}

function pdf_finder_oscar_source_label(string $source): string
{
    return match ($source) {
        'oscar_db' => 'Oscar DB',
        'oscar_document' => 'OscarDocument',
        default => 'OSCAR',
    };
}

/**
 * Merge pdffinder + oscar results; dedupe by real path.
 *
 * @param list<array<string, mixed>> $pdffinder
 * @param list<array<string, mixed>> $oscar
 * @return list<array<string, mixed>>
 */
function pdf_finder_merge_results(array $pdffinder, array $oscar): array
{
    $merged = [];
    $paths = [];

    foreach ($pdffinder as $row) {
        $path = isset($row['path']) ? realpath((string) $row['path']) : false;
        if ($path !== false) {
            $paths[$path] = true;
        }
        $row['source'] = 'pdffinder';
        $row['patient_name'] = $row['patient_name'] ?? '';
        $row['demographic_no'] = $row['demographic_no'] ?? '';
        $row['document_title'] = $row['document_title'] ?? '';
        $merged[] = $row;
    }

    foreach ($oscar as $row) {
        $path = isset($row['path']) ? realpath((string) $row['path']) : false;
        if ($path !== false && isset($paths[$path])) {
            continue;
        }
        if ($path !== false) {
            $paths[$path] = true;
        }
        $merged[] = $row;
    }

    return $merged;
}
