<?php
// admin_banning.php — Admin Page for Reviewing Warnings & Bans (role-gated)
// Loads warned/banned users with counts and reasons (from DB table or log)

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$user = auth_require($conn, ['admin']); // Admins only

require_once 'includes/header.php';

// ---------- CSRF & FLASH (reuse from admin.php) ----------
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

// ---------- CHECK COLUMNS & TABLE ----------
$hasBannedColumn = false;
$hasWarningsColumn = false;
$tableExists = false;
$checkRes = $conn->query("SHOW COLUMNS FROM users");
if ($checkRes) {
    while ($col = $checkRes->fetch_assoc()) {
        if ($col['Field'] === 'is_banned')
            $hasBannedColumn = true;
        if ($col['Field'] === 'warnings_count')
            $hasWarningsColumn = true;
    }
    $checkRes->close();
}
$checkTable = $conn->query("SHOW TABLES LIKE 'warning_reasons'");
if ($checkTable && $checkTable->num_rows > 0)
    $tableExists = true;
$checkTable->close();

// ---------- ACTION: UNWARN USER (Removes 1 from warnings_count AND last reason) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unwarn_user') {
    csrf_verify();

    try {
        $userId = (int) ($_POST['user_id'] ?? 0);

        if ($userId <= 0) {
            throw new RuntimeException('Invalid user.');
        }

        if ($hasWarningsColumn && $tableExists) {
            // Decrement count AND remove latest reason
            $conn->query("UPDATE users SET warnings_count = GREATEST(warnings_count - 1, 0) WHERE id = {$userId}");
            $conn->query("DELETE FROM warning_reasons WHERE user_id = {$userId} ORDER BY issued_at DESC LIMIT 1");
            flash_set('success', 'Warning and reason removed.');
        } elseif ($hasWarningsColumn) {
            $conn->query("UPDATE users SET warnings_count = GREATEST(warnings_count - 1, 0) WHERE id = {$userId}");
            flash_set('success', 'Warning count reduced (reason not tracked in DB).');
        } else {
            flash_set('warning', 'Unwarn not supported without DB warnings_count column.');
        }

        header('Location: admin_banning.php');
        exit;

    } catch (Throwable $e) {
        flash_set('danger', $e->getMessage());
        header('Location: admin_banning.php');
        exit;
    }
}

// ---------- LOAD WARNED/BANNED USERS & REASONS FROM DB ----------
$warnedUsers = [];
$sql = "
  SELECT u.id, u.name, u.email, u.forum_designation";

if ($hasBannedColumn) {
    $sql .= ", u.is_banned";
} else {
    $sql .= ", FALSE AS is_banned";
}

if ($hasWarningsColumn) {
    $sql .= ", COALESCE(u.warnings_count, 0) AS warnings_count";
} else {
    $sql .= ", 0 AS warnings_count";
}

if ($tableExists) {
    // Join with warning_reasons for reasons
    $sql .= "
      FROM users u
      LEFT JOIN warning_reasons wr ON u.id = wr.user_id
      WHERE u.warnings_count > 0";
    if ($hasBannedColumn)
        $sql .= " OR u.is_banned = TRUE";
    $sql .= "
      GROUP BY u.id
      ORDER BY u.id DESC
      LIMIT 100";
} else {
    // No table, just users with warnings
    $sql .= "
      FROM users u
      WHERE u.warnings_count > 0";
    if ($hasBannedColumn)
        $sql .= " OR u.is_banned = TRUE";
    $sql .= "
      ORDER BY u.id DESC
      LIMIT 100";
}

$stmt = $conn->prepare($sql);
if (!$stmt) {
    flash_set('danger', 'Query failed: ' . $conn->error);
} else {
    $stmt->execute();
    $stmt->bind_result($id, $name, $email, $forum_designation, $is_banned, $warnings_count);

    while ($stmt->fetch()) {
        $warnedUsers[] = [
            'id' => (int) $id,
            'name' => $name,
            'email' => $email,
            'forum_designation' => $forum_designation,
            'is_banned' => (bool) $is_banned,
            'warnings_count' => (int) $warnings_count,
        ];
    }
    $stmt->close();
}

