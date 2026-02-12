<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// -------------------- Paths --------------------
$installDir = __DIR__;
$rootDir    = dirname(__DIR__);                 // /public_html
$lockFile   = $installDir . '/install.lock';
$envFile    = $rootDir . '/.env';
$schemaFile = $installDir . '/schema.sql';
$seedFile   = $installDir . '/seed.sql';

// -------------------- Prevent re-install --------------------
if (file_exists($lockFile)) {
  http_response_code(403);
  echo "<h2>Installer locked</h2><p>This installation is already locked. Remove <code>/install/install.lock</code> to reinstall.</p>";
  exit;
}

// -------------------- Helpers --------------------
function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function is_https(): bool {
  return (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? '') == 443)
  );
}

function current_base_url(): string {
  $scheme = is_https() ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  return $scheme . '://' . $host;
}

function normalize_bool(string $v, bool $default = false): string {
  $v = strtolower(trim($v));
  if ($v === 'true' || $v === '1' || $v === 'yes' || $v === 'on') return 'true';
  if ($v === 'false' || $v === '0' || $v === 'no' || $v === 'off') return 'false';
  return $default ? 'true' : 'false';
}

function write_env(string $path, array $pairs): void {
  $lines = [];
  foreach ($pairs as $k => $v) {
    $k = trim((string)$k);
    $v = (string)$v;
    // Keep it simple: one key per line, no quoting rules.
    $lines[] = $k . '=' . $v;
  }
  $content = implode("\n", $lines) . "\n";
  if (file_put_contents($path, $content, LOCK_EX) === false) {
    throw new RuntimeException("Failed to write .env to: {$path}");
  }
  // Best-effort permissions; shared hosting may ignore.
  @chmod($path, 0640);
}

function get_columns(mysqli $conn, string $table): array {
  $cols = [];
  $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
  $res = $conn->query("SHOW COLUMNS FROM `{$safe}`");
  if (!$res) return $cols;
  while ($row = $res->fetch_assoc()) {
    $cols[] = $row['Field'];
  }
  return $cols;
}

function sql_run_file(mysqli $conn, string $filePath): void {
  if (!is_readable($filePath)) {
    throw new RuntimeException("SQL file not readable: {$filePath}");
  }

  $sql = file_get_contents($filePath);
  if ($sql === false) {
    throw new RuntimeException("Failed to read SQL file: {$filePath}");
  }

  // Strip BOM
  $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);

  // Remove /* ... */ comments
  $sql = preg_replace('~/\*.*?\*/~s', '', $sql);

  // Remove -- and # line comments
  $lines = explode("\n", $sql);
  $clean = [];
  foreach ($lines as $line) {
    $t = ltrim($line);
    if ($t === '' || str_starts_with($t, '--') || str_starts_with($t, '#')) continue;
    $clean[] = $line;
  }
  $sql = implode("\n", $clean);

  // Split into statements safely (basic; assumes no DELIMITER routines)
  $statements = [];
  $buf = '';
  $inStr = false;
  $strChar = '';
  $len = strlen($sql);

  for ($i = 0; $i < $len; $i++) {
    $ch = $sql[$i];

    if ($inStr) {
      // handle escaped quotes
      if ($ch === $strChar) {
        $prev = $sql[$i - 1] ?? '';
        if ($prev !== '\\') {
          $inStr = false;
          $strChar = '';
        }
      }
      $buf .= $ch;
      continue;
    }

    if ($ch === "'" || $ch === '"') {
      $inStr = true;
      $strChar = $ch;
      $buf .= $ch;
      continue;
    }

    if ($ch === ';') {
      $stmt = trim($buf);
      if ($stmt !== '') $statements[] = $stmt;
      $buf = '';
      continue;
    }

    $buf .= $ch;
  }

  $last = trim($buf);
  if ($last !== '') $statements[] = $last;

  $conn->begin_transaction();
  try {
    foreach ($statements as $statement) {
      $statement = trim($statement);
      if ($statement === '') continue;
      if ($conn->query($statement) === false) {
        throw new RuntimeException("SQL error: " . $conn->error . "\nStatement:\n" . $statement);
      }
    }
    $conn->commit();
  } catch (Throwable $e) {
    $conn->rollback();
    throw $e;
  }
}

