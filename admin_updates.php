<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

// Get version from admin.php without executing it
$adminFile = file_get_contents(__DIR__ . '/admin.php');
preg_match("/define\('LEDGER_VERSION',\s*'([^']+)'\)/", $adminFile, $matches);
$currentVersion = $matches[1] ?? 'Unknown';

$user = auth_require($conn, ['admin']);
require_once 'includes/header.php';

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

function csrf_verify()
{
    $posted = $_POST['csrf_token'] ?? '';
    if (!is_string($posted) || !hash_equals($_SESSION['csrf_token'] ?? '', $posted)) {
        http_response_code(400);
        exit('Bad request');
    }
}

// Flash messages
function flash_set($type, $msg)
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function flash_get()
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

$githubToken = env('GITHUB_TOKEN');

$latestVersion = null;
$updateAvailable = false;
$releaseUrl = null;
$downloadUrl = null;
$releaseNotes = null;
$checkError = null;
$tagName = null; // Store the full tag name for version updates

// Check for updates
try {
    $apiUrl = 'https://api.github.com/repos/venture-logistics/ledger/releases/latest';

    // Try curl first (most reliable)
    if (function_exists('curl_init')) {
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Ledger-Update-Checker');

        $headers = ['Accept: application/vnd.github.v3+json'];
        if ($githubToken) {
            $headers[] = 'Authorization: Bearer ' . $githubToken;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        // SSL certificate handling
        $caBundle = __DIR__ . '/includes/cacert.pem';
        if (file_exists($caBundle)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        } else {
            // Fallback: disable verification (less secure, but works)
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        // curl_close() removed - deprecated in PHP 8.5

        if ($response === false || $httpCode !== 200) {
            throw new Exception('Unable to connect to GitHub (HTTP ' . $httpCode . ')');
        }
    }
    // Fallback to file_get_contents
    else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Ledger-Update-Checker',
                    'Accept: application/vnd.github.v3+json'
                ],
                'timeout' => 10
            ]
        ]);

        $response = @file_get_contents($apiUrl, false, $context);

        if ($response === false) {
            throw new Exception('Unable to connect to GitHub. Check server firewall settings.');
        }
    }

    $release = json_decode($response, true);

    if (isset($release['tag_name'])) {
        $tagName = $release['tag_name']; // Store full tag name (e.g., "v1.2.0")
        $latestVersion = ltrim($release['tag_name'], 'v');
        $releaseUrl = $release['html_url'] ?? null;
        $releaseNotes = $release['body'] ?? null;

        // Use tar.gz instead of zip (can extract with tar command, no unzip needed)
        $downloadUrl = "https://codeload.github.com/venture-logistics/ledger/legacy.tar.gz/{$release['tag_name']}";

        // Compare versions
        $updateAvailable = version_compare($latestVersion, ltrim($currentVersion, 'v'), '>');
    }

} catch (Exception $e) {
    $checkError = $e->getMessage();
}

// Handle manual migration run
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_migrations') {
    csrf_verify();

    try {
        require_once __DIR__ . '/includes/migrations.php';
        $migrationResults = run_migrations($conn);

        if (!empty($migrationResults['errors'])) {
            flash_set('danger', 'Migration errors: ' . implode(', ', $migrationResults['errors']));
        } elseif (!empty($migrationResults['applied'])) {
            flash_set('success', 'Migrations applied: ' . implode(', ', $migrationResults['applied']));
        } else {
            flash_set('info', 'No pending migrations to apply.');
        }
    } catch (Exception $e) {
        flash_set('danger', 'Migration failed: ' . $e->getMessage());
    }

    header('Location: admin_updates.php');
    exit;
}

