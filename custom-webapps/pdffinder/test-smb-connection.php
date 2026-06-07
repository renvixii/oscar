<?php
declare(strict_types=1);

require __DIR__ . '/includes/smb_config.php';
require __DIR__ . '/includes/smb.php';

header('Content-Type: text/plain; charset=utf-8');

/** Max items to print per list (remainder shown as count only). */
const PDF_FINDER_SMB_TEST_SAMPLE_LIMIT = 5;

/**
 * @param list<string> $items
 */
function pdf_finder_smb_test_print_sample(string $label, array $items, string $prefix = '  '): void
{
    $total = count($items);
    if ($total === 0) {
        echo "{$label}: 0\n";
        return;
    }
    echo "{$label}: {$total}\n";
    foreach (array_slice($items, 0, PDF_FINDER_SMB_TEST_SAMPLE_LIMIT) as $item) {
        echo $prefix . $item . "\n";
    }
    if ($total > PDF_FINDER_SMB_TEST_SAMPLE_LIMIT) {
        echo $prefix . '... and ' . ($total - PDF_FINDER_SMB_TEST_SAMPLE_LIMIT) . " more\n";
    }
}

function pdf_finder_smb_test_print_brief_error(string $message): void
{
    $lines = array_values(array_filter(explode("\n", trim($message)), static fn(string $l): bool => trim($l) !== ''));
    $shown = array_slice($lines, 0, 6);
    foreach ($shown as $line) {
        echo $line . "\n";
    }
    if (count($lines) > count($shown)) {
        echo '  (... ' . (count($lines) - count($shown)) . " more lines omitted)\n";
    }
}

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

echo "=== SHARE DISCOVERY BY HOST ===\n";
echo "Lists SMB shares and top-level folders (sample of " . PDF_FINDER_SMB_TEST_SAMPLE_LIMIT . " each).\n\n";

foreach (pdf_finder_smb_host_groups() as $group) {
    $host = $group['host'];
    $username = $group['username'];

    echo str_repeat('=', 60) . "\n";
    echo "Host: {$host}\n";
    echo "User: {$username}\n";
    echo 'Password: ' . pdf_finder_smb_mask_password($group['password']) . "\n\n";

    $discovery = pdf_finder_smb_discover_host(
        $host,
        $username,
        $group['password'],
        $group['sources']
    );

    if (!$discovery['ok']) {
        echo "Share list: FAILED\n";
        echo ($discovery['raw_error'] !== '' ? $discovery['raw_error'] : $discovery['message']) . "\n\n";
        continue;
    }

    if ($discovery['shares'] === []) {
        echo "Share list: no shares parsed (check credentials / SMB access).\n\n";
    } else {
        $diskShares = $discovery['disk_shares'];
        echo 'Disk shares on //' . $host . ': ' . ($diskShares !== [] ? count($diskShares) : 0) . "\n";
        pdf_finder_smb_test_print_sample('  Sample', $diskShares, '    [Disk] ');
        echo "\n";
    }

    foreach ($discovery['sources'] as $report) {
        echo str_repeat('-', 60) . "\n";
        echo 'Configured source: ' . $report['label'] . ' (' . $report['id'] . ")\n";
        echo 'Enabled: ' . ($report['enabled'] ? 'yes' : 'no') . "\n";
        echo 'Configured share name: ' . $report['configured_share'] . "\n";

        foreach ($report['config_warnings'] ?? [] as $warning) {
            echo "CONFIG WARNING: {$warning}\n";
        }

        if ($discovery['disk_shares'] !== [] && !$report['share_visible']) {
            echo "WARNING: configured share '" . $report['configured_share'] . "' was NOT found on this host.\n";
            echo "         Pick a [Disk] name from the list above in config.smb.local.php.\n";
        } elseif ($discovery['disk_shares'] !== [] && $report['share_visible']) {
            echo "Configured share: visible on host\n";
            if (
                !empty($report['share_canonical'])
                && $report['share_canonical'] !== $report['configured_share']
            ) {
                echo "NOTE: NAS lists share as '" . $report['share_canonical'] . "'. ";
                echo "Try 'share' => '" . $report['share_canonical'] . "' in config.\n";
            }
        }

        $root = $report['root'];
        if ($root['ok']) {
            echo "\nTop-level on //{$host}/{$report['configured_share']}:\n";
            pdf_finder_smb_test_print_sample('[dir] folders', $root['directories'], '  ');
            pdf_finder_smb_test_print_sample('[pdf] at root', $root['pdfs'], '  ');

            if ($report['subdirectory'] === '') {
                echo "\nTip: set 'subdirectory' to a [dir] name above to scope indexing.\n";
            }
        } else {
            echo "\nShare root not listed: ";
            pdf_finder_smb_test_print_brief_error($root['message']);
        }

        if ($report['subdirectory'] !== '') {
            echo "\nConfigured subdirectory: " . $report['subdirectory'] . "\n";
            $sub = $report['subdirectory_listing'];
            if ($sub === null && ($report['config_warnings'] ?? []) !== []) {
                echo "Subdirectory test skipped (fix config warning above).\n";
            } elseif ($sub !== null && $sub['ok']) {
                pdf_finder_smb_test_print_sample('[dir] under subdirectory', $sub['directories'], '  ');
                pdf_finder_smb_test_print_sample('[pdf] at this level', $sub['pdfs'], '  ');
            } elseif ($sub !== null) {
                echo "Subdirectory list failed:\n";
                pdf_finder_smb_test_print_brief_error($sub['message']);
                if (!empty($sub['hint_directories'])) {
                    pdf_finder_smb_test_print_sample(
                        'Folders at share root (use one of these in subdirectory, or leave empty)',
                        $sub['hint_directories'],
                        '  '
                    );
                }
            }
        }

        echo "\n";
    }
}

