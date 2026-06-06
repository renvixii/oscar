<?php
/**
 * PDF Finder — secure SMB download (indexed files only, streamed read-only).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/smb.php';

$id = isset($_GET['id']) ? trim((string) $_GET['id']) : '';

if ($id === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Missing file reference.';
    exit;
}

$entry = pdf_finder_smb_find_by_id($id);

if ($entry === null || !pdf_finder_smb_validate_entry($entry)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'PDF not found in the SMB index.';
    exit;
}

pdf_finder_smb_stream_file($entry, false);
