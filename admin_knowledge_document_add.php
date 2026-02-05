<?php
$pageTitle = "Add Knowledge Document";

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

auth_require($conn, ['admin']);

// Fetch categories (unique var name)
$kbCategories = [];
$res = $conn->query("
  SELECT id, name
  FROM knowledge_categories
  WHERE is_active = 1
  ORDER BY sort_order ASC, name ASC
");
if ($res === false) {
  die("Category query failed: " . $conn->error);
}
while ($row = $res->fetch_assoc()) {
  $kbCategories[] = $row;
}
$res->free();

// Handle submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $category_id = (int)($_POST['category_id'] ?? 0);
  $title       = trim($_POST['title'] ?? '');
  $slug        = trim($_POST['slug'] ?? '');
  $content     = $_POST['content'] ?? '';
  $published   = isset($_POST['is_published']) ? 1 : 0;

  if ($slug === '') {
    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $title));
    $slug = trim($slug, '-');
  }

  if ($category_id && $title && $content) {
    $stmt = $conn->prepare("
      INSERT INTO knowledge_documents
      (category_id, title, slug, content, is_published)
      VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("isssi",
      $category_id,
      $title,
      $slug,
      $content,
      $published
    );

    if ($stmt->execute()) {
      header("Location: admin_knowledge_documents.php");
      exit;
    }

    $error = "Save failed: " . $stmt->error;
    $stmt->close();
  } else {
    $error = "All fields except slug are required.";
  }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
  <h1 class="h4 mb-4">Add Knowledge Document</h1>

  <?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <div class="mb-3">
      <label class="form-label">Category</label>
      <select name="category_id" class="form-select" required>
        <option value="">Choose category…</option>
        <?php foreach ($kbCategories as $cat): ?>
          <option value="<?= $cat['id'] ?>">
            <?= h($cat['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="mb-3">
      <label class="form-label">Title</label>
      <input type="text" name="title" class="form-control" required>
    </div>

    <div class="mb-3">
      <label class="form-label">Slug (optional)</label>
      <input type="text" name="slug" class="form-control">
      <div class="form-text">Auto-generated if left blank</div>
    </div>

    <div class="mb-3">
      <label class="form-label">Content</label>
      <textarea name="content" id="editor" rows="10" data-ckeditor="1" class="form-control"></textarea>
    </div>

    <div class="form-check mb-3">
      <input class="form-check-input" type="checkbox" name="is_published" checked>
      <label class="form-check-label">Published</label>
    </div>

    <button class="btn btn-primary">Save document</button>
  </form>
</div>

<script src="/path/to/ckeditor.js"></script>
<script>
  ClassicEditor.create(document.querySelector('#editor'))
    .catch(error => console.error(error));
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
