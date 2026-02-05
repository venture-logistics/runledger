<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$currentUser = auth_require($conn);

// CSRF
if (
  empty($_POST['csrf_token']) ||
  !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])
) {
  http_response_code(400);
  exit('Bad request');
}

$docId = (int)($_POST['document_id'] ?? 0);
if ($docId <= 0) {
  http_response_code(400);
  exit('Invalid document');
}

// Insert flag (ignore duplicates)
$stmt = $conn->prepare("
  INSERT INTO knowledge_flags (document_id, user_id)
  VALUES (?, ?)
  ON DUPLICATE KEY UPDATE created_at = created_at
");
$stmt->bind_param("ii", $docId, $currentUser['id']);
$stmt->execute();
$stmt->close();

header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '/'));
exit;
