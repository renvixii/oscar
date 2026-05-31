<?php
declare(strict_types=1);

putenv('OSCAR_DEBUG=1');

require __DIR__ . '/includes/oscar_config.php';
require __DIR__ . '/includes/oscar.php';

header('Content-Type: text/plain; charset=utf-8');

if (!pdf_finder_oscar_enabled()) {
    echo "OSCAR integration is disabled.\n";
    exit(1);
}

$c = pdf_finder_oscar_config();
echo "OSCAR integration: enabled\n";
echo "pdo_mysql: " . (extension_loaded('pdo_mysql') ? 'yes' : 'NO') . "\n";
echo "DB: {$c['db_user']}@{$c['db_host']}:{$c['db_port']}/{$c['db_name']}\n";
echo "Documents: {$c['document_path']}\n\n";

if (!extension_loaded('pdo_mysql')) {
    echo "Rebuild pdffinder: docker compose up -d --build pdffinder\n";
    exit(1);
}

$pdo = pdf_finder_oscar_pdo();
if ($pdo === null) {
    echo "DB FAILED: " . pdf_finder_oscar_last_error() . "\n";
    $ex = pdf_finder_oscar_last_pdo_exception();
    if ($ex !== null) {
        echo $ex->getMessage() . "\n";
    }
    echo "\nRun: docker compose up -d --build --force-recreate pdffinder\n";
    exit(1);
}

$n = (int) $pdo->query('SELECT COUNT(*) FROM demographic')->fetchColumn();
echo "DB OK — demographic rows: {$n}\n";

$docs = (int) $pdo->query('SELECT COUNT(*) FROM document')->fetchColumn();
echo "document rows: {$docs}\n";

$docRoot = $c['document_path'] . '/' . $c['document_subpath'];
echo is_dir($docRoot) ? "PDF folder OK: {$docRoot}\n" : "PDF folder MISSING: {$docRoot}\n";
