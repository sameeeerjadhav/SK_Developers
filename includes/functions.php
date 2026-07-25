<?php
declare(strict_types=1);

function app_config(?string $key = null, $default = null)
{
    static $cfg;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config/app.php';
    }
    if ($key === null) {
        return $cfg;
    }
    return $cfg[$key] ?? $default;
}

function base_url(string $path = ''): string
{
    $configured = rtrim((string) app_config('base_url', ''), '/');
    if ($configured !== '') {
        return $configured . '/' . ltrim($path, '/');
    }

    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = str_replace('\\', '/', dirname($script));
    // Climb out of /pages or /api so assets & root links stay correct
    $base = basename($dir);
    if ($base === 'pages' || $base === 'api') {
        $dir = dirname($dir);
    }
    if ($dir === '/' || $dir === '\\' || $dir === '.') {
        $dir = '';
    }
    return rtrim($dir, '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . base_url($path));
    exit;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money($amount): string
{
    return '₹' . number_format((float) $amount, 2);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
}

function post(string $key, $default = null)
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}

function get(string $key, $default = null)
{
    return isset($_GET[$key]) ? trim((string) $_GET[$key]) : $default;
}

function status_chip(string $status): string
{
    $map = [
        'active'    => 'success',
        'completed' => 'info',
        'planning'  => 'warning',
        'on_hold'   => 'danger',
        'closed'    => 'danger',
        'inactive'  => 'danger',
        'matured'   => 'info',
        'withdrawn' => 'warning',
        'credit'    => 'success',
        'debit'     => 'danger',
    ];
    $class = $map[$status] ?? 'primary';
    $label = ucwords(str_replace('_', ' ', $status));
    return '<span class="chip chip-' . e($class) . '">' . e($label) . '</span>';
}

function txn_type_chip(string $type): string
{
    if ($type === 'credit') {
        return '<span class="chip chip-success">Credit</span>';
    }
    return '<span class="chip chip-danger">Debit</span>';
}

function company_options(PDO $pdo, ?int $selected = null): string
{
    $rows = $pdo->query('SELECT id, name, type FROM companies WHERE status = "active" ORDER BY type ASC, name ASC')->fetchAll();
    $html = '<option value="">Select company</option>';
    foreach ($rows as $row) {
        $sel = ((int) $row['id'] === (int) $selected) ? ' selected' : '';
        $prefix = $row['type'] === 'main' ? '★ ' : '';
        $html .= '<option value="' . (int) $row['id'] . '"' . $sel . '>' . e($prefix . $row['name']) . '</option>';
    }
    return $html;
}

function project_options(PDO $pdo, ?int $companyId = null, ?int $selected = null): string
{
    if ($companyId) {
        $stmt = $pdo->prepare('SELECT id, name FROM projects WHERE company_id = ? ORDER BY name');
        $stmt->execute([$companyId]);
    } else {
        $stmt = $pdo->query('SELECT id, name FROM projects ORDER BY name');
    }
    $rows = $stmt->fetchAll();
    $html = '<option value="">All / none</option>';
    foreach ($rows as $row) {
        $sel = ((int) $row['id'] === (int) $selected) ? ' selected' : '';
        $html .= '<option value="' . (int) $row['id'] . '"' . $sel . '>' . e($row['name']) . '</option>';
    }
    return $html;
}

function category_options(PDO $pdo, ?string $section = null, ?int $selected = null): string
{
    if ($section) {
        $stmt = $pdo->prepare('SELECT id, name, section FROM categories WHERE section = ? ORDER BY sort_order');
        $stmt->execute([$section]);
    } else {
        $stmt = $pdo->query("SELECT id, name, section FROM categories WHERE section IN ('credit','land_purchase','expense') ORDER BY FIELD(section,'credit','land_purchase','expense'), sort_order");
    }
    $rows = $stmt->fetchAll();
    $html = '<option value="">Select category</option>';
    $labels = [
        'credit' => 'Credit',
        'land_purchase' => 'Land Purchase',
        'expense' => 'Expense',
    ];
    foreach ($rows as $row) {
        $sel = ((int) $row['id'] === (int) $selected) ? ' selected' : '';
        $group = $labels[$row['section']] ?? $row['section'];
        $html .= '<option value="' . (int) $row['id'] . '"' . $sel . '>[' . e($group) . '] ' . e($row['name']) . '</option>';
    }
    return $html;
}

function bank_account_options(PDO $pdo, ?int $companyId = null, ?int $selected = null): string
{
    if ($companyId) {
        $stmt = $pdo->prepare('SELECT id, account_name, bank_name FROM bank_accounts WHERE company_id = ? AND status = "active" ORDER BY account_name');
        $stmt->execute([$companyId]);
    } else {
        $stmt = $pdo->query('SELECT id, account_name, bank_name FROM bank_accounts WHERE status = "active" ORDER BY account_name');
    }
    $rows = $stmt->fetchAll();
    $html = '<option value="">None</option>';
    foreach ($rows as $row) {
        $sel = ((int) $row['id'] === (int) $selected) ? ' selected' : '';
        $html .= '<option value="' . (int) $row['id'] . '"' . $sel . '>' . e($row['account_name'] . ' — ' . $row['bank_name']) . '</option>';
    }
    return $html;
}

function partner_options(PDO $pdo, ?int $companyId = null, ?int $selected = null): string
{
    if ($companyId) {
        $stmt = $pdo->prepare('SELECT id, name FROM partners WHERE company_id = ? OR company_id IS NULL ORDER BY name');
        $stmt->execute([$companyId]);
    } else {
        $stmt = $pdo->query('SELECT id, name FROM partners ORDER BY name');
    }
    $rows = $stmt->fetchAll();
    $html = '<option value="">None</option>';
    foreach ($rows as $row) {
        $sel = ((int) $row['id'] === (int) $selected) ? ' selected' : '';
        $html .= '<option value="' . (int) $row['id'] . '"' . $sel . '>' . e($row['name']) . '</option>';
    }
    return $html;
}

function category_id_by_slug(PDO $pdo, string $section, string $slug): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM categories WHERE section = ? AND slug = ? LIMIT 1');
    $stmt->execute([$section, $slug]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

function find_category_id(PDO $pdo, ?int $explicitId = null, ?string $section = null, ?string $slug = null): ?int
{
    if ($explicitId) {
        return $explicitId;
    }
    if ($section && $slug) {
        return category_id_by_slug($pdo, $section, $slug);
    }
    return null;
}

function create_transaction(
    PDO $pdo,
    int $companyId,
    int $categoryId,
    string $txnType,
    float $amount,
    string $txnDate,
    ?int $projectId = null,
    ?int $bankAccountId = null,
    ?int $partnerId = null,
    ?string $reference = null,
    ?string $description = null,
    ?int $createdBy = null
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO transactions
        (company_id, project_id, bank_account_id, category_id, partner_id, txn_type, amount, txn_date, reference_no, description, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $companyId,
        $projectId,
        $bankAccountId,
        $categoryId,
        $partnerId,
        $txnType,
        $amount,
        $txnDate,
        $reference,
        $description,
        $createdBy,
    ]);
    return (int) $pdo->lastInsertId();
}

function sum_transactions(PDO $pdo, string $txnType, ?int $companyId = null, ?int $projectId = null, ?string $section = null): float
{
    $sql = 'SELECT COALESCE(SUM(t.amount),0) AS total
            FROM transactions t
            JOIN categories c ON c.id = t.category_id
            WHERE t.txn_type = ?';
    $params = [$txnType];

    if ($companyId) {
        $sql .= ' AND t.company_id = ?';
        $params[] = $companyId;
    }
    if ($projectId) {
        $sql .= ' AND t.project_id = ?';
        $params[] = $projectId;
    }
    if ($section) {
        $sql .= ' AND c.section = ?';
        $params[] = $section;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

function account_balance(PDO $pdo, int $accountId): float
{
    $stmt = $pdo->prepare('SELECT opening_balance FROM bank_accounts WHERE id = ?');
    $stmt->execute([$accountId]);
    $opening = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN txn_type = 'credit' THEN amount ELSE 0 END),0) AS credits,
        COALESCE(SUM(CASE WHEN txn_type = 'debit' THEN amount ELSE 0 END),0) AS debits
        FROM transactions WHERE bank_account_id = ?");
    $stmt->execute([$accountId]);
    $row = $stmt->fetch() ?: ['credits' => 0, 'debits' => 0];
    return $opening + (float) $row['credits'] - (float) $row['debits'];
}

function project_profit(PDO $pdo, int $projectId): float
{
    return sum_transactions($pdo, 'credit', null, $projectId) - sum_transactions($pdo, 'debit', null, $projectId);
}

function company_profit(PDO $pdo, int $companyId): float
{
    return sum_transactions($pdo, 'credit', $companyId) - sum_transactions($pdo, 'debit', $companyId);
}

function sum_by_category_slug(PDO $pdo, string $section, string $slug, ?int $companyId = null): float
{
    $sql = 'SELECT COALESCE(SUM(t.amount),0)
            FROM transactions t
            JOIN categories c ON c.id = t.category_id
            WHERE c.section = ? AND c.slug = ?';
    $params = [$section, $slug];
    if ($companyId) {
        $sql .= ' AND t.company_id = ?';
        $params[] = $companyId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

function scalar_sum(PDO $pdo, string $sql, array $params = []): float
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

function summary_totals(PDO $pdo, ?int $companyId = null): array
{
    $creditInvestment = sum_by_category_slug($pdo, 'credit', 'investment', $companyId);
    $creditPartner = sum_by_category_slug($pdo, 'credit', 'partner', $companyId);
    $creditBooking = sum_by_category_slug($pdo, 'credit', 'booking', $companyId);
    $expenses = sum_transactions($pdo, 'debit', $companyId, null, 'expense')
        + sum_transactions($pdo, 'debit', $companyId, null, 'land_purchase');
    $bankLoanCredits = sum_by_category_slug($pdo, 'credit', 'bank_loan', $companyId);

    $params = [];
    $loanSql = 'SELECT COALESCE(SUM(outstanding_amount),0) FROM bank_loans WHERE status = "active"';
    $assetSql = 'SELECT COALESCE(SUM(COALESCE(current_value, purchase_value)),0) FROM assets WHERE 1=1';
    $depositSql = 'SELECT COALESCE(SUM(amount),0) FROM deposits WHERE status = "active"';
    $bankOpenSql = 'SELECT COALESCE(SUM(opening_balance),0) FROM bank_accounts WHERE status = "active"';

    if ($companyId) {
        $loanSql .= ' AND company_id = ?';
        $assetSql .= ' AND company_id = ?';
        $depositSql .= ' AND company_id = ?';
        $bankOpenSql .= ' AND company_id = ?';
        $params = [$companyId];
    }

    $outstandingLoans = scalar_sum($pdo, $loanSql, $params);
    $assets = scalar_sum($pdo, $assetSql, $params);
    $deposits = scalar_sum($pdo, $depositSql, $params);

    // Live bank balances = openings + linked txn credits - debits
    $bankBalance = 0.0;
    if ($companyId) {
        $accStmt = $pdo->prepare('SELECT id FROM bank_accounts WHERE status = "active" AND company_id = ?');
        $accStmt->execute([$companyId]);
    } else {
        $accStmt = $pdo->query('SELECT id FROM bank_accounts WHERE status = "active"');
    }
    foreach ($accStmt->fetchAll() as $acc) {
        $bankBalance += account_balance($pdo, (int) $acc['id']);
    }

    $credits = sum_transactions($pdo, 'credit', $companyId);
    $debits = sum_transactions($pdo, 'debit', $companyId);

    return [
        'investment'   => $creditInvestment,
        'partner'      => $creditPartner,
        'booking'      => $creditBooking,
        'expense'      => $expenses,
        'bank_loans'   => $outstandingLoans > 0 ? $outstandingLoans : $bankLoanCredits,
        'loan_credits' => $bankLoanCredits,
        'assets'       => $assets,
        'deposits'     => $deposits,
        'bank_balance' => $bankBalance,
        'profit'       => $credits - $debits,
        'credits'      => $credits,
        'debits'       => $debits,
    ];
}

function section_breakdown(PDO $pdo, int $projectId, string $section): array
{
    $stmt = $pdo->prepare(
        'SELECT c.id, c.name, c.slug, c.sort_order,
                COALESCE(SUM(t.amount),0) AS total
         FROM categories c
         LEFT JOIN transactions t ON t.category_id = c.id AND t.project_id = ?
         WHERE c.section = ?
         GROUP BY c.id, c.name, c.slug, c.sort_order
         ORDER BY c.sort_order'
    );
    $stmt->execute([$projectId, $section]);
    return $stmt->fetchAll();
}

function sync_partner_invested(PDO $pdo, int $partnerId): void
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount),0) FROM transactions t
         JOIN categories c ON c.id = t.category_id
         WHERE t.partner_id = ? AND t.txn_type = 'credit' AND c.slug = 'partner'"
    );
    $stmt->execute([$partnerId]);
    $total = (float) $stmt->fetchColumn();
    $upd = $pdo->prepare('UPDATE partners SET invested_amount = ? WHERE id = ?');
    $upd->execute([$total, $partnerId]);
}
