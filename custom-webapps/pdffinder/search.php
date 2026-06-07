<?php
/**
 * PDF Finder — search results (local index + optional OSCAR + SMB sources)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/oscar_config.php';
require_once __DIR__ . '/includes/smb_config.php';

$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$source = isset($_GET['source']) ? pdf_finder_normalize_search_source((string) $_GET['source']) : 'all';
$oscarEnabled = pdf_finder_oscar_enabled();
$smbEnabled = pdf_finder_smb_enabled();
$extendedSources = pdf_finder_extended_sources_enabled();
$searchAvailable = pdf_finder_search_available();

if (!$extendedSources) {
    $source = 'pdffinder';
}

$index = pdf_finder_load_index();
$results = [];
$warnings = [];
$searched = false;
$sourceOptions = pdf_finder_search_source_options();

if ($query !== '') {
    $searched = true;
    if (!in_array($source, $sourceOptions, true)) {
        $source = 'all';
    }
    if ($source === 'pdffinder' && $index === null) {
        $warnings[] = 'PDF Finder index not found. Rebuild the local index or choose another source.';
    } else {
        $unified = pdf_finder_search_unified($query, $source);
        $results = $unified['results'];
        $warnings = $unified['warnings'];
        if ($index === null && $source === 'all' && ($oscarEnabled || $smbEnabled)) {
            $warnings[] = 'PDF Finder local index not found — other sources may still return results.';
        }
    }
}

$resultCount = count($results);
$indexedCount = $index['count'] ?? 0;
$showSourceColumn = $extendedSources;

pdf_finder_header('Search Results', 'search');
?>

<div class="card">
    <h2 class="page-title">Search</h2>

    <?php if ($index === null && $source === 'pdffinder'): ?>
        <div class="alert alert-warning">
            PDF index not found. Please <a href="rebuild-index.php">rebuild the index</a> first.
        </div>
    <?php elseif ($index !== null): ?>
        <p class="meta"><?= (int) $indexedCount ?> PDF<?= $indexedCount === 1 ? '' : 's' ?> in local PDF Finder index</p>
    <?php endif; ?>

    <?php pdf_finder_render_integration_status(); ?>

    <form class="search-form search-form--stacked" action="search.php" method="get" role="search">
        <input
            type="search"
            name="q"
            class="search-input"
            placeholder="Search by patient name, chart number, file name, or document title"
            value="<?= h($query) ?>"
            autocomplete="off"
            <?= $searchAvailable ? '' : 'disabled' ?>
            autofocus
        >
        <?php if ($extendedSources): ?>
            <label class="source-label" for="source">Search in</label>
            <select name="source" id="source" class="search-select">
                <?php foreach ($sourceOptions as $opt): ?>
                    <option value="<?= h($opt) ?>" <?= $source === $opt ? 'selected' : '' ?>>
                        <?= h(pdf_finder_search_source_label($opt)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary" <?= $searchAvailable ? '' : 'disabled' ?>>Search</button>
    </form>
</div>

<?php if ($searched && $warnings !== []): ?>
    <div class="card">
        <?php foreach ($warnings as $warning): ?>
            <div class="alert alert-warning"><?= h($warning) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($searched): ?>
    <div class="card">
        <?php if ($resultCount === 0): ?>
            <div class="alert alert-info">No PDF found.</div>
            <p class="meta">0 results for &ldquo;<?= h($query) ?>&rdquo;</p>
        <?php else: ?>
            <p class="meta">
                <strong><?= (int) $resultCount ?></strong>
                result<?= $resultCount === 1 ? '' : 's' ?> for &ldquo;<?= h($query) ?>&rdquo;
                <?php if ($extendedSources): ?>
                    &middot; filter: <?= h(pdf_finder_search_source_label($source)) ?>
                <?php endif; ?>
            </p>

            <div class="results-table-wrap">
                <table class="results-table">
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>File name</th>
                            <?php if ($showSourceColumn): ?>
                                <th>Source</th>
                            <?php endif; ?>
                            <th>Document / folder</th>
                            <th>Date</th>
                            <th>Size</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $file): ?>
                            <?php
                            $id = (string) ($file['id'] ?? '');
                            $size = (int) ($file['size'] ?? 0);
                            $modified = (int) ($file['modified'] ?? 0);
                            $patient = trim((string) ($file['patient_name'] ?? ''));
                            $demoNo = (string) ($file['demographic_no'] ?? '');
                            $docTitle = (string) ($file['document_title'] ?? '');
                            $patientLine = $patient !== '' ? $patient : '—';
                            if ($demoNo !== '') {
                                $patientLine .= ' (' . $demoNo . ')';
                            }
                            $detail = $docTitle !== '' ? $docTitle : ($file['directory'] ?? '');
                            ?>
                            <tr>
                                <td data-label="Patient"><?= h($patientLine) ?></td>
                                <td data-label="File name">
                                    <span class="filename"><?= h($file['filename'] ?? '') ?></span>
                                </td>
                                <?php if ($showSourceColumn): ?>
                                    <td data-label="Source">
                                        <span class="badge badge-source-<?= h(str_replace(':', '-', (string) ($file['source'] ?? 'pdffinder'))) ?>">
                                            <?= h(pdf_finder_result_source_label($file)) ?>
                                        </span>
                                    </td>
                                <?php endif; ?>
                                <td data-label="Details">
                                    <span class="folder"><?= h($detail) ?></span>
                                </td>
                                <td data-label="Date"><?= h(pdf_finder_format_date($modified)) ?></td>
                                <td data-label="Size"><?= h(pdf_finder_format_size($size)) ?></td>
                                <td class="actions" data-label="">
                                    <div class="btn-group">
                                        <a class="btn btn-secondary btn-sm" href="<?= h(pdf_finder_result_view_url($file, $query)) ?>">View</a>
                                        <a class="btn btn-primary btn-sm" href="<?= h(pdf_finder_result_download_url($file)) ?>">Download</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<p><a href="index.php">&larr; Back to home</a></p>

<?php
pdf_finder_footer('app');
