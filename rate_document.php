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

$docId  = (int)($_POST['document_id'] ?? 0);
$rating = (int)($_POST['rating'] ?? 0);

if ($docId <= 0 || $rating < 1 || $rating > 5) {
  http_response_code(400);
  exit('Invalid rating');
}

// Insert or update rating
$stmt = $conn->prepare("
  INSERT INTO knowledge_ratings (document_id, user_id, rating)
  VALUES (?, ?, ?)
  ON DUPLICATE KEY UPDATE
    rating = VALUES(rating),
    created_at = NOW()
");
$stmt->bind_param("iii", $docId, $currentUser['id'], $rating);
$stmt->execute();
$stmt->close();

// Redirect back
header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '/'));
exit;