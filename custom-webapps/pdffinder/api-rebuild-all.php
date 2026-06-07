<?php
/**
 * PDF Finder — async JSON endpoint for rebuild all (local + SMB).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

ignore_user_abort(true);
set_time_limit(0);

$result = pdf_finder_rebuild_all();

echo json_encode([
    'ok' => $result['ok'],
    'partial' => $result['partial'],
    'message' => $result['message'],
    'count' => $result['count'],
    'built_at' => $result['built_at'],
    'smb_results' => $result['smb_results'],
    'parts' => $result['parts'],
], JSON_UNESCAPED_SLASHES);
