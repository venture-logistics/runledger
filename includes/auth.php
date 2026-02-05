<?php
// /includes/auth.php
// Minimal auth helper. No header logic. No globals. No get_result().

function auth_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function auth_login(int $userId): void
{
    auth_session_start();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

function auth_logout(string $redirectTo = '/login.php'): void
{
    auth_session_start();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();

    header("Location: {$redirectTo}");
    exit;
}

/**
 * Returns user row (subset) or null if not logged in / not found.
 * Requires $conn (mysqli) passed in.
 */
function auth_user(mysqli $conn): ?array
{
    auth_session_start();

    if (empty($_SESSION['user_id'])) return null;

    $uid = (int)$_SESSION['user_id'];

    $stmt = $conn->prepare("SELECT id, role, name, email, forum_designation, forum_about, badge_class FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) return null;

    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $stmt->bind_result($id, $role, $name, $email, $forumDesignation, $forumAbout, $badgeClass);

    if (!$stmt->fetch()) {
        $stmt->close();
        unset($_SESSION['user_id']);
        return null;
    }
    $stmt->close();

    return [
        'id'                => (int)$id,
        'role'              => (string)$role,
        'name'              => (string)$name,
        'email'             => (string)$email,
        'forum_designation' => (string)$forumDesignation,
        'forum_about'       => (string)$forumAbout,
        'badge_class'       => (string)$badgeClass,
    ];
}

/**
 * Enforce login on a page. Optionally enforce roles.
 * Usage: $user = auth_require($conn); or auth_require($conn, ['admin','manager']);
 */
function auth_require(mysqli $conn, array $roles = []): array
{
    $user = auth_user($conn);

    if (!$user) {
        $next = $_SERVER['REQUEST_URI'] ?? '/';
        header("Location: /login.php?next=" . urlencode($next));
        exit;
    }

    if ($roles && !in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit("Forbidden.");
    }

    return $user;
}