<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

header('Content-Type: application/json');
$companyId = (int) get('company_id', 0);
if ($companyId <= 0) {
    echo json_encode([]);
    exit;
}
$stmt = $pdo->prepare('SELECT id, name FROM projects WHERE company_id = ? ORDER BY name');
$stmt->execute([$companyId]);
echo json_encode($stmt->fetchAll());
