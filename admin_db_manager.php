<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$currentUser = auth_require($conn, ['admin']); // admin only

if (!isset($conn) || !($conn instanceof mysqli)) {
  http_response_code(500);
  exit('DB connection not available.');
}

mysqli_report(MYSQLI_REPORT_OFF);

function qident(mysqli $conn, string $name): string {
  // Backtick-quote identifier safely
  return '`' . str_replace('`', '``', $name) . '`';
}

// --------- INPUTS ----------
$tab = (string)($_GET['table'] ?? '');
$view = (string)($_GET['view'] ?? 'browse'); // browse | structure | sql
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$search = trim((string)($_GET['search'] ?? ''));
$sqlText = trim((string)($_POST['sql'] ?? ''));

// --------- LIST TABLES ----------
$tables = [];
$res = $conn->query("SHOW TABLES");
if ($res) {
  while ($row = $res->fetch_row()) $tables[] = (string)$row[0];
  $res->free();
}
sort($tables);

// Default to first table if none chosen
if ($tab === '' && count($tables) > 0) $tab = $tables[0];

// Validate table exists
if ($tab !== '' && !in_array($tab, $tables, true)) {
  $tab = '';
}

$alert = null;
$alertType = 'info';

// --------- HANDLE SQL RUN ----------
$sqlResult = null;
$sqlCols = [];
$sqlRows = [];
$affectedRows = null;
$backupFile = null;

// Function to detect destructive queries
function is_destructive_query(string $sql): bool {
  $sql = trim($sql);
  return (bool)preg_match('/^\s*(DELETE|DROP|TRUNCATE|ALTER.*DROP)\s/i', $sql);
}

// Function to create database backup
function create_db_backup(mysqli $conn): ?string {
  $backupDir = __DIR__ . '/backups';
  
  // Create backups directory if it doesn't exist
  if (!is_dir($backupDir)) {
    if (!mkdir($backupDir, 0755, true)) {
      return null;
    }
    
    // Add .htaccess for security
    $htaccess = $backupDir . '/.htaccess';
    if (!file_exists($htaccess)) {
      file_put_contents($htaccess, "Deny from all\n");
    }
  }
  
  // Generate backup filename with timestamp
  $timestamp = date('Y-m-d_H-i-s');
  $backupFile = $backupDir . '/db_backup_' . $timestamp . '.sql';
  
  // Get database credentials
  $host = env('DB_HOST') ?? '127.0.0.1';
  $port = env('DB_PORT') ?? '3307';
  $user = env('DB_USER');
  $pass = env('DB_PASS');
  $name = env('DB_NAME');
  
  // Build mysqldump command
  $command = sprintf(
    'mysqldump --host=%s --port=%s --user=%s --password=%s %s > %s 2>&1',
    escapeshellarg($host),
    escapeshellarg($port),
    escapeshellarg($user),
    escapeshellarg($pass),
    escapeshellarg($name),
    escapeshellarg($backupFile)
  );
  
  // Execute backup
  exec($command, $output, $returnCode);
  
  if ($returnCode !== 0 || !file_exists($backupFile) || filesize($backupFile) === 0) {
    // Backup failed, clean up empty file
    if (file_exists($backupFile)) {
      unlink($backupFile);
    }
    return null;
  }
  
  return basename($backupFile);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $view === 'sql') {
  if ($sqlText === '') {
    $alertType = 'warning';
    $alert = "Please enter a SQL query.";
  } else {
    // Split multiple statements by semicolon
    $statements = array_filter(array_map('trim', explode(';', $sqlText)), function($s) {
      return $s !== '';
    });
    
    if (empty($statements)) {
      $alertType = 'warning';
      $alert = "Please enter a SQL query.";
    } else {
      // Check if any statement is destructive
      $hasDestructive = false;
      foreach ($statements as $stmt) {
        if (is_destructive_query($stmt)) {
          $hasDestructive = true;
          break;
        }
      }
      
      // Create backup before destructive queries
      if ($hasDestructive) {
        $backupFile = create_db_backup($conn);
        if (!$backupFile) {
          $alertType = 'warning';
          $alert = "Warning: Could not create automatic backup. Proceeding with query anyway. Consider creating a manual backup via phpMyAdmin.";
        }
      }
      
      // Execute all statements
      $executedCount = 0;
      $totalAffected = 0;
      $errors = [];
      $statementCount = count($statements);
      
      foreach ($statements as $index => $stmt) {
        $r = $conn->query($stmt);
        
        if ($r === false) {
          $errors[] = "Statement " . ($executedCount + 1) . ": " . $conn->error;
        } else {
          $executedCount++;
          
          if ($r instanceof mysqli_result) {
            // For the last SELECT query, keep results to display
            $isLastStatement = ($index === $statementCount - 1);
            
            if ($isLastStatement) {
              $sqlResult = $r;
              $f = $r->fetch_fields();
              foreach ($f as $field) $sqlCols[] = $field->name;
              $max = 500;
              $i = 0;
              while (($row = $r->fetch_assoc()) && $i < $max) {
                $sqlRows[] = $row;
                $i++;
              }
              // Don't free - we're using this result below
            } else {
              // Not the last statement, free it
              $r->free();
            }
          } else {
            $totalAffected += $conn->affected_rows;
          }
        }
      }
      
      // Build result message
      if (!empty($errors)) {
        $alertType = 'danger';
        $alert = "Errors occurred:<br>" . implode("<br>", $errors);
        if ($executedCount > 0) {
          $alert .= "<br>Successfully executed: {$executedCount} statement(s)";
        }
      } else {
        $alertType = 'success';
        
        if (!empty($sqlRows)) {
          $alert = "Executed {$executedCount} statement(s). Showing results from last query (up to 500 rows).";
        } else {
          $alert = "Executed {$executedCount} statement(s) successfully.";
          if ($totalAffected > 0) {
            $alert .= " Total rows affected: {$totalAffected}";
          }
        }
        
        if ($backupFile) {
          $alert .= " ✓ Automatic backup created: {$backupFile}";
        }
      }
    }
  }
}