// Handle auto-update using system commands (no zip extension needed)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install_update') {
    csrf_verify();

    try {
        if (!$downloadUrl) {
            throw new Exception('Download URL not available');
        }

        $backupDir = __DIR__ . '/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = date('Y-m-d_H-i-s');
        $tempFile = __DIR__ . '/temp_update.tar.gz';

        // Get GitHub token
        $githubToken = env('GITHUB_TOKEN');

        // Download using curl (handles redirects better than file_get_contents)
        if (function_exists('curl_init')) {
            $ch = curl_init($downloadUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Ledger-Update-Downloader');

            // Add authentication header if token is available
            $headers = [];
            if ($githubToken) {
                $headers[] = 'Authorization: token ' . $githubToken;
            }
            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }

            $data = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($data === false || $httpCode !== 200) {
                throw new Exception('Failed to download (HTTP ' . $httpCode . '). Check server firewall or GitHub token.');
            }
        } else {
            // Fallback to wget command
            $wgetCmd = "wget -q -O " . escapeshellarg($tempFile) . " " . escapeshellarg($downloadUrl);
            if ($githubToken) {
                $wgetCmd = "wget -q --header='Authorization: token {$githubToken}' -O " . escapeshellarg($tempFile) . " " . escapeshellarg($downloadUrl);
            }
            exec($wgetCmd . " 2>&1", $output, $result);
            if ($result !== 0 || !file_exists($tempFile)) {
                throw new Exception('Failed to download. Server needs curl or wget enabled.');
            }
            $data = file_get_contents($tempFile);
        }

        file_put_contents($tempFile, $data);

        // Create backup using tar (available on most systems)
        $backupFile = $backupDir . '/backup_' . $timestamp . '.tar.gz';
        $output = [];
        $result = 0;

        // Try tar backup
        exec("tar -czf " . escapeshellarg($backupFile) . " *.php includes/ 2>&1", $output, $result);
        if ($result !== 0) {
            // Backup failed, but continue anyway
            error_log('Backup failed: ' . implode("\n", $output));
        }

        // Extract using tar command
        $extractDir = __DIR__ . '/temp_extract';
        @mkdir($extractDir, 0755, true);

        $output = [];
        exec("tar -xzf " . escapeshellarg($tempFile) . " -C " . escapeshellarg($extractDir) . " 2>&1", $output, $result);

        if ($result !== 0) {
            @unlink($tempFile);
            throw new Exception('Cannot extract archive. Tar command failed: ' . implode(' ', $output));
        }

        @unlink($tempFile);

        // Find extracted folder (GitHub creates venture-logistics-ledger-xxxxx)
        $dirs = glob($extractDir . '/*', GLOB_ONLYDIR);
        if (empty($dirs)) {
            throw new Exception('Extraction failed - no folder found');
        }
        $sourceDir = $dirs[0];

        // Copy files using system commands (faster and more reliable)
        exec("cp -R " . escapeshellarg($sourceDir) . "/* " . escapeshellarg(__DIR__) . "/ 2>&1", $output, $result);

        // Update version number in admin.php to match the downloaded version
        $targetVersion = $_POST['target_version'] ?? null;
        if ($targetVersion) {
            $adminPhp = __DIR__ . '/admin.php';
            if (file_exists($adminPhp)) {
                $adminContent = file_get_contents($adminPhp);
                $adminContent = preg_replace(
                    "/define\('LEDGER_VERSION',\s*'[^']+'\)/",
                    "define('LEDGER_VERSION', '{$targetVersion}')",
                    $adminContent
                );
                file_put_contents($adminPhp, $adminContent);
            }
        }

        // Cleanup
        exec("rm -rf " . escapeshellarg($extractDir) . " 2>&1");

        // Run migrations
        require_once __DIR__ . '/includes/migrations.php';
        $migrationResults = run_migrations($conn);

        $message = 'Update installed! Backup: ' . basename($backupFile);
        if (!empty($migrationResults['applied'])) {
            $message .= ' | Migrations: ' . count($migrationResults['applied']);
        }

        flash_set('success', $message);
        header('Location: admin_updates.php');
        exit;

    } catch (Exception $e) {
        flash_set('danger', 'Update failed: ' . $e->getMessage());
        header('Location: admin_updates.php');
        exit;
    }
}

