<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$currentUser = auth_require($conn, ['admin']); // admin only

$pageTitle = "Docs quality";

// -------------------- Flagged docs (Needs improvement) --------------------
$flagged = [];
$stmt = $conn->prepare("
  SELECT
    d.id,
    d.title,
    d.slug,
    c.name AS category_name,
    COUNT(f.id) AS flag_count,
    COALESCE(ROUND(AVG(r.rating), 1), 0) AS avg_rating,
    COUNT(r.id) AS rating_count,
    MAX(f.created_at) AS last_flagged
  FROM knowledge_documents d
  JOIN knowledge_categories c ON c.id = d.category_id
  JOIN knowledge_flags f ON f.document_id = d.id
  LEFT JOIN knowledge_ratings r ON r.document_id = d.id
  WHERE d.is_published = 1
  GROUP BY d.id
  ORDER BY flag_count DESC, last_flagged DESC
  LIMIT 50
");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $flagged[] = $row;
$stmt->close();

// -------------------- Low rated docs --------------------
$low = [];
$minRatings = 3;     // only consider docs with meaningful signal
$lowThreshold = 3.0; // <= 3.0 considered low quality signal

$stmt = $conn->prepare("
  SELECT
    d.id,
    d.title,
    d.slug,
    c.name AS category_name,
    ROUND(AVG(r.rating), 1) AS avg_rating,
    COUNT(r.id) AS rating_count,
    MAX(r.created_at) AS last_rated
  FROM knowledge_documents d
  JOIN knowledge_categories c ON c.id = d.category_id
  JOIN knowledge_ratings r ON r.document_id = d.id
  WHERE d.is_published = 1
  GROUP BY d.id
  HAVING COUNT(r.id) >= ? AND AVG(r.rating) <= ?
  ORDER BY avg_rating ASC, rating_count DESC, last_rated DESC
  LIMIT 50
");
$stmt->bind_param("id", $minRatings, $lowThreshold);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $low[] = $row;
$stmt->close();

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    
<?php require_once 'includes/admin_menu.php'; ?> 

<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h4 mb-0">Docs quality</h1>
</div>

<!-- Flagged -->
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <div class="fw-semibold">Needs improvement flags</div>
    <span class="badge text-bg-danger"><?= count($flagged) ?></span>
  </div>

  <div class="card-body p-0">
    <?php if (!$flagged): ?>
      <div class="p-3 small text-muted">No documents have been flagged.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th>Document</th>
              <th>Category</th>
              <th class="text-center">Flags</th>
              <th class="text-center">Rating</th>
              <th class="text-end">Last flagged</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($flagged as $d): ?>
              <tr>
                <td class="fw-semibold">
                  <?= h($d['title']) ?>
                  <div class="small text-muted"><?= h($d['slug']) ?></div>
                </td>
                <td><?= h($d['category_name']) ?></td>
                <td class="text-center">
                  <span class="badge text-bg-danger"><?= (int)$d['flag_count'] ?></span>
                </td>
                <td class="text-center">
                  <span class="badge text-bg-warning">
                    <?= h($d['avg_rating']) ?>★
                  </span>
                  <span class="small text-muted">(<?= (int)$d['rating_count'] ?>)</span>
                </td>
                <td class="text-end small text-muted">
                  <?= h($d['last_flagged'] ?? '') ?>
                </td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-secondary"
                     href="/knowledge_document.php?doc=<?= h($d['slug']) ?>">
                    View
                  </a>
                  <!-- If you have an edit page, add it here:
                  <a class="btn btn-sm btn-outline-primary" href="/admin_knowledge_edit.php?id=<?= (int)$d['id'] ?>">Edit</a>
                  -->
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Low rated -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <div class="fw-semibold">Low-rated documents</div>
    <span class="badge text-bg-warning"><?= count($low) ?></span>
  </div>

  <div class="card-body p-0">
    <?php if (!$low): ?>
      <div class="p-3 small text-muted">No low-rated documents (with ≥ <?= (int)$minRatings ?> ratings).</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th>Document</th>
              <th>Category</th>
              <th class="text-center">Rating</th>
              <th class="text-end">Last rated</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($low as $d): ?>
              <tr>
                <td class="fw-semibold">
                  <?= h($d['title']) ?>
                  <div class="small text-muted"><?= h($d['slug']) ?></div>
                </td>
                <td><?= h($d['category_name']) ?></td>
                <td class="text-center">
                  <span class="badge text-bg-warning"><?= h($d['avg_rating']) ?>★</span>
                  <span class="small text-muted">(<?= (int)$d['rating_count'] ?>)</span>
                </td>
                <td class="text-end small text-muted">
                  <?= h($d['last_rated'] ?? '') ?>
                </td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-secondary"
                     href="/knowledge_document.php?doc=<?= h($d['slug']) ?>">
                    View
                  </a>
                  <!-- Optional edit link goes here too -->
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
