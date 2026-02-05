<?php
declare(strict_types = 1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// DEBUG SCRIPT - Check installer.zip location
// Upload this to the same directory as download_file.php and access via browser
// Example: http://yoursite.com/check_installer_debug.php

echo "<!DOCTYPE html><html><head><title>Installer Debug</title></head><body>";
echo "<h2>Installer.zip Debug Information</h2>";

$filepath = __DIR__ . '/downloads/installer.zip';

echo "<strong>Current script location (__DIR__):</strong> " . htmlspecialchars(__DIR__) . "<br><br>";

echo "<strong>Looking for file at:</strong><br>" . htmlspecialchars($filepath) . "<br><br>";

echo "<h3>File Checks:</h3>";
echo "✓ <strong>File exists?</strong> " . (file_exists($filepath) ? '<span style="color:green">YES</span>' : '<span style="color:red">NO</span>') . "<br>";
echo "✓ <strong>Is a file?</strong> " . (is_file($filepath) ? '<span style="color:green">YES</span>' : '<span style="color:red">NO</span>') . "<br>";
echo "✓ <strong>Is readable?</strong> " . (is_readable($filepath) ? '<span style="color:green">YES</span>' : '<span style="color:red">NO</span>') . "<br>";

if (file_exists($filepath)) {
    $size = filesize($filepath);
    $sizeGB = round($size / (1024*1024*1024), 2);
    echo "✓ <strong>File size:</strong> " . $sizeGB . " GB (" . number_format($size) . " bytes)<br>";
}

echo "<br><h3>Directory Checks:</h3>";
$downloadsDir = __DIR__ . '/downloads';
echo "✓ <strong>Downloads directory exists?</strong> " . (is_dir($downloadsDir) ? '<span style="color:green">YES</span>' : '<span style="color:red">NO</span>') . "<br>";

if (is_dir($downloadsDir)) {
    echo "<br><h3>Files in downloads directory:</h3>";
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse'>";
    echo "<tr><th>Filename</th><th>Size</th><th>Type</th></tr>";
    
    $files = scandir($downloadsDir);
    $fileCount = 0;
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $fileCount++;
            $fullpath = $downloadsDir . '/' . $file;
            $type = is_file($fullpath) ? 'File' : (is_dir($fullpath) ? 'Directory' : 'Unknown');
            
            if (is_file($fullpath)) {
                $size = filesize($fullpath);
                $sizeFormatted = round($size / (1024*1024*1024), 2) . ' GB';
            } else {
                $sizeFormatted = '-';
            }
            
            echo "<tr>";
            echo "<td>" . htmlspecialchars($file) . "</td>";
            echo "<td>" . $sizeFormatted . "</td>";
            echo "<td>" . $type . "</td>";
            echo "</tr>";
        }
    }
    
    if ($fileCount === 0) {
        echo "<tr><td colspan='3'><em>Directory is empty</em></td></tr>";
    }
    
    echo "</table>";
} else {
    echo "<br><span style='color:red;font-weight:bold'>⚠ The 'downloads' directory does not exist!</span>";
    echo "<br><br><strong>Solution:</strong> Create a 'downloads' directory in: " . htmlspecialchars(__DIR__);
}

echo "<br><br><h3>Possible Solutions:</h3>";
echo "<ol>";
echo "<li><strong>If directory doesn't exist:</strong> Create it<br><code>mkdir " . htmlspecialchars(__DIR__ . '/downloads') . "</code></li>";
echo "<li><strong>If file doesn't exist:</strong> Move installer.zip to the downloads directory</li>";
echo "<li><strong>If file exists but not readable:</strong> Fix permissions<br><code>chmod 644 " . htmlspecialchars($filepath) . "</code></li>";
echo "<li><strong>Check file spelling:</strong> Make sure it's named exactly 'installer.zip' (case-sensitive on Linux)</li>";
echo "</ol>";

echo "</body></html>";
?>