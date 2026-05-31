<?php
/**
 * PDF Finder — rebuild the PDF index (scan directories once, save to JSON)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$message = '';
$messageType = '';
$count = 0;
$builtAt = '';

// Handle rebuild POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $directories = pdf_finder_directories();

    if ($directories === []) {
        $message = 'No PDF directories configured. Edit config.php and add at least one path.';
        $messageType = 'error';
    } else {
        $files = pdf_finder_build_index();

        if (pdf_finder_save_index($files)) {
            $count = count($files);
            $builtAt = date('Y-m-d H:i:s');
            $message = 'Index rebuilt successfully.';
            $messageType = 'success';
        } else {
            $message = 'Could not write the index file. Check folder permissions for the storage directory.';
            $messageType = 'error';
        }
    }
}

// Show current index stats when not just rebuilt
$index = pdf_finder_load_index();
if ($index !== null && $messageType !== 'success') {
    $count = (int) ($index['count'] ?? count($index['files'] ?? []));
    $builtAt = (string) ($index['built_at'] ?? '');
}

pdf_finder_header('Rebuild Index', 'index');
?>

<div class="card card--center">
    <h2 class="page-title">Rebuild PDF Index</h2>
    <p class="page-subtitle">
        Scans all folders listed in <code>config.php</code> and saves file names to
        <code>storage/pdf_index.json</code>. Searches use this file — directories are not scanned on every search.
    </p>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= h($messageType === 'success' ? 'success' : 'error') ?>">
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($index === null && $messageType !== 'success'): ?>
        <div class="alert alert-warning">
            PDF index not found. Please rebuild the index first.
        </div>
    <?php elseif ($builtAt !== ''): ?>
        <p class="meta">
            <strong><?= (int) $count ?></strong> PDF<?= $count === 1 ? '' : 's' ?> indexed
            <?php if ($builtAt !== ''): ?>
                &middot; Last built: <?= h($builtAt) ?>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <form method="post" action="rebuild-index.php">
        <button type="submit" class="btn btn-primary">Rebuild Index</button>
    </form>

    <p class="meta" style="margin-top: 1.25rem;">
        Configured directories:
    </p>
    <ul class="meta" style="text-align: left; display: inline-block;">
        <?php foreach (pdf_finder_directories() as $dir): ?>
            <li><?= h($dir) ?><?= is_dir($dir) ? '' : ' (not found)' ?></li>
        <?php endforeach; ?>
        <?php if (pdf_finder_directories() === []): ?>
            <li><em>None — edit config.php</em></li>
        <?php endif; ?>
    </ul>
</div>

<p style="text-align: center;"><a href="index.php">Go to Search</a></p>

<?php
pdf_finder_footer();
