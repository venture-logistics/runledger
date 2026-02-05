<?php
// admin.php — Forum Members Admin (members-only)
// Assumes: header.php includes auth, mysqli $conn, Bootstrap 5

define('LEDGER_VERSION', 'v1.2.1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$user = auth_require($conn, ['admin']); // role-gated

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

function csrf_verify() {
    $posted = $_POST['csrf_token'] ?? '';
    if (!is_string($posted) || !hash_equals($_SESSION['csrf_token'] ?? '', $posted)) {
        http_response_code(400);
        exit('Bad request');
    }
}

// ---------- FLASH ----------
function flash_set($type, $msg) {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function flash_get() {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ---------- DESIGNATIONS (SOURCE OF TRUTH = DB ENUM) ----------
$FORUM_DESIGNATIONS = [];
$res = $conn->query("SHOW COLUMNS FROM users LIKE 'forum_designation'");
if ($res && ($col = $res->fetch_assoc())) {
    // Type will be like: enum('Member','Trusted Member',...)
    if (isset($col['Type']) && preg_match("/^enum\((.*)\)$/i", (string)$col['Type'], $m)) {
        preg_match_all("/'((?:\\\\'|[^'])*)'/", $m[1], $matches);
        $FORUM_DESIGNATIONS = array_map(
            static fn($v) => str_replace("\\'", "'", $v),
            $matches[1]
        );
    }
}
if (!$FORUM_DESIGNATIONS) {
    echo "<div class='container py-4'><div class='alert alert-danger mb-0'>forum_designation ENUM not detected on users table.</div></div>";
    require_once 'footer.php';
    exit;
}

// ---------- ACTION: UPDATE USER ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_user') {
    csrf_verify();

    try {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $designation = (string) ($_POST['forum_designation'] ?? '');

        if ($userId <= 0) {
            throw new RuntimeException('Invalid user.');
        }
        if ($name === '') {
            throw new RuntimeException('Name cannot be empty.');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid email address.');
        }
        if (!in_array($designation, $FORUM_DESIGNATIONS, true)) {
            throw new RuntimeException('Invalid designation.');
        }

        // Check if email is already taken by another user
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        if (!$checkStmt)
            throw new RuntimeException('Prepare failed.');
        $checkStmt->bind_param("si", $email, $userId);
        $checkStmt->execute();
        $checkStmt->store_result();
        if ($checkStmt->num_rows > 0) {
            throw new RuntimeException('Email already in use by another user.');
        }
        $checkStmt->close();

        // Update users table
        $stmt = $conn->prepare("UPDATE users SET name=?, email=?, forum_designation=? WHERE id=?");
        if (!$stmt)
            throw new RuntimeException('Prepare failed.');
        $stmt->bind_param("sssi", $name, $email, $designation, $userId);
        if (!$stmt->execute())
            throw new RuntimeException('Execute failed: ' . $stmt->error);
        $stmt->close();

        // Update all posts by this user to reflect new name
        $postStmt = $conn->prepare("UPDATE forum_posts SET created_by_name = ? WHERE created_by_user_id = ?");
        if ($postStmt) {
            $postStmt->bind_param("si", $name, $userId);
            $postStmt->execute();
            $postStmt->close();
        }

        flash_set('success', 'User updated successfully.');
        header('Location: admin.php');
        exit;

    } catch (Throwable $e) {
        flash_set('danger', $e->getMessage());
        header('Location: admin.php');
        exit;
    }
}

// ---------- QUERY MEMBERS (WITH POST COUNT) ----------
$q = trim((string)($_GET['q'] ?? ''));
$members = [];
$resOk = false;

$sql = "
  SELECT
    u.id,
    u.name,
    u.email,
    u.forum_designation,
    COUNT(p.id) AS post_count
  FROM users u
  LEFT JOIN forum_posts p
    ON p.created_by_user_id = u.id
   AND p.is_deleted = 0
  WHERE 1=1
";

$types = '';
$params = [];

if ($q !== '') {
  $sql .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.forum_designation LIKE ?)";
  $like = "%{$q}%";
  $types = 'sss';
  $params = [$like, $like, $like];
}

// IMPORTANT: strict SQL mode safe GROUP BY
$sql .= "
  GROUP BY u.id, u.name, u.email, u.forum_designation
  ORDER BY post_count DESC, u.id ASC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
  flash_set('danger', 'Admin list query failed: ' . $conn->error);
} else {
  if ($types !== '') {
    $stmt->bind_param($types, ...$params);
  }

  if (!$stmt->execute()) {
    flash_set('danger', 'Admin list query failed: ' . $stmt->error);
  } else {
    $resOk = true;

    $stmt->bind_result($id, $name, $email, $forum_designation, $post_count);
    while ($stmt->fetch()) {
      $members[] = [
        'id' => (int)$id,
        'name' => $name,
        'email' => $email,
        'forum_designation' => $forum_designation,
        'post_count' => (int)$post_count,
      ];
    }
  }

  $stmt->close();
}

