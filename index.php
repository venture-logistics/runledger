<?php

$pageTitle = "Planning Support Forum";

require_once __DIR__ . '/includes/header.php';

// Categories for sidebar
// Categories for sidebar
$cats = $conn->query("
  SELECT id, name, slug, description, accent_color
  FROM forum_categories
  ORDER BY sort_order ASC, name ASC
");

// Latest topics for main feed (with last post snippet + post count)
$stmt = $conn->prepare("
  SELECT
    t.title,
    t.slug,
    t.updated_at,
    t.is_locked,
    c.name AS category_name,
    c.slug AS category_slug,
    c.accent_color AS category_accent,
    (
      SELECT COUNT(*)
      FROM forum_posts p
      WHERE p.topic_id = t.id AND p.is_deleted = 0
    ) AS post_count,
    (
      SELECT LEFT(p2.body, 220)
      FROM forum_posts p2
      WHERE p2.topic_id = t.id AND p2.is_deleted = 0
      ORDER BY p2.created_at DESC
      LIMIT 1
    ) AS last_snippet,
    (
      SELECT p3.created_by_name
      FROM forum_posts p3
      WHERE p3.topic_id = t.id AND p3.is_deleted = 0
      ORDER BY p3.created_at DESC
      LIMIT 1
    ) AS last_author
  FROM forum_topics t
  JOIN forum_categories c ON c.id = t.category_id
  WHERE t.is_deleted = 0
  ORDER BY t.updated_at DESC
  LIMIT 25
");
$stmt->execute();
$topics = $stmt->get_result();

$res = $conn->query("
  SELECT id, name, slug, description, accent_color
  FROM forum_categories
  ORDER BY sort_order ASC, id ASC
");

function excerpt_from_html(string $html, int $max = 180): string
{
    // 1) Strip tags (remove <p>, <ul>, etc.)
    $text = strip_tags($html);

    // 2) Decode entities (&nbsp; etc.)
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // 3) Replace non-breaking spaces with normal spaces
    $text = str_replace("\xC2\xA0", ' ', $text);

    // 4) Collapse whitespace
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);

    // 5) Limit length
    if (mb_strlen($text) > $max) {
        $text = mb_substr($text, 0, $max - 1) . '…';
    }

    return $text;
}

// Load welcome card settings
$welcome = [
  'title' => 'Welcome',
  'message' => "If you’re trying to make sense of a planning decision, this community looks at how decisions are made — we focus on process, evidence, and how decisions are reached in practice. Our aim is to help you understand the process properly, and to raise concerns in an informed, procedurally sound way.",
  'accent_color' => '#000000',
];

if (isset($conn) && ($conn instanceof mysqli)) {
  $stmt = $conn->prepare("SELECT title, message, accent_color FROM site_welcome WHERE id=1 LIMIT 1");
  if ($stmt && $stmt->execute()) {
    $stmt->bind_result($t, $m, $c);
    if ($stmt->fetch()) {
      $welcome['title'] = (string)$t;
      $welcome['message'] = (string)$m;
      $welcome['accent_color'] = (string)$c;
    }
    $stmt->close();
  }
}

// escape helper if you don't already have one
if (!function_exists('h')) {
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
?>

<style>
  /* Topic list: category accent stripe */
  .topic-row {
    border-left: 4px solid var(--cat-accent, #adb5bd);
    padding-left: 12px;
  }

  /* Make “Open” less visually dominant */
  .topic-open {
    opacity: .85;
  }

  /* Sidebar category accent */
  
.cat-row{
  border-left-width: 3px !important;
  border-left-style: solid !important;
  border-left-color: var(--cat-accent, #adb5bd) !important;
  padding-left: 12px;
}

/* Welcome card accent */
.welcome-accent {
  border-left: 4px solid #000000; /* Bootstrap primary blue */
  background-color: #f8f9fa; /* very light Bootstrap grey */
}

</style>


    <div class="row g-4">
        
    <section class="pt-4">
      <div class="card mb-3 shadow-sm welcome-accent"
           style="border-left:4px solid <?= h($welcome['accent_color']) ?>;">
        <div class="card-body">
          <div class="fw-semibold fs-3 mb-1"><?= h($welcome['title']) ?></div>
          <div class="text-muted"><?= $welcome['message'] ?></div>
        </div>
      </div>
    </section>

  <!-- MAIN: Latest topics -->
  <div class="col-12 col-lg-8">

    <div class="d-flex justify-content-between align-items-center mb-3">
      <h1 class="h4 mb-0">Latest discussions</h1>
      <a href="/index.php" class="btn btn-sm btn-outline-secondary">Refresh</a>
    </div>

    <?php if ($topics->num_rows === 0): ?>
      <div class="alert alert-light border">No topics yet.</div>
    <?php else: ?>
      <div class="list-group">
        <?php while ($t = $topics->fetch_assoc()): ?>
        
        <?php
            $accent = $t['category_accent'] ?? $t['accent_color'] ?? null; // depending on your SELECT alias
            if (!$accent) {
              $slug = $t['category_slug'] ?? '';
              $accent = $t['category_accent'] ?? '#adb5bd'; // fallback during transition
            }
        ?>
        <a class="list-group-item list-group-item-action topic-row"
           style="--cat-accent: <?= h($accent) ?>;"
           href="/topic.php?t=<?= h($t['slug']) ?>">

            <div class="d-flex justify-content-between align-items-start gap-3">
              <div class="flex-grow-1">

                <div class="d-flex flex-wrap align-items-center gap-2">
                  <div class="fw-semibold"><?= h($t['title']) ?></div>

                  <?php if ((int)$t['is_locked'] === 1): ?>
                    <span class="badge text-bg-secondary">Locked</span>
                  <?php endif; ?>

                  <span class="badge text-bg-light border">
                    <?= (int)$t['post_count'] ?> posts
                  </span>
                </div>

                <div class="small text-muted mt-1">
                  In
                  <span class="text-dark"><?= h($t['category_name']) ?></span>
                  · Updated <?= h($t['updated_at']) ?>
                  <?php if (!empty($t['last_author'])): ?>
                    · Last by <?= h($t['last_author']) ?>
                  <?php endif; ?>
                </div>

                <?php if (!empty($t['last_snippet'])): ?>
                
                
                  <div class="mt-2 text-muted">
                      
                    <?php
                    $snippet = strip_tags($t['last_snippet'] ?? '');
                    $snippet = html_entity_decode($snippet, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $snippet = str_replace("\xC2\xA0", ' ', $snippet);
                    $snippet = trim(preg_replace('/\s+/u', ' ', $snippet));
                    ?>
                    
                    <?= h(mb_substr($snippet, 0, 220)) ?><?= (mb_strlen($snippet) > 220 ? '…' : '') ?>

                  </div>
                <?php endif; ?>

              </div>

              <div class="text-end d-none d-md-block">
                <span class="btn btn-sm btn-outline-success topic-open">Read</span>
              </div>
            </div>
          </a>
        <?php endwhile; ?>
      </div>
    <?php endif; ?>

  </div>

  <!-- SIDEBAR: Categories -->
  <div class="col-12 col-lg-4 mt-5">

    <div class="d-flex justify-content-between align-items-center mb-2">
      <h2 class="h6 mb-0 text-uppercase text-muted">Categories</h2>
      <a href="/index.php" class="small text-decoration-none">All</a>
    </div>

    <div class="list-group">
      <?php while ($c = $cats->fetch_assoc()): ?>
      
        <?php $accent = $c['accent_color'] ?? '#adb5bd'; ?>
        <a class="list-group-item list-group-item-action cat-row"
           style="--cat-accent: <?= h($accent) ?>; border-left-color: <?= h($accent) ?> !important;"
           href="/category.php?c=<?= h($c['slug']) ?>">
            
          <div class="fw-semibold"><?= h($c['name']) ?></div>
          <div class="small text-muted"><?= h($c['description']) ?></div>
        </a>
        
      <?php endwhile; ?>
    </div>

    <div class="mt-3 p-3 border rounded bg-light">
      <div class="fw-semibold mb-1">Start a new topic</div>
      <div class="small text-muted mb-2">Click a category (above) first, then hit “New topic”.</div>
    </div>

  </div>
</div>

<?php

require_once __DIR__ . '/includes/footer.php';
