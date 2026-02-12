<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$user = auth_require($conn, ['admin']);
require_once 'includes/header.php';

// CSRF
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

// Flash messages
function flash_set($type, $msg) {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function flash_get() {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// Sanitize HTML - allow safe tags only
function sanitize_notice_html($html) {
    // Strip all tags except safe ones
    $allowed = '<a><strong><b><em><i><br><p>';
    $clean = strip_tags($html, $allowed);
    
    // Remove javascript: from links and dangerous attributes
    $clean = preg_replace('/javascript:/i', '', $clean);
    $clean = preg_replace('/on\w+\s*=/i', '', $clean); // Remove onclick, onload, etc.
    
    return trim($clean);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_notice') {
    csrf_verify();
    
    try {
        $message = sanitize_notice_html($_POST['message'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $is_dismissible = isset($_POST['is_dismissible']) ? 1 : 0;
        $color_scheme = trim($_POST['color_scheme'] ?? 'info');
        
        // Validate color scheme
        $valid_colors = ['primary', 'secondary', 'success', 'danger', 'warning', 'info'];
        if (!in_array($color_scheme, $valid_colors)) {
            $color_scheme = 'info';
        }
        
        // Generate new ID if message changed (forces re-display for dismissed users)
        $currentNotice = $conn->query("SELECT message FROM site_notice WHERE id = 1")->fetch_assoc();
        $notice_id = ($currentNotice && $currentNotice['message'] === $message) 
            ? $conn->query("SELECT notice_id FROM site_notice WHERE id = 1")->fetch_assoc()['notice_id'] ?? uniqid()
            : uniqid();
        
        // Update or insert
        $stmt = $conn->prepare("
            INSERT INTO site_notice (id, notice_id, message, is_active, is_dismissible, color_scheme, updated_at)
            VALUES (1, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                notice_id = VALUES(notice_id),
                message = VALUES(message),
                is_active = VALUES(is_active),
                is_dismissible = VALUES(is_dismissible),
                color_scheme = VALUES(color_scheme),
                updated_at = NOW()
        ");
        
        if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
        
        $stmt->bind_param("ssiis", $notice_id, $message, $is_active, $is_dismissible, $color_scheme);
        
        if (!$stmt->execute()) throw new Exception('Execute failed: ' . $stmt->error);
        
        flash_set('success', 'Notice saved successfully.');
        header('Location: admin_notice.php');
        exit;
        
    } catch (Exception $e) {
        flash_set('danger', 'Error: ' . $e->getMessage());
        header('Location: admin_notice.php');
        exit;
    }
}

// Get current notice
$notice = $conn->query("SELECT * FROM site_notice WHERE id = 1")->fetch_assoc();
$flash = flash_get();
?>

<div class="container py-4">
    
<?php require_once 'includes/admin_menu.php'; ?>

  <h1 class="h3 mb-4">Site Notice Banner</h1>

  <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show">
      <?= htmlspecialchars($flash['msg']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div class="row">
    <div class="col-12 col-lg-8">
      
      <div class="card mb-4">
        <div class="card-body">
          <form method="post" action="admin_notice.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="save_notice">
            
            <div class="mb-3">
              <label for="message" class="form-label fw-semibold">Notice Message</label>
              <textarea class="form-control" name="message" id="message" rows="4" 
                        placeholder="Enter your notice message. You can use HTML tags like <a>, <strong>, <em>, etc."><?= htmlspecialchars($notice['message'] ?? '') ?></textarea>
              <div class="form-text">
                HTML allowed: &lt;a&gt;, &lt;strong&gt;, &lt;b&gt;, &lt;em&gt;, &lt;i&gt;, &lt;br&gt;, &lt;p&gt;
              </div>
            </div>

            <div class="mb-3">
              <label for="color_scheme" class="form-label fw-semibold">Color Scheme</label>
              <select class="form-select" name="color_scheme" id="color_scheme">
                <?php
                $colors = [
                  'primary' => 'Primary (Blue)',
                  'secondary' => 'Secondary (Gray)',
                  'success' => 'Success (Green)',
                  'danger' => 'Danger (Red)',
                  'warning' => 'Warning (Yellow)',
                  'info' => 'Info (Light Blue)'
                ];
                $current = $notice['color_scheme'] ?? 'info';
                foreach ($colors as $value => $label):
                ?>
                  <option value="<?= $value ?>" <?= $current === $value ? 'selected' : '' ?>>
                    <?= $label ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" 
                       <?= ($notice['is_active'] ?? 0) ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_active">
                  <strong>Display notice</strong>
                  <div class="form-text">Uncheck to hide the notice without deleting it</div>
                </label>
              </div>
            </div>

            <div class="mb-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_dismissible" id="is_dismissible" 
                       <?= ($notice['is_dismissible'] ?? 1) ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_dismissible">
                  <strong>Allow users to dismiss</strong>
                  <div class="form-text">Users can close the notice (stored in cookie)</div>
                </label>
              </div>
            </div>

            <button type="submit" class="btn btn-primary">Save Notice</button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <h5 class="card-title">Usage Instructions</h5>
          <p class="mb-2">To display the notice banner, add this line to your page:</p>
          <pre class="bg-light p-3 rounded"><code>&lt;?php include __DIR__ . '/includes/notice_banner.php'; ?&gt;</code></pre>
          <p class="mb-0 text-muted small">
            <strong>Recommended locations:</strong> Forum pages, home page. 
            Not recommended for knowledge base articles (users are focused on reading).
          </p>
        </div>
      </div>

    </div>
  </div>

</div>

<?php require_once 'includes/footer.php'; ?>
