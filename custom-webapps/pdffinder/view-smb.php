<?php
/**
 * PDF Finder — view SMB PDF in browser (streamed via smbclient, read-only).
 *
 * URLs use only indexed IDs — never remote SMB paths or credentials.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/smb.php';

$id = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$stream = isset($_GET['stream']) && $_GET['stream'] === '1';

if ($id === '') {
    http_response_code(400);
    pdf_finder_header('View PDF');
    echo '<div class="alert alert-error">Missing file reference.</div>';
    pdf_finder_footer();
    exit;
}

$entry = pdf_finder_smb_find_by_id($id);

if ($entry === null || !pdf_finder_smb_validate_entry($entry)) {
    http_response_code(404);
    pdf_finder_header('View PDF');
    echo '<div class="alert alert-error">PDF not found in the SMB index. Rebuild the SMB index if files were added recently.</div>';
    echo '<p><a href="index.php">Back to Search</a></p>';
    pdf_finder_footer();
    exit;
}

$filename = (string) ($entry['filename'] ?? 'document.pdf');

if ($stream) {
    pdf_finder_smb_stream_file($entry, true);
}

$backQuery = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$backUrl = $backQuery !== ''
    ? 'search.php?q=' . rawurlencode($backQuery)
    : 'index.php';

$streamUrl = 'view-smb.php?id=' . rawurlencode($id) . '&stream=1';

pdf_finder_header('View: ' . $filename);
?>

<div class="viewer-toolbar">
    <h2 class="viewer-title"><?= h($filename) ?></h2>
    <div class="btn-group">
        <a class="btn btn-secondary" href="<?= h($backUrl) ?>">&larr; Back</a>
        <a class="btn btn-primary" href="download-smb.php?id=<?= h($id) ?>">Download</a>
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
    Source: <?= h(pdf_finder_smb_source_label($entry)) ?>
    <?php if (!empty($entry['directory'])): ?>
        &middot; Folder: <?= h((string) $entry['directory']) ?>
    <?php endif; ?>
    &middot; <?= h(pdf_finder_format_size((int) ($entry['size'] ?? 0))) ?>
    &middot; <?= h(pdf_finder_format_date((int) ($entry['modified'] ?? 0))) ?>
</p>

<?php
pdf_finder_footer();
