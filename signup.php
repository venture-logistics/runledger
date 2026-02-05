<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/includes/db.php';

$pageTitle = "Sign up";
require_once __DIR__ . '/includes/header.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    if ($name === '' || $email === '' || $pass === '') {
        $errors[] = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    } elseif (strlen($pass) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    // Check email uniqueness
    if (!$errors) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        if (!$stmt) {
            $errors[] = 'Database error.';
        } else {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $errors[] = 'An account with this email already exists.';
            }
            $stmt->close();
        }
    }

    // Create user
    if (!$errors) {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $role = 'user';
        $designation = 'Member';
        $badgeClass = 'text-bg-secondary';

        $stmt = $conn->prepare("
        INSERT INTO users (name, email, password_hash, role, forum_designation, badge_class)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

        if (!$stmt) {
            $errors[] = 'Database error.';
        } else {
            $stmt->bind_param("ssssss", $name, $email, $hash, $role, $designation, $badgeClass);

            if ($stmt->execute()) {
                $_SESSION['user_id'] = (int) $stmt->insert_id;

                // Admin notification (keep existing email code)
                $adminEmail = 'info@pgat.co.uk';
                $siteName = 'Planning Support Forum';
                $fromEmail = 'no-reply@pgat.co.uk';

                $subject = "New forum member: {$name}";
                $message =
                    "A new member has joined the forum.\n\n" .
                    "Name: {$name}\n" .
                    "Email: {$email}\n" .
                    "Role: {$role}\n" .
                    "Time: " . date('Y-m-d H:i:s') . "\n";

                $headers =
                    "From: {$siteName} <{$fromEmail}>\r\n" .
                    "Reply-To: {$fromEmail}\r\n" .
                    "Content-Type: text/plain; charset=UTF-8\r\n";

                @mail($adminEmail, $subject, $message, $headers);

                $stmt->close();
                header("Location: /");
                exit;
            } else {
                $errors[] = 'Could not create account.';
                $stmt->close();
            }
        }
    }
}
?>

<div class="row justify-content-center">
  <div class="col-md-6 col-lg-5">

    <h1 class="h4 mb-3 text-center">Create an account</h1>

    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <ul class="mb-0">
          <?php foreach ($errors as $e): ?>
            <li><?= h($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" novalidate>
      <div class="mb-3">
        <label class="form-label">Name</label>
        <input type="text" name="name" class="form-control" required value="<?= h($_POST['name'] ?? '') ?>">
      </div>

      <div class="mb-3">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" required value="<?= h($_POST['email'] ?? '') ?>">
      </div>

      <div class="mb-3">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" required>
        <div class="form-text">Minimum 8 characters.</div>
      </div>

      <button class="btn btn-primary w-100">Create account</button>
    </form>

  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>