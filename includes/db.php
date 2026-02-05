<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

$host = env('DB_HOST');
$user = env('DB_USER');
$pass = env('DB_PASS');
$name = env('DB_NAME');
$port = (int) (env('DB_PORT') ?: 3307);

if (!$host || !$user || !$name) {
    die('Database configuration missing (.env)');
}

$conn = new mysqli($host, $user, $pass, $name, $port);

if ($conn->connect_error) {
    die('Database connection failed');
}

$conn->set_charset('utf8mb4');

$githubToken = env('GITHUB_TOKEN');

// SMTP Configuration
define('SMTP_HOST', env('SMTP_HOST'));
define('SMTP_USER', env('SMTP_USER'));
define('SMTP_PASS', env('SMTP_PASS'));
define('SMTP_PORT', (int) env('SMTP_PORT'));
define('SMTP_SECURE', env('SMTP_SECURE'));
define('SMTP_FROM', env('SMTP_FROM') ?: env('SMTP_USER'));