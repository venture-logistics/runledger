<?php
// /includes/download_counter.php
// Requires $conn (mysqli). Provides: download_get_count($slug)

if (!function_exists('download_get_count')) {
  function download_get_count(mysqli $conn, string $slug): int {
    try {
      $stmt = $conn->prepare("SELECT downloads FROM download_stats WHERE slug = ? LIMIT 1");
      if (!$stmt) return 0;
      $stmt->bind_param("s", $slug);
      $stmt->execute();
      $res = $stmt->get_result();
      $row = $res ? $res->fetch_assoc() : null;
      $stmt->close();
      return (int)($row['downloads'] ?? 0);
    } catch (Exception $e) {
      return 0;
    }
  }
}