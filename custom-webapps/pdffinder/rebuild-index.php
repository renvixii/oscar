<?php
/**
 * PDF Finder — rebuild local and SMB PDF indexes
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/oscar_config.php';
require_once __DIR__ . '/includes/smb_config.php';
require_once __DIR__ . '/includes/smb.php';

$message = '';
$messageType = '';
$count = 0;
$builtAt = '';
$smbResults = [];
$lastAction = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim((string) $_POST['action']) : 'rebuild_local';
    $lastAction = $action;

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
    } elseif ($action === 'rebuild_all') {
        $result = pdf_finder_rebuild_all();
        $count = $result['count'];
        $builtAt = $result['built_at'];
        $smbResults = $result['smb_results'];
        $message = $result['message'];
        $messageType = $result['ok'] ? 'success' : 'error';
        if ($result['partial']) {
            $messageType = 'success';
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
$smbCachedTotal = 0;
$smbEnabledCount = 0;
foreach ($smbSources as $source) {
    if ($source['enabled']) {
        $smbEnabledCount++;
    }
    $smbIndex = pdf_finder_smb_load_index($source['id']);
    if ($smbIndex !== null) {
        $smbCachedTotal += (int) ($smbIndex['count'] ?? 0);
    }
}

$openLocal = in_array($lastAction, ['rebuild_local', 'rebuild_all'], true);
$openSmb = $lastAction === 'rebuild_smb_all'
    || $lastAction === 'rebuild_all'
    || str_starts_with($lastAction, 'rebuild_smb:');

$localDirs = pdf_finder_directories();
$localSummary = $builtAt !== ''
    ? (int) $count . ' PDF' . ($count === 1 ? '' : 's') . ' · last built ' . $builtAt
    : ($index === null ? 'No index yet' : (int) $count . ' PDF' . ($count === 1 ? '' : 's'));

if ($smbSources === []) {
    $smbSummary = 'Not configured';
} elseif ($smbEnabledCount === 0) {
    $smbSummary = count($smbSources) . ' source(s) · none enabled';
} else {
    $smbSummary = $smbEnabledCount . ' enabled · ' . $smbCachedTotal . ' PDF' . ($smbCachedTotal === 1 ? '' : 's') . ' cached';
}

pdf_finder_header('Rebuild Index', 'index');
?>

<div class="card">
    <h2 class="page-title">Rebuild PDF Index</h2>
    <p class="page-subtitle">
        Local folders are scanned into <code>storage/pdf_index.json</code>.
        SMB shares are listed read-only via <code>smbclient</code> and cached under <code>storage/smb_index_*.json</code>
        — nothing is written to the remote NAS. OSCAR search is live and does not use these indexes.
    </p>

    <?php pdf_finder_render_integration_status(); ?>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= h($messageType === 'success' ? 'success' : 'error') ?>">
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <details class="rebuild-section"<?= $openLocal ? ' open' : '' ?>>
        <summary class="rebuild-section-summary">
            <span class="rebuild-section-title">Local folders</span>
            <span class="rebuild-section-meta"><?= h($localSummary) ?></span>
        </summary>
        <div class="rebuild-section-body">
            <?php if ($index === null && $messageType !== 'success' && $lastAction !== 'rebuild_local' && $lastAction !== 'rebuild_all'): ?>
                <div class="alert alert-warning">Local PDF index not found.</div>
            <?php endif; ?>

            <form method="post" action="rebuild-index.php" class="rebuild-section-form">
                <input type="hidden" name="action" value="rebuild_local">
                <button type="submit" class="btn btn-primary">Rebuild local index</button>
            </form>

            <ul class="meta rebuild-dir-list">
                <?php foreach ($localDirs as $dir): ?>
                    <li><?= h($dir) ?><?= is_dir($dir) ? '' : ' (not found)' ?></li>
                <?php endforeach; ?>
                <?php if ($localDirs === []): ?>
                    <li><em>None — edit config.php</em></li>
                <?php endif; ?>
            </ul>
        </div>
    </details>

    <details class="rebuild-section"<?= $openSmb ? ' open' : '' ?>>
        <summary class="rebuild-section-summary">
            <span class="rebuild-section-title">SMB shares (read-only)</span>
            <span class="rebuild-section-meta"><?= h($smbSummary) ?></span>
        </summary>
        <div class="rebuild-section-body">
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
                <form method="post" action="rebuild-index.php" class="rebuild-section-form">
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
                    <ul class="meta rebuild-smb-results">
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

            <p class="meta">
                <a href="test-smb-connection.php">SMB connection diagnostics</a>
            </p>
        </div>
    </details>

    <form method="post" action="rebuild-index.php" class="rebuild-all-form">
        <input type="hidden" name="action" value="rebuild_all">
        <button type="submit" class="btn btn-danger btn-lg">Rebuild all (local + SMB)</button>
        <p class="meta rebuild-all-note">
            Scans local <code>config.php</code> folders and all enabled SMB sources in one step.
            SMB shares are read-only — indexes are saved under <code>storage/</code> only.
        </p>
    </form>

    <div class="daily-rebuild-settings" id="daily-rebuild-settings">
        <h4 class="daily-rebuild-settings-title">Daily rebuild</h4>
        <p class="meta daily-rebuild-settings-intro">
            Runs once on your first visit each day. Stored in this browser only.
        </p>
        <fieldset class="radio-group daily-rebuild-radio-group">
            <legend class="visually-hidden">Daily rebuild mode</legend>
            <label class="radio-option">
                <input type="radio" name="daily_rebuild_mode" value="disabled">
                <span class="radio-option-body">
                    <span class="radio-option-label">Disabled</span>
                    <span class="radio-option-desc">Manual rebuild only — no automatic or prompted rebuilds.</span>
                </span>
            </label>
            <label class="radio-option">
                <input type="radio" name="daily_rebuild_mode" value="silent">
                <span class="radio-option-body">
                    <span class="radio-option-label">Silent auto-rebuild</span>
                    <span class="radio-option-desc">Rebuilds in the background on first open each day without interrupting you.</span>
                </span>
            </label>
            <label class="radio-option">
                <input type="radio" name="daily_rebuild_mode" value="reminder">
                <span class="radio-option-body">
                    <span class="radio-option-label">Smart reminder</span>
                    <span class="radio-option-desc">Shows a subtle banner on first visit — choose Rebuild or Dismiss.</span>
                </span>
            </label>
        </fieldset>
        <p class="meta" id="daily-rebuild-settings-status"></p>
    </div>
</div>

<p style="text-align: center;"><a href="index.php">Go to Search</a></p>

<?php
pdf_finder_footer('settings');