// --------- STRUCTURE ----------
$columns = [];
$indexes = [];
if ($tab !== '' && $view === 'structure') {
  $q = "SHOW FULL COLUMNS FROM " . qident($conn, $tab);
  $r = $conn->query($q);
  if ($r) {
    while ($row = $r->fetch_assoc()) $columns[] = $row;
    $r->free();
  }

  $q = "SHOW INDEX FROM " . qident($conn, $tab);
  $r = $conn->query($q);
  if ($r) {
    while ($row = $r->fetch_assoc()) $indexes[] = $row;
    $r->free();
  }
}

// --------- BROWSE ROWS ----------
$rows = [];
$cols = [];
$totalRows = null;

if ($tab !== '' && $view === 'browse') {
  // Get columns for display (limit to first N columns)
  $r = $conn->query("SHOW COLUMNS FROM " . qident($conn, $tab));
  if ($r) {
    while ($c = $r->fetch_assoc()) $cols[] = $c['Field'];
    $r->free();
  }

  // Build search clause across first ~8 columns to keep it sane
  $where = '';
  $params = [];
  $types = '';

  if ($search !== '' && count($cols) > 0) {
    $scanCols = array_slice($cols, 0, 8);
    $likeParts = [];
    foreach ($scanCols as $c) {
      $likeParts[] = qident($conn, $c) . " LIKE ?";
      $params[] = '%' . $search . '%';
      $types .= 's';
    }
    $where = " WHERE (" . implode(' OR ', $likeParts) . ")";
  }

  // Count total rows (for pagination)
  $countSql = "SELECT COUNT(*) AS c FROM " . qident($conn, $tab) . $where;
  if ($where === '') {
    $rc = $conn->query($countSql);
    if ($rc) {
      $totalRows = (int)($rc->fetch_assoc()['c'] ?? 0);
      $rc->free();
    }
  } else {
    $stmt = $conn->prepare($countSql);
    if ($stmt) {
      $stmt->bind_param($types, ...$params);
      $stmt->execute();
      $resC = $stmt->get_result();
      if ($resC) {
        $totalRows = (int)($resC->fetch_assoc()['c'] ?? 0);
        $resC->free();
      }
      $stmt->close();
    }
  }

  // Row query
  $dataSql = "SELECT * FROM " . qident($conn, $tab) . $where . " LIMIT {$perPage} OFFSET {$offset}";
  if ($where === '') {
    $rd = $conn->query($dataSql);
    if ($rd) {
      while ($row = $rd->fetch_assoc()) $rows[] = $row;
      $rd->free();
    }
  } else {
    $stmt = $conn->prepare($dataSql);
    if ($stmt) {
      $stmt->bind_param($types, ...$params);
      $stmt->execute();
      $resD = $stmt->get_result();
      if ($resD) {
        while ($row = $resD->fetch_assoc()) $rows[] = $row;
        $resD->free();
      }
      $stmt->close();
    }
  }
}

$pageTitle = "Docs quality";

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    
<?php require_once 'includes/admin_menu.php'; ?> 

