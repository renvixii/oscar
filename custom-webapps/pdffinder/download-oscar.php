<?php
/**
 * PDF Finder — download OSCAR PDF (read-only, OscarDocument only)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/oscar.php';

if (!pdf_finder_oscar_enabled()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'OSCAR integration is disabled.';
    exit;
}

$id = isset($_GET['id']) ? trim((string) $_GET['id']) : '';

if ($id === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Missing file reference.';
    exit;
}

$entry = pdf_finder_oscar_find_by_id($id);

if ($entry === null || !pdf_finder_oscar_validate_entry($entry)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'PDF not found or not allowed.';
    exit;
}

$path = (string) $entry['path'];
$filename = basename(str_replace(["\r", "\n", '"'], '', (string) ($entry['filename'] ?? basename($path))));

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-cache');

$handle = fopen($path, 'rb');
if ($handle === false) {
    http_response_code(500);
    echo 'Could not open file.';
    exit;
}

while (!feof($handle)) {
    echo fread($handle, 8192);
}
fclose($handle);
exit;