function insert_dynamic(mysqli $conn, string $table, array $data): void {
  $cols = get_columns($conn, $table);
  if (!$cols) {
    throw new RuntimeException("Cannot detect columns for table: {$table}");
  }

  $insert = [];
  foreach ($data as $k => $v) {
    if (in_array($k, $cols, true)) {
      $insert[$k] = $v;
    }
  }

  if (!$insert) {
    // Nothing to insert, table exists but columns don’t match what we expected.
    return;
  }

  $fields = array_keys($insert);
  $place  = array_fill(0, count($fields), '?');

  $types = '';
  $vals  = [];

  foreach ($fields as $f) {
    $val = $insert[$f];
    if (is_int($val)) {
      $types .= 'i';
      $vals[] = $val;
    } else {
      $types .= 's';
      $vals[] = (string)$val;
    }
  }

  $sql = "INSERT INTO `{$table}` (`" . implode('`,`', $fields) . "`) VALUES (" . implode(',', $place) . ")";
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    throw new RuntimeException("Prepare failed for insert into {$table}: " . $conn->error);
  }
  $stmt->bind_param($types, ...$vals);
  $stmt->execute();
  $stmt->close();
}

// -------------------- Form handling --------------------
$errors = [];
$success = false;

$defaults = [
  'db_host' => 'localhost',
  'db_name' => '',
  'db_user' => '',
  'db_pass' => '',
  'smtp_host' => 'smtp.hostinger.com',
  'smtp_user' => '',
  'smtp_pass' => '',
  'smtp_port' => '587',
  'smtp_secure' => 'tls',
  'site_name' => 'Ledger – Open Support Framework',
  'admin_name' => 'Admin',
  'admin_email' => '',
  'admin_pass' => '',
];

$input = $defaults;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  foreach ($defaults as $k => $v) {
    $input[$k] = trim((string)($_POST[$k] ?? $v));
  }

  // Basic validation
  if ($input['db_host'] === '' || $input['db_name'] === '' || $input['db_user'] === '') {
    $errors[] = "Database host, name, and user are required.";
  }
  if ($input['admin_name'] === '' || $input['admin_pass'] === '') {
    $errors[] = "Admin name and password are required.";
  }
  if (!is_readable($schemaFile)) {
    $errors[] = "Missing schema file: /install/schema.sql";
  }

  if (!$errors) {
    // Connect to DB using provided creds
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli($input['db_host'], $input['db_user'], $input['db_pass'], $input['db_name']);
    if ($conn->connect_error) {
      $errors[] = "Database connection failed: " . $conn->connect_error;
    } else {
      $conn->set_charset('utf8mb4');

      try {
        // Run schema
        sql_run_file($conn, $schemaFile);

        // Optional seed
        if (is_readable($seedFile)) {
          sql_run_file($conn, $seedFile);
        }

        // Create site settings row (best-effort; only inserts matching columns)
        // If your table is named differently, rename it here to match your schema.sql.
        $siteTable = 'site_settings';
        $siteCols = get_columns($conn, $siteTable);
        if ($siteCols) {
          insert_dynamic($conn, $siteTable, [
            'id' => 1,
            'site_name' => $input['site_name'],
            'site_description' => '', // you said ignore description for now
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
          ]);
        }

        // Create admin user (best-effort; adapts to your users schema)
        $passHash = password_hash($input['admin_pass'], PASSWORD_DEFAULT);
        $usersTable = 'users';
        $userCols = get_columns($conn, $usersTable);
        if ($userCols) {
          // prefer password_hash, else password
          $adminData = [
            'name' => $input['admin_name'],
            'email' => $input['admin_email'],
            'role' => 'admin',
            'password_hash' => $passHash,
            'password' => $passHash,
            'created_at' => date('Y-m-d H:i:s'),
            'is_active' => 1,
          ];
          insert_dynamic($conn, $usersTable, $adminData);
        }

        // Write .env (root)
        $envPairs = [
          'APP_ENV' => 'production',
          'APP_DEBUG' => 'false',
          'APP_URL' => current_base_url(),

          'DB_HOST' => $input['db_host'],
          'DB_NAME' => $input['db_name'],
          'DB_USER' => $input['db_user'],
          'DB_PASS' => $input['db_pass'],

          'SMTP_HOST' => $input['smtp_host'],
          'SMTP_USER' => $input['smtp_user'],
          'SMTP_PASS' => $input['smtp_pass'],
          'SMTP_PORT' => $input['smtp_port'] !== '' ? $input['smtp_port'] : '587',
          'SMTP_SECURE' => $input['smtp_secure'] !== '' ? $input['smtp_secure'] : 'tls',
        ];

        write_env($envFile, $envPairs);

        // Write lock file
        if (file_put_contents($lockFile, 'installed ' . date('c') . "\n", LOCK_EX) === false) {
          throw new RuntimeException("Failed to write install lock: {$lockFile}");
        }

        $success = true;

        // Redirect to login (or home) after a short message
        header("Location: /login.php");
        exit;

      } catch (Throwable $e) {
        $errors[] = $e->getMessage();
      }
    }
  }
}

