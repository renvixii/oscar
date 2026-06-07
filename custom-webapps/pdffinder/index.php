<?php
/**
 * PDF Finder — main search page (search form)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/oscar_config.php';
require_once __DIR__ . '/includes/smb_config.php';

$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$oscarEnabled = pdf_finder_oscar_enabled();
$smbEnabled = pdf_finder_smb_enabled();
$searchAvailable = pdf_finder_search_available();

if ($query !== '') {
    $params = 'q=' . rawurlencode($query);
    if ($oscarEnabled || $smbEnabled) {
        $params .= '&source=all';
    }
    header('Location: search.php?' . $params);
    exit;
}

$index = pdf_finder_load_index();
$indexedCount = $index['count'] ?? 0;

pdf_finder_header('Search', 'search');
?>

<div class="card card--center">
    <h2 class="page-title">Find a PDF</h2>
    <p class="page-subtitle">Search by first name, last name, or patient ID</p>

    <?php if ($index === null && !$oscarEnabled && !$smbEnabled): ?>
        <div class="alert alert-warning">
            PDF index not found. Please <a href="rebuild-index.php">rebuild the index</a> first.
        </div>
    <?php elseif ($index === null && ($oscarEnabled || $smbEnabled)): ?>
        <div class="alert alert-warning">
            Local PDF index not found — you can still search
            <?php if ($oscarEnabled && $smbEnabled): ?>
                <strong>OSCAR</strong> and <strong>SMB</strong> sources.
            <?php elseif ($oscarEnabled): ?>
                <strong>OSCAR</strong> sources.
            <?php else: ?>
                <strong>SMB</strong> sources.
            <?php endif; ?>
            <a href="rebuild-index.php">Rebuild the local index</a> to include <code>pdf-files</code> folders.
        </div>
    <?php else: ?>
        <p class="meta"><?= (int) $indexedCount ?> PDF<?= $indexedCount === 1 ? '' : 's' ?> indexed (local folders)</p>
    <?php endif; ?>

    <?php pdf_finder_render_integration_status(); ?>

    <form class="search-form" action="search.php" method="get" role="search">
        <input
            type="search"
            name="q"
            class="search-input"
            placeholder="Search by patient name, chart number, or file name"
            autocomplete="off"
            <?= $searchAvailable ? '' : 'disabled' ?>
        >
        <?php if ($oscarEnabled || $smbEnabled): ?>
            <input type="hidden" name="source" value="all">
        <?php endif; ?>
        <button type="submit" class="btn btn-primary" <?= $searchAvailable ? '' : 'disabled' ?>>Search</button>
    </form>
</div>

<?php
pdf_finder_footer('app');
