<?php
// admin_knowledge_document_edit.php

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

auth_require($conn, ['admin']);

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

function csrf_verify(): void {
  $posted = $_POST['csrf_token'] ?? '';
  if (!is_string($posted) || !hash_equals($_SESSION['csrf_token'] ?? '', $posted)) {
    http_response_code(400);
    exit('Bad request (CSRF).');
  }
}

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
if ($id <= 0) {
  http_response_code(404);
  exit('Document not found.');
}

// Fetch categories
$kbCategories = [];
$res = $conn->query("SELECT id, name FROM knowledge_categories WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
if ($res === false) die("Category query failed: " . $conn->error);
while ($row = $res->fetch_assoc()) $kbCategories[] = $row;
$res->free();

// Handle POST update BEFORE output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $category_id  = (int)($_POST['category_id'] ?? 0);
  $title        = trim($_POST['title'] ?? '');
  $slug         = trim($_POST['slug'] ?? '');
  $content      = $_POST['content'] ?? '';
  $is_published = isset($_POST['is_published']) ? 1 : 0;

    if ($title === '' || $category_id <= 0 || trim(strip_tags($content)) === '') {
        $error = "Category, title and content are required.";
    } else {
        $slug = trim($_POST['slug'] ?? '');
    
        if ($slug === '') {
            $error = "Slug is required.";
        }
    }

    $stmt = $conn->prepare("
      UPDATE knowledge_documents
      SET category_id = ?, title = ?, slug = ?, content = ?, is_published = ?
      WHERE id = ?
      LIMIT 1
    ");
    if (!$stmt) {
      $error = "Prepare failed: " . $conn->error;
    } else {
      $stmt->bind_param("isssii", $category_id, $title, $slug, $content, $is_published, $id);
      if ($stmt->execute()) {
        $stmt->close();
        header("Location: admin_knowledge_documents.php");
        exit;
      }
      $error = "Save failed: " . $stmt->error;
      $stmt->close();
    }
  }

// Fetch current document (for form)
$kbDoc = null;
$stmt = $conn->prepare("
  SELECT id, category_id, title, slug, content, is_published
  FROM knowledge_documents
  WHERE id = ?
  LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
$kbDoc = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$kbDoc) {
  http_response_code(404);
  exit('Document not found.');
}

$pageTitle = "Edit Knowledge Document";

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Edit Document</h1>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="admin_knowledge_documents.php">Back</a>
      <a class="btn btn-sm btn-outline-secondary" target="_blank"
         href="knowledge_document.php?doc=<?= h($kbDoc['slug']) ?>">View</a>
    </div>
  </div>

  <?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="id" value="<?= (int)$kbDoc['id'] ?>">

    <div class="mb-3">
      <label class="form-label">Category</label>
      <select name="category_id" class="form-select" required>
        <option value="">Choose…</option>
        <?php foreach ($kbCategories as $cat): ?>
          <option value="<?= (int)$cat['id'] ?>" <?= ((int)$kbDoc['category_id'] === (int)$cat['id']) ? 'selected' : '' ?>>
            <?= h($cat['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="mb-3">
      <label class="form-label">Title</label>
      <input type="text" name="title" class="form-control" required value="<?= h($kbDoc['title']) ?>">
    </div>

    <div class="mb-3">
      <label class="form-label">Slug</label>
      <input type="text" name="slug" class="form-control" value="<?= h($kbDoc['slug']) ?>">
      <div class="form-text">Lowercase + hyphens. Leave as-is unless you want to change the URL.</div>
    </div>

    <div class="mb-3">
      <label class="form-label">Content</label>
      <textarea name="content" id="editor" rows="10" data-ckeditor="1" class="form-control"><?= h($kbDoc['content']) ?></textarea>
    </div>

    <div class="form-check mb-3">
      <input class="form-check-input" type="checkbox" name="is_published" id="is_published"
        <?= ((int)$kbDoc['is_published'] === 1) ? 'checked' : '' ?>>
      <label class="form-check-label" for="is_published">Published</label>
    </div>

    <button class="btn btn-primary">Save changes</button>
  </form>
</div>

<!-- CKEditor: update path to your actual installed file -->
<script src="/path/to/ckeditor.js"></script>
<script>
  ClassicEditor.create(document.querySelector('#editor'))
    .catch(error => console.error(error));
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>