<?php
// admin_site_welcome.php — Welcome card editor
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$user = auth_require($conn, ['admin']); // role-gated

// ---------- DB GUARD ----------
if (!isset($conn) || !($conn instanceof mysqli)) {
    echo "<div class='container py-4'><div class='alert alert-danger mb-0'>DB connection not available.</div></div>";
    require_once 'footer.php';
    exit;
}

// ---------- CSRF ----------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

function csrf_verify() {
    $posted = $_POST['csrf_token'] ?? '';
    if (!is_string($posted) || !hash_equals($_SESSION['csrf_token'] ?? '', $posted)) {
        http_response_code(400);
        exit('Bad request');
    }
}

// ---------- FLASH ----------
function flash_set($type, $msg) {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function flash_get() {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ---------- LOAD CURRENT ----------
$welcome = [
    'title' => 'Welcome',
    'message' => '',
    'accent_color' => '#000000',
];

$stmt = $conn->prepare("SELECT title, message, accent_color FROM site_welcome WHERE id=1 LIMIT 1");
if ($stmt && $stmt->execute()) {
    $stmt->bind_result($t, $m, $c);
    if ($stmt->fetch()) {
        $welcome['title'] = (string)$t;
        $welcome['message'] = (string)$m;
        $welcome['accent_color'] = (string)$c;
    }
    $stmt->close();
}

// ---------- SAVE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_welcome') {
    csrf_verify();

    $title = trim((string)($_POST['title'] ?? 'Welcome'));
    $message = trim((string)($_POST['message'] ?? ''));
    $accent = trim((string)($_POST['accent_color'] ?? '#000000'));

    if ($title === '') $title = 'Welcome';
    if ($message === '') {
        flash_set('danger', 'Welcome message cannot be empty.');
        header('Location: admin_site_welcome.php');
        exit;
    }

    // Basic #RRGGBB guard
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
        $accent = '#000000';
    }

    $sql = "
      INSERT INTO site_welcome (id, title, message, accent_color)
      VALUES (1, ?, ?, ?)
      ON DUPLICATE KEY UPDATE
        title=VALUES(title),
        message=VALUES(message),
        accent_color=VALUES(accent_color)
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        flash_set('danger', 'Save failed: prepare error.');
        header('Location: admin_site_welcome.php');
        exit;
    }

    $stmt->bind_param("sss", $title, $message, $accent);

    if (!$stmt->execute()) {
        flash_set('danger', 'Save failed: ' . $stmt->error);
        $stmt->close();
        header('Location: admin_site_welcome.php');
        exit;
    }

    $stmt->close();
    flash_set('success', 'Welcome card updated.');
    header('Location: admin_site_welcome.php');
    exit;
}

$flash = flash_get();

require_once 'includes/header.php';
?>

<div class="container py-4">
    
<?php require_once 'includes/admin_menu.php'; ?>  

  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h3 mb-0">Welcome Card</h1>
      <div class="text-muted small">Controls the welcome panel at the top of index.php</div>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">

      <form method="post" action="admin_site_welcome.php">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_welcome">

        <div class="mb-3">
          <label class="form-label">Title</label>
          <input type="text" name="title" class="form-control" maxlength="100" value="<?= h($welcome['title']) ?>">
        </div>

        <div class="mb-3">
          <label class="form-label">Message</label>
          <textarea name="message" class="form-control" rows="5" data-ckeditor="1"><?= h($welcome['message']) ?></textarea>
          <div class="form-text">This is the body text shown under the title.</div>
        </div>

        <div class="mb-3">
          <label class="form-label">Accent colour</label>
          <input type="color" name="accent_color" class="form-control form-control-color" value="<?= h($welcome['accent_color']) ?>">
          <div class="form-text">Used for the left border accent on the welcome card.</div>
        </div>

        <button class="btn btn-primary">Save</button>
      </form>

    </div>
  </div>

</div>

<?php require_once 'includes/footer.php'; ?>
