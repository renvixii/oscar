<?php
/**
 * PDF Finder — rebuild local and SMB PDF indexes
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/smb_config.php';
require_once __DIR__ . '/includes/smb.php';

$message = '';
$messageType = '';
$count = 0;
$builtAt = '';
$smbResults = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim((string) $_POST['action']) : 'rebuild_local';

    if ($action === 'rebuild_local') {
        $directories = pdf_finder_directories();

        if ($directories === []) {
            $message = 'No PDF directories configured. Edit config.php and add at least one path.';
            $messageType = 'error';
        } else {
            $files = pdf_finder_build_index();

            if (pdf_finder_save_index($files)) {
                $count = count($files);
                $builtAt = date('Y-m-d H:i:s');
                $message = 'Local index rebuilt successfully.';
                $messageType = 'success';
            } else {
                $message = 'Could not write the index file. Check folder permissions for the storage directory.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'rebuild_smb_all') {
        if (!pdf_finder_smbclient_available()) {
            $message = 'smbclient is not installed. Rebuild the pdffinder Docker image or install smbclient on the host.';
            $messageType = 'error';
        } elseif (!pdf_finder_smb_enabled()) {
            $message = 'No enabled SMB sources. Edit config.smb.local.php.';
            $messageType = 'error';
        } else {
            $ok = 0;
            foreach (pdf_finder_smb_enabled_sources() as $source) {
                $result = pdf_finder_smb_build_index($source);
                $smbResults[] = [
                    'label' => $source['label'],
                    'ok' => $result['ok'],
                    'message' => $result['message'],
                    'count' => count($result['files']),
                ];
                if ($result['ok']) {
                    $ok++;
                }
            }
            $message = 'SMB index rebuild finished (' . $ok . ' of ' . count($smbResults) . ' source(s) OK).';
            $messageType = $ok > 0 ? 'success' : 'error';
        }
    } elseif (str_starts_with($action, 'rebuild_smb:')) {
        $sourceId = substr($action, 12);
        $source = pdf_finder_smb_source_by_id($sourceId);
        if ($source === null || !$source['enabled']) {
            $message = 'Invalid or disabled SMB source.';
            $messageType = 'error';
        } elseif (!pdf_finder_smbclient_available()) {
            $message = 'smbclient is not installed.';
            $messageType = 'error';
        } else {
            $result = pdf_finder_smb_build_index($source);
            $smbResults[] = [
                'label' => $source['label'],
                'ok' => $result['ok'],
                'message' => $result['message'],
                'count' => count($result['files']),
            ];
            $message = $source['label'] . ': ' . $result['message'];
            $messageType = $result['ok'] ? 'success' : 'error';
        }
    }
}

$index = pdf_finder_load_index();
if ($index !== null && $messageType !== 'success' && !isset($_POST['action'])) {
    $count = (int) ($index['count'] ?? count($index['files'] ?? []));
    $builtAt = (string) ($index['built_at'] ?? '');
}

$smbSources = pdf_finder_smb_sources_raw();

pdf_finder_header('Rebuild Index', 'index');
?>

<div class="card">
    <h2 class="page-title">Rebuild PDF Index</h2>
    <p class="page-subtitle">
        Local folders are scanned into <code>storage/pdf_index.json</code>.
        SMB shares are listed read-only via <code>smbclient</code> and cached under <code>storage/smb_index_*.json</code>
        — nothing is written to the remote NAS.
    </p>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= h($messageType === 'success' ? 'success' : 'error') ?>">
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <h3 class="section-title">Local folders</h3>

    <?php if ($index === null && $messageType !== 'success'): ?>
        <div class="alert alert-warning">Local PDF index not found.</div>
    <?php elseif ($builtAt !== '' && $messageType !== 'success'): ?>
        <p class="meta">
            <strong><?= (int) $count ?></strong> PDF<?= $count === 1 ? '' : 's' ?> indexed
            &middot; Last built: <?= h($builtAt) ?>
        </p>
    <?php endif; ?>

    <form method="post" action="rebuild-index.php" style="margin-bottom: 1.5rem;">
        <input type="hidden" name="action" value="rebuild_local">
        <button type="submit" class="btn btn-primary">Rebuild local index</button>
    </form>

    <ul class="meta" style="text-align: left;">
        <?php foreach (pdf_finder_directories() as $dir): ?>
            <li><?= h($dir) ?><?= is_dir($dir) ? '' : ' (not found)' ?></li>
        <?php endforeach; ?>
        <?php if (pdf_finder_directories() === []): ?>
            <li><em>None — edit config.php</em></li>
        <?php endif; ?>
    </ul>
</div>

<div class="card">
    <h3 class="section-title">SMB shares (read-only)</h3>

    <?php if (!pdf_finder_smbclient_available()): ?>
        <div class="alert alert-warning">
            <code>smbclient</code> is not installed. Rebuild the pdffinder image (see README) or run
            <a href="test-smb-connection.php">SMB diagnostics</a>.
        </div>
    <?php endif; ?>

    <?php if ($smbSources === []): ?>
        <p class="meta">
            No SMB sources configured. Copy
            <code>config.smb.local.php.example</code> to <code>config.smb.local.php</code>.
        </p>
    <?php else: ?>
        <form method="post" action="rebuild-index.php" style="margin-bottom: 1rem;">
            <input type="hidden" name="action" value="rebuild_smb_all">
            <button type="submit" class="btn btn-primary" <?= pdf_finder_smb_enabled() ? '' : 'disabled' ?>>
                Rebuild all enabled SMB indexes
            </button>
        </form>

        <div class="results-table-wrap">
            <table class="results-table">
                <thead>
                    <tr>
                        <th>Source</th>
                        <th>Enabled</th>
                        <th>Cached PDFs</th>
                        <th>Last built</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($smbSources as $source): ?>
                        <?php
                        $smbIndex = pdf_finder_smb_load_index($source['id']);
                        $smbCount = $smbIndex !== null ? (int) ($smbIndex['count'] ?? 0) : 0;
                        $smbBuilt = $smbIndex['built_at'] ?? '—';
                        ?>
                        <tr>
                            <td><?= h($source['label']) ?> <span class="meta">(<?= h($source['id']) ?>)</span></td>
                            <td><?= $source['enabled'] ? 'yes' : 'no' ?></td>
                            <td><?= $smbCount ?></td>
                            <td><?= h((string) $smbBuilt) ?></td>
                            <td>
                                <?php if ($source['enabled']): ?>
                                    <form method="post" action="rebuild-index.php" style="display:inline;">
                                        <input type="hidden" name="action" value="rebuild_smb:<?= h($source['id']) ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm">Rebuild</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($smbResults !== []): ?>
            <ul class="meta" style="margin-top: 1rem;">
                <?php foreach ($smbResults as $row): ?>
                    <li>
                        <?= h($row['label']) ?>:
                        <?= h($row['message']) ?>
                        <?php if ($row['ok']): ?>
                            (<?= (int) $row['count'] ?> PDFs)
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>

    <p class="meta" style="margin-top: 1rem;">
        <a href="test-smb-connection.php">SMB connection diagnostics</a>
    </p>
</div>

<p style="text-align: center;"><a href="index.php">Go to Search</a></p>

<?php
pdf_finder_footer();
