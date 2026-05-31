<?php
/**
 * PDF Finder — view PDF in browser (HTML viewer + secure inline stream)
 *
 * URLs use only the indexed file ID — never the raw file path.
 * Inline PDF: view.php?id=...&stream=1
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$id = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$stream = isset($_GET['stream']) && $_GET['stream'] === '1';

if ($id === '') {
    http_response_code(400);
    pdf_finder_header('View PDF');
    echo '<div class="alert alert-error">Missing file reference.</div>';
    pdf_finder_footer();
    exit;
}

$entry = pdf_finder_find_by_id($id);

if ($entry === null) {
    http_response_code(404);
    pdf_finder_header('View PDF');
    echo '<div class="alert alert-error">File not found in the index. Rebuild the index if files were added recently.</div>';
    echo '<p><a href="index.php">Back to Search</a></p>';
    pdf_finder_footer();
    exit;
}

if (!pdf_finder_validate_entry($entry)) {
    http_response_code(404);
    pdf_finder_header('View PDF');
    echo '<div class="alert alert-error">PDF file is missing or not allowed. It may have been moved or deleted.</div>';
    echo '<p><a href="index.php">Back to Search</a></p>';
    pdf_finder_footer();
    exit;
}

$path = (string) $entry['path'];
$filename = (string) ($entry['filename'] ?? basename($path));

// Stream PDF inline for iframe/embed (same page, stream flag only).
if ($stream) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $filename) . '"');
    header('Content-Length: ' . (string) filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        http_response_code(500);
        exit('Could not open file.');
    }
    while (!feof($handle)) {
        echo fread($handle, 8192);
    }
    fclose($handle);
    exit;
}

// HTML viewer page
$backQuery = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$backUrl = $backQuery !== ''
    ? 'search.php?q=' . rawurlencode($backQuery)
    : 'index.php';

$streamUrl = 'view.php?id=' . rawurlencode($id) . '&stream=1';

pdf_finder_header('View: ' . $filename);
?>

<div class="viewer-toolbar">
    <h2 class="viewer-title"><?= h($filename) ?></h2>
    <div class="btn-group">
        <a class="btn btn-secondary" href="<?= h($backUrl) ?>">&larr; Back</a>
        <a class="btn btn-primary" href="download.php?id=<?= h($id) ?>">Download</a>
    </div>
</div>

<div class="pdf-frame-wrap">
    <iframe
        class="pdf-frame"
        src="<?= h($streamUrl) ?>"
        title="<?= h('PDF: ' . $filename) ?>"
    ></iframe>
</div>

<p class="meta" style="margin-top: 0.75rem;">
    Folder: <?= h($entry['directory'] ?? '') ?>
    &middot;
    <?= h(pdf_finder_format_size((int) ($entry['size'] ?? 0))) ?>
    &middot;
    <?= h(pdf_finder_format_date((int) ($entry['modified'] ?? 0))) ?>
</p>

<?php
pdf_finder_footer();
