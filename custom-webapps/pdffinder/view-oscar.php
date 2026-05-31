<?php
/**
 * PDF Finder — view OSCAR/OpenOSP PDF (read-only, OscarDocument only)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/oscar.php';

if (!pdf_finder_oscar_enabled()) {
    http_response_code(403);
    pdf_finder_header('View PDF');
    echo '<div class="alert alert-error">OSCAR integration is disabled.</div>';
    pdf_finder_footer();
    exit;
}

$id = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$stream = isset($_GET['stream']) && $_GET['stream'] === '1';

if ($id === '') {
    http_response_code(400);
    pdf_finder_header('View PDF');
    echo '<div class="alert alert-error">Missing file reference.</div>';
    pdf_finder_footer();
    exit;
}

$entry = pdf_finder_oscar_find_by_id($id);

if ($entry === null) {
    http_response_code(404);
    pdf_finder_header('View PDF');
    echo '<div class="alert alert-error">OSCAR document not found or not linked to a PDF file on disk.</div>';
    echo '<p><a href="index.php">Back to Search</a></p>';
    pdf_finder_footer();
    exit;
}

if (!pdf_finder_oscar_validate_entry($entry)) {
    http_response_code(404);
    pdf_finder_header('View PDF');
    echo '<div class="alert alert-error">PDF is missing, not readable, or outside the allowed OscarDocument area.</div>';
    pdf_finder_footer();
    exit;
}

$path = (string) $entry['path'];
$filename = (string) ($entry['filename'] ?? basename($path));

if ($stream) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . str_replace('"', '', basename($filename)) . '"');
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

$backQuery = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$backUrl = $backQuery !== ''
    ? 'search.php?q=' . rawurlencode($backQuery) . '&source=all'
    : 'index.php';

$streamUrl = 'view-oscar.php?id=' . rawurlencode($id) . '&stream=1';

pdf_finder_header('View: ' . $filename);
?>

<div class="viewer-toolbar">
    <h2 class="viewer-title"><?= h($filename) ?></h2>
    <div class="btn-group">
        <a class="btn btn-secondary" href="<?= h($backUrl) ?>">&larr; Back</a>
        <a class="btn btn-primary" href="download-oscar.php?id=<?= h($id) ?>">Download</a>
    </div>
</div>

<?php if (($entry['patient_name'] ?? '') !== ''): ?>
    <p class="meta">
        Patient: <?= h($entry['patient_name']) ?>
        <?php if (($entry['demographic_no'] ?? '') !== ''): ?>
            &middot; Demo #<?= h($entry['demographic_no']) ?>
        <?php endif; ?>
        <?php if (($entry['document_title'] ?? '') !== ''): ?>
            &middot; <?= h($entry['document_title']) ?>
        <?php endif; ?>
        &middot; <span class="badge badge-oscar"><?= h(pdf_finder_result_source_label($entry)) ?></span>
    </p>
<?php endif; ?>

<div class="pdf-frame-wrap">
    <iframe
        class="pdf-frame"
        src="<?= h($streamUrl) ?>"
        title="<?= h('PDF: ' . $filename) ?>"
    ></iframe>
</div>

<p class="meta" style="margin-top: 0.75rem;">
    Read-only view from OscarDocument (no changes to OSCAR).
</p>

<?php
pdf_finder_footer();
