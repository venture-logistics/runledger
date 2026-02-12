<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();
$currentUser = getCurrentUser($conn);

if (($currentUser['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$postId = (int)($_POST['post_id'] ?? 0);
$topicSlug = trim($_POST['topic_slug'] ?? '');

if ($postId <= 0 || $topicSlug === '') {
    http_response_code(400);
    exit('Bad request');
}

$stmt = $conn->prepare("DELETE FROM forum_posts WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $postId);
$stmt->execute();
$stmt->close();

header("Location: /index.php"); // adjust if needed
exit;