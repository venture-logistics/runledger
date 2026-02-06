<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

require_once __DIR__ . '/includes/header.php';

$slug = trim($_GET['t'] ?? '');
if ($slug === '') {
    http_response_code(404);
    exit('Topic not found');
}

$stmt = $conn->prepare('SELECT title FROM forum_topics WHERE slug = ? LIMIT 1');
$stmt->bind_param('s', $slug);
$stmt->execute();
$result = $stmt->get_result();
$topic = $result->fetch_assoc();

if (!$topic) {
    http_response_code(404);
    exit('Topic not found');
}

$pageTitle = $topic['title'];


if (isset($_SESSION['smtp_debug'])) {
    $d = $_SESSION['smtp_debug'];
    echo "<!-- SMTP DEBUG: sent={$d['sent']} to={$d['to']} owner={$d['topicOwnerId']} author={$d['authorId']} -->";
    unset($_SESSION['smtp_debug']);
}

/**
 * Render CKEditor HTML safely (allowlist).
 * Supports CKEditor 5 images: <figure class="image"><img ...></figure>
 */
function forum_render_html(string $html): string
{
    // 1) Allow tags (add figure/img/figcaption)
    $allowed = '<p><br><strong><b><em><i><u><ul><ol><li><blockquote><a><h2><h3><hr><figure><img><figcaption><pre><code><s>';
    $clean = strip_tags($html, $allowed);

    // 2) Clean <img> attributes (only allow safe attrs + safe src)
    $clean = preg_replace_callback(
        '/<img\b([^>]*)>/i',
        function ($m) {
            $attrs = $m[1];

            // Extract src
            if (!preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i', $attrs, $sm)) {
                return ''; // no src => drop
            }
            $src = trim($sm[2]);

            // Allow only site uploads (adjust path if yours differs) OR https
            $isAllowed =
                (strpos($src, '/uploads/') === 0) ||
                (preg_match('#^https://#i', $src) === 1);

            if (!$isAllowed)
                return '';

            // alt (optional)
            $alt = '';
            if (preg_match('/\balt\s*=\s*(["\'])(.*?)\1/i', $attrs, $am)) {
                $alt = htmlspecialchars($am[2], ENT_QUOTES, 'UTF-8');
            }

            return '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '" loading="lazy">';
        },
        $clean
    );

    // 3) Clean <figure> (allow only class="image" / "image image-style-...")
    $clean = preg_replace_callback(
        '/<figure\b([^>]*)>/i',
        function ($m) {
            $attrs = $m[1];

            $class = 'image';
            if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $attrs, $cm)) {
                // keep only ckeditor image classes
                $raw = $cm[2];
                $tokens = preg_split('/\s+/', trim($raw));
                $keep = [];
                foreach ($tokens as $t) {
                    if ($t === 'image' || strpos($t, 'image-style-') === 0)
                        $keep[] = $t;
                }
                if (!empty($keep))
                    $class = implode(' ', $keep);
            }

            return '<figure class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">';
        },
        $clean
    );

    // 4) Make links safer (your existing logic, unchanged)
    $clean = preg_replace_callback(
        '/<a\s+([^>]+)>/i',
        function ($m) {
            $attrs = $m[1];

            if (!preg_match('/\btarget\s*=\s*["\']?_blank["\']?/i', $attrs)) {
                $attrs .= ' target="_blank"';
            }

            if (preg_match('/\brel\s*=\s*["\']([^"\']*)["\']/i', $attrs, $rm)) {
                $rel = $rm[1];
                if (stripos($rel, 'noopener') === false)
                    $rel .= ' noopener';
                if (stripos($rel, 'noreferrer') === false)
                    $rel .= ' noreferrer';
                if (stripos($rel, 'nofollow') === false)
                    $rel .= ' nofollow';
                $attrs = preg_replace('/\brel\s*=\s*["\'][^"\']*["\']/i', 'rel="' . trim($rel) . '"', $attrs);
            } else {
                $attrs .= ' rel="nofollow noopener noreferrer"';
            }

            return '<a ' . $attrs . '>';
        },
        $clean
    );

    // 5) Optional: remove truly empty paragraphs left behind
    $clean = preg_replace('#<p>\s*</p>#i', '', $clean);

    return $clean;
}

// CSRF token (used by Helpful + admin actions)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

