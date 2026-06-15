<?php
/**
 * PDF Finder — test SMB download for one indexed file (plain text diagnostics).
 *
 * Usage: test-smb-get.php?id=smb:westmount:...
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/smb.php';

header('Content-Type: text/plain; charset=utf-8');

$id = isset($_GET['id']) ? trim((string) $_GET['id']) : '';

if ($id === '') {
    echo "Missing id parameter.\n";
    echo "Copy an SMB result id from search results (starts with smb:).\n";
    exit(1);
}

$entry = pdf_finder_smb_find_by_id($id);
if ($entry === null) {
    echo "Not found in SMB index: {$id}\n";
    exit(1);
}

$source = pdf_finder_smb_source_by_id((string) $entry['source_id']);
if ($source === null) {
    echo "SMB source missing for entry.\n";
    exit(1);
}

$remote = (string) $entry['remote_path'];
echo "Source: {$source['label']} (//{$source['host']}/{$source['share']})\n";
echo "Subdirectory: " . (($source['subdirectory'] ?? '') !== '' ? $source['subdirectory'] : '(none)') . "\n";
echo "Remote path (indexed): {$remote}\n";
echo "Remote path (normalized): " . pdf_finder_smb_normalize_ls_path($remote) . "\n";
echo "Indexed size: " . (int) ($entry['size'] ?? 0) . " bytes\n\n";

$attempts = pdf_finder_smb_get_command_attempts($source, $remote, 'pdffinder_test.pdf');
echo "Will try " . count($attempts) . " smbclient command variant(s):\n";
foreach ($attempts as $i => $cmd) {
    echo '  ' . ($i + 1) . '. ' . $cmd . "\n";
}
echo "\n";

$dl = pdf_finder_smb_download_to_temp($source, $remote, (int) ($entry['size'] ?? 0));
if (!$dl['ok']) {
    echo "FAILED: {$dl['message']}\n";
    exit(1);
}

$size = filesize($dl['path']);
echo "OK — downloaded {$size} bytes to temp\n";
echo "PDF header verified.\n";
@unlink($dl['path']);
