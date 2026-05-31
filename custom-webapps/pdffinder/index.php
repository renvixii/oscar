<?php
/**
 * PDF Finder — main search page (search form)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/oscar_config.php';

$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$oscarEnabled = pdf_finder_oscar_enabled();

// If a query is present on the home URL, forward to the search results page.
if ($query !== '') {
    header('Location: search.php?q=' . rawurlencode($query));
    exit;
}

$index = pdf_finder_load_index();
$indexedCount = $index['count'] ?? 0;

pdf_finder_header('Search', 'search');
?>

<div class="card card--center">
    <h2 class="page-title">Find a PDF</h2>
    <p class="page-subtitle">Search by first name, last name, or patient ID</p>

    <?php if ($index === null && !$oscarEnabled): ?>
        <div class="alert alert-warning">
            PDF index not found. Please <a href="rebuild-index.php">rebuild the index</a> first.
        </div>
    <?php elseif ($index === null && $oscarEnabled): ?>
        <div class="alert alert-warning">
            PDF Finder index not found — you can still search <strong>OSCAR</strong> sources.
            <a href="rebuild-index.php">Rebuild the index</a> to include local <code>pdf-files</code>.
        </div>
    <?php else: ?>
        <p class="meta"><?= (int) $indexedCount ?> PDF<?= $indexedCount === 1 ? '' : 's' ?> indexed (PDF Finder folders)</p>
    <?php endif; ?>

    <?php if ($oscarEnabled): ?>
        <p class="meta">OSCAR integration: <strong>on</strong> — optional search in Oscar database and OscarDocument.</p>
    <?php endif; ?>

    <form class="search-form" action="search.php" method="get" role="search">
        <input
            type="search"
            name="q"
            class="search-input"
            placeholder="Search by patient name, chart number, or file name"
            autocomplete="off"
            <?= ($index === null && !$oscarEnabled) ? 'disabled' : '' ?>
        >
        <?php if ($oscarEnabled): ?>
            <input type="hidden" name="source" value="all">
        <?php endif; ?>
        <button type="submit" class="btn btn-primary" <?= ($index === null && !$oscarEnabled) ? 'disabled' : '' ?>>Search</button>
    </form>
</div>

<?php
pdf_finder_footer();