// Load topic (now includes is_pinned)
$stmt = $conn->prepare("
  SELECT t.id, t.title, t.slug, t.is_locked, t.is_pinned,
         c.name AS category_name, c.slug AS category_slug
  FROM forum_topics t
  JOIN forum_categories c ON c.id = t.category_id
  WHERE t.slug = ?
  LIMIT 1
");
if (!$stmt) {
    http_response_code(500);
    exit('DB error (topic): ' . $conn->error);
}

$stmt->bind_param("s", $slug);
$stmt->execute();
$topic = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$topic) {
    http_response_code(404);
    exit('Topic not found');
}

$pageTitle = $topic['title'];

$userPostCount = 0;
if (!empty($currentUser)) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM forum_posts WHERE created_by_name = ?");
    if ($stmt) {
        $stmt->bind_param("s", $currentUser['name']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $userPostCount = (int) ($row['c'] ?? 0);
        $stmt->close();
    }
}

// Load posts
$posts = $conn->prepare("
  SELECT p.id, p.body, p.helpful_count, p.created_by_user_id, p.created_by_name, p.created_at,
         u.forum_designation, u.badge_class
  FROM forum_posts p
  LEFT JOIN users u ON u.id = p.created_by_user_id
  WHERE p.topic_id = ?
  ORDER BY p.created_at ASC
  LIMIT 200
");
if (!$posts) {
    http_response_code(500);
    exit('DB error (posts): ' . $conn->error);
}

$posts->bind_param("i", $topic['id']);
$posts->execute();
$res = $posts->get_result();


$backgroundPerspective = null;

if (!empty($currentUser['id'])) {
    $stmt = $conn->prepare(
        "SELECT background_perspective
         FROM users
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->bind_param("i", $currentUser['id']);
    $stmt->execute();
    $stmt->bind_result($backgroundPerspective);
    $stmt->fetch();
    $stmt->close();
}

?>

<style>
.author-context {
  background-color: #f8f9fa!important;            /* same as bg-light */
  border-left: 4px solid #adb5bd!important;        /* neutral accent */
}    
</style>

<?php include __DIR__ . '/includes/notice_banner.php'; ?>

<a class="text-decoration-none" href="/category.php?c=<?= h($topic['category_slug']) ?>">&larr; Back to <?= h($topic['category_name']) ?></a>

<div class="d-flex justify-content-between align-items-start mt-2 mb-2">
  <div>
    <h1 class="h4 mb-1"><?= h($topic['title']) ?></h1>

    <div class="small text-muted">
      <?php if ((int) $topic['is_pinned'] === 1): ?>
        <span class="badge text-bg-warning me-1">Pinned</span>
      <?php endif; ?>
      <?php if ((int) $topic['is_locked'] === 1): ?>
        <span class="badge text-bg-danger">Locked</span>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($currentUser) && ($currentUser['role'] ?? '') === 'admin'): ?>
    <div class="d-flex gap-2">
      <form method="post" action="/topic_pin_toggle.php" class="m-0">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="topic_id" value="<?= (int) $topic['id'] ?>">
        <button class="btn btn-sm btn-warning">
          <?= ((int) $topic['is_pinned'] === 1) ? 'Unpin' : 'Pin' ?>
        </button>
      </form>

      <form method="post" action="/topic_lock_toggle.php" class="m-0">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="topic_id" value="<?= (int) $topic['id'] ?>">
        <button class="btn btn-sm btn-danger">
          <?= ((int) $topic['is_locked'] === 1) ? 'Unlock' : 'Lock' ?>
        </button>
      </form>
    </div>
  <?php endif; ?>
</div>

<?php while ($p = $res->fetch_assoc()): ?>

  <?php
      // Per-post about box logic MUST be computed inside the loop (because it depends on $p)
      $showAbout = (
          !empty($currentUser)
          && !empty($p['created_by_name'])
          && !empty($currentUser['name'])
          && $p['created_by_name'] === $currentUser['name']
          && !empty($currentUser['forum_about'])
      );
      ?>

  <div id="post-<?= (int) $p['id'] ?>" class="card mb-3">

    <div class="card-header d-flex justify-content-between">
      <div class="small text-muted text-start d-inline-flex align-items-center text-nowrap">
        Posted by <strong class="ms-1"><?= h($p['created_by_name'] ?: 'Guest') ?></strong>

        <?php
        // Show badge for post author (from JOIN with users table)
        $badge = $p['forum_designation'] ?? '';

        // Map designation to badge color
        $designationColors = [
            'Admin' => 'text-bg-dark',
            'Expert' => 'text-bg-danger',
            'Moderator' => 'text-bg-danger',
            'Contributor' => 'text-bg-primary',
            'Trusted Member' => 'text-bg-success',
            'Member' => 'text-bg-secondary',
        ];
        $badgeClass = $designationColors[$badge] ?? 'text-bg-secondary';

        // Don't show badge for regular Members
        if ($badge === 'Member') {
            $badge = '';
        }
        ?>

        <?php if ($badge !== '' && $badgeClass): ?>
          <span class="badge <?= h($badgeClass) ?> ms-1" style="transform: translateY(-1px);">
            <?= h($badge) ?>
          </span>
        <?php endif; ?>
      </div>

<?php
        echo "<!-- DEBUG: designation=" . ($currentUser['forum_designation'] ?? 'NONE') . " -->";
        echo "<!-- DEBUG: badge_class=" . ($currentUser['badge_class'] ?? 'NONE') . " -->";
        ?>

      <div class="small text-muted"><?= h($p['created_at']) ?></div>
    </div>

    <div class="card-body">
      <div class="forum-post-body"><?= forum_render_html($p['body'] ?? '') ?></div>

      <?php
      $canEdit = false;
      if (!empty($currentUser)) {
          if (($currentUser['role'] ?? '') === 'admin')
              $canEdit = true;
          elseif (($currentUser['forum_designation'] ?? '') === 'Moderator')
              $canEdit = true;
          elseif (!empty($p['created_by_user_id']) && (int) $p['created_by_user_id'] === (int) $currentUser['id'])
              $canEdit = true;
      }
      ?>
      
      <hr class="mb-3 mt-5" />

<div class="d-flex gap-2 mt-3 align-items-center flex-wrap">

    <?php if ($canEdit): ?>
        <a class="btn btn-sm btn-outline-secondary"
           href="/edit_post.php?id=<?= (int) $p['id'] ?>">
            Edit
        </a>
    <?php endif; ?>

    <?php if (($currentUser['role'] ?? '') === 'admin' || ($currentUser['forum_designation'] ?? '') === 'Moderator'): ?>
        <form method="post"
              action="/delete_topic.php"
              onsubmit="return confirm('Delete this topic and ALL replies permanently?');"
              class="m-0">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="topic_id" value="<?= (int) $topic['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
        </form>
    <?php endif; ?>

    <?php if (!empty($currentUser)): ?>
        <form method="post"
              action="/helpful_toggle.php"
              class="m-0">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="post_id" value="<?= (int) $p['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-success">
                Mark as Helpful
            </button>
        </form>

        <!-- Cite button: NOT a form, and type=button so it never submits anything -->
        <button type="button"
                class="btn btn-sm btn-outline-secondary"
                onclick="toggleCite<?= (int) $p['id'] ?>()">
            Cite
        </button>
    <?php endif; ?>

    <span class="small text-muted ms-2">
        Helpful: <?= (int) ($p['helpful_count'] ?? 0) ?>
    </span>

</div>

<?php if (!empty($currentUser)): ?>
    <?php
            // Build a stable citation string (post-level, anchor included)
            $postId = (int) $p['id'];
            $citeUrl = 'https://support.pgat.co.uk/topic.php?t=' . ($topic['slug'] ?? '') . '#post-' . $postId;

            $author = $p['created_by_name'] ?? 'Unknown';
            $dateY = !empty($p['created_at']) ? date('Y', strtotime($p['created_at'])) : date('Y');
            $cat = $topic['category_name'] ?? 'Forum';
            $title = $topic['title'] ?? 'Topic';

            // Plain text (works everywhere)
            $citePlain = $author . " (" . $dateY . ") — " . $title . " (" . $cat . "), post #" . $postId . "\n" . $citeUrl;

            // HTML (ensures CKEditor pastes a clickable link)
            $citeHtml =
                '<div>' . h($author) . ' (' . h($dateY) . ') — ' . h($title) . ' (' . h($cat) . '), post #' . $postId . '.</div>' .
                '<div><a href="' . h($citeUrl) . '">' . h($citeUrl) . '</a></div>';
            ?>

    <div id="citeBox<?= $postId ?>"
         class="mt-2 p-3 border rounded small d-none"
         style="background:#f8f9fa; max-width: 900px;">

        <div class="fw-semibold mb-2">Citation</div>

        <!-- Display (readable, and clickable inside your page too) -->
        <div class="small p-2 border rounded bg-white">
            <div><?= h($author) ?> (<?= h($dateY) ?>) — <?= h($title) ?> (<?= h($cat) ?>), post #<?= $postId ?>.</div>
            <div>
                <a href="<?= h($citeUrl) ?>" target="_blank" rel="noopener"><?= h($citeUrl) ?></a>
            </div>
        </div>

        <!-- Hidden sources for clipboard -->
        <div id="citePlain<?= $postId ?>" class="d-none"><?= h($citePlain) ?></div>
        <div id="citeHtml<?= $postId ?>" class="d-none"><?= $citeHtml ?></div>

        <button type="button"
                class="btn btn-sm btn-outline-primary mt-2"
                onclick="copyCite<?= $postId ?>()">
            Copy citation
        </button>
    </div>

    <script>
      function toggleCite<?= $postId ?>() {
        document.getElementById('citeBox<?= $postId ?>').classList.toggle('d-none');
      }

      async function copyCite<?= $postId ?>() {
        const plain = document.getElementById('citePlain<?= $postId ?>').innerText;
        const html  = document.getElementById('citeHtml<?= $postId ?>').innerHTML;

        // Best path: write HTML + plain text so CKEditor pastes a clickable link
        if (navigator.clipboard && window.ClipboardItem) {
          const item = new ClipboardItem({
            'text/plain': new Blob([plain], { type: 'text/plain' }),
            'text/html':  new Blob([html],  { type: 'text/html'  })
          });
          await navigator.clipboard.write([item]);
          return;
        }

        // Fallback: plain text copy
        const ta = document.createElement('textarea');
        ta.value = plain;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
      }
    </script>
<?php endif; ?>


<?php if ($showAbout): ?>
  <hr class="my-3" />

  <div class="mt-2 p-3 border rounded bg-light small author-context">
    <div class="d-flex align-items-center justify-content-between">
      <div class="fw-semibold">Why I’m here</div>

      <div class="d-flex gap-2 align-items-center">
        <span class="text-muted small"><?= (int) $userPostCount ?> posts</span>

        <!-- ADDED: forum designation (Admin / Moderator / Member) -->
        <?php if (!empty($author['forum_designation'] ?? $currentUser['forum_designation'] ?? null)): ?>
          <span class="badge <?= $currentUser['badge_class'] ?? 'text-bg-secondary' ?>">
            <?= h($author['forum_designation'] ?? $currentUser['forum_designation']) ?>
          </span>
        <?php endif; ?>
        <!-- /ADDED -->
      </div>
    </div>

    <?php if (!empty($backgroundPerspective)): ?>
      <span class="badge bg-secondary-subtle text-dark border fw-normal mt-1">
        <?= h($backgroundPerspective) ?>
      </span>
    <?php endif; ?>

    <div class="text-muted mt-2" style="line-height:1.5;">
      <?= h($currentUser['forum_about']) ?>
    </div>
  </div>
<?php endif; ?>


    </div>

  </div>
<?php endwhile; ?>

<?php
// LEGACY BLOCK (kept to avoid "deleting stuff"):
if (false):
    ?>
<?php while ($p = $res->fetch_assoc()): ?>
  <div class="card mb-3">
    <div class="card-header d-flex justify-content-between">
      <div class="small text-muted">
        Posted by <strong><?= h($p['created_by_name'] ?: 'Guest') ?></strong>
      </div>
      <div class="small text-muted"><?= h($p['created_at']) ?></div>
    </div>

    <div class="card-body">
      <div style="white-space: pre-wrap;"><?= h($p['body']) ?></div>
    </div>

    <div class="card-footer">
      <a class="btn btn-sm btn-outline-secondary" href="/edit_post.php?id=<?= (int) $p['id'] ?>">Edit</a>
    </div>
  </div>
<?php endwhile; ?>
<?php endif; ?>

<?php $posts->close(); ?>

<?php if ((int) $topic['is_locked'] === 1): ?>
  <div class="alert alert-secondary">This topic is locked.</div>
<?php else: ?>
  <a class="btn btn-primary" href="/reply.php?t=<?= h($topic['slug']) ?>">Add reply</a>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>