<?php
$pageTitle = "Knowledge Category";

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/db.php';

if (!function_exists('h')) {
  function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
  echo "<div class='container py-4'><div class='alert alert-danger mb-0'>DB connection not available.</div></div>";
  require_once __DIR__ . '/includes/footer.php';
  exit;
}

$catSlug = trim($_GET['cat'] ?? '');
if ($catSlug === '') {
  http_response_code(404);
  echo "<div class='container py-4'><div class='alert alert-danger mb-0'>Category not found.</div></div>";
  require_once __DIR__ . '/includes/footer.php';
  exit;
}

// Fetch category
$kbCategory = null;
$stmt = $conn->prepare("
  SELECT id, name, slug, description, accent_color
  FROM knowledge_categories
  WHERE slug = ? AND is_active = 1
  LIMIT 1
");
$stmt->bind_param("s", $catSlug);
$stmt->execute();
$res = $stmt->get_result();
$kbCategory = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$kbCategory) {
  http_response_code(404);
  echo "<div class='container py-4'><div class='alert alert-danger mb-0'>Category not found.</div></div>";
  require_once __DIR__ . '/includes/footer.php';
  exit;
}

// Fetch documents in category
$kbDocs = [];
$stmt = $conn->prepare("
  SELECT id, title, slug, content, created_at, updated_at
  FROM knowledge_documents
  WHERE category_id = ?
    AND is_published = 1
  ORDER BY created_at DESC, title ASC
");
$catId = (int)$kbCategory['id'];
$stmt->bind_param("i", $catId);
$stmt->execute();
$res = $stmt->get_result();
while ($res && ($row = $res->fetch_assoc())) {
  $kbDocs[] = $row;
}
$stmt->close();

function kb_excerpt(string $html, int $words = 20): string {
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
    if ($text === '') return '';

    $parts = explode(' ', $text);
    if (count($parts) <= $words) {
        return $text;
    }

    return implode(' ', array_slice($parts, 0, $words)) . '…';
}

$pageTitle = $kbCategory['name'] . " - Knowledge Base";
?>

<div class="page-content">
  <div class="container">
    <div class="py-4">

      <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
          <h1 class="h4 mb-1"><?= h($kbCategory['name']) ?></h1>
          <?php if (!empty($kbCategory['description'])): ?>
            <div class="text-muted"><?= h($kbCategory['description']) ?></div>
          <?php endif; ?>
        </div>
        <a class="btn btn-sm btn-outline-secondary" href="knowledge.php">All categories</a>
      </div>

      <div class="row">
        <?php if (count($kbDocs) === 0): ?>
          <div class="col-12">
            <div class="alert alert-info mb-0">No documents in this category yet.</div>
          </div>
        <?php else: ?>

          <?php foreach ($kbDocs as $d): ?>
            <div class="col-12 col-lg-4 py-3">
              <div class="card shadow-sm position-relative h-100" style="border-left:4px solid <?= h($kbCategory['accent_color'] ?? '#adb5bd') ?>;">
                <div class="card-body">
                <h5 class="card-title mb-2"><?= h($d['title']) ?></h5>
                <?php if (!empty($d['content'])): ?>
                  <div class="card-text small text-muted mb-2">
                    <?= h(kb_excerpt($d['content'], 20)) ?>
                  </div>
                <?php endif; ?>
                
                <div class="small text-muted">
                  <?= h(substr((string)$d['created_at'], 0, 10)) ?>
                </div>
                  <a class="stretched-link" href="knowledge_document.php?doc=<?= h($d['slug']) ?>"></a>
                </div>
              </div>
            </div>
          <?php endforeach; ?>

        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
