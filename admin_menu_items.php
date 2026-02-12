<?php
// admin_menu_items.php - Manage custom navigation menu items

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$user = auth_require($conn, ['admin']);

require_once 'includes/header.php';

// ---------- DB GUARD ----------
if (!isset($conn) || !($conn instanceof mysqli)) {
    echo "<div class='container py-4'><div class='alert alert-danger mb-0'>DB connection not available.</div></div>";
    require_once 'footer.php';
    exit;
}

// ---------- CSRF ----------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

function csrf_verify()
{
    $posted = $_POST['csrf_token'] ?? '';
    if (!is_string($posted) || !hash_equals($_SESSION['csrf_token'] ?? '', $posted)) {
        http_response_code(400);
        exit('Bad request');
    }
}

// ---------- FLASH ----------
function flash_set($type, $msg)
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function flash_get()
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ---------- ADD MENU ITEM ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    csrf_verify();

    try {
        $label = trim((string) ($_POST['label'] ?? ''));
        $url = trim((string) ($_POST['url'] ?? ''));
        $parentId = !empty($_POST['parent_id']) ? (int) $_POST['parent_id'] : null;
        $visibility = (string) ($_POST['visibility'] ?? 'public');
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);

        if ($label === '') {
            throw new RuntimeException('Label is required.');
        }

        if (!in_array($visibility, ['public', 'members', 'admin'], true)) {
            throw new RuntimeException('Invalid visibility.');
        }

        // If parent_id is set, url can be null (dropdown item)
        // If no parent_id, url should be provided
        if ($parentId === null && $url === '') {
            // This is a top-level dropdown parent - that's OK
        }

        $stmt = $conn->prepare("
            INSERT INTO menu_items (label, url, parent_id, sort_order, visibility, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        if (!$stmt)
            throw new RuntimeException('Prepare failed.');

        $urlValue = $url ?: null;
        $stmt->bind_param("ssiis", $label, $urlValue, $parentId, $sortOrder, $visibility);
        if (!$stmt->execute())
            throw new RuntimeException('Execute failed: ' . $stmt->error);
        $stmt->close();

        flash_set('success', 'Menu item added successfully.');
        header('Location: admin_menu_items.php');
        exit;

    } catch (Throwable $e) {
        flash_set('danger', $e->getMessage());
    }
}

// ---------- UPDATE MENU ITEM ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    csrf_verify();

    try {
        $id = (int) ($_POST['id'] ?? 0);
        $label = trim((string) ($_POST['label'] ?? ''));
        $url = trim((string) ($_POST['url'] ?? ''));
        $parentId = !empty($_POST['parent_id']) ? (int) $_POST['parent_id'] : null;
        $visibility = (string) ($_POST['visibility'] ?? 'public');
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $isActive = !empty($_POST['is_active']) ? 1 : 0;

        if ($id <= 0)
            throw new RuntimeException('Invalid ID.');
        if ($label === '')
            throw new RuntimeException('Label is required.');
        if (!in_array($visibility, ['public', 'members', 'admin'], true)) {
            throw new RuntimeException('Invalid visibility.');
        }

        // Prevent circular references
        if ($parentId === $id) {
            throw new RuntimeException('Menu item cannot be its own parent.');
        }

        $stmt = $conn->prepare("
            UPDATE menu_items 
            SET label=?, url=?, parent_id=?, sort_order=?, visibility=?, is_active=?
            WHERE id=?
        ");
        if (!$stmt)
            throw new RuntimeException('Prepare failed.');

        $urlValue = $url ?: null;
        $stmt->bind_param("ssiisii", $label, $urlValue, $parentId, $sortOrder, $visibility, $isActive, $id);
        if (!$stmt->execute())
            throw new RuntimeException('Execute failed: ' . $stmt->error);
        $stmt->close();

        flash_set('success', 'Menu item updated successfully.');
        header('Location: admin_menu_items.php');
        exit;

    } catch (Throwable $e) {
        flash_set('danger', $e->getMessage());
    }
}

