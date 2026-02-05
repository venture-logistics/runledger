<?php
declare(strict_types = 1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);


if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$currentUser = auth_require($conn);

if (($currentUser['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$topicId = (int)($_POST['topic_id'] ?? 0);
if ($topicId <= 0) {
    http_response_code(400);
    exit('Bad request');
}

$conn->begin_transaction();

try {
    // Delete replies first
    $p = $conn->prepare("DELETE FROM forum_posts WHERE topic_id = ?");
    $p->bind_param("i", $topicId);
    $p->execute();
    $p->close();

    // Delete topic
    $t = $conn->prepare("DELETE FROM forum_topics WHERE id = ? LIMIT 1");
    $t->bind_param("i", $topicId);
    $t->execute();
    $t->close();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    exit('Delete failed');
}

header("Location: /index.php"); // adjust if needed
exit;