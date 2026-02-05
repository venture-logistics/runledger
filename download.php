<?php
$pageTitle = "Download Ledger";

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/download_counter.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo "<div class='container py-4'><div class='alert alert-danger mb-0'>DB connection not available.</div></div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Define your download "product"
$slug = 'ledger';
$fileLabel = 'Ledger (PHP / MySQL)';

// Fetch latest version from GitHub API
$repo = 'venture-logistics/runledger';
$apiUrl = "https://api.github.com/repos/{$repo}/releases/latest";
$version = 'v1.1.0'; // fallback

$context = stream_context_create([
    'http' => [
        'header' => "User-Agent: Ledger-Download\r\n",
        'timeout' => 5
    ]
]);

$response = @file_get_contents($apiUrl, false, $context);
if ($response !== false) {
    $data = json_decode($response, true);
    if (isset($data['tag_name'])) {
        $version = $data['tag_name'];
    }
}

$downloads = download_get_count($conn, $slug);
$installerDownloads = download_get_count($conn, 'installer');
?>

<div class="page-content">
  <div class="container">
    <div class="py-5">
      <div class="row justify-content-center">
        <div class="col-12 col-lg-9">

          <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
            <div>
              <h1 class="h3 mb-1">Download Ledger</h1>
              <div class="text-muted">Get the latest build and start using Ledger in minutes.</div>
            </div>

            <div class="text-end">
              <div class="small text-muted">Total downloads</div>
              <div class="h4 mb-0"><?= (int) $downloads ?></div>
            </div>
          </div>

          <div class="card shadow-sm">
            <div class="card-body">
              <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                  <div class="fw-semibold"><?= htmlspecialchars($fileLabel) ?></div>
                  <div class="text-muted small">
                    <?= htmlspecialchars($version) ?> · Secure download from GitHub
                  </div>
                </div>

                <a class="btn btn-primary"
                   href="download_file.php?slug=ledger">
                  Download
                </a>
              </div>

              <hr>

              <div class="row g-3">
                <div class="col-12 col-md-6">
                  <div class="small text-muted mb-1">What you get</div>
                  <ul class="mb-0">
                    <li>Knowledge base + forum ready</li>
                    <li>Fast setup</li>
                    <li>Clean admin tools</li>
                  </ul>
                </div>
                <div class="col-12 col-md-6">
                  <div class="small text-muted mb-1">Notes</div>
                  <ul class="mb-0">
                    <li>PHP 8.3 (8.3.19)</li>
                    <li>New versions overwrite old</li>
                    <li>If you hit issues, check the Knowledge Base</li>
                  </ul>
                </div>
              </div>

            </div>
          </div>

          <div class="text-muted small mt-3">
            Downloads are counted when the file starts downloading.
          </div>

          <div class="card shadow-sm mt-4">
            <div class="card-body">
              <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                  <div class="fw-semibold">Ledger Installer</div>
                  <div class="text-muted small mb-2">
                    Local download · installer.zip · <?= (int) $installerDownloads ?> downloads
                  </div>
                </div>

                <a class="btn btn-outline-primary"
                   href="download_file.php?slug=installer">
                  Download
                </a>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>