<?php
// admin_knowledge_documents.php

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
    exit('Access denied. Only admins and experts can manage knowledge documents.');
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

// Handle delete BEFORE output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify();

    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM knowledge_documents WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
        }
    }

    header("Location: admin_knowledge_documents.php");
    exit;
}

// Fetch docs (unique var name)
$kbDocs = [];
$sql = "
  SELECT d.id, d.title, d.slug, d.is_published, d.created_at, d.updated_at,
         c.name AS category_name
  FROM knowledge_documents d
  JOIN knowledge_categories c ON c.id = d.category_id
  ORDER BY d.created_at DESC
";
$res = $conn->query($sql);
if ($res === false) {
    die("Query failed: " . $conn->error);
}
while ($row = $res->fetch_assoc())
    $kbDocs[] = $row;
$res->free();

$pageTitle = "Knowledge Documents";
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    
<?php require_once 'includes/admin_menu.php'; ?>     
    
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Knowledge Documents</h1>
    <a class="btn btn-sm btn-primary" href="admin_knowledge_document_add.php">Add document</a>
  </div>

  <?php if (!$kbDocs): ?>
    <div class="alert alert-info mb-0">No documents yet.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>Title</th>
            <th>Category</th>
            <th>Status</th>
            <th>Created</th>
            <th>Updated</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($kbDocs as $d): ?>
            <tr>
              <td>
                <strong><?= h($d['title']) ?></strong><br>
                <small class="text-muted"><code><?= h($d['slug']) ?></code></small>
              </td>
              <td><?= h($d['category_name']) ?></td>
              <td>
                <?php if ((int) $d['is_published'] === 1): ?>
                  <span class="badge bg-success">Published</span>
                <?php else: ?>
                  <span class="badge bg-secondary">Draft</span>
                <?php endif; ?>
              </td>
              <td><?= h(substr((string) $d['created_at'], 0, 10)) ?></td>
              <td><?= !empty($d['updated_at']) ? h(substr((string) $d['updated_at'], 0, 10)) : '—' ?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-primary" href="admin_knowledge_document_edit.php?id=<?= (int) $d['id'] ?>">Edit</a>
                <a class="btn btn-sm btn-outline-secondary" target="_blank"
                   href="knowledge_document.php?doc=<?= h($d['slug']) ?>">View</a>

                <form method="post" class="d-inline" onsubmit="return confirm('Delete this document?');">
                  <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>