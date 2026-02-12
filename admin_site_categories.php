<?php
// admin_settings.php — Site settings admin
declare(strict_types = 1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$currentUser = auth_require($conn, ['admin']);

// --------------------
// HANDLE ACTIONS
// --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CREATE / UPDATE
    if (($_POST['action'] ?? '') === 'save') {
        $id           = (int)($_POST['id'] ?? 0);
        $name         = trim($_POST['name'] ?? '');
        $slug         = trim($_POST['slug'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $accent_color = trim($_POST['accent_color'] ?? '');
        $sort_order   = (int)($_POST['sort_order'] ?? 0);

        if ($name && $slug) {
            if ($id > 0) {
                // UPDATE
                $stmt = $conn->prepare("
                    UPDATE forum_categories
                    SET name = ?, slug = ?, description = ?, accent_color = ?, sort_order = ?
                    WHERE id = ?
                ");
                $stmt->bind_param(
                    "ssssii",
                    $name,
                    $slug,
                    $description,
                    $accent_color,
                    $sort_order,
                    $id
                );
            } else {
                // CREATE
                $stmt = $conn->prepare("
                    INSERT INTO forum_categories (name, slug, description, accent_color, sort_order)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    "ssssi",
                    $name,
                    $slug,
                    $description,
                    $accent_color,
                    $sort_order
                );
            }

            $stmt->execute();
            $stmt->close();
        }

        header('Location: admin_site_categories.php');
        exit;
    }

    // DELETE
    if (($_POST['action'] ?? '') === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM forum_categories WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
        }

        header('Location: admin_site_categories.php');
        exit;
    }
}

// --------------------
// LOAD CATEGORIES
// --------------------
$categories = [];

$res = $conn->query("
    SELECT
        id,
        name,
        slug,
        description,
        accent_color,
        sort_order,
        created_at
    FROM forum_categories
    ORDER BY sort_order ASC, id ASC
");

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $categories[] = $row;
    }
    $res->free();
}

// EDIT TARGET (optional)
$edit = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    foreach ($categories as $c) {
        if ((int)$c['id'] === $editId) {
            $edit = $c;
            break;
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    
<?php require_once 'includes/admin_menu.php'; ?>     

    <h1 class="h3 mb-4">Forum Categories</h1>

    <div class="row g-4">
        
        <!-- EDIT / NEW FORM -->
        <div class="col-lg-12">

            <div class="card">
                <div class="card-body">

                    <h5 class="card-title mb-3">
                        <?= $edit ? 'Edit Category' : 'New Category' ?>
                    </h5>

                    <form method="post">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

                        <div class="mb-3">
                            <label class="form-label">Name</label>
                            <input class="form-control"
                                   name="name"
                                   required
                                   value="<?= htmlspecialchars($edit['name'] ?? '') ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Slug</label>
                            <input class="form-control"
                                   name="slug"
                                   required
                                   value="<?= htmlspecialchars($edit['slug'] ?? '') ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control"
                                      name="description"
                                      rows="3"><?= htmlspecialchars($edit['description'] ?? '') ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Accent colour</label>
                            <input class="form-control"
                                   name="accent_color"
                                   placeholder="#ff0000"
                                   value="<?= htmlspecialchars($edit['accent_color'] ?? '') ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Sort order</label>
                            <input type="number"
                                   class="form-control"
                                   name="sort_order"
                                   value="<?= (int)($edit['sort_order'] ?? 0) ?>">
                        </div>

                        <button class="btn btn-primary w-100">
                            Save category
                        </button>

                    </form>

                </div>
            </div>

        </div>        

        <!-- CATEGORY LIST -->
        <div class="col-lg-12">

            <table class="table align-middle">
                <thead>
                    <tr>
                        <th style="width:40px;"></th>
                        <th>Name</th>
                        <th>Slug</th>
                        <th class="text-end">Sort</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>

                <?php if (!$categories): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            No categories yet.
                        </td>
                    </tr>
                <?php else: foreach ($categories as $c): ?>

                    <tr>
                        <td>
                            <?php if ($c['accent_color']): ?>
                                <span class="d-inline-block rounded-circle"
                                      style="width:14px;height:14px;background:<?= htmlspecialchars($c['accent_color']) ?>;"></span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <strong><?= htmlspecialchars($c['name']) ?></strong>
                            <?php if ($c['description']): ?>
                                <div class="text-muted small"><?= htmlspecialchars($c['description']) ?></div>
                            <?php endif; ?>
                        </td>

                        <td class="text-muted"><?= htmlspecialchars($c['slug']) ?></td>

                        <td class="text-end"><?= (int)$c['sort_order'] ?></td>

                        <td class="text-end">
                          <div class="d-inline-flex gap-2">
                        
                            <a href="?edit=<?= (int)$c['id'] ?>"
                               class="btn btn-sm btn-outline-primary">
                              Edit
                            </a>
                        
                            <form method="post" class="m-0"
                                  onsubmit="return confirm('Delete this category?');">
                              <input type="hidden" name="action" value="delete">
                              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                              <button class="btn btn-sm btn-outline-danger">
                                Delete
                              </button>
                            </form>
                        
                          </div>
                        </td>
                    </tr>

                <?php endforeach; endif; ?>

                </tbody>
            </table>
        </div>

    </div>

</div>


<?php require_once __DIR__ . '/includes/footer.php'; ?>