// Fallback only if the main query genuinely failed
if (!$resOk) {
  $fallbackSql = "
    SELECT id, name, email, forum_designation, 0 AS post_count
    FROM users
    ORDER BY id ASC
  ";
  $res2 = $conn->query($fallbackSql);
  if ($res2) {
    $members = [];
    while ($row = $res2->fetch_assoc()) $members[] = $row;
    flash_set('warning', 'Post counts unavailable (forum_posts query failed). Showing users only.');
  } else {
    flash_set('danger', 'Admin list query failed: ' . $conn->error);
  }
}

$flash = flash_get();

?>

<div class="container py-4">
    
<?php require_once 'includes/admin_menu.php'; ?> 

  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h3 mb-0">Forum Members</h1>
      <div class="text-muted small">Post count & forum designation</div>
    </div>

    <form class="d-flex gap-2" method="get" action="admin.php">
      <input class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search">
      <button class="btn btn-primary" type="submit">Search</button>
      <a class="btn btn-outline-secondary" href="admin.php">Reset</a>
    </form>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th style="width:70px;">ID</th>
              <th>Name</th>
              <th>Email</th>
              <th style="width:220px;">Rank</th>
              <th class="text-end" style="width:110px;">Posts</th>
              <th class="text-end" style="width:100px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($members as $m): ?>
              <tr>
                <td><?= (int)$m['id'] ?></td>
                <td>
                  <form method="post" class="user-edit-form" action="admin.php" id="form-<?= (int)$m['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="update_user">
                    <input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>">
                    <input type="text" name="name" class="form-control form-control-sm" 
                           value="<?= htmlspecialchars((string)$m['name']) ?>" required>
                </td>
                <td>
                    <input type="email" name="email" class="form-control form-control-sm" 
                           value="<?= htmlspecialchars((string)$m['email']) ?>" required>
                </td>
                <td>
                    <select name="forum_designation" class="form-select form-select-sm">
                      <?php foreach ($FORUM_DESIGNATIONS as $d): ?>
                        <option value="<?= htmlspecialchars($d) ?>"
                          <?= ((string)($m['forum_designation'] ?? '') === (string)$d) ? 'selected' : '' ?>>
                          <?= htmlspecialchars($d) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                </td>
                <td class="text-end"><?= (int)$m['post_count'] ?></td>
                <td class="text-end">
                    <button class="btn btn-sm btn-primary" type="submit">Save</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>

            <?php if (!$members): ?>
              <tr>
                <td colspan="6" class="text-center text-muted py-4">No members found</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card-footer small text-muted">
      Sorted by post count (non-deleted posts). Rank list is pulled from users.forum_designation ENUM.
    </div>
  </div>

</div>

<?php require_once 'includes/footer.php'; ?>