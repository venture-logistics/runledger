<?php
// admin_settings.php — Site settings admin
declare(strict_types = 1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// admin_site_settings.php — Site settings (uploads)
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$user = auth_require($conn, ['admin']); // role-gated

// ---------- DB GUARD ----------
if (!isset($conn) || !($conn instanceof mysqli)) {
    echo "<div class='container py-4'><div class='alert alert-danger mb-0'>DB connection not available.</div></div>";
    require_once 'footer.php';
    exit;
}

function save_upload(array $file, string $targetDirAbs, string $targetWebPrefix, string $baseName, array $allowedExts, int $maxBytes): ?string
{
  if (empty($file) || !isset($file['error'])) return null;
  if ($file['error'] === UPLOAD_ERR_NO_FILE) return null;
  if ($file['error'] !== UPLOAD_ERR_OK) return null;
  if (!is_uploaded_file($file['tmp_name'])) return null;
  if ($file['size'] > $maxBytes) return null;

  $orig = $file['name'] ?? '';
  $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
  if (!is_image_ext_allowed($ext, $allowedExts)) return null;

  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime  = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
  if ($finfo) finfo_close($finfo);

  $okMimes = [
    'image/png', 'image/jpeg', 'image/webp', 'image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon'
  ];
  if ($mime && !in_array($mime, $okMimes, true)) return null;

  $filename = $baseName . '.' . $ext;

  if (!is_dir($targetDirAbs)) {
    @mkdir($targetDirAbs, 0755, true);
  }

  $destAbs = rtrim($targetDirAbs, '/\\') . DIRECTORY_SEPARATOR . $filename;
  if (!move_uploaded_file($file['tmp_name'], $destAbs)) return null;

  return rtrim($targetWebPrefix, '/') . '/' . $filename;
}

// Load current settings
$site = [
  'site_name'        => 'Ledger',
  'site_description' => '',
  'footer_bottom' => '',
  'logo_top'         => '/img/logo-top.png',
  'logo_bottom'      => '/img/logo-bottom.png',
  'favicon'          => '/img/favicon.ico',
];

$res = $conn->query("
  SELECT site_name, logo_top, logo_bottom, site_description, footer_bottom, favicon
  FROM site_settings
  WHERE id=1
  LIMIT 1
");
if ($res && $res->num_rows === 1) {
  $site = array_merge($site, $res->fetch_assoc());
}

$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $site_name = trim($_POST['site_name'] ?? '');
  $site_description = trim($_POST['site_description'] ?? '');
  $footer_bottom = trim($_POST['footer_bottom'] ?? '');

  if ($site_name === '') {
    $error = 'Site name is required.';
  } else {

    // Upload targets
    $imgDirAbs = __DIR__ . '/img';     // /public_html/img
    $imgWeb    = '/img';

    // Logos: allow png/jpg/webp/svg
    $logoAllowed = ['png','jpg','jpeg','webp','svg'];
    // Favicon: allow ico/png/svg
    $faviconAllowed = ['ico','png','svg'];

    // 2MB max for logos, 512KB for favicon
    $newTop = save_upload($_FILES['logo_top'] ?? [], $imgDirAbs, $imgWeb, 'logo-top', $logoAllowed, 2 * 1024 * 1024);
    $newBottom = save_upload($_FILES['logo_bottom'] ?? [], $imgDirAbs, $imgWeb, 'logo-bottom', $logoAllowed, 2 * 1024 * 1024);
    $newFavicon = save_upload($_FILES['favicon'] ?? [], $imgDirAbs, $imgWeb, 'favicon', $faviconAllowed, 512 * 1024);

    // Keep existing if not uploaded
    $logo_top = $newTop ?: ($site['logo_top'] ?? '/img/logo-top.png');
    $logo_bottom = $newBottom ?: ($site['logo_bottom'] ?? '/img/logo-bottom.png');
    $favicon = $newFavicon ?: ($site['favicon'] ?? '/img/favicon.ico');

    $stmt = $conn->prepare("
      INSERT INTO site_settings (
        id,
        site_name,
        site_description,
        footer_bottom,
        logo_top,
        logo_bottom,
        favicon
      )
      VALUES (1, ?, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE
        site_name        = VALUES(site_name),
        site_description = VALUES(site_description),
        footer_bottom    = VALUES(footer_bottom),
        logo_top         = VALUES(logo_top),
        logo_bottom      = VALUES(logo_bottom),
        favicon          = VALUES(favicon)
    ");

    if (!$stmt) {
      $error = 'Database error: ' . $conn->error;
    } else {

      // 5 placeholders => 5 types + 5 vars
      $stmt->bind_param('ssssss', $site_name, $site_description, $footer_bottom, $logo_top, $logo_bottom, $favicon);

      if ($stmt->execute()) {
        $success = 'Saved.';
        $site = [
          'site_name'        => $site_name,
          'site_description' => $site_description,
          'footer_bottom'    => $footer_bottom,
          'logo_top'         => $logo_top,
          'logo_bottom'      => $logo_bottom,
          'favicon'          => $favicon,
        ];
      } else {
        $error = 'Save failed: ' . $stmt->error;
      }

      $stmt->close();
    }
  }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    
<?php require_once 'includes/admin_menu.php'; ?> 

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Site branding</h1>
    <div class="d-flex gap-2">
      <a href="/admin.php" class="btn btn-sm btn-outline-secondary">Admin home</a>
      <a href="/index.php" class="btn btn-sm btn-outline-secondary">View site</a>
    </div>
  </div>

  <?php if ($success): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="card">
    <div class="card-body">

      <div class="mb-3">
        <label class="form-label">Site name</label>
        <input name="site_name" class="form-control" value="<?= h($site['site_name']) ?>" required>
      </div>

      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Top logo</label>
          <input type="file" name="logo_top" class="form-control" accept=".png,.jpg,.jpeg,.webp,.svg">
          <div class="form-text">PNG/JPG/WebP/SVG · saved as <code>/img/logo-top.*</code></div>
          <div class="mt-2">
            <img src="<?= h($site['logo_top']) ?>" alt="Top logo" style="max-height:60px;">
          </div>
        </div>

        <div class="col-md-6">
          <label class="form-label">Bottom logo</label>
          <input type="file" name="logo_bottom" class="form-control" accept=".png,.jpg,.jpeg,.webp,.svg">
          <div class="form-text">PNG/JPG/WebP/SVG · saved as <code>/img/logo-bottom.*</code></div>
          <div class="mt-2">
            <img src="<?= h($site['logo_bottom']) ?>" alt="Bottom logo" style="max-height:60px;">
          </div>
        </div>

        <div class="col-md-6">
          <label class="form-label">Favicon</label>
          <input type="file" name="favicon" class="form-control" accept=".ico,.png,.svg">
          <div class="form-text">ICO/PNG/SVG · saved as <code>/img/favicon.*</code></div>
          <div class="mt-2">
            <img src="<?= h($site['favicon']) ?>" alt="Favicon" style="max-height:32px;">
          </div>
        </div>
        
        <div class="my-3">
          <label class="form-label">Site description</label>
          <textarea name="site_description" class="form-control" rows="3"><?= h($site['site_description'] ?? '') ?></textarea>
          <div class="form-text">Shown in the footer.</div>
        </div>
        
        <div class="mb-3">
          <label for="footer_bottom" class="form-label">Footer bottom text</label>
          <textarea
            id="footer_bottom"
            name="footer_bottom"
            class="form-control"
            rows="2"
          ><?= h($site['footer_bottom'] ?? '') ?></textarea>
          <div class="form-text">
            Copyright notice, company number, or any footer text.
          </div>
        </div>        
        
      </div>

      <div class="mt-4 d-flex gap-2">
        <button class="btn btn-primary">Save</button>
      </div>

    </div>
  </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