echo "=== PER-SOURCE RECURSIVE PDF TEST ===\n";
echo "Runs recurse ON; ls (same as index rebuild). Shows count + sample of " . PDF_FINDER_SMB_TEST_SAMPLE_LIMIT . " paths.\n\n";

foreach ($sources as $source) {
    echo str_repeat('-', 60) . "\n";
    echo 'Source: ' . $source['label'] . ' (' . $source['id'] . ")\n";
    echo 'Target: //' . $source['host'] . '/' . $source['share'];
    if ($source['subdirectory'] !== '') {
        echo '/' . $source['subdirectory'];
    }
    echo "\n";
    echo 'Local index: ' . basename($source['index_file']) . "\n";

    $index = pdf_finder_smb_load_index($source['id']);
    if ($index !== null) {
        echo 'Cached index: ' . (int) ($index['count'] ?? 0) . ' PDF(s)';
        if (!empty($index['built_at'])) {
            echo ' (built ' . $index['built_at'] . ')';
        }
        echo "\n";
    }

    if (!$source['enabled']) {
        echo "Skipped recursive test (source disabled).\n\n";
        continue;
    }

    echo "Running recursive scan...\n";
    $test = pdf_finder_smb_test_connection($source, PDF_FINDER_SMB_TEST_SAMPLE_LIMIT);
    echo 'Result: ' . ($test['ok'] ? 'OK' : 'FAILED') . "\n";
    if (!$test['ok']) {
        echo "Error:\n";
        pdf_finder_smb_test_print_brief_error($test['message']);
        foreach (pdf_finder_smb_config_warnings($source) as $warning) {
            echo "CONFIG WARNING: {$warning}\n";
        }
        echo "\n";
        continue;
    }

    echo $test['message'] . "\n";
    echo 'Total PDFs (recursive): ' . $test['pdf_count'] . "\n";

    $r = $test['recursive'] ?? pdf_finder_smb_empty_recursive_stats();
    echo 'At share root: ' . (int) ($r['at_root'] ?? 0) . "\n";
    echo 'In subfolders: ' . (int) ($r['in_subfolders'] ?? 0) . "\n";
    echo 'Max folder depth: ' . (int) ($r['max_depth'] ?? 0) . "\n";
    $depths = $r['depths_with_pdfs'] ?? [];
    if (is_array($depths) && $depths !== []) {
        echo 'Depth levels with PDFs: ' . implode(', ', array_map('strval', $depths)) . "\n";
    }

    if ($test['sample'] !== []) {
        pdf_finder_smb_test_print_sample('Sample paths (showing folder depth)', $test['sample'], '  ');
    } elseif ($test['pdf_count'] === 0) {
        echo "No PDFs found in recursive scan.\n";
    }
    echo "\n";
}

echo "=== END ===\n";
echo "Large shares may take a while to scan; only " . PDF_FINDER_SMB_TEST_SAMPLE_LIMIT . " sample paths are printed.\n";
echo "pdffinder never writes to remote SMB shares — indexes are stored under storage/ only.\n";