// ---------- DELETE MENU ITEM ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify();

    try {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0)
            throw new RuntimeException('Invalid ID.');

        // Check if this item has children
        $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM menu_items WHERE parent_id = ?");
        if ($checkStmt) {
            $checkStmt->bind_param("i", $id);
            $checkStmt->execute();
            $checkStmt->bind_result($count);
            $checkStmt->fetch();
            $checkStmt->close();

            if ($count > 0) {
                throw new RuntimeException('Cannot delete menu item with child items. Delete children first.');
            }
        }

        $stmt = $conn->prepare("DELETE FROM menu_items WHERE id=?");
        if (!$stmt)
            throw new RuntimeException('Prepare failed.');

        $stmt->bind_param("i", $id);
        if (!$stmt->execute())
            throw new RuntimeException('Execute failed: ' . $stmt->error);
        $stmt->close();

        flash_set('success', 'Menu item deleted successfully.');
        header('Location: admin_menu_items.php');
        exit;

    } catch (Throwable $e) {
        flash_set('danger', $e->getMessage());
    }
}

// ---------- REORDER (MOVE UP/DOWN) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reorder') {
    csrf_verify();

    try {
        $id = (int) ($_POST['id'] ?? 0);
        $direction = (string) ($_POST['direction'] ?? '');

        if ($id <= 0)
            throw new RuntimeException('Invalid ID.');
        if (!in_array($direction, ['up', 'down'], true))
            throw new RuntimeException('Invalid direction.');

        // Get current item
        $stmt = $conn->prepare("SELECT sort_order, parent_id FROM menu_items WHERE id=?");
        if (!$stmt)
            throw new RuntimeException('Prepare failed.');
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->bind_result($currentOrder, $parentId);
        if (!$stmt->fetch()) {
            $stmt->close();
            throw new RuntimeException('Menu item not found.');
        }
        $stmt->close();

        // Get sibling to swap with
        if ($direction === 'up') {
            $sql = "SELECT id, sort_order FROM menu_items 
                    WHERE sort_order < ? AND " . ($parentId ? "parent_id = ?" : "parent_id IS NULL") . "
                    ORDER BY sort_order DESC LIMIT 1";
        } else {
            $sql = "SELECT id, sort_order FROM menu_items 
                    WHERE sort_order > ? AND " . ($parentId ? "parent_id = ?" : "parent_id IS NULL") . "
                    ORDER BY sort_order ASC LIMIT 1";
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt)
            throw new RuntimeException('Prepare failed.');

        if ($parentId) {
            $stmt->bind_param("ii", $currentOrder, $parentId);
        } else {
            $stmt->bind_param("i", $currentOrder);
        }

        $stmt->execute();
        $stmt->bind_result($siblingId, $siblingOrder);

        if ($stmt->fetch()) {
            $stmt->close();

            // Swap sort orders
            $conn->begin_transaction();

            $update1 = $conn->prepare("UPDATE menu_items SET sort_order=? WHERE id=?");
            $update1->bind_param("ii", $siblingOrder, $id);
            $update1->execute();
            $update1->close();

            $update2 = $conn->prepare("UPDATE menu_items SET sort_order=? WHERE id=?");
            $update2->bind_param("ii", $currentOrder, $siblingId);
            $update2->execute();
            $update2->close();

            $conn->commit();

            flash_set('success', 'Menu item reordered successfully.');
        } else {
            $stmt->close();
            flash_set('warning', 'Cannot move further in that direction.');
        }

        header('Location: admin_menu_items.php');
        exit;

    } catch (Throwable $e) {
        if ($conn->in_transaction) {
            $conn->rollback();
        }
        flash_set('danger', $e->getMessage());
    }
}

// ---------- LOAD MENU ITEMS ----------
$menuItems = [];
$stmt = $conn->prepare("
    SELECT id, label, url, parent_id, sort_order, visibility, is_active
    FROM menu_items
    ORDER BY parent_id IS NULL DESC, parent_id ASC, sort_order ASC, id ASC
");

if ($stmt && $stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $menuItems[] = $row;
    }
    $stmt->close();
}

