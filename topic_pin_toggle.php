<?php
declare(strict_types = 1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$user = auth_require($conn, ['admin']); // role-gated

$topicId = (int)($_POST['topic_id'] ?? 0);
$csrf    = (string)($_POST['csrf_token'] ?? '');

if ($topicId <= 0) { http_response_code(400); exit('Bad request'); }
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(403);
    exit('CSRF failed');
}

$stmt = $conn->prepare("
  UPDATE forum_topics
  SET is_pinned = IF(is_pinned = 1, 0, 1),
      pinned_at = IF(is_pinned = 1, NULL, NOW())
  WHERE id = ?
  LIMIT 1
");
if (!$stmt) { http_response_code(500); exit('DB error: '.$conn->error); }

$stmt->bind_param("i", $topicId);
$stmt->execute();
$stmt->close();

$back = $_SERVER['HTTP_REFERER'] ?? '/index.php';
header("Location: " . $back);
exit;