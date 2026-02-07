<?php
// admin_plugin_manager.php - Plugin Management (admin-only)

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$user = auth_require($conn, ['admin']); // role-gated

// ---------- FLASH FUNCTIONS ----------
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

require_once 'includes/header.php';

// ---------- CSRF ----------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get all loaded plugins
$plugins = $GLOBALS['plugin_loader']->get_plugins();

// Sort plugins by name
uasort($plugins, fn($a, $b) => strcasecmp($a['name'], $b['name']));

$flash = flash_get();
?>

<div class="container py-4">
    
<?php require_once 'includes/admin_menu.php'; ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h3 mb-0">Plugins</h1>
      <div class="text-muted small">Installed plugins & extensions</div>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <?php if (empty($plugins)): ?>
    <div class="alert alert-info">
      <strong>No plugins installed.</strong><br>
      Upload PHP files to <code>/plugins/</code> directory to install plugins.
    </div>
  <?php else: ?>
    <div class="row g-3">
      <?php foreach ($plugins as $slug => $plugin): ?>
        <div class="col-md-6">
          <div class="card h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <h5 class="card-title mb-0"><?= htmlspecialchars($plugin['name']) ?></h5>
                <span class="badge bg-secondary"><?= htmlspecialchars($plugin['version']) ?></span>
              </div>
              
              <?php if ($plugin['description']): ?>
                <p class="card-text text-muted small mb-2">
                  <?= htmlspecialchars($plugin['description']) ?>
                </p>
              <?php endif; ?>
              
              <?php if ($plugin['author']): ?>
                <p class="card-text mb-3">
                  <small class="text-muted">By <?= htmlspecialchars($plugin['author']) ?></small>
                </p>
              <?php endif; ?>
              
              <a href="plugin.php?t=<?= urlencode($slug) ?>" class="btn btn-sm btn-primary">
                View Page
              </a>
            </div>
            
            <div class="card-footer small text-muted">
              <code><?= htmlspecialchars(basename($plugin['file'])) ?></code>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="alert alert-light mt-4">
    <strong>How to add plugins:</strong> Upload a PHP file to the <code>/plugins/</code> directory. 
    Each plugin becomes a page at <code>plugin.php?t=filename</code>
  </div>

</div>

<?php require_once 'includes/footer.php'; ?>