// ---------- LOAD REASONS (From DB or Fallback File) ----------
foreach ($warnedUsers as &$wu) {
    if ($tableExists) {
        // DB: Get reasons
        $reasonStmt = $conn->prepare("SELECT reason, issued_at, issued_by FROM warning_reasons WHERE user_id = ? ORDER BY issued_at DESC");
        if ($reasonStmt) {
            $reasonStmt->bind_param("i", $wu['id']);
            $reasonStmt->execute();
            $result = $reasonStmt->get_result();
            $wu['reasons'] = [];
            while ($row = $result->fetch_assoc()) {
                $wu['reasons'][] = ['reason' => $row['reason'], 'date' => $row['issued_at'], 'issued_by' => $row['issued_by']];
            }
            $reasonStmt->close();
        }
    } else {
        // Fallback to file/session for testing
        $warningsLog = [];
        $logFile = __DIR__ . '/warnings.log';
        if (file_exists($logFile)) {
            $lines = file($logFile, FILE_IGNORE_NEW_LINES);
            foreach ($lines as $line) {
                $data = json_decode($line, true);
                if ($data && isset($data['userId'], $data['reason'], $data['date']) && $data['userId'] == $wu['id']) {
                    $warningsLog[] = ['reason' => $data['reason'], 'date' => $data['date'], 'issued_by' => $data['issued_by'] ?? 0];
                }
            }
        } else {
            $warningsLog = $_SESSION['user_warnings'][$wu['id']] ?? [];
        }
        $wu['reasons'] = $warningsLog;
    }

    // Pad if log count < DB count
    $logCount = count($wu['reasons']);
    if ($wu['warnings_count'] > $logCount) {
        for ($i = $logCount; $i < $wu['warnings_count']; $i++) {
            $wu['reasons'][] = ['reason' => 'Details not tracked', 'date' => '', 'issued_by' => 0];
        }
    }
}

$flash = flash_get();
?>

<div class="container py-4">
    
<?php require_once 'includes/admin_menu.php'; ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h3 mb-0">Admin - Warnings & Bans</h1>
      <div class="text-muted small">Review warned/banned users, reasons, and counts</div>
    </div>
    <a class="btn btn-secondary" href="admin.php">Back to Members</a>
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
              <th>ID</th>
              <th>Name</th>
              <th>Rank</th>
              <th>Warnings</th>
              <th>Reasons</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($warnedUsers as $wu): ?>
              <tr class="<?= ($wu['is_banned'] ? 'table-secondary opacity-75' : '') ?>">
                <td><span class="badge bg-light text-dark"><?= (int) $wu['id'] ?></span></td>
                <td>
                  <?php if (!empty($wu['email'])): ?>
                    <a href="mailto:<?= htmlspecialchars($wu['email']) ?>" class="text-decoration-none">
                      <?= htmlspecialchars($wu['name']) ?>
                    </a>
                  <?php else: ?>
                    <?= htmlspecialchars($wu['name']) ?>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($wu['forum_designation'] ?? 'N/A') ?></td>
                <td>
                  <span class="badge bg-warning text-dark"><?= (int) $wu['warnings_count'] ?></span>
                </td>
                <td>
                  <?php if (!empty($wu['reasons'])): ?>
                    <ul class="list-unstyled small mb-0">
                      <?php foreach ($wu['reasons'] as $r): ?>
                        <li><strong><?= htmlspecialchars($r['reason']) ?></strong> (<?= htmlspecialchars($r['date'] ?: 'Recent') ?> by User #<?= (int) $r['issued_by'] ?>)</li>
                      <?php endforeach; ?>
                    </ul>
                  <?php else: ?>
                    <small class="text-muted">None</small>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($wu['is_banned']): ?>
                    <span class="badge bg-danger">Banned</span>
                  <?php else: ?>
                    <span class="badge bg-success">Active</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="d-flex gap-1">
                    <?php if ($hasWarningsColumn && $wu['warnings_count'] > 0): ?>
                      <form method="post" action="admin_banning.php" class="m-0">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="unwarn_user">
                        <input type="hidden" name="user_id" value="<?= (int) $wu['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-warning" onclick="return confirm('Remove last warning?')">Unwarn</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>

            <?php if (!$warnedUsers): ?>
              <tr>
                <td colspan="7" class="text-center text-muted py-4">No warned or banned users found</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card-footer small text-muted">
      Data from DB counts and warning_reasons table (or log if not created). Unwarn removes count + last reason.
    </div>
  </div>

</div>

<?php require_once 'includes/footer.php'; ?>