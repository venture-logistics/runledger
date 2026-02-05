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

$postId = (int)($_GET['id'] ?? 0);
if ($postId <= 0) {
    http_response_code(404);
    exit('Post not found');
}

// Load post + topic slug for redirect
$stmt = $conn->prepare("
    SELECT
      p.id, p.topic_id, p.body, p.created_by_name, p.created_at, p.is_deleted,
      t.slug AS topic_slug, t.title AS topic_title
    FROM forum_posts p
    JOIN forum_topics t ON t.id = p.topic_id
    WHERE p.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $postId);
$stmt->execute();
$res = $stmt->get_result();
$post = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$post || (int)$post['is_deleted'] === 1) {
    http_response_code(404);
    exit('Post not found');
}

$stmt = $conn->prepare("
    SELECT
      p.*,
      u.id AS created_by_id,
      u.name AS created_by_name
    FROM forum_posts p
    JOIN users u ON u.id = p.created_by_name
    WHERE p.id = ?    
");

$canEdit = false;

if (!empty($currentUser)) {
    if (($currentUser['role'] ?? '') === 'admin') {
        $canEdit = true;
    } else {
        $canEdit = (
            !empty($post['created_by_name']) &&
            !empty($currentUser['name']) &&
            trim($post['created_by_name']) === trim($currentUser['name'])
        );
    }
}

if (!$canEdit) {
    http_response_code(403);
    exit('Forbidden');
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$errors = [];
$body = (string)($post['body'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrf, $postedToken)) {
        $errors[] = "Security check failed. Please refresh and try again.";
    }

    $body = (string)($_POST['body'] ?? '');
    $plain = trim(strip_tags($body));

    if ($plain === '') {
        $errors[] = "Post body cannot be empty.";
    } elseif (mb_strlen($plain) < 5) {
        $errors[] = "Post body is too short.";
    }

    if (!$errors) {
        $stmt = $conn->prepare("UPDATE forum_posts SET body = ? WHERE id = ? LIMIT 1");
        $stmt->bind_param("si", $body, $postId);

        if ($stmt->execute()) {
            $stmt->close();
            header("Location: /topic.php?t=" . urlencode($post['topic_slug']) . "#post-" . $postId);
            exit;
        }

        $stmt->close();
        $errors[] = "Could not save changes. Please try again.";
    }
}

// Render page
$pageTitle = "Edit post";
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-1">Edit post</h1>
        <div class="text-muted small">
            Topic: <strong><?= h($post['topic_title']) ?></strong>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="/topic.php?t=<?= h($post['topic_slug']) ?>">Cancel</a>
        <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
            <span class="badge text-bg-warning align-self-center">Admin</span>
        <?php endif; ?>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
                <li><?= h($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" class="card" novalidate>
    <div class="card-body">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

        <label class="form-label">Post content</label>
        <textarea name="body" class="form-control" rows="10" data-ckeditor="1"><?= h($body) ?></textarea>

        <div class="d-flex justify-content-between align-items-center mt-3">
            <div class="small text-muted">
                Editing as <strong><?= h($currentUser['name'] ?? 'User') ?></strong>
            </div>
            <button class="btn btn-primary">Save changes</button>
        </div>
    </div>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
