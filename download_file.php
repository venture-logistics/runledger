<?php
require_once __DIR__ . '/includes/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('DB connection not available.');
}

$slug = trim($_GET['slug'] ?? '');
$allowedSlugs = ['ledger', 'installer'];

if (!in_array($slug, $allowedSlugs, true)) {
    http_response_code(404);
    exit('Not found.');
}

// Handle installer download (local file)
if ($slug === 'installer') {
    $filepath = __DIR__ . '/downloads/installer.zip';

    if (!is_file($filepath)) {
        http_response_code(404);
        exit('File not found.');
    }

    // Atomic counter update
    try {
        $stmt = $conn->prepare("
      INSERT INTO download_stats (slug, downloads)
      VALUES (?, 1)
      ON DUPLICATE KEY UPDATE
        downloads = downloads + 1
    ");
        if ($stmt) {
            $stmt->bind_param("s", $slug);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Exception $e) {
        // Continue with download
    }

    // Force download
    header('Content-Description: File Transfer');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="installer.zip"');
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    readfile($filepath);
    exit;
}

// Fetch latest release from GitHub API
$repo = 'venture-logistics/runledger';
$apiUrl = "https://api.github.com/repos/{$repo}/releases/latest";
$fallbackUrl = 'https://github.com/venture-logistics/runledger/archive/refs/tags/v1.1.0.zip';

$downloadUrl = $fallbackUrl;

$context = stream_context_create([
    'http' => [
        'header' => "User-Agent: Ledger-Download\r\n",
        'timeout' => 5
    ]
]);

$response = @file_get_contents($apiUrl, false, $context);
if ($response !== false) {
    $data = json_decode($response, true);
    if (isset($data['zipball_url'])) {
        $downloadUrl = $data['zipball_url'];
    } elseif (isset($data['tag_name'])) {
        // Build URL from tag name
        $downloadUrl = "https://github.com/{$repo}/archive/refs/tags/{$data['tag_name']}.zip";
    }
}

// Atomic counter update (silently skip if table doesn't exist)
try {
    $stmt = $conn->prepare("
    INSERT INTO download_stats (slug, downloads)
    VALUES (?, 1)
    ON DUPLICATE KEY UPDATE
      downloads = downloads + 1
  ");
    if ($stmt) {
        $stmt->bind_param("s", $slug);
        $stmt->execute();
        $stmt->close();
    }
} catch (Exception $e) {
    // Table may not exist yet - continue with redirect
}

// Redirect to GitHub release
header('Location: ' . $downloadUrl);
exit;