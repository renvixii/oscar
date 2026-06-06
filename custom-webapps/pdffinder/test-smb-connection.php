<?php
declare(strict_types=1);

require __DIR__ . '/includes/smb_config.php';
require __DIR__ . '/includes/smb.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== PDF Finder SMB diagnostics ===\n\n";

$smbclient = pdf_finder_smbclient_path();
echo 'smbclient installed: ' . ($smbclient !== null ? 'yes (' . $smbclient . ')' : 'NO') . "\n\n";

if ($smbclient === null) {
    echo "Install smbclient in the pdffinder container:\n";
    echo "  RUN apt-get update && apt-get install -y smbclient && rm -rf /var/lib/apt/lists/*\n\n";
    echo "Then rebuild the pdffinder image.\n";
    exit(1);
}

$sources = pdf_finder_smb_sources_raw();
if ($sources === []) {
    echo "No SMB sources configured.\n";
    echo "Copy config.smb.local.php.example to config.smb.local.php and edit.\n";
    exit(0);
}

foreach ($sources as $source) {
    echo str_repeat('-', 60) . "\n";
    echo 'Source: ' . $source['label'] . ' (' . $source['id'] . ")\n";
    echo 'Enabled: ' . ($source['enabled'] ? 'yes' : 'no') . "\n";
    echo 'Target: //' . $source['host'] . '/' . $source['share'] . "\n";
    echo 'User: ' . $source['username'] . "\n";
    echo 'Password: ' . pdf_finder_smb_mask_password($source['password']) . "\n";
    if ($source['subdirectory'] !== '') {
        echo 'Subdirectory: ' . $source['subdirectory'] . "\n";
    }
    echo 'Local index: ' . basename($source['index_file']) . "\n";

    $index = pdf_finder_smb_load_index($source['id']);
    if ($index !== null) {
        echo 'Cached index: ' . (int) ($index['count'] ?? 0) . ' PDF(s)';
        if (!empty($index['built_at'])) {
            echo ' (built ' . $index['built_at'] . ')';
        }
        echo "\n";
    } else {
        echo "Cached index: none — rebuild on Rebuild Index page\n";
    }

    if (!$source['enabled']) {
        echo "Skipped connection test (disabled).\n\n";
        continue;
    }

    $test = pdf_finder_smb_test_connection($source);
    echo 'Connection: ' . ($test['ok'] ? 'OK' : 'FAILED') . "\n";
    if (!$test['ok']) {
        echo 'Error: ' . $test['message'] . "\n\n";
        continue;
    }

    echo 'Live PDF count (recursive): ' . $test['pdf_count'] . "\n";
    if ($test['sample'] !== []) {
        echo "Sample PDFs:\n";
        foreach ($test['sample'] as $name) {
            echo '  - ' . $name . "\n";
        }
    }
    echo "\n";
}

echo "=== END ===\n";
echo "pdffinder never writes to remote SMB shares — indexes are stored under storage/ only.\n";
