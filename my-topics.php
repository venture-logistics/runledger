<?php
declare(strict_types = 1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

// require login (any logged-in user)
auth_require($conn);

$pageTitle = "My topics";

require_once __DIR__ . '/includes/header.php';

// Simple paging
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$topics = [];
$total  = 0;

// --- Try both modes, choose what actually returns rows ---
$totalByUserId = 0;
$totalByName   = 0;

// Count by created_by_user_id (preferred when populated)
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM forum_topics WHERE created_by_user_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $totalByUserId = (int)($row['c'] ?? 0);
    $stmt->close();
}

// Count by created_by_name (fallback for current name-based build)
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM forum_topics WHERE created_by_name = ?");
if ($stmt) {
    $stmt->bind_param("s", $userName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $totalByName = (int)($row['c'] ?? 0);
    $stmt->close();
}

// Choose mode: use user_id only if it finds rows; otherwise use name
$mode = ($totalByUserId > 0) ? 'user_id' : 'name';
$total = ($mode === 'user_id') ? $totalByUserId : $totalByName;

// --- List ---
$sqlListByUserId = "
  SELECT t.id, t.title, t.slug, t.created_at,
         c.name AS category_name, c.slug AS category_slug,
         (SELECT COUNT(*) FROM forum_posts p WHERE p.topic_id = t.id) AS post_count
  FROM forum_topics t
  LEFT JOIN forum_categories c ON c.id = t.category_id
  WHERE t.created_by_user_id = ?
  ORDER BY t.created_at DESC
  LIMIT ? OFFSET ?
";

$sqlListByName = "
  SELECT t.id, t.title, t.slug, t.created_at,
         c.name AS category_name, c.slug AS category_slug,
         (SELECT COUNT(*) FROM forum_posts p WHERE p.topic_id = t.id) AS post_count
  FROM forum_topics t
  LEFT JOIN forum_categories c ON c.id = t.category_id
  WHERE t.created_by_name = ?
  ORDER BY t.created_at DESC
  LIMIT ? OFFSET ?
";

if ($mode === 'user_id') {
    $stmt = $conn->prepare($sqlListByUserId);
    if (!$stmt) {
        http_response_code(500);
        exit("DB error: " . $conn->error);
    }
    $stmt->bind_param("iii", $userId, $perPage, $offset);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $topics[] = $r;
    $stmt->close();
} else {
    $stmt = $conn->prepare($sqlListByName);
    if (!$stmt) {
        http_response_code(500);
        exit("DB error: " . $conn->error);
    }
    $stmt->bind_param("sii", $userName, $perPage, $offset);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $topics[] = $r;
    $stmt->close();
}

$totalPages = max(1, (int)ceil($total / $perPage));
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h4 mb-1">My topics</h1>
    <div class="text-muted small">
      Showing topics for <strong><?= h($currentUser['name'] ?? 'User') ?></strong>
    </div>
  </div>

  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="/index.php">Forum home</a>
    <a class="btn btn-primary btn-sm" href="/new_topic.php">New topic</a>
  </div>
</div>

<?php if (empty($topics)): ?>
  <div class="alert alert-info">
    You haven’t created any topics yet.
    <a href="/new_topic.php">Start a new topic</a>.
  </div>
<?php else: ?>
  <div class="list-group">
    <?php foreach ($topics as $t): ?>
      <a class="list-group-item list-group-item-action" href="/topic.php?t=<?= h($t['slug']) ?>">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <div class="fw-semibold"><?= h($t['title']) ?></div>
            <div class="small text-muted">
              <?php if (!empty($t['category_name'])): ?>
                <?= h($t['category_name']) ?>
              <?php else: ?>
                Uncategorized
              <?php endif; ?>
              · <?= h($t['created_at']) ?>
            </div>
          </div>
          <div class="text-muted small">
            <?= max(0, (int)($t['post_count'] ?? 0) - 1) ?> replies
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($totalPages > 1): ?>
    <nav class="mt-4">
      <ul class="pagination">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
          <a class="page-link" href="/my-topics.php?page=<?= max(1, $page-1) ?>">Prev</a>
        </li>

        <li class="page-item disabled">
          <span class="page-link">Page <?= $page ?> of <?= $totalPages ?></span>
        </li>

        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
          <a class="page-link" href="/my-topics.php?page=<?= min($totalPages, $page+1) ?>">Next</a>
        </li>
      </ul>
    </nav>
  <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>