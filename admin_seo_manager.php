<?php
// admin_seo_manager.php — Forum SEO Admin (members-only)
// Assumes: header.php includes auth, mysqli $conn, Bootstrap 5

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$user = auth_require($conn, ['admin']); // role-gated

require_once 'includes/header.php';

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

function csrf_verify()
{
    $posted = $_POST['csrf_token'] ?? '';
    if (!is_string($posted) || !hash_equals($_SESSION['csrf_token'] ?? '', $posted)) {
        http_response_code(400);
        exit('Bad request');
    }
}

// Generate sitemap.xml
function generate_sitemap()
{
    global $conn;

    $site_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
    $sitemap = array();
    $sitemap[] = "<url><loc>{$site_url}/</loc><changefreq>daily</changefreq><priority>1.0</priority></url>";

    $knowledge_documents = $conn->query("SELECT slug FROM knowledge_documents");
    while ($row = $knowledge_documents->fetch_assoc()) {
        $sitemap[] = "<url><loc>{$site_url}/knowledge_document.php?doc=" . $row['slug'] . "</loc><changefreq>monthly</changefreq><priority>0.8</priority></url>";
    }

    $forum_topics = $conn->query("SELECT slug FROM forum_topics");
    while ($row = $forum_topics->fetch_assoc()) {
        $sitemap[] = "<url><loc>{$site_url}/topic.php?t=" . $row['slug'] . "</loc><changefreq>weekly</changefreq><priority>0.9</priority></url>";
    }

    $sitemap_xml = "<?xml version='1.0' encoding='UTF-8'?>
<urlset xmlns='http://www.sitemaps.org/schemas/sitemap/0.9'>
" . implode("\n", $sitemap) . "
</urlset>";

    file_put_contents(__DIR__ . '/sitemap.xml', $sitemap_xml);
    return $sitemap_xml;
}

// Generate .well-known/agents.json
function generate_agents()
{
    global $conn;

    $agents = array();
    $knowledge_docs = $conn->query("SELECT title, slug, content FROM knowledge_documents");
    while ($row = $knowledge_docs->fetch_assoc()) {
        $site_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
        $summary = substr(strip_tags($row['content']), 0, 200);
        $agent = array(
            "title" => strip_tags($row['title']),
            "summary" => $summary,
            "url" => $site_url . "/knowledge_document.php?doc=" . $row['slug']
        );
        $agents[] = $agent;
    }

    $forum_topics = $conn->query("SELECT id, title, slug FROM forum_topics");
    while ($row = $forum_topics->fetch_assoc()) {
        $posts = $conn->query("SELECT body FROM forum_posts WHERE id = " . $row['id']);
        $summary = '';
        while ($post = $posts->fetch_assoc()) {
            $summary .= strip_tags($post['body']);
        }
        if ($summary) {
            $summary = substr($summary, 0, 200);
        }
        $site_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
        $agent = array(
            "title" => strip_tags($row['title']),
            "summary" => $summary,
            "url" => $site_url . "/topic.php?t=" . $row['slug']
        );
        $agents[] = $agent;
    }

    $agents_json = json_encode($agents, JSON_PRETTY_PRINT);

    file_put_contents(__DIR__ . '/.well-known/agents.json', $agents_json);
    return $agents_json;
}

// Manual update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_sitemap') {
    csrf_verify();
    $sitemap_xml = generate_sitemap();
    flash_set('success', 'Sitemap updated successfully.');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_agents') {
    csrf_verify();
    $agents_json = generate_agents();
    flash_set('success', 'Agents file updated successfully.');
}

// ---------- FLASH ----------
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

// ---------- PAGE CONTENT ----------
$flash = flash_get();

?>

<div class="container py-4">
    
<?php require_once 'includes/admin_menu.php'; ?>

  <h1 class="h3 mb-3">SEO Manager</h1>

      <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
          <?= htmlspecialchars($flash['msg']) ?>
        </div>
      <?php endif; ?>
  
      <div class="row">
        <div class="col-md-6">
          <div class="card">
            <div class="card-body">
              <h2 class="h5">Sitemap.xml</h2>
              <p>Last updated: <?= file_exists('sitemap.xml') ? date('Y-m-d H:i:s', filemtime('sitemap.xml')) : 'Never' ?></p>
              <form method="post" action="admin_seo_manager.php" id="form-sitemap">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="update_sitemap">
                <button class="btn btn-primary" type="submit">Update Sitemap</button>
                <a class="btn btn-outline-secondary" href="sitemap.xml" target="_blank">View Sitemap</a>
              </form>
            </div>
          </div>
         </div>

        <div class="col-md-6">
          <div class="card">
            <div class="card-body">
              <h2 class="h5">.well-known/agents.json</h2>
              <p>Last updated: <?= file_exists('.well-known/agents.json') ? date('Y-m-d H:i:s', filemtime('.well-known/agents.json')) : 'Never' ?></p>
              <form method="post" action="admin_seo_manager.php" id="form-agents">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="update_agents">
                <button class="btn btn-primary" type="submit">Update Agents</button>
                <a class="btn btn-outline-secondary" href=".well-known/agents.json" target="_blank">View Agents</a>
              </form>
            </div>
          </div>
         </div>

        </div>
    </div>


<?php require_once 'includes/footer.php'; ?>