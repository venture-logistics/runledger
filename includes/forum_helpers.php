<?php
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function slugify($text){
  $text = strtolower(trim($text ?? ''));
  $text = preg_replace('~[^\pL\d]+~u', '-', $text);
  $text = preg_replace('~[^-\w]+~', '', $text);
  $text = trim($text, '-');
  $text = preg_replace('~-+~', '-', $text);
  return $text ?: 'topic';
}

function csrf_token(){
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  return $_SESSION['csrf'];
}
function csrf_ok($t){
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t ?? '');
}
