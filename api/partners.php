<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

header('Content-Type: application/json');
$companyId = (int) get('company_id', 0);
if ($companyId <= 0) {
    $rows = $pdo->query('SELECT id, name FROM partners ORDER BY name')->fetchAll();
    echo json_encode($rows);
    exit;
}
$stmt = $pdo->prepare('SELECT id, name FROM partners WHERE company_id = ? OR company_id IS NULL ORDER BY name');
$stmt->execute([$companyId]);
echo json_encode($stmt->fetchAll());