// -------------------- Render --------------------
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ledger Installer</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container py-5" style="max-width: 760px;">
    <div class="mb-4">
      <h1 class="h3 mb-1">Ledger Installer</h1>
      <div class="text-muted">Creates database schema, admin user, and writes <code>.env</code>.</div>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <div class="fw-semibold mb-2">Install failed</div>
        <ul class="mb-0">
          <?php foreach ($errors as $e): ?>
            <li><?= h($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="card shadow-sm">
      <div class="card-body">
        <form method="post" autocomplete="off">
          <h2 class="h5 mb-3">Database</h2>
          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label class="form-label">DB host</label>
              <input class="form-control" name="db_host" value="<?= h($input['db_host']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">DB name</label>
              <input class="form-control" name="db_name" value="<?= h($input['db_name']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">DB user</label>
              <input class="form-control" name="db_user" value="<?= h($input['db_user']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">DB password</label>
              <input class="form-control" type="password" name="db_pass" value="<?= h($input['db_pass']) ?>">
            </div>
          </div>

          <h2 class="h5 mb-3">SMTP</h2>
          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label class="form-label">SMTP host</label>
              <input class="form-control" name="smtp_host" value="<?= h($input['smtp_host']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">SMTP user</label>
              <input class="form-control" name="smtp_user" value="<?= h($input['smtp_user']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">SMTP password</label>
              <input class="form-control" type="password" name="smtp_pass" value="<?= h($input['smtp_pass']) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Port</label>
              <input class="form-control" name="smtp_port" value="<?= h($input['smtp_port']) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Secure</label>
              <select class="form-select" name="smtp_secure">
                <?php
                  $sec = $input['smtp_secure'] ?: 'tls';
                  foreach (['tls', 'ssl', 'none'] as $opt) {
                    $sel = ($sec === $opt) ? 'selected' : '';
                    echo "<option value=\"".h($opt)."\" {$sel}>".h($opt)."</option>";
                  }
                ?>
              </select>
            </div>
          </div>

          <h2 class="h5 mb-3">Site</h2>
          <div class="row g-3 mb-4">
            <div class="col-md-12">
              <label class="form-label">Site name</label>
              <input class="form-control" name="site_name" value="<?= h($input['site_name']) ?>">
              <div class="form-text">Saved into <code>site_settings</code> if that table exists.</div>
            </div>
          </div>

          <h2 class="h5 mb-3">Admin account</h2>
          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label class="form-label">Admin name</label>
              <input class="form-control" name="admin_name" value="<?= h($input['admin_name']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Admin email</label>
              <input class="form-control" name="admin_email" value="<?= h($input['admin_email']) ?>">
            </div>
            <div class="col-md-12">
              <label class="form-label">Admin password</label>
              <input class="form-control" type="password" name="admin_pass" value="<?= h($input['admin_pass']) ?>" required>
            </div>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">Install</button>
            <a class="btn btn-outline-secondary" href="<?= h(current_base_url()) ?>">Cancel</a>
          </div>

          <hr class="my-4">

          <div class="small text-muted">
            This installer will delete the <code>/install</code> folder after installation.
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>