$flash = flash_get();
?>

<div class="container py-4">
    
<?php require_once 'includes/admin_menu.php'; ?>

  <h1 class="h3 mb-4">System Updates</h1>

  <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <div class="row">
    <div class="col-12 col-lg-8">
      
      <!-- Current Version -->
      <div class="card mb-3">
        <div class="card-body">
          <h5 class="card-title">Current Version</h5>
          <div class="d-flex align-items-center gap-3">
            <div class="display-6">v<?= htmlspecialchars($currentVersion) ?></div>
            <?php if (!$checkError && !$updateAvailable && $latestVersion): ?>
              <span class="badge bg-success">Up to date</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Update Check -->
      <?php if ($checkError): ?>
        <div class="alert alert-warning">
          <strong>Unable to check for updates:</strong> <?= htmlspecialchars($checkError) ?>
        </div>
      <?php elseif ($updateAvailable): ?>
        <div class="card border-primary mb-3">
          <div class="card-header bg-primary text-white">
            <strong>Update Available!</strong>
          </div>
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-3">
              <div>
                <h5 class="card-title mb-1">Version <?= htmlspecialchars($latestVersion) ?></h5>
                <div class="text-muted small">A new version is ready to install</div>
              </div>
            </div>

            <?php if ($releaseNotes): ?>
              <div class="mb-3">
                <strong>What's New:</strong>
                <div class="mt-2 p-3 bg-light rounded small">
                  <?= nl2br(htmlspecialchars($releaseNotes)) ?>
                </div>
              </div>
            <?php endif; ?>

            <form method="post" action="admin_updates.php" onsubmit="return confirm('This will update your installation. A backup will be created. Continue?');">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="install_update">
              <input type="hidden" name="target_version" value="<?= htmlspecialchars($tagName ?? '') ?>">
              
              <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                  <i class="bi bi-download"></i> Install Update
                </button>
                <?php if ($releaseUrl): ?>
                  <a href="<?= htmlspecialchars($releaseUrl) ?>" target="_blank" class="btn btn-outline-secondary">
                    View on GitHub
                  </a>
                <?php endif; ?>
              </div>
            </form>
          </div>
        </div>
      <?php elseif ($latestVersion): ?>
        <div class="alert alert-success">
          <i class="bi bi-check-circle"></i> You're running the latest version!
        </div>
      <?php endif; ?>

      <!-- Info -->
      <div class="card mb-3">
        <div class="card-body">
          <h6 class="card-title">Update Information</h6>
          <ul class="mb-0 small">
            <li>One-click auto-update (uses system commands, no PHP extensions required)</li>
            <li>Automatic backup before each update</li>
            <li>Backups stored in <code>/backups</code> directory</li>
            <li>Database migrations run automatically</li>
          </ul>
        </div>
      </div>

      <!-- Database Migrations -->
      <?php
      require_once __DIR__ . '/includes/migrations.php';
      $pendingCount = has_pending_migrations($conn);
      ?>
      <div class="card">
        <div class="card-body">
          <h6 class="card-title">Database Migrations</h6>
          <p class="small text-muted mb-3">
            Migrations update your database schema when new features are added.
            They run automatically during updates, but you can also run them manually.
          </p>

          <div class="d-flex align-items-center justify-content-between">
            <div>
              <?php if ($pendingCount > 0): ?>
                <span class="badge bg-warning text-dark"><?= $pendingCount ?> pending migration<?= $pendingCount > 1 ? 's' : '' ?></span>
              <?php else: ?>
                <span class="badge bg-success">Database up to date</span>
              <?php endif; ?>
            </div>

            <form method="post" action="admin_updates.php" onsubmit="return confirm('Run database migrations now?');">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="run_migrations">
              <button type="submit" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-database-gear"></i> Run Migrations
              </button>
            </form>
          </div>
        </div>
      </div>

    </div>
  </div>

</div>

<?php require_once 'includes/footer.php'; ?>