<?php

declare(strict_types = 1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';

$catSlug = trim($_GET['c'] ?? '');
if ($catSlug === '') { http_response_code(404); exit('Category not found'); }

$stmt = $conn->prepare("SELECT id,name,description,slug FROM forum_categories WHERE slug=? LIMIT 1");
$stmt->bind_param("s", $catSlug);
$stmt->execute();
$cat = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$cat) { http_response_code(404); exit('Category not found'); }

$pageTitle = $cat['name'];

require_once __DIR__ . '/includes/header.php';

$list = $conn->prepare("
  SELECT id,title,slug,updated_at,is_locked
  FROM forum_topics
  WHERE category_id=? AND is_deleted=0
  ORDER BY updated_at DESC
  LIMIT 50
");
$list->bind_param("i", $cat['id']);
$list->execute();
$res = $list->get_result();

?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h1 class="h3 mb-1"><?= h($cat['name']) ?></h1>
    <div class="text-muted"><?= h($cat['description']) ?></div>
  </div>
  <a class="btn btn-primary" href="/new_topic.php?c=<?= h($cat['slug']) ?>">New topic</a>
</div>

<div class="list-group">
<?php while($t = $res->fetch_assoc()): ?>
  <a class="list-group-item list-group-item-action" href="/topic.php?t=<?= h($t['slug']) ?>">
    <div class="fw-semibold">
      <?= h($t['title']) ?>
      <?php if ((int)$t['is_locked'] === 1): ?><span class="badge text-bg-secondary ms-2">Locked</span><?php endif; ?>
    </div>
    <div class="small text-muted">Updated: <?= h($t['updated_at']) ?></div>
  </a>
<?php endwhile; ?>
</div>

<?php
$list->close();
require_once __DIR__ . '/includes/footer.php';
