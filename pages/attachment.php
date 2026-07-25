<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$id = (int) get('id', 0);
$stmt = $pdo->prepare('SELECT * FROM attachments WHERE id = ?');
$stmt->execute([$id]);
$file = $stmt->fetch();
if (!$file) {
    http_response_code(404);
    exit('File not found');
}
$path = uploads_dir() . '/' . $file['stored_name'];
if (!is_file($path)) {
    http_response_code(404);
    exit('File missing on disk');
}
header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $file['original_name']) . '"');
header('Content-Length: ' . (string) filesize($path));
readfile($path);
exit;
