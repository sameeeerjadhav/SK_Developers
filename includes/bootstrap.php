<?php
declare(strict_types=1);

session_start();

$config = require __DIR__ . '/../config/app.php';
date_default_timezone_set($config['timezone']);

$dbConfig = require __DIR__ . '/../config/database.php';

try {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $dbConfig['host'],
        $dbConfig['dbname'],
        $dbConfig['charset']
    );
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem">';
    echo '<h1>Database connection failed</h1>';
    echo '<p>Update <code>config/database.php</code> with your Hostinger MySQL credentials, then import <code>sql/schema.sql</code>.</p>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre></body></html>';
    exit;
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
