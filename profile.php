<?php

declare(strict_types = 1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/forum_helpers.php'; // for h()

if (!isset($conn) || !($conn instanceof mysqli)) {
  http_response_code(500);
  exit('Database connection missing');
}

// Hard gate: profile requires login (no auth.php, no getCurrentUser())
if (empty($_SESSION['user_id'])) {
  header('Location: /login.php');
  exit;
}

$pageTitle = "My profile";

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

$errors = [];
$success = [];

// Load fresh user row (NO get_result)
$uid = (int)$_SESSION['user_id'];

$stmt = $conn->prepare("
  SELECT id, name, email, role, forum_designation, forum_about, password_hash
  FROM users
  WHERE id = ?
  LIMIT 1
");
if (!$stmt) {
  http_response_code(500);
  exit('DB error: ' . $conn->error);
}

$stmt->bind_param("i", $uid);
$stmt->execute();
$stmt->bind_result($id, $name, $email, $role, $forum_designation, $forum_about, $password_hash);

if (!$stmt->fetch()) {
  $stmt->close();
  http_response_code(403);
  exit('User not found');
}

$stmt->close();

$user = [
  'id' => (int)$id,
  'name' => (string)$name,
  'email' => (string)$email,
  'role' => (string)$role,
  'forum_designation' => $forum_designation,
  'forum_about' => $forum_about,
  'password_hash' => (string)$password_hash,
];

// Optional: make header dropdown work if it expects $currentUser
$currentUser = $user;

require_once __DIR__ . '/includes/header.php';


// Handle updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postedToken = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals($csrf, $postedToken)) {
    $errors[] = "Security check failed. Please refresh and try again.";
  } else {
    $action = (string)($_POST['action'] ?? '');

    // ---- Update "Why I'm here" ----
    if ($action === 'update_about') {
      $about = trim((string)($_POST['forum_about'] ?? ''));

      // hard limits (also enforce client-side maxlength)
      if ($about !== '' && mb_strlen($about) > 240) {
        $errors[] = "“Why I’m here” must be 240 characters or less.";
      }

      if (!$errors) {
        $stmt = $conn->prepare("UPDATE users SET forum_about = ? WHERE id = ? LIMIT 1");
        if (!$stmt) { $errors[] = "DB error: ".$conn->error; }
        else {
          // store NULL when empty
          $aboutParam = ($about === '') ? null : $about;
          $stmt->bind_param("si", $aboutParam, $uid);
          $stmt->execute();
          $stmt->close();
          $success[] = "Updated “Why I’m here”.";
          $user['forum_about'] = $aboutParam;
        }
      }
    }

    // ---- Change password ----
    if ($action === 'change_password') {
      $current = (string)($_POST['current_password'] ?? '');
      $new1    = (string)($_POST['new_password'] ?? '');
      $new2    = (string)($_POST['new_password_confirm'] ?? '');

      if ($current === '' || $new1 === '' || $new2 === '') {
        $errors[] = "All password fields are required.";
      } elseif (!password_verify($current, (string)$user['password_hash'])) {
        $errors[] = "Current password is incorrect.";
      } elseif (strlen($new1) < 8) {
        $errors[] = "New password must be at least 8 characters.";
      } elseif ($new1 !== $new2) {
        $errors[] = "New passwords do not match.";
      }

      if (!$errors) {
        $hash = password_hash($new1, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ? LIMIT 1");
        if (!$stmt) { $errors[] = "DB error: ".$conn->error; }
        else {
          $stmt->bind_param("si", $hash, $uid);
          $stmt->execute();
          $stmt->close();
          $success[] = "Password updated.";
          $user['password_hash'] = $hash;
        }
      }
    }
  }
}

$backgroundOptions = [
  'Founding Member',
  'Developer',
  'Maintainer / contributor',
  'System administrator / ops',
  'Self-hosting user',
  'Using this in an organisation',
  'Evaluating / trialling',
  'End user',
  'Research / academic use',
  'Just exploring',
  'Other',
];

if (isset($_POST['background_perspective'])) {

    $bg = trim((string)($_POST['background_perspective'] ?? ''));

    // Allow only known values; otherwise keep current value
    $currentBackground = (string)($currentUser['background_perspective'] ?? '');
    if ($bg !== '' && !in_array($bg, $backgroundOptions, true)) {
        $bg = $currentBackground;
    }

    // Always bind a STRING; convert "" to NULL inside SQL
    $stmt = $conn->prepare(
        "UPDATE users
         SET background_perspective = NULLIF(?, '')
         WHERE id = ?
         LIMIT 1"
    );
    if (!$stmt) { http_response_code(500); exit('DB prepare failed: ' . $conn->error); }

    $uid = (int)$currentUser['id'];
    $stmt->bind_param("si", $bg, $uid);

    if (!$stmt->execute()) {
        http_response_code(500);
        exit('DB execute failed: ' . $stmt->error);
    }

    $stmt->close();

    // update session/cache so UI reflects immediately
    $currentUser['background_perspective'] = ($bg === '' ? null : $bg);
    if (isset($_SESSION['user'])) {
        $_SESSION['user']['background_perspective'] = ($bg === '' ? null : $bg);
    }
}

// Background / perspective
$background_perspective = trim($_POST['background_perspective'] ?? '');

// allow only known values (or blank)
if ($background_perspective !== '' && !in_array($background_perspective, $backgroundOptions, true)) {
  $background_perspective = '';
}

// Save (include this in your existing UPDATE)
$stmt = $conn->prepare("
  UPDATE users
  SET background_perspective = ?
  WHERE id = ?
  LIMIT 1
");
$stmt->bind_param("si", $background_perspective, $currentUser['id']);
$stmt->execute();
$stmt->close();

// Load purchased plugins
$purchasedPlugins = [];
$stmt = $conn->prepare("
  SELECT plugin_id, plugin_name, download_url
  FROM purchased_plugins
  WHERE user_id = ?
");
$stmt->bind_param("i", $uid);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
  $purchasedPlugins[] = [
    'id' => (int)$row['plugin_id'],
    'name' => (string)$row['plugin_name'],
    'download_url' => (string)$row['download_url'],
  ];
}
$stmt->close();

?>

<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h1 class="h4 mb-1">My profile</h1>
    <div class="text-muted small">
      Signed in as <strong><?= h($user['name']) ?></strong>
      <?php if (!empty($user['forum_designation'])): ?>
        <span class="badge text-bg-secondary ms-1"><?= h($user['forum_designation']) ?></span>
      <?php endif; ?>
      <?php if (($user['role'] ?? '') === 'admin'): ?>
        <span class="badge text-bg-warning ms-1">Admin</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="/my-topics.php">My topics</a>
    <a class="btn btn-primary btn-sm" href="/new_topic.php">New topic</a>
  </div>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($success): ?>
  <div class="alert alert-success">
    <ul class="mb-0">
      <?php foreach ($success as $m): ?><li><?= h($m) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="row g-4">

  <!-- Account details -->
  <div class="col-lg-5">
    <div class="card">
      <div class="card-body">
        <div class="fw-semibold mb-3">Account</div>

        <div class="mb-2">
          <div class="small text-muted">Name</div>
          <div><?= h($user['name']) ?></div>
        </div>

        <div class="mb-2">
          <div class="small text-muted">Email</div>
          <div><?= h($user['email']) ?></div>
        </div>

        <div class="mb-2">
          <div class="small text-muted">Role</div>
          <div><?= h($user['role']) ?></div>
        </div>

        <div class="mb-0">
          <div class="small text-muted">Forum designation</div>
          <div><?= h($user['forum_designation'] ?? 'Member') ?></div>
        </div>

      </div>
    </div>

    <!-- Purchased plugins -->
    <div class="card mt-3">
      <div class="card-body">
        <div class="fw-semibold mb-3">Purchased plugins</div>

        <?php if (empty($purchasedPlugins)): ?>
          <p>No purchased plugins.</p>
        <?php else: ?>
          <ul>
            <?php foreach ($purchasedPlugins as $plugin): ?>
              <li>
                <?= h($plugin['name']) ?>
                <a href="<?= h($plugin['download_url']) ?>" class="btn btn-sm btn-primary">Download</a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>


  </div>




  <!-- Why I'm here -->
  <div class="col-lg-7">
    <div class="card mb-4">
      <div class="card-body">
        <div class="fw-semibold mb-1">Why I’m here</div>
        <div class="small text-muted mb-3">
          One sentence on your focus and intent. Keep it factual. No personal details.
        </div>

        <form method="post" class="mb-0" novalidate>
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="update_about">
        
          <!-- Background / perspective -->
          <div class="mb-3">
            <label class="form-label">
              Background / perspective <span class="text-muted">(optional)</span>
            </label>
        
            <select name="background_perspective" class="form-select form-select-sm">
              <option value="">— Not specified —</option>
        
              <?php foreach ($backgroundOptions as $opt): ?>
                <option value="<?= h($opt) ?>"
                  <?= (($user['background_perspective'] ?? '') === $opt ? 'selected' : '') ?>>
                  <?= h($opt) ?>
                </option>
              <?php endforeach; ?>
            </select>
        
            <div class="form-text">
              Shown under “Why I’m here” to give readers context.
            </div>
          </div>
        
          <!-- Why I'm here -->
          <textarea
            name="forum_about"
            class="form-control"
            rows="3"
            maxlength="240"
            placeholder="Example: I'm here to discover more about Ledger, so long as it's polite and not offensive, anything is fine."
          ><?= h((string)($user['forum_about'] ?? '')) ?></textarea>
        
          <div class="d-flex justify-content-between align-items-center mt-2">
            <div class="small text-muted">Max 240 characters.</div>
            <button class="btn btn-primary btn-sm">Save</button>
          </div>
        </form>

      </div>
    </div>

    <!-- Change password -->
    <div class="card">
      <div class="card-body">
        <div class="fw-semibold mb-3">Change password</div>

        <form method="post" class="mb-0" novalidate>
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="change_password">

          <div class="mb-3">
            <label class="form-label">Current password</label>
            <input type="password" name="current_password" class="form-control" autocomplete="current-password">
          </div>

          <div class="mb-3">
            <label class="form-label">New password</label>
            <input type="password" name="new_password" class="form-control" autocomplete="new-password">
            <div class="form-text">Minimum 8 characters.</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Confirm new password</label>
            <input type="password" name="new_password_confirm" class="form-control" autocomplete="new-password">
          </div>

          <button class="btn btn-outline-primary btn-sm">Update password</button>
        </form>
      </div>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>