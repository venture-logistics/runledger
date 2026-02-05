<?php
// includes/header.php

ini_set('display_errors', '1');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/forum_helpers.php';
require_once __DIR__ . '/menu_helpers.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection missing');
}

if (!function_exists('getCurrentUser')) {
    function getCurrentUser(mysqli $conn): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        $uid = (int)$_SESSION['user_id'];

        $stmt = $conn->prepare("
            SELECT id, role, forum_designation, forum_about, badge_class, name, email
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->bind_result($id, $role, $forum_designation, $forum_about, $badgeClass, $name, $email);

        if (!$stmt->fetch()) {
            $stmt->close();
            return null;
        }

        $stmt->close();

        return [
            'id' => (int)$id,
            'role' => $role,
            'forum_designation' => $forum_designation,
            'forum_about' => $forum_about,
            'badge_class' => $badgeClass,
            'name' => $name,
            'email' => $email,
        ];
    }
}

// IMPORTANT: define it BEFORE any navbar uses it
$currentUser = getCurrentUser($conn);

// ---- Load site settings (admin-controlled) ----
$site = null;

$res = $conn->query("
    SELECT site_name, site_description, footer_bottom, logo_top, logo_bottom, favicon
    FROM site_settings
    WHERE id=1
    LIMIT 1
");

if ($res && $res->num_rows === 1) {
  $site = $res->fetch_assoc();
} else {

  // If we’re in admin, allow the page to load so you can configure settings.
  $isAdminPage = (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin') !== false)
              || (strpos(basename($_SERVER['PHP_SELF'] ?? ''), 'admin_') === 0)
              || (basename($_SERVER['PHP_SELF'] ?? '') === 'admin.php');

  if ($isAdminPage) {
    // Safe defaults for admin only (so you can reach Settings page)
    $site = [
      'site_name'   => 'Ledger (Setup)',
      'logo_top'    => '/assets/img/logo-top.png',
      'logo_bottom' => '/assets/img/logo-bottom.png',
      'favicon'     => '/favicon.ico',
    ];
  } else {
    http_response_code(500);
    exit('Site settings not configured. Please configure in Admin → Settings.');
  }
}

// Page title (pages may override with $pageTitle)
$pageTitle = $pageTitle ?? $site['site_name'];
$mainClass = $mainClass ?? '';

$cats = [];

if (isset($conn) && ($conn instanceof mysqli)) {
  $sql = "SELECT name, slug
          FROM forum_categories
          ORDER BY sort_order ASC, name ASC";
  if ($res = $conn->query($sql)) {
    while ($row = $res->fetch_assoc()) {
      if (!empty($row['slug']) && !empty($row['name'])) {
        $cats[] = $row;
      }
    }
    $res->free();
  }
}

// helper if not already available on this page
if (!function_exists('h')) {
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$kbCats = [];
$q = $conn->prepare("SELECT slug, name FROM knowledge_categories ORDER BY name ASC");
if ($q && $q->execute()) {
  $res = $q->get_result();
  while ($row = $res->fetch_assoc()) $kbCats[] = $row;
  $q->close();
}

$customMenuItems = [];
if (isset($conn) && ($conn instanceof mysqli)) {
    $customMenuItems = getCustomMenuItems($conn, $currentUser);
}

require_once __DIR__ . '/seo.php';

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="<?= h($site['favicon']) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <title><?= h($seoTitle) ?></title>
  <link rel="canonical" href="<?= h($seoCanonical) ?>">

<style>
/* Topic-page category accent */
main[class*="cat-"] { border-left: 4px solid transparent; padding-left: 12px; }

</style>  
  
</head>
<body>
    
<nav class="navbar navbar-dark bg-dark">
  <div class="container">

    <!-- Brand -->
    <a class="navbar-brand d-flex align-items-center" href="/index.php">
      <img src="<?= h($site['logo_top']) ?>" width="60" height="60" alt="Logo">
      <span class="ms-2"><?= h($site['site_name']) ?></span>
    </a>

    <!-- Always-visible offcanvas toggler -->
    <button class="navbar-toggler" type="button"
            data-bs-toggle="offcanvas"
            data-bs-target="#ledgerNav"
            aria-controls="ledgerNav"
            aria-label="Open menu">
      <span class="navbar-toggler-icon"></span>
    </button>

  </div>
</nav>

<!-- OFFCANVAS: used on ALL screen sizes -->
<div class="offcanvas offcanvas-end text-bg-dark" tabindex="-1" id="ledgerNav" aria-labelledby="ledgerNavLabel">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title" id="ledgerNavLabel"><?= h($site['site_name']) ?></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>

  <div class="offcanvas-body d-flex flex-column">

    <!-- Search -->
    <form method="get" action="/search.php" class="mb-3" role="search">
      <div class="input-group">
        <input type="search" name="q" class="form-control" placeholder="Search" aria-label="Search forum">
        <button class="btn btn-outline-secondary" type="submit">Search</button>
      </div>
    </form>

    <!-- Primary nav -->
    <nav class="nav flex-column gap-1">

      <!-- CUSTOM MENU ITEMS START -->
      <?php if (!empty($customMenuItems)): ?>
        <?php echo renderCustomMenuItems($customMenuItems); ?>
      <?php endif; ?>
      <!-- CUSTOM MENU ITEMS END -->

          <a class="nav-link text-white" href="download.php">
            Download Ledger
          </a>
        
        <div class="dropdown">
          <a class="nav-link dropdown-toggle text-white" href="#" data-bs-toggle="dropdown" aria-expanded="false">
            Knowledge Base
          </a>
          <ul class="dropdown-menu dropdown-menu-dark w-100">
            <?php if (empty($kbCats)): ?>
              <li><span class="dropdown-item-text text-muted">No docs categories</span></li>
            <?php else: ?>
              <?php foreach ($kbCats as $k): ?>
                <li>
                  <a class="dropdown-item"
                     href="/knowledge_category.php?cat=<?= h($k['slug']) ?>">
                    <?= h($k['name']) ?>
                  </a>
                </li>
              <?php endforeach; ?>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="/knowledge.php">All docs</a></li>
            <?php endif; ?>
          </ul>
        </div>
      
      <div class="dropdown">
        <a class="nav-link dropdown-toggle text-white" href="#" data-bs-toggle="dropdown" aria-expanded="false">
          Discussion
        </a>
        <ul class="dropdown-menu dropdown-menu-dark w-100">
          <?php if (!$cats): ?>
            <li><span class="dropdown-item-text text-muted">No categories</span></li>
          <?php else: ?>
            <?php foreach ($cats as $c): ?>
              <li>
                <a class="dropdown-item"
                   href="/category.php?c=<?= h($c['slug']) ?>">
                  <?= h($c['name']) ?>
                </a>
              </li>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
      </div>

      <a class="nav-link text-white" href="/new_topic.php">New topic</a>

      <?php if (!empty($currentUser) && ($currentUser['role'] ?? '') === 'admin'): ?>
        <a class="nav-link text-warning" href="/admin.php">Admin</a>
      <?php endif; ?>
    </nav>

    <hr class="border-secondary my-3">

    <!-- Account actions pinned to bottom -->
    <div class="mt-auto">
      <?php if (empty($currentUser)): ?>
        <a href="/login.php" class="btn btn-primary w-100 mb-2">Log in</a>
        <a href="/signup.php" class="btn btn-outline-light w-100">Sign up</a>
      <?php else: ?>
        <a href="/profile.php" class="btn btn-outline-light w-100 mb-2">Account</a>
        <a href="/logout.php" class="btn btn-outline-danger w-100">Log out</a>
      <?php endif; ?>
    </div>

  </div>
</div>


<?php
$mainClass = $mainClass ?? '';
?>
<main class="container py-4 <?= htmlspecialchars($mainClass) ?>">