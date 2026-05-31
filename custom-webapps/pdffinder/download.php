<?php
/**
 * PDF Finder — secure download (indexed files only, original file name)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$id = isset($_GET['id']) ? trim((string) $_GET['id']) : '';

if ($id === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Missing file reference.';
    exit;
}

$index = pdf_finder_load_index();
if ($index === null) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'PDF index not found. Please rebuild the index first.';
    exit;
}

$entry = pdf_finder_find_by_id($id);

if ($entry === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'File not found in the index.';
    exit;
}

if (!pdf_finder_validate_entry($entry)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'PDF file is missing or not allowed.';
    exit;
}

$path = (string) $entry['path'];
$filename = (string) ($entry['filename'] ?? basename($path));
// Safe attachment name (no path segments).
$filename = basename(str_replace(["\r", "\n", '"'], '', $filename));

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
