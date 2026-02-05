<?php
$pageTitle = "Knowledge Base";

// Load shared layout first (so any header-side variables/functions run first)
require_once __DIR__ . '/includes/header.php';

// Then load DB (or swap order if your header already loads db.php)
require_once __DIR__ . '/includes/db.php';

// Local escape helper (don't rely on header.php defining it)
if (!function_exists('h')) {
  function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

// Helper to truncate content to N words
function truncate_words($text, $limit = 20) {
  $words = explode(' ', strip_tags($text));
  if (count($words) > $limit) {
    return implode(' ', array_slice($words, 0, $limit)) . '...';
  }
  return implode(' ', $words);
}

// ---- DB guard ----
if (!isset($conn) || !($conn instanceof mysqli)) {
  echo "<div class='container py-4'><div class='alert alert-danger mb-0'>DB connection not available.</div></div>";
  require_once __DIR__ . '/includes/footer.php';
  exit;
}

// IMPORTANT: use a unique variable name that header.php will never overwrite
$kbCats = [];

$sql = "SELECT id, name, slug, description, accent_color
        FROM knowledge_categories
        WHERE is_active = 1
        ORDER BY sort_order ASC, name ASC";

$res = $conn->query($sql);
if ($res === false) {
  echo "<div class='container py-4'><div class='alert alert-danger mb-0'>Query failed: " . h($conn->error) . "</div></div>";
  require_once __DIR__ . '/includes/footer.php';
  exit;
}

while ($row = $res->fetch_assoc()) {
  $kbCats[] = $row;
}
$res->free();

// Fetch latest documents across all categories
$latestDocs = [];
$docsSql = "SELECT d.id, d.title, d.slug, d.content, d.created_at, c.name AS category_name, c.accent_color
            FROM knowledge_documents d
            INNER JOIN knowledge_categories c ON d.category_id = c.id
            WHERE d.is_published = 1 AND c.is_active = 1
            ORDER BY d.created_at DESC
            LIMIT 18";

$docsRes = $conn->query($docsSql);
if ($docsRes) {
  while ($doc = $docsRes->fetch_assoc()) {
    $latestDocs[] = $doc;
  }
  $docsRes->free();
}
?>

<div class="page-content">
  <div class="container">
    <div class="docs-overview py-5">
      <div class="row justify-content-center">
          
          <h2 class="h2 mb-4">Document Categories</h2>

        <?php if (count($kbCats) === 0): ?>
          <div class="col-12 col-lg-8">
            <div class="alert alert-info mb-0">
              No knowledge categories yet.
              <?php if (!empty($currentUser) && ($currentUser['role'] ?? '') === 'admin'): ?>
                <div class="mt-2">
                  <a class="btn btn-sm btn-primary" href="admin_knowledge_categories.php">Add categories</a>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php else: ?>

          <?php foreach ($kbCats as $c):
            $color = $c['accent_color'] ?? '#adb5bd';
            $name  = $c['name'] ?? '';
            $slug  = $c['slug'] ?? '';
            $desc  = trim($c['description'] ?? '');
          ?>
            <div class="col-12 col-lg-4 py-3">
              <div class="card shadow-sm position-relative h-100" style="border-left: 4px solid <?= h($color) ?>;">
                <div class="card-body">
                  <h5 class="card-title mb-3"><?= h($name) ?></h5>

                  <div class="card-text">
                    <?= h($desc !== '' ? $desc : 'No description yet.') ?>
                  </div>

                  <!-- Use Bootstrap's built-in stretched link (no custom mask CSS issues) -->
                  <a class="stretched-link" href="knowledge_category.php?cat=<?= h($slug) ?>"></a>
                </div>
              </div>
            </div>
          <?php endforeach; ?>

        <?php endif; ?>

      </div>
    </div>
    
    <hr class="my-1">

    <!-- Latest Documents Section -->
    <?php if (count($latestDocs) > 0): ?>
      <div class="latest-documents py-4">
        <h2 class="h2 mb-4">Latest Documents</h2>
        <div class="row">
          <?php foreach ($latestDocs as $doc): 
            $color = $doc['accent_color'] ?? '#adb5bd';
          ?>
            <div class="col-12 col-md-6 col-lg-4 mb-3">
              <div class="card h-100 shadow-sm" style="border-left: 4px solid <?= h($color) ?>;">
                <div class="card-body">
                  <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="badge" style="background-color: <?= h($color) ?>;">
                      <?= h($doc['category_name']) ?>
                    </span>
                    <small class="text-muted">
                      <?= date('M d, Y', strtotime($doc['created_at'])) ?>
                    </small>
                  </div>
                  
                  <h5 class="card-title">
                    <a href="knowledge_document.php?doc=<?= h($doc['slug']) ?>" 
                       class="text-decoration-none text-dark">
                      <?= h($doc['title']) ?>
                    </a>
                  </h5>
                  
                  <p class="card-text text-muted small">
                    <?= h(truncate_words($doc['content'], 20)) ?>
                  </p>
                  
                  <a href="knowledge_document.php?doc=<?= h($doc['slug']) ?>" 
                     class="btn btn-sm btn-outline-secondary">
                    Read More →
                  </a>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>