<?php
declare(strict_types = 1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
// admin/knowledge_categories.php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

auth_require($conn, ['admin']);

$pageTitle = "Knowledge Categories";

// ---- DB guard ----
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo "<div class='container py-4'><div class='alert alert-danger mb-0'>DB connection not available.</div></div>";
    exit;
}

// ---- helpers ----

function flash_set($type, $msg) {
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function flash_get_all() {
    $out = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $out;
}

// ---- CSRF ----
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

function csrf_verify() {
    $posted = $_POST['csrf_token'] ?? '';
    if (!is_string($posted) || !hash_equals($_SESSION['csrf_token'] ?? '', $posted)) {
        http_response_code(400);
        exit('Bad request (CSRF).');
    }
}

// ---- handle POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $accent_color = trim($_POST['accent_color'] ?? '#0d6efd');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            flash_set('danger', 'Name is required.');
            header("Location: admin_knowledge_categories.php");
            exit;
        }

        // basic hex colour guard (#RRGGBB)
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent_color)) {
            $accent_color = '#0d6efd';
        }

        if ($id > 0) {
            $sql = "UPDATE knowledge_categories
                    SET name=?, slug=?, description=?, accent_color=?, sort_order=?, is_active=?
                    WHERE id=?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                flash_set('danger', 'DB error (prepare failed).');
                header("Location: admin_knowledge_categories.php");
                exit;
            }
            $stmt->bind_param("ssssiii", $name, $slug, $description, $accent_color, $sort_order, $is_active, $id);
            if ($stmt->execute()) {
                flash_set('success', 'Category updated.');
            } else {
                // likely slug unique collision
                flash_set('danger', 'Save failed. Is the slug already in use?');
            }
            $stmt->close();
        } else {
            $sql = "INSERT INTO knowledge_categories (name, slug, description, accent_color, sort_order, is_active)
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                flash_set('danger', 'DB error (prepare failed).');
                header("Location: admin_knowledge_categories.php");
                exit;
            }
            $stmt->bind_param("ssssii", $name, $slug, $description, $accent_color, $sort_order, $is_active);
            if ($stmt->execute()) {
                flash_set('success', 'Category created.');
            } else {
                flash_set('danger', 'Create failed. Is the slug already in use?');
            }
            $stmt->close();
        }

        header("Location: admin_knowledge_categories.php");
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM knowledge_categories WHERE id=?");
            if ($stmt) {
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();
                flash_set('success', 'Category deleted.');
            } else {
                flash_set('danger', 'DB error (prepare failed).');
            }
        }
        header("Location: admin_knowledge_categories.php");
        exit;
    }

    header("Location: admin_knowledge_categories.php");
    exit;
}

// ---- editing? ----
$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;

if ($editId > 0) {
    $stmt = $conn->prepare("SELECT * FROM knowledge_categories WHERE id=? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $editId);
        $stmt->execute();
        $res = $stmt->get_result();
        $editRow = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
}

// ---- fetch list ----
$list = [];
$res = $conn->query("SELECT * FROM knowledge_categories ORDER BY sort_order ASC, name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) $list[] = $row;
    $res->free();
}

// header include (adjust path to your admin header if needed)
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    
<?php require_once 'includes/admin_menu.php'; ?> 

    <div class="d-flex align-items-center justify-content-between mb-3">
        <h1 class="h4 mb-0">Knowledge Categories</h1>
        <a href="knowledge_categories.php" class="btn btn-sm btn-outline-secondary">Reset</a>
    </div>

    <?php foreach (flash_get_all() as $f): ?>
        <div class="alert alert-<?= h($f['type']) ?>"><?= h($f['msg']) ?></div>
    <?php endforeach; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6 mb-3"><?= $editRow ? 'Edit category' : 'Add category' ?></h2>

            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= h($editRow['id'] ?? 0) ?>">

                <div class="col-12 col-lg-6">
                    <label class="form-label">Name</label>
                    <input type="text" name="name" class="form-control" required
                           value="<?= h($editRow['name'] ?? '') ?>">
                </div>

                <div class="col-12 col-lg-6">
                    <label class="form-label">Slug</label>
                    <input type="text" name="slug" class="form-control"
                           placeholder="auto-generated if blank"
                           value="<?= h($editRow['slug'] ?? '') ?>">
                    <div class="form-text">Lowercase; hyphens only. Must be unique.</div>
                </div>

                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" rows="2" class="form-control"><?= h($editRow['description'] ?? '') ?></textarea>
                </div>

                <div class="col-12 col-lg-4">
                    <label class="form-label">Accent colour</label>
                    <input type="color" name="accent_color" class="form-control form-control-color"
                           value="<?= h($editRow['accent_color'] ?? '#0d6efd') ?>">
                </div>

                <div class="col-12 col-lg-4">
                    <label class="form-label">Sort order</label>
                    <input type="number" name="sort_order" class="form-control"
                           value="<?= h($editRow['sort_order'] ?? 0) ?>">
                </div>

                <div class="col-12 col-lg-4 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
                               <?= ((int)($editRow['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary"><?= $editRow ? 'Save changes' : 'Create category' ?></button>
                    <?php if ($editRow): ?>
                        <a class="btn btn-outline-secondary" href="admin_knowledge_categories.php">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <h2 class="h6 mb-3">All categories</h2>

            <?php if (!$list): ?>
                <div class="alert alert-info mb-0">No categories yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Slug</th>
                                <th>Active</th>
                                <th>Sort</th>
                                <th>Colour</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($list as $row): ?>
                            <tr>
                                <td><strong><?= h($row['name']) ?></strong><br><small class="text-muted"><?= h($row['description']) ?></small></td>
                                <td><code><?= h($row['slug']) ?></code></td>
                                <td><?= ((int)$row['is_active'] === 1) ? 'Yes' : 'No' ?></td>
                                <td><?= (int)$row['sort_order'] ?></td>
                                <td>
                                    <span class="badge" style="background: <?= h($row['accent_color']) ?>;">
                                        <?= h($row['accent_color']) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="admin_knowledge_categories.php?edit=<?= (int)$row['id'] ?>">Edit</a>

                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this category?');">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
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
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
