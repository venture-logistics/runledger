<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/includes/header.php'; // gives $conn + h()

$q = trim((string)($_GET['q'] ?? ''));
$forumResults = [];
$kbResults = [];

if ($q !== '' && strlen($q) >= 2) {
    // Forum Topics Search
    $forumSql = "
      SELECT
        t.id AS topic_id,
        t.title,
        t.slug AS topic_slug,
        c.name AS category_name,
        c.slug AS category_slug,
        t.updated_at,
        (
          MATCH(t.title) AGAINST (? IN NATURAL LANGUAGE MODE)
          +
          COALESCE((
            SELECT SUM(MATCH(p.body) AGAINST (? IN NATURAL LANGUAGE MODE))
            FROM forum_posts p
            WHERE p.topic_id = t.id AND p.is_deleted=0
          ), 0)
        ) AS relevance,
        COALESCE((
          SELECT SUM(p2.helpful_count)
          FROM forum_posts p2
          WHERE p2.topic_id = t.id AND p2.is_deleted=0
        ), 0) AS helpful_sum
      FROM forum_topics t
      JOIN forum_categories c ON c.id = t.category_id
      WHERE t.is_deleted=0
        AND (
          MATCH(t.title) AGAINST (? IN NATURAL LANGUAGE MODE)
          OR EXISTS (
            SELECT 1 FROM forum_posts p3
            WHERE p3.topic_id=t.id AND p3.is_deleted=0
              AND MATCH(p3.body) AGAINST (? IN NATURAL LANGUAGE MODE)
          )
        )
      ORDER BY relevance DESC, helpful_sum DESC, t.updated_at DESC
      LIMIT 25
    ";

    $stmt = $conn->prepare($forumSql);
    $stmt->bind_param("ssss", $q, $q, $q, $q);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $forumResults[] = $row;

    // Knowledge Documents Search (using FULLTEXT)
    $kbSql = "
      SELECT
        d.id,
        d.title,
        d.slug,
        d.content,
        d.created_at,
        c.name AS category_name,
        c.accent_color,
        MATCH(d.title, d.content) AGAINST (? IN NATURAL LANGUAGE MODE) AS relevance
      FROM knowledge_documents d
      JOIN knowledge_categories c ON c.id = d.category_id
      WHERE d.is_published = 1
        AND c.is_active = 1
        AND MATCH(d.title, d.content) AGAINST (? IN NATURAL LANGUAGE MODE)
      ORDER BY relevance DESC, d.created_at DESC
      LIMIT 25
    ";

    $stmt2 = $conn->prepare($kbSql);
    $stmt2->bind_param("ss", $q, $q);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    while ($row = $res2->fetch_assoc()) $kbResults[] = $row;
}

// Helper to truncate content
function truncate_words($text, $limit = 30) {
  $words = explode(' ', strip_tags($text));
  if (count($words) > $limit) {
    return implode(' ', array_slice($words, 0, $limit)) . '...';
  }
  return implode(' ', $words);
}
?>

<div class="container py-4">
  <h1 class="h3 mb-3">Search</h1>

  <form class="d-flex gap-2 mb-4" method="get" action="search.php">
    <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search topics and knowledge base">
    <button class="btn btn-primary">Search</button>
  </form>

  <?php if ($q !== '' && strlen($q) < 2): ?>
    <div class="alert alert-warning">Enter at least 2 characters.</div>
  <?php endif; ?>

  <?php if ($q !== '' && !$forumResults && !$kbResults && strlen($q) >= 2): ?>
    <div class="alert alert-secondary">No results found.</div>
  <?php endif; ?>

  <?php if ($q !== '' && strlen($q) >= 2): ?>
    
    <!-- Knowledge Base Results -->
    <?php if ($kbResults): ?>
      <div class="mb-4">
        <h2 class="h5 mb-3">
          <i class="bi bi-book"></i> Knowledge Base 
          <span class="badge bg-secondary"><?= count($kbResults) ?></span>
        </h2>
        <div class="list-group">
          <?php foreach ($kbResults as $r): 
            $color = $r['accent_color'] ?? '#6c757d';
          ?>
            <a class="list-group-item list-group-item-action" 
               href="/knowledge_document.php?doc=<?= h($r['slug']) ?>"
               style="border-left: 4px solid <?= h($color) ?>;">
              <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <div>
                  <div class="fw-semibold"><?= h($r['title']) ?></div>
                  <span class="badge" style="background-color: <?= h($color) ?>; font-size: 0.7rem;">
                    <?= h($r['category_name']) ?>
                  </span>
                </div>
                <span class="btn btn-sm btn-outline-primary">Read</span>
              </div>
              <div class="text-muted small">
                <?= h(truncate_words($r['content'], 30)) ?>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Forum Results -->
    <?php if ($forumResults): ?>
      <div class="mb-4">
        <h2 class="h5 mb-3">
          <i class="bi bi-chat-dots"></i> Forum Topics 
          <span class="badge bg-secondary"><?= count($forumResults) ?></span>
        </h2>
        <div class="list-group">
          <?php foreach ($forumResults as $r): ?>
            <a class="list-group-item list-group-item-action"
               href="/topic.php?t=<?= h($r['topic_slug']) ?>"
               style="border-left: 4px solid #0d6efd;">
              <div class="d-flex justify-content-between align-items-start gap-2">
                <div class="fw-semibold"><?= h($r['title']) ?></div>
                <span class="btn btn-sm btn-outline-primary">Read</span>
              </div>
              <div class="small text-muted">
                In <?= h($r['category_name']) ?> · Updated <?= h($r['updated_at']) ?> · Helpful <?= (int)$r['helpful_sum'] ?>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>