<div class="row g-3">
    <div class="col-12 col-lg-3">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <div class="fw-semibold">Tables</div>
          <span class="badge text-bg-secondary"><?= count($tables) ?></span>
        </div>
        <div class="card-body sidebar p-2">
          <?php if (!$tables): ?>
            <div class="text-muted p-2">No tables found.</div>
          <?php else: ?>
            <div class="list-group list-group-flush">
              <?php foreach ($tables as $t): ?>
                <?php
                  $active = ($t === $tab) ? 'active' : '';
                  $url = '?table=' . urlencode($t) . '&view=' . urlencode($view);
                ?>
                <a class="list-group-item list-group-item-action <?= $active ?>" href="<?= h($url) ?>">
                  <span class="mono"><?= h($t) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="card-footer small text-muted">
          <strong>⚠️ Full SQL access enabled</strong> - You can modify/delete data and structure. 
          <a target="_blank" href="https://runledger.org/knowledge_document.php?doc=knowledge-db-use"><strong>Read the guide before using</strong></a> | 
          <a target="_blank" href="https://github.com/venture-logistics/ledger/blob/main/install/schema.sql">Download fresh schema</a> if needed.
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-9">
      <div class="card">
        <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
          <div class="fw-semibold">
            <?= $tab ? ('Table: <span class="mono">' . h($tab) . '</span>') : 'No table selected' ?>
          </div>
          <div class="btn-group">
            <a class="btn btn-outline-primary btn-sm <?= $view==='browse'?'active':'' ?>" href="?table=<?= h(urlencode($tab)) ?>&view=browse">Browse</a>
            <a class="btn btn-outline-primary btn-sm <?= $view==='structure'?'active':'' ?>" href="?table=<?= h(urlencode($tab)) ?>&view=structure">Structure</a>
            <a class="btn btn-outline-primary btn-sm <?= $view==='sql'?'active':'' ?>" href="?table=<?= h(urlencode($tab)) ?>&view=sql">SQL</a>
          </div>
        </div>

        <div class="card-body">
          <?php if ($alert): ?>
            <div class="alert alert-<?= h($alertType) ?>"><?= h($alert) ?></div>
          <?php endif; ?>

          <?php if ($view === 'browse'): ?>

            <form class="row g-2 align-items-end mb-3" method="get">
              <input type="hidden" name="table" value="<?= h($tab) ?>">
              <input type="hidden" name="view" value="browse">
              <div class="col-12 col-md-6">
                <label class="form-label">Search</label>
                <input class="form-control" name="search" value="<?= h($search) ?>" placeholder="Search first few columns…">
              </div>
              <div class="col-6 col-md-3">
                <label class="form-label">Page</label>
                <input class="form-control" name="page" type="number" min="1" value="<?= h((string)$page) ?>">
              </div>
              <div class="col-6 col-md-3 d-grid">
                <button class="btn btn-primary">Apply</button>
              </div>
            </form>

            <?php if ($totalRows !== null): ?>
              <div class="small text-muted mb-2">
                Rows: <?= (int)$totalRows ?> • Showing <?= count($rows) ?> • Page <?= (int)$page ?>
              </div>
            <?php endif; ?>

            <div class="table-responsive">
              <table class="table table-sm table-striped align-middle">
                <thead>
                  <tr>
                    <?php if ($rows): ?>
                      <?php foreach (array_keys($rows[0]) as $c): ?>
                        <th class="small mono"><?= h($c) ?></th>
                      <?php endforeach; ?>
                    <?php elseif ($cols): ?>
                      <?php foreach ($cols as $c): ?>
                        <th class="small mono"><?= h($c) ?></th>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$rows): ?>
                    <tr><td class="text-muted" colspan="999">No rows found.</td></tr>
                  <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                      <tr>
                        <?php foreach ($r as $v): ?>
                          <?php
                            if (is_null($v)) $out = '<span class="text-muted">NULL</span>';
                            else {
                              $s = (string)$v;
                              if (strlen($s) > 300) $s = substr($s, 0, 300) . '…';
                              $out = h($s);
                            }
                          ?>
                          <td class="cell mono"><?= $out ?></td>
                        <?php endforeach; ?>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <?php
              if ($totalRows !== null) {
                $pages = max(1, (int)ceil($totalRows / $perPage));
                $prev = max(1, $page - 1);
                $next = min($pages, $page + 1);
                $base = '?table=' . urlencode($tab) . '&view=browse&search=' . urlencode($search) . '&page=';
              }
            ?>
            <?php if ($totalRows !== null && $pages > 1): ?>
              <div class="d-flex gap-2 align-items-center">
                <a class="btn btn-outline-secondary btn-sm <?= $page<=1?'disabled':'' ?>" href="<?= h($base.$prev) ?>">Prev</a>
                <div class="small text-muted">Page <?= (int)$page ?> of <?= (int)$pages ?></div>
                <a class="btn btn-outline-secondary btn-sm <?= $page>=$pages?'disabled':'' ?>" href="<?= h($base.$next) ?>">Next</a>
              </div>
            <?php endif; ?>

          <?php elseif ($view === 'structure'): ?>

            <h6 class="mb-2">Columns</h6>
            <div class="table-responsive mb-4">
              <table class="table table-sm table-bordered">
                <thead>
                  <tr>
                    <th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th><th>Collation</th><th>Comment</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($columns as $c): ?>
                    <tr>
                      <td class="mono"><?= h($c['Field'] ?? '') ?></td>
                      <td class="mono"><?= h($c['Type'] ?? '') ?></td>
                      <td><?= h($c['Null'] ?? '') ?></td>
                      <td><?= h($c['Key'] ?? '') ?></td>
                      <td class="mono"><?= h($c['Default'] ?? '') ?></td>
                      <td><?= h($c['Extra'] ?? '') ?></td>
                      <td class="mono"><?= h($c['Collation'] ?? '') ?></td>
                      <td><?= h($c['Comment'] ?? '') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <h6 class="mb-2">Indexes</h6>
            <div class="table-responsive">
              <table class="table table-sm table-bordered">
                <thead>
                  <tr>
                    <th>Key_name</th><th>Non_unique</th><th>Seq_in_index</th><th>Column_name</th><th>Index_type</th><th>Cardinality</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$indexes): ?>
                    <tr><td colspan="6" class="text-muted">No indexes found.</td></tr>
                  <?php else: ?>
                    <?php foreach ($indexes as $i): ?>
                      <tr>
                        <td class="mono"><?= h($i['Key_name'] ?? '') ?></td>
                        <td><?= h($i['Non_unique'] ?? '') ?></td>
                        <td><?= h($i['Seq_in_index'] ?? '') ?></td>
                        <td class="mono"><?= h($i['Column_name'] ?? '') ?></td>
                        <td><?= h($i['Index_type'] ?? '') ?></td>
                        <td><?= h($i['Cardinality'] ?? '') ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

          <?php else: // SQL ?>

            <div class="alert alert-warning mb-3" role="alert">
              <h6 class="alert-heading mb-2">⚠️ Full SQL Access - Use With Caution</h6>
              <p class="mb-2">
                This tool allows <strong>unrestricted SQL execution</strong> including INSERT, UPDATE, DELETE, ALTER, CREATE, and DROP commands. 
                You can permanently modify or delete data and table structures.
              </p>
              <p class="mb-0">
                <strong>Before using this tool:</strong> Read the 
                <a href="https://runledger.org/knowledge_document.php?doc=knowledge-db-use" target="_blank" class="alert-link">
                  <strong>Database Management Guide</strong>
                </a>
                 | If you break something: 
                <a href="https://github.com/venture-logistics/ledger/blob/main/install/schema.sql" target="_blank" class="alert-link">
                  <strong>Download fresh schema</strong>
                </a>
              </p>
            </div>

            <form method="post" class="mb-3">
              <div class="mb-2">
                <label class="form-label fw-semibold">SQL Query</label>
                <textarea class="form-control mono" name="sql" rows="8" placeholder="SELECT * FROM users LIMIT 50;
