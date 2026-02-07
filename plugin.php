<?php
$pageTitle = "Plugin";

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/header.php';

// ---- DB guard ----
if (!isset($conn) || !($conn instanceof mysqli)) {
    echo "<div class='container py-4'><div class='alert alert-danger mb-0'>DB connection not available.</div></div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Get plugin name from URL
$pluginName = trim($_GET['t'] ?? '');
if ($pluginName === '') {
    echo "<div class='container py-4'><div class='alert alert-danger mb-0'>Plugin not specified.</div></div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

?>

<div class="page-content">
  <div class="container">
    <div class="py-4">

       <!-- Plugin injects all its content below -->
      <?php do_action('plugin_content_' . $pluginName); ?>

    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>