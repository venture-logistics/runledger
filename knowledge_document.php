<?php
$pageTitle = "Knowledge Document";


require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/header.php';
// h() already exists in your forum helpers; don't redeclare it.

// ---- DB guard ----
if (!isset($conn) || !($conn instanceof mysqli)) {
  echo "<div class='container py-4'><div class='alert alert-danger mb-0'>DB connection not available.</div></div>";
  require_once __DIR__ . '/includes/footer.php';
  exit;
}

$docSlug = trim($_GET['doc'] ?? '');
if ($docSlug === '') {
  http_response_code(404);
  echo "<div class='container py-4'><div class='alert alert-danger mb-0'>Document not found.</div></div>";
  require_once __DIR__ . '/includes/footer.php';
  exit;
}

$userRating = 0;

// Fetch document + category (use unique var name to avoid collisions)

$kbDoc = null;

$stmt = $conn->prepare("
  SELECT
    d.id, d.title, d.slug, d.content, d.created_at, d.updated_at,
    c.name AS category_name, c.slug AS category_slug, c.accent_color,
    COALESCE(ROUND(AVG(r.rating), 1), 0) AS avg_rating,
    COUNT(r.rating) AS rating_count
  FROM knowledge_documents d
  JOIN knowledge_categories c ON c.id = d.category_id
  LEFT JOIN knowledge_ratings r ON r.document_id = d.id
  WHERE d.slug = ?
    AND d.is_published = 1
    AND c.is_active = 1
  GROUP BY d.id
  LIMIT 1
");

if (!$stmt) {
  echo "<div class='container py-4'><div class='alert alert-danger mb-0'>Query prepare failed: " . h($conn->error) . "</div></div>";
  require_once __DIR__ . '/includes/footer.php';
  exit;
}

$stmt->bind_param("s", $docSlug);
$stmt->execute();
$res = $stmt->get_result();
$kbDoc = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$kbDoc) {
  http_response_code(404);
  echo "<div class='container py-4'><div class='alert alert-danger mb-0'>Document not found.</div></div>";
  require_once __DIR__ . '/includes/footer.php';
  exit;
}

$userFlagged = false;

if (!empty($currentUser)) {
  $uid = (int)$currentUser['id'];
  $stmt = $conn->prepare("
    SELECT 1
    FROM knowledge_flags
    WHERE document_id = ? AND user_id = ?
    LIMIT 1
  ");
  $stmt->bind_param("ii", $kbDoc['id'], $uid);
  $stmt->execute();
  $userFlagged = (bool)$stmt->get_result()->fetch_row();
  $stmt->close();
}

$pageTitle = $kbDoc['title'] . " — Knowledge Base";
?>

<div class="page-content">
  <div class="container">
    <div class="py-4">

      <div class="d-flex align-items-center justify-content-between mb-3">
        <a class="btn btn-sm btn-outline-secondary"
           href="knowledge_category.php?cat=<?= h($kbDoc['category_slug']) ?>">
          ← <?= h($kbDoc['category_name']) ?>
        </a>

        <a class="btn btn-sm btn-outline-secondary" href="knowledge.php">All categories</a>
      </div>

      <div class="card shadow-sm" style="border-left:4px solid <?= h($kbDoc['accent_color'] ?? '#adb5bd') ?>;">
        <div class="card-body">
          <h1 class="h4 mb-2"><?= h($kbDoc['title']) ?></h1>

          <div class="small text-muted mb-3">
            <?= h(substr((string)$kbDoc['created_at'], 0, 10)) ?>
            <?php if (!empty($kbDoc['updated_at'])): ?>
              · Updated <?= h(substr((string)$kbDoc['updated_at'], 0, 10)) ?>
            <?php endif; ?>
          </div>

          <!-- CKEditor HTML stored in DB; render as HTML -->
          <div class="kb-content">
            <?= $kbDoc['content'] ?>
            
            <hr class="mt-5 mb-3">
            
            <p>Is this document high quality? Or does it need improvement? High ranking articles display in the footer, admin notified if improvements required.</p>
            
            <?php if (!empty($currentUser)): ?>
              <form method="post" action="/rate_document.php" class="d-inline">
                <input type="hidden" name="document_id" value="<?= (int)$kbDoc['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            
                <div class="rating-stars">
                  <?php for ($i = 1; $i <= 5; $i++): ?>
                    <button
                      type="submit"
                      name="rating"
                      value="<?= $i ?>"
                      class="btn btn-link p-0 <?= $userRating === $i ? 'fw-bold' : '' ?>"
                      title="Rate <?= $i ?> / 5"
                    >
                      <?= $i <= $userRating ? '★' : '☆' ?>
                    </button>
                  <?php endfor; ?>
                </div>
              </form>
            <?php else: ?>
              <div class="small text-muted">
                Log in to rate this document
              </div>
            <?php endif; ?>
            
             <div class="border-top pt-3 mt-3 d-flex justify-content-between align-items-center">
              <div class="small text-muted">
                Rating: <?= h($kbDoc['avg_rating']) ?> / 5 (<?= (int)$kbDoc['rating_count'] ?>)
              </div>
              
                <?php if (!empty($currentUser)): ?>
                  <form method="post" action="/flag_document.php" class="d-inline">
                    <input type="hidden" name="document_id" value="<?= (int)$kbDoc['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                
                    <button type="submit"
                            class="btn btn-sm <?= $userFlagged ? 'btn-danger' : 'btn-outline-danger' ?>"
                            <?= $userFlagged ? 'disabled' : '' ?>>
                      <?= $userFlagged ? 'Flagged for improvement' : 'Needs improvement' ?>
                    </button>
                  </form>
                <?php endif; ?>          
                
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