-- Multiple statements allowed (separate with semicolons):
ALTER TABLE users ADD COLUMN new_field VARCHAR(255);
UPDATE users SET new_field = 'default' WHERE id = 1;
-- or single statement:
INSERT INTO table_name (col1, col2) VALUES ('val1', 'val2')"><?= h($sqlText) ?></textarea>
                <div class="form-text">
                  <strong>Multiple statements allowed</strong> - separate with semicolons. 
                  All SQL operations allowed: SELECT, INSERT, UPDATE, DELETE, ALTER, CREATE, DROP, etc.
                  Automatic backup created before destructive operations.
                </div>
              </div>
              <button class="btn btn-primary">Execute Query</button>
            </form>

            <?php if ($sqlCols): ?>
              <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                  <thead>
                    <tr>
                      <?php foreach ($sqlCols as $c): ?>
                        <th class="small mono"><?= h($c) ?></th>
                      <?php endforeach; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($sqlRows as $r): ?>
                      <tr>
                        <?php foreach ($sqlCols as $c): ?>
                          <?php
                            $v = $r[$c] ?? null;
                            if (is_null($v)) $out = '<span class="text-muted">NULL</span>';
                            else {
                              $s = (string)$v;
                              if (strlen($s) > 300) $s = substr($s, 0, 300) . '…';
                              $out = h($s);
                            }
                          ?>
                          <td class="cell mono"><?= $out ?></td>
                        <?php endforeach; ?>
                      </tr>
                    <?php endforeach; ?>
                    <?php if (!$sqlRows): ?>
                      <tr><td class="text-muted" colspan="999">No rows returned.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

          <?php endif; ?>
        </div>
      </div>

      <div class="small text-muted mt-2">
        <strong>Pro tip:</strong> For routine tasks, use dedicated admin pages. This tool is for advanced operations, migrations, and debugging.
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>