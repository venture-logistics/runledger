<?php
declare(strict_types = 1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    file_put_contents(__DIR__ . '/login_debug.txt', "POST HIT\n", FILE_APPEND);
}

auth_session_start();

// Already logged in? Only redirect if session is valid
$user = auth_user($conn);
if ($user) {
    header("Location: /");
    exit;
}


$errors = [];
$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass  = $_POST['password'] ?? '';

    if ($email === '' || $pass === '') {
        $errors[] = 'Email and password are required.';
    } else {

        $stmt = $conn->prepare("
            SELECT id, password_hash
            FROM users
            WHERE email = ?
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] = 'Login failed (query error).';
        } else {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->bind_result($id, $hash);

            if (!$stmt->fetch() || !password_verify($pass, $hash)) {
                $errors[] = 'Invalid email or password.';
            } else {
                auth_login((int)$id);

                $next = $_GET['next'] ?? '/';
                if (!is_string($next) || $next === '' || $next[0] !== '/') {
                    $next = '/';
                }

                header("Location: {$next}");
                exit;
            }

            $stmt->close();
        }
    }
}

$pageTitle = "Log in";

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-5" style="max-width:520px;">
    <h1 class="h3 mb-4">Log in</h1>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" class="card card-body shadow-sm">
        <div class="mb-3">
            <label class="form-label">Email</label>
            <input name="email" type="email" class="form-control" value="<?= htmlspecialchars($email) ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Password</label>
            <input name="password" type="password" class="form-control" required>
        </div>

        <button class="btn btn-primary w-100">Log in</button>

        <div class="text-center mt-3">
            <a href="/signup.php">Create an account</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
