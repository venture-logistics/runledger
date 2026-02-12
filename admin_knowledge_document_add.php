<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// admin_knowledge_document_add.php

if (session_status() !== PHP_SESSION_ACTIVE)
    session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

// Require authentication first
if (empty($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

// Check if user is admin OR expert
$stmt = $conn->prepare("SELECT role, forum_designation FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user || ($user['role'] !== 'admin' && $user['forum_designation'] !== 'Expert')) {
    http_response_code(403);
    exit('Access denied. Only admins and experts can add knowledge documents.');
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

function csrf_verify(): void
{
    $posted = $_POST['csrf_token'] ?? '';
    if (!is_string($posted) || !hash_equals($_SESSION['csrf_token'] ?? '', $posted)) {
        http_response_code(400);
        exit('Bad request (CSRF).');
    }
}

// Fetch categories
$kbCategories = [];
$res = $conn->query("SELECT id, name FROM knowledge_categories WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
if ($res === false)
    die("Category query failed: " . $conn->error);
while ($row = $res->fetch_assoc())
    $kbCategories[] = $row;
$res->free();

// Handle POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $category_id = (int) ($_POST['category_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $content = $_POST['content'] ?? '';
    $is_published = isset($_POST['is_published']) ? 1 : 0;

    // Auto-generate slug if empty
    if ($slug === '' && $title !== '') {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));
    }

    // Validate
    if ($title === '' || $category_id <= 0 || trim(strip_tags($content)) === '') {
        $error = "Category, title and content are required.";
    } elseif ($slug === '') {
        $error = "Slug could not be generated.";
    } else {
        // Check if slug already exists
        $stmt = $conn->prepare("SELECT id FROM knowledge_documents WHERE slug = ? LIMIT 1");
        $stmt->bind_param("s", $slug);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $error = "A document with this slug already exists. Please use a different slug.";
            $stmt->close();
        } else {
            $stmt->close();

            // Insert new document
            $stmt = $conn->prepare("
    INSERT INTO knowledge_documents 
    (category_id, title, slug, content, is_published, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
");
            if (!$stmt) {
                $error = "Prepare failed: " . $conn->error;
            } else {
                $stmt->bind_param("isssi", $category_id, $title, $slug, $content, $is_published);
                if ($stmt->execute()) {
                    $stmt->close();
                    header("Location: admin_knowledge_documents.php");
                    exit;
                }
                $error = "Save failed: " . $stmt->error;
                $stmt->close();
            }
        }
    }
}

$pageTitle = "Add Knowledge Document";

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Add New Document</h1>
    <a class="btn btn-sm btn-outline-secondary" href="admin_knowledge_documents.php">Back</a>
  </div>

  <?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

    <div class="mb-3">
      <label class="form-label">Category</label>
      <select name="category_id" class="form-select" required>
        <option value="">Choose…</option>
        <?php foreach ($kbCategories as $cat): ?>
          <option value="<?= (int) $cat['id'] ?>" <?= (isset($_POST['category_id']) && (int) $_POST['category_id'] === (int) $cat['id']) ? 'selected' : '' ?>>
            <?= h($cat['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="mb-3">
      <label class="form-label">Title</label>
      <input type="text" name="title" class="form-control" required value="<?= h($_POST['title'] ?? '') ?>">
    </div>

    <div class="mb-3">
      <label class="form-label">Slug (optional)</label>
      <input type="text" name="slug" class="form-control" value="<?= h($_POST['slug'] ?? '') ?>">
      <div class="form-text">Leave empty to auto-generate from title. Use lowercase + hyphens.</div>
    </div>

    <div class="mb-3">
      <label class="form-label">Content</label>
      <textarea name="content" id="editor" rows="10" data-ckeditor="1" class="form-control"><?= h($_POST['content'] ?? '') ?></textarea>
    </div>

    <div class="form-check mb-3">
      <input class="form-check-input" type="checkbox" name="is_published" id="is_published"
        <?= (isset($_POST['is_published'])) ? 'checked' : '' ?>>
      <label class="form-check-label" for="is_published">Published</label>
    </div>

    <button class="btn btn-primary">Create Document</button>
  </form>
</div>

<!-- CKEditor: update path to your actual installed file -->
<script src="/path/to/ckeditor.js"></script>
<script>
  ClassicEditor.create(document.querySelector('#editor'))
    .catch(error => console.error(error));
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>