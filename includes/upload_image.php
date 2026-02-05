<?php
// upload_image.php
require_once __DIR__ . 'auth.php';

header('Content-Type: application/json');

if (empty($_FILES['upload']) || $_FILES['upload']['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo json_encode(['error' => 'No file uploaded']);
  exit;
}

$f = $_FILES['upload'];

$allowed = [
  'image/jpeg' => 'jpg',
  'image/png'  => 'png',
  'image/webp' => 'webp',
  'image/gif'  => 'gif',
];

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($f['tmp_name']);

if (!isset($allowed[$mime])) {
  http_response_code(415);
  echo json_encode(['error' => 'Unsupported file type']);
  exit;
}

$ext = $allowed[$mime];
$dir = __DIR__ . '/uploads/forum_images';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$name = bin2hex(random_bytes(16)) . '.' . $ext;
$path = $dir . '/' . $name;

if (!move_uploaded_file($f['tmp_name'], $path)) {
  http_response_code(500);
  echo json_encode(['error' => 'Failed to save file']);
  exit;
}

// Return public URL
$url = '/uploads/forum_images/' . $name;
echo json_encode(['url' => $url]);