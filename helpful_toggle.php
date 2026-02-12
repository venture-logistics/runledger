<?php

ini_set('display_errors','1');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$currentUser = auth_require($conn); // must be logged in to vote

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$postId = (int)($_POST['post_id'] ?? 0);
$csrf   = (string)($_POST['csrf_token'] ?? '');

if ($postId <= 0) {
    http_response_code(400);
    exit('Bad request (missing post_id)');
}

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(403);
    exit('CSRF failed');
}

$userId = (int)$currentUser['id'];

// Get topic slug for redirect + basic post existence
$stmt = $conn->prepare("
    SELECT p.id, t.slug AS topic_slug
    FROM forum_posts p
    JOIN forum_topics t ON t.id = p.topic_id
    WHERE p.id = ? AND p.is_deleted = 0 AND t.is_deleted = 0
    LIMIT 1
");
$stmt->bind_param("i", $postId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    exit('Post not found');
}

$topicSlug = (string)$row['topic_slug'];

// Toggle Helpful
$conn->begin_transaction();

try {
    // Try add vote
    $stmt = $conn->prepare("INSERT IGNORE INTO forum_post_helpful (post_id, user_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $postId, $userId);
    $stmt->execute();
    $added = ($stmt->affected_rows === 1);
    $stmt->close();

    if ($added) {
        $stmt = $conn->prepare("UPDATE forum_posts SET helpful_count = helpful_count + 1 WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $postId);
        $stmt->execute();
        $stmt->close();
    } else {
        // Remove vote
        $stmt = $conn->prepare("DELETE FROM forum_post_helpful WHERE post_id = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param("ii", $postId, $userId);
        $stmt->execute();
        $removed = ($stmt->affected_rows === 1);
        $stmt->close();

        if ($removed) {
            $stmt = $conn->prepare("
                UPDATE forum_posts
                SET helpful_count = IF(helpful_count > 0, helpful_count - 1, 0)
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->bind_param("i", $postId);
            $stmt->execute();
            $stmt->close();
        }
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    exit('Database error');
}

header("Location: /topic.php?t=" . urlencode($topicSlug) . "#post-" . $postId);
exit;
