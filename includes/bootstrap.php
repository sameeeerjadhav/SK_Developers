<?php
declare(strict_types=1);

// Secure session cookies before starting
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$config = require __DIR__ . '/../config/app.php';
date_default_timezone_set($config['timezone']);

$dbFile = __DIR__ . '/../config/database.php';
$installLock = __DIR__ . '/../config/installed.lock';

if (!is_file($dbFile)) {
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
        header('Location: install.php');
        exit;
    }
    $pdo = null;
    require_once __DIR__ . '/functions.php';
    require_once __DIR__ . '/auth.php';
    return;
}

$dbConfig = require $dbFile;

try {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $dbConfig['host'],
        $dbConfig['dbname'],
        $dbConfig['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script !== 'install.php') {
        http_response_code(500);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Setup needed</title></head><body style="font-family:Plus Jakarta Sans,system-ui,sans-serif;padding:2rem;background:#edf7f5;color:#0b1f1c">';
        echo '<div style="max-width:560px;margin:2rem auto;background:#fff;border:1px solid #e2efec;border-radius:16px;padding:1.5rem">';
        echo '<h1 style="margin-top:0">Database connection failed</h1>';
        echo '<p>Update <code>config/database.php</code> or run the installer.</p>';
        echo '<p><a href="install.php" style="color:#0d9488;font-weight:700">Open installer →</a></p>';
        echo '</div></body></html>';
        exit;
    }
    $pdo = null;
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