// Organize by parent
$topLevel = [];
$children = [];

foreach ($menuItems as $item) {
    if ($item['parent_id'] === null) {
        $topLevel[] = $item;
    } else {
        $children[$item['parent_id']][] = $item;
    }
}

$flash = flash_get();

?>

<div class="container py-4">

<?php require_once 'includes/admin_menu.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h3 mb-0">Custom Menu Items</h1>
        <div class="text-muted small">Add custom links to the main navigation menu</div>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMenuModal">
        Add Menu Item
    </button>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show">
        <?= htmlspecialchars($flash['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px;">ID</th>
                        <th>Label</th>
                        <th>URL</th>
                        <th style="width:100px;">Order</th>
                        <th style="width:120px;">Visibility</th>
                        <th style="width:80px;">Active</th>
                        <th class="text-end" style="width:180px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($topLevel)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                No custom menu items yet. Click "Add Menu Item" to get started.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($topLevel as $item): ?>
                            <tr class="<?= $item['is_active'] ? '' : 'table-secondary' ?>">
                                <td><?= (int) $item['id'] ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($item['label']) ?></strong>
                                    <?php if (empty($item['url'])): ?>
                                        <span class="badge bg-secondary">Dropdown</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small"><?= htmlspecialchars($item['url'] ?: '-') ?></td>
                                <td><?= (int) $item['sort_order'] ?></td>
                                <td>
                                    <span class="badge bg-<?= $item['visibility'] === 'admin' ? 'danger' : ($item['visibility'] === 'members' ? 'warning' : 'success') ?>">
                                        <?= htmlspecialchars(ucfirst($item['visibility'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $item['is_active'] ? 'success' : 'secondary' ?>">
                                        <?= $item['is_active'] ? 'Yes' : 'No' ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-secondary" 
                                                onclick="editMenuItem(<?= htmlspecialchars(json_encode($item)) ?>)"
                                                title="Edit">
                                            ✏️
                                        </button>
                                        
                                        <form method="post" class="d-inline" onsubmit="return confirm('Move up?')">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="reorder">
                                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                            <input type="hidden" name="direction" value="up">
                                            <button class="btn btn-outline-secondary" type="submit" title="Move Up">↑</button>
                                        </form>
                                        
                                        <form method="post" class="d-inline" onsubmit="return confirm('Move down?')">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="reorder">
                                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                            <input type="hidden" name="direction" value="down">
                                            <button class="btn btn-outline-secondary" type="submit" title="Move Down">↓</button>
                                        </form>
                                        
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this menu item?')">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                            <button class="btn btn-outline-danger" type="submit" title="Delete">🗑️</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            
                            <?php if (isset($children[$item['id']])): ?>
                                <?php foreach ($children[$item['id']] as $child): ?>
                                    <tr class="<?= $child['is_active'] ? '' : 'table-secondary' ?>">
                                        <td class="ps-4"><?= (int) $child['id'] ?></td>
                                        <td class="ps-4">
                                            ↳ <?= htmlspecialchars($child['label']) ?>
                                        </td>
                                        <td class="text-muted small"><?= htmlspecialchars($child['url'] ?: '-') ?></td>
                                        <td><?= (int) $child['sort_order'] ?></td>
                                        <td>
                                            <span class="badge bg-<?= $child['visibility'] === 'admin' ? 'danger' : ($child['visibility'] === 'members' ? 'warning' : 'success') ?>">
                                                <?= htmlspecialchars(ucfirst($child['visibility'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $child['is_active'] ? 'success' : 'secondary' ?>">
                                                <?= $child['is_active'] ? 'Yes' : 'No' ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-secondary" 
                                                        onclick="editMenuItem(<?= htmlspecialchars(json_encode($child)) ?>)"
                                                        title="Edit">
                                                    ✏️
                                                </button>
                                                
                                                <form method="post" class="d-inline" onsubmit="return confirm('Move up?')">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                    <input type="hidden" name="action" value="reorder">
                                                    <input type="hidden" name="id" value="<?= (int) $child['id'] ?>">
                                                    <input type="hidden" name="direction" value="up">
                                                    <button class="btn btn-outline-secondary" type="submit" title="Move Up">↑</button>
                                                </form>
                                                
                                                <form method="post" class="d-inline" onsubmit="return confirm('Move down?')">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                    <input type="hidden" name="action" value="reorder">
                                                    <input type="hidden" name="id" value="<?= (int) $child['id'] ?>">
                                                    <input type="hidden" name="direction" value="down">
                                                    <button class="btn btn-outline-secondary" type="submit" title="Move Down">↓</button>
                                                </form>
                                                
                                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this menu item?')">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= (int) $child['id'] ?>">
                                                    <button class="btn btn-outline-danger" type="submit" title="Delete">🗑️</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="card-footer small text-muted">
        <strong>Note:</strong> Custom menu items appear <em>below</em> the hardcoded items (Download, Knowledge Base, Discussion, New Topic). 
        Parent items without URLs become dropdown menus.
    </div>
</div>

</div>

<!-- Add Menu Item Modal -->
<div class="modal fade" id="addMenuModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="admin_menu_items.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="add">
                
                <div class="modal-header">
                    <h5 class="modal-title">Add Menu Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Label *</label>
                        <input type="text" name="label" class="form-control" required>
                        <div class="form-text">Display text for the menu item</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">URL</label>
                        <input type="text" name="url" class="form-control" placeholder="/page.php">
                        <div class="form-text">Leave empty to create a dropdown parent</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Parent Menu Item</label>
                        <select name="parent_id" class="form-select">
                            <option value="">(Top Level)</option>
                            <?php foreach ($topLevel as $item): ?>
                                <option value="<?= (int) $item['id'] ?>">
                                    <?= htmlspecialchars($item['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Select a parent to create a dropdown item</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="0">
                        <div class="form-text">Lower numbers appear first</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Visibility *</label>
                        <select name="visibility" class="form-select" required>
                            <option value="public">Public (everyone)</option>
                            <option value="members">Members only (logged in)</option>
                            <option value="admin">Admin only</option>
                        </select>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Menu Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Menu Item Modal -->
<div class="modal fade" id="editMenuModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="admin_menu_items.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">Edit Menu Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Label *</label>
                        <input type="text" name="label" id="edit_label" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">URL</label>
                        <input type="text" name="url" id="edit_url" class="form-control">
                        <div class="form-text">Leave empty to create a dropdown parent</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Parent Menu Item</label>
                        <select name="parent_id" id="edit_parent_id" class="form-select">
                            <option value="">(Top Level)</option>
                            <?php foreach ($topLevel as $item): ?>
                                <option value="<?= (int) $item['id'] ?>">
                                    <?= htmlspecialchars($item['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" id="edit_sort_order" class="form-control">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Visibility *</label>
                        <select name="visibility" id="edit_visibility" class="form-select" required>
                            <option value="public">Public (everyone)</option>
                            <option value="members">Members only (logged in)</option>
                            <option value="admin">Admin only</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="is_active" id="edit_is_active" class="form-check-input" value="1">
                            <label class="form-check-label" for="edit_is_active">
                                Active (visible in menu)
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editMenuItem(item) {
    document.getElementById('edit_id').value = item.id;
    document.getElementById('edit_label').value = item.label;
    document.getElementById('edit_url').value = item.url || '';
    document.getElementById('edit_parent_id').value = item.parent_id || '';
    document.getElementById('edit_sort_order').value = item.sort_order;
    document.getElementById('edit_visibility').value = item.visibility;
    document.getElementById('edit_is_active').checked = item.is_active == 1;
    
    const modal = new bootstrap.Modal(document.getElementById('editMenuModal'));
    modal.show();
}
</script>

<?php require_once 'includes/footer.php'; ?>