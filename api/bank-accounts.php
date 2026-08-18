<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $companyId = (int) ($data['company_id'] ?? 0);
    $accountName = trim((string) ($data['account_name'] ?? ''));
    $bankName = trim((string) ($data['bank_name'] ?? ''));
    if ($companyId <= 0 || $accountName === '' || $bankName === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Company, account name and bank name are required.']);
        exit;
    }
    $stmt = $pdo->prepare('INSERT INTO bank_accounts (company_id, account_name, bank_name, account_number, ifsc, opening_balance, notes, status) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $companyId,
        $accountName,
        $bankName,
        trim((string) ($data['account_number'] ?? '')),
        trim((string) ($data['ifsc'] ?? '')),
        round((float) ($data['opening_balance'] ?? 0), 2),
        trim((string) ($data['notes'] ?? '')),
        'active',
    ]);
    $id = (int) $pdo->lastInsertId();
    echo json_encode(['id' => $id, 'account_name' => $accountName, 'bank_name' => $bankName]);
    exit;
}

$companyId = (int) get('company_id', 0);
if ($companyId <= 0) {
    echo json_encode([]);
    exit;
}
$stmt = $pdo->prepare('SELECT id, account_name, bank_name FROM bank_accounts WHERE company_id = ? AND status = "active" ORDER BY account_name');
$stmt->execute([$companyId]);
echo json_encode($stmt->fetchAll());
