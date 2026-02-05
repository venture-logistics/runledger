<?php
declare(strict_types = 1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

// require login (any logged-in user)
$currentUser = auth_require($conn);

$pageTitle = "New topic";

$errors = [];

// Load categories
$cats = [];
$res = $conn->query("SELECT id, name FROM forum_categories ORDER BY name ASC");
while ($r = $res->fetch_assoc()) {
    $cats[] = $r;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $title       = trim($_POST['title'] ?? '');
    $body        = trim($_POST['body'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);

    if ($title === '' || $body === '' || $category_id <= 0) {
        $errors[] = 'All fields are required.';
    }

    if (!$errors) {

        // Create slug
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));

        // Ensure unique slug
        $baseSlug = $slug;
        $i = 1;
        while (true) {
            $stmt = $conn->prepare("SELECT id FROM forum_topics WHERE slug = ? LIMIT 1");
            $stmt->bind_param("s", $slug);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                $stmt->close();
                break;
            }
            $stmt->close();
            $slug = $baseSlug . '-' . (++$i);
        }

        // Insert topic
        $stmt = $conn->prepare("
            INSERT INTO forum_topics
              (category_id, title, slug, created_by_name, created_by_user_id, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");

        if (!$stmt) {
            $errors[] = 'Database error.';
        } else {
            $stmt->bind_param(
                "isssi",
                $category_id,
                $title,
                $slug,
                $currentUser['name'],
                $currentUser['id']
            );

            if ($stmt->execute()) {
                $topicId = (int)$stmt->insert_id;
                $stmt->close();

                // Insert first post
                $stmt = $conn->prepare("
                    INSERT INTO forum_posts
                      (topic_id, body, created_by_name, created_by_user_id, created_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");

                if ($stmt) {
                    $stmt->bind_param(
                        "issi",
                        $topicId,
                        $body,
                        $currentUser['name'],
                        $currentUser['id']
                    );
                    $stmt->execute();
                    $stmt->close();
                }

                // ---------- ADMIN NOTIFY: NEW TOPIC ----------
                $adminEmail = 'info@runledger.org'; // <<< CHANGE THIS
                $siteName   = 'Ledger Open Source';
                $fromEmail  = 'info@runledger.org';

                // Get category name for email
                $catName = '';
                foreach ($cats as $c) {
                    if ((int)$c['id'] === $category_id) {
                        $catName = $c['name'];
                        break;
                    }
                }

                $subject = "New topic posted: {$title}";
                $message =
                    "A new topic has been posted on the forum.\n\n" .
                    "Author: {$currentUser['name']}\n" .
                    "Category: {$catName}\n" .
                    "Title: {$title}\n" .
                    "Time: " . date('Y-m-d H:i:s') . "\n\n" .
                    "View topic:\n" .
                    "https://support.pgat.co.uk/topic.php?t={$slug}\n";

                $headers =
                    "From: {$siteName} <{$fromEmail}>\r\n" .
                    "Reply-To: {$fromEmail}\r\n" .
                    "Content-Type: text/plain; charset=UTF-8\r\n";

                @mail($adminEmail, $subject, $message, $headers);
                // ---------- END NOTIFY ----------

                header("Location: /topic.php?t={$slug}");
                exit;

            } else {
                $errors[] = 'Could not create topic.';
                $stmt->close();
            }
        }
    }
}

// Load categories for <select>
$forumCats = [];

$res = $conn->query("
  SELECT id, name
  FROM forum_categories
  ORDER BY sort_order ASC, name ASC
");

while ($row = $res->fetch_assoc()) {
  $forumCats[] = $row;
}

$selectedCatId = (int)($_POST['category_id'] ?? 0);

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="h4 mb-3">Start a new topic</h1>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $e): ?>
        <li><?= h($e) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post">
  <div class="mb-3">
    <label class="form-label">Title</label>
    <input type="text" name="title" class="form-control" required value="<?= h($_POST['title'] ?? '') ?>">
  </div>

  <div class="mb-3">
    <label class="form-label">Category</label>
        <select name="category_id" class="form-select" required>
          <option value="">Select a category</option>
          <?php foreach ($forumCats as $c): ?>
            <?php $id = (int)$c['id']; ?>
            <option value="<?= $id ?>" <?= ((int)($selectedCatId ?? 0) === $id) ? 'selected' : '' ?>>
              <?= h($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
  </div>

  <div class="mb-3">
    <label class="form-label">Message</label>
    <textarea class="form-control" name="body" rows="8" data-ckeditor="1"><?= h($_POST['body'] ?? '') ?></textarea>
  </div>

  <button class="btn btn-primary">Post topic</button>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>