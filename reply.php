<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

// require login (any logged-in user)
auth_require($conn);

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

require_once __DIR__ . '/includes/header.php';

$slug = trim($_GET['t'] ?? ($_POST['t'] ?? ''));
if ($slug === '') { http_response_code(404); exit('Topic not found'); }

// Load topic
$stmt = $conn->prepare("
  SELECT id, title, slug, is_locked
  FROM forum_topics
  WHERE slug = ?
  LIMIT 1
");
if (!$stmt) { http_response_code(500); exit("DB error: " . $conn->error); }

$stmt->bind_param("s", $slug);
$stmt->execute();
$topic = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$topic) { http_response_code(404); exit('Topic not found'); }
if ((int)$topic['is_locked'] === 1) { http_response_code(403); exit('Topic locked'); }

$pageTitle = "Reply";
$errors = [];
$body = (string)($_POST['body'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $postedToken = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals($csrf, $postedToken)) {
    $errors[] = "Security check failed. Please refresh and try again.";
  }

  $plain = trim(strip_tags($body));
  if (mb_strlen($plain) < 2) {
    $errors[] = "Reply too short.";
  }

  if (!$errors) {
    $authorName = (string)($currentUser['name'] ?? 'Member');
    $authorId   = (int)($currentUser['id'] ?? 0);

    $conn->begin_transaction();

    try {
      // Insert reply
      $ins = $conn->prepare("
        INSERT INTO forum_posts (topic_id, body, created_by_name, created_by_user_id, created_at)
        VALUES (?, ?, ?, ?, NOW())
      ");
      if (!$ins) throw new Exception("Insert prepare failed: " . $conn->error);

      $ins->bind_param("issi", $topic['id'], $body, $authorName, $authorId);
      $ins->execute();
      $newPostId = (int)$ins->insert_id;
      $ins->close();

      // Optional: bump updated_at if column exists
      $up = $conn->prepare("UPDATE forum_topics SET updated_at = NOW() WHERE id = ? LIMIT 1");
      if ($up) {
        $up->bind_param("i", $topic['id']);
        $up->execute();
        $up->close();
      }

      $conn->commit();

      // ---------- NOTIFY TOPIC AUTHOR (if not self) ----------
      // forum_topics does not store the owner id, so derive it from the first post in the topic.
      $topicOwnerId = 0;
      $own = $conn->prepare("
        SELECT created_by_user_id
        FROM forum_posts
        WHERE topic_id = ?
        ORDER BY id ASC
        LIMIT 1
      " );
      if (!$own) {
        // Never break reply posting just because notifications can't run.
        error_log('Reply notify: owner lookup prepare failed: ' . $conn->error);
      } else {
        $own->bind_param("i", $topic['id']);

        if (!$own->execute()) {
          error_log('Reply notify: owner lookup execute failed: ' . $own->error);
        } else {
          // Prefer get_result if available; fallback to bind_result (mysqlnd may be absent).
          $row = null;
          if (method_exists($own, 'get_result')) {
            $res = $own->get_result();
            if ($res) {
              $row = $res->fetch_assoc();
            }
          }

          if (!$row) {
            $ownerIdTmp = null;
            $own->bind_result($ownerIdTmp);
            if ($own->fetch()) {
              $row = ['created_by_user_id' => $ownerIdTmp];
            }
          }

          $topicOwnerId = (int)($row['created_by_user_id'] ?? 0);
        }

        $own->close();
      }

      if ($topicOwnerId > 0 && $topicOwnerId !== $authorId) {

        $stmt = $conn->prepare("
          SELECT email, name
          FROM users
          WHERE id = ?
          LIMIT 1
        " );
        if (!$stmt) {
          error_log('Reply notify: owner user lookup prepare failed: ' . $conn->error);
        } else {
          $stmt->bind_param("i", $topicOwnerId);

          $owner = null;
          if (!$stmt->execute()) {
            error_log('Reply notify: owner user lookup execute failed: ' . $stmt->error);
          } else {
            if (method_exists($stmt, 'get_result')) {
              $res = $stmt->get_result();
              if ($res) {
                $owner = $res->fetch_assoc();
              }
            }

            if (!$owner) {
              $email = $name = null;
              $stmt->bind_result($email, $name);
              if ($stmt->fetch()) {
                $owner = [
                  'email' => $email,
                  'name'  => $name,
                ];
              }
            }
          }

          $stmt->close();

          // Always notify for now (no preferences).
          if ($owner && !empty($owner['email'])) {

            $to = $owner['email'];

            $subject = "New reply to your topic: " . $topic['title'];

            $link = "https://support.pgat.co.uk/topic.php?t=" . urlencode($topic['slug']) . "#post-" . $newPostId;

            $message =
              "Someone has replied to your topic.

" .
              "Topic: " . $topic['title'] . "
" .
              "Reply by: " . $authorName . "

" .
              "View reply:
" . $link . "

";

            // SMTP via PHPMailer (fail-safe: never breaks reply posting)
            require_once __DIR__ . '/includes/mailer.php';

            $sent = send_smtp_mail([
              'to_email'  => $to,
              'to_name'   => $owner['name'] ?? '',
              'subject'   => $subject,
              'body_text' => $message,
            ]);

            if (!$sent) {
              error_log("REPLY_NOTIFY: SMTP failed to={$to} topicOwnerId={$topicOwnerId} authorId={$authorId} topic={$topic['id']} post={$newPostId}");
            }
          }
        }
      }
      // ---------- END NOTIFY ----------

      header("Location: /topic.php?t=" . urlencode($topic['slug']) . "#post-" . $newPostId);
      exit;

    } catch (Throwable $e) {
      $conn->rollback();
      $errors[] = "DB error posting reply.";
    }
  }
}
?>

<a class="text-decoration-none" href="/topic.php?t=<?= h($topic['slug']) ?>">&larr; Back</a>
<h1 class="h4 mt-2 mb-3">Reply</h1>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach($errors as $er): ?><li><?= h($er) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" class="card" novalidate>
  <div class="card-body">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="t" value="<?= h($topic['slug']) ?>">

    <div class="mb-3">
      <label class="form-label">Reply</label>
      <textarea class="form-control" name="body" rows="8" data-ckeditor="1"><?= h($body) ?></textarea>
      <div class="form-text">
        Posting as <strong><?= h($currentUser['name'] ?? 'Member') ?></strong>
      </div>
    </div>

    <button class="btn btn-primary">Post reply</button>
  </div>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>