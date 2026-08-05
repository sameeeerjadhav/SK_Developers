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

/**
 * Format number using Indian digit grouping (lakhs/crores).
 * Example: 1000000 => 10,00,000
 */
function indian_number_format(float $number, int $decimals = 2): string
{
    $negative = $number < 0;
    $abs = abs($number);

    // Keep fixed decimals without any thousands separators.
    $fixed = sprintf('%.' . $decimals . 'f', $abs);
    $parts = explode('.', $fixed, 2);
    $intPart = $parts[0];
    $decPart = $parts[1] ?? '';

    $len = strlen($intPart);
    if ($len <= 3) {
        $grouped = $intPart;
    } else {
        $last3 = substr($intPart, -3);
        $rest = substr($intPart, 0, -3);
        $restLen = strlen($rest);
        $firstGroupLen = $restLen % 2; // 0 or 1

        $groups = [];
        if ($firstGroupLen > 0) {
            $groups[] = substr($rest, 0, $firstGroupLen);
        }
        for ($i = $firstGroupLen; $i < $restLen; $i += 2) {
            $groups[] = substr($rest, $i, 2);
        }

        $grouped = implode(',', $groups) . ',' . $last3;
    }

    $result = $grouped;
    if ($decimals > 0) {
        $result .= '.' . $decPart;
    }

    return ($negative ? '-' : '') . $result;
}

function money($amount): string
{
    return '₹' . indian_number_format((float) $amount, 2);
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

/** @return array{0:?string,1:?string,2:string,3:string} [from, to, monthYm, year] */
function period_from_request(): array
{
    $month = get('month', '');
    $year = get('year', '');
    if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $from = $month . '-01';
        $to = date('Y-m-t', strtotime($from));
        return [$from, $to, $month, substr($month, 0, 4)];
    }
    if (is_string($year) && preg_match('/^\d{4}$/', $year)) {
        return [$year . '-01-01', $year . '-12-31', '', $year];
    }
    $from = get('from', '');
    $to = get('to', '');
    if ($from !== '' || $to !== '') {
        return [$from !== '' ? $from : null, $to !== '' ? $to : null, '', ''];
    }
    return [null, null, '', ''];
}

function period_filter_fields(string $selectedMonth = '', string $selectedYear = ''): string
{
    $html = '<div class="field"><label>Month</label><select name="month" onchange="this.form.year.value=\'\';this.form.submit()">';
    $html .= '<option value="">All months</option>';
    $now = new DateTime('first day of this month');
    for ($i = 0; $i < 36; $i++) {
        $ym = $now->format('Y-m');
        $label = $now->format('M Y');
        $sel = ($selectedMonth === $ym) ? ' selected' : '';
        $html .= '<option value="' . e($ym) . '"' . $sel . '>' . e($label) . '</option>';
        $now->modify('-1 month');
    }
    $html .= '</select></div>';

    $html .= '<div class="field"><label>Year</label><select name="year" onchange="this.form.month.value=\'\';this.form.submit()">';
    $html .= '<option value="">All years</option>';
    $yNow = (int) date('Y');
    for ($y = $yNow; $y >= $yNow - 8; $y--) {
        $sel = ($selectedYear === (string) $y && $selectedMonth === '') ? ' selected' : '';
        $html .= '<option value="' . $y . '"' . $sel . '>' . $y . '</option>';
    }
    $html .= '</select></div>';
    return $html;
}

function format_date(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $ts = strtotime($date);
    return $ts ? date('d-m-Y', $ts) : $date;
}

function period_label(?string $from, ?string $to, string $month = '', string $year = ''): string
{
    if ($month !== '') {
        return date('F Y', strtotime($month . '-01'));
    }
    if ($year !== '') {
        return 'Year ' . $year;
    }
    if ($from || $to) {
        return ($from ?: '…') . ' → ' . ($to ?: '…');
    }
    return 'All time';
}

/** @deprecated use period_filter_fields */
function month_filter_fields(string $selectedMonth = '', bool $preserveGets = true): string
{
    return period_filter_fields($selectedMonth, '');
}

function apply_date_range(string &$sql, array &$params, ?string $from, ?string $to, string $column = 't.txn_date'): void
{
    if ($from) {
        $sql .= " AND {$column} >= ?";
        $params[] = $from;
    }
    if ($to) {
        $sql .= " AND {$column} <= ?";
        $params[] = $to;
    }
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 64);
}

function audit_log(PDO $pdo, string $action, string $entityType, ?int $entityId, string $summary, $before = null, $after = null): void
{
    try {
        $user = current_user();
        $stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, user_name, action, entity_type, entity_id, summary, before_json, after_json, ip_address) VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $user['id'] ?? null,
            $user['name'] ?? null,
            $action,
            $entityType,
            $entityId,
            substr($summary, 0, 255),
            $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
            client_ip(),
        ]);
    } catch (Throwable $e) {
        // never break main flow
    }
}

/** Per-request cache for expensive aggregates. */
function summary_totals_cache_key(?int $companyId, ?string $from, ?string $to): string
{
    return ($companyId ?? 0) . '|' . ($from ?? '') . '|' . ($to ?? '');
}

/**
 * Company profit (credits − debits) in one query. Keys = company_id.
 *
 * @return array<int,float>
 */
function company_profits_bulk(PDO $pdo, ?string $from = null, ?string $to = null): array
{
    $sql = 'SELECT company_id,
            COALESCE(SUM(CASE WHEN txn_type = "credit" THEN amount ELSE 0 END), 0)
          - COALESCE(SUM(CASE WHEN txn_type = "debit" THEN amount ELSE 0 END), 0) AS profit
            FROM transactions WHERE 1=1';
    $params = [];
    apply_date_range($sql, $params, $from, $to);
    $sql .= ' GROUP BY company_id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int) $row['company_id']] = (float) $row['profit'];
    }
    return $out;
}

/**
 * @return array<int,int> company_id => project count
 */
function company_project_counts(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT company_id, COUNT(*) AS cnt FROM projects GROUP BY company_id') as $row) {
        $out[(int) $row['company_id']] = (int) $row['cnt'];
    }
    return $out;
}

/**
 * All active bank account balances in one query (per request cache).
 *
 * @return array<int,float> account_id => balance
 */
function account_balance_map(PDO $pdo): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $sql = 'SELECT ba.id, ba.company_id,
            ba.opening_balance
            + COALESCE(SUM(CASE WHEN t.txn_type = "credit" THEN t.amount WHEN t.txn_type = "debit" THEN -t.amount ELSE 0 END), 0) AS balance
            FROM bank_accounts ba
            LEFT JOIN transactions t ON t.bank_account_id = ba.id
            WHERE ba.status = "active"
            GROUP BY ba.id, ba.company_id, ba.opening_balance';
    $map = [];
    foreach ($pdo->query($sql) as $row) {
        $map[(int) $row['id']] = (float) $row['balance'];
    }
    return $map;
}

function total_bank_balance(PDO $pdo, ?int $companyId = null): float
{
    $map = account_balance_map($pdo);
    if (!$companyId) {
        return array_sum($map);
    }
    $stmt = $pdo->prepare('SELECT id FROM bank_accounts WHERE status = "active" AND company_id = ?');
    $stmt->execute([$companyId]);
    $total = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $total += $map[(int) $id] ?? 0.0;
    }
    return $total;
}

function notification_count(PDO $pdo): int
{
    return count(system_notifications($pdo));
}

function setup_progress(PDO $pdo): array
{
    $accounts = (int) $pdo->query('SELECT COUNT(*) FROM bank_accounts')->fetchColumn();
    $projects = (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
    $txns = (int) $pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
    $steps = [
        ['key' => 'account', 'label' => 'Add a bank account', 'done' => $accounts > 0, 'href' => 'pages/bank-accounts.php?action=add'],
        ['key' => 'project', 'label' => 'Create a project', 'done' => $projects > 0, 'href' => 'pages/projects.php?action=add'],
        ['key' => 'txn', 'label' => 'Record first transaction', 'done' => $txns > 0, 'href' => 'pages/transactions.php?action=add'],
    ];
    $done = count(array_filter($steps, fn($s) => $s['done']));
    return ['steps' => $steps, 'done' => $done, 'total' => count($steps), 'complete' => $done === count($steps)];
}

function system_notifications(PDO $pdo, bool $forceRefresh = false): array
{
    static $requestCache = null;
    if ($requestCache !== null && !$forceRefresh) {
        return $requestCache;
    }

    $ttl = 300; // 5 minutes — avoids heavy queries on every page navigation
    if (
        !$forceRefresh
        && isset($_SESSION['notif_cache'], $_SESSION['notif_cache_at'])
        && is_array($_SESSION['notif_cache'])
        && (time() - (int) $_SESSION['notif_cache_at']) < $ttl
    ) {
        $requestCache = $_SESSION['notif_cache'];
        return $requestCache;
    }

    $notes = [];
    try {
        $due = $pdo->query("SELECT e.*, l.lender_name FROM loan_emis e JOIN bank_loans l ON l.id = e.loan_id WHERE e.status IN ('pending','partial') AND e.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY e.due_date ASC LIMIT 10")->fetchAll();
        foreach ($due as $row) {
            $notes[] = [
                'type' => strtotime($row['due_date']) < strtotime('today') ? 'danger' : 'warning',
                'title' => 'EMI due — ' . $row['lender_name'],
                'body' => 'Installment #' . $row['installment_no'] . ' · ' . money($row['amount'] - $row['paid_amount']) . ' · due ' . $row['due_date'],
                'href' => 'pages/loan-view.php?id=' . $row['loan_id'],
            ];
        }
    } catch (Throwable $e) {
    }

    try {
        $deps = $pdo->query("SELECT * FROM deposits WHERE status='active' AND maturity_date IS NOT NULL AND maturity_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) ORDER BY maturity_date ASC LIMIT 8")->fetchAll();
        foreach ($deps as $d) {
            $notes[] = [
                'type' => 'info',
                'title' => 'Deposit maturing — ' . $d['title'],
                'body' => money($d['amount']) . ' · maturity ' . $d['maturity_date'],
                'href' => 'pages/deposits.php?action=edit&id=' . $d['id'],
            ];
        }
    } catch (Throwable $e) {
    }

    try {
        $lowBal = $pdo->query(
            'SELECT ba.id, ba.account_name,
                    ba.opening_balance
                    + COALESCE(SUM(CASE WHEN t.txn_type = "credit" THEN t.amount WHEN t.txn_type = "debit" THEN -t.amount ELSE 0 END), 0) AS balance
             FROM bank_accounts ba
             LEFT JOIN transactions t ON t.bank_account_id = ba.id
             WHERE ba.status = "active"
             GROUP BY ba.id, ba.account_name, ba.opening_balance
             HAVING balance < 10000
             ORDER BY balance ASC
             LIMIT 10'
        )->fetchAll();
        foreach ($lowBal as $acc) {
            $bal = (float) $acc['balance'];
            $notes[] = [
                'type' => 'warning',
                'title' => 'Low balance — ' . $acc['account_name'],
                'body' => 'Live balance ' . money($bal),
                'href' => 'pages/bank-account-view.php?id=' . $acc['id'],
            ];
        }
    } catch (Throwable $e) {
    }

    $_SESSION['notif_cache'] = $notes;
    $_SESSION['notif_cache_at'] = time();
    $requestCache = $notes;
    return $notes;
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
        'cancelled' => 'danger',
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

function bank_account_options(PDO $pdo, ?int $companyId = null, ?int $selected = null, string $noneLabel = 'None'): string
{
    if ($companyId) {
        $stmt = $pdo->prepare('SELECT id, account_name, bank_name FROM bank_accounts WHERE company_id = ? AND status = "active" ORDER BY account_name');
        $stmt->execute([$companyId]);
    } else {
        $stmt = $pdo->query('SELECT id, account_name, bank_name FROM bank_accounts WHERE status = "active" ORDER BY account_name');
    }
    $rows = $stmt->fetchAll();
    $html = '<option value="">' . e($noneLabel) . '</option>';
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
    ?int $createdBy = null,
    ?int $investorId = null,
    ?float $interestAmount = null,
    ?string $payeeName = null
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO transactions
        (company_id, project_id, bank_account_id, category_id, partner_id, investor_id, interest_amount, txn_type, amount, txn_date, reference_no, payee_name, description, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $companyId,
        $projectId,
        $bankAccountId,
        $categoryId,
        $partnerId,
        $investorId,
        $interestAmount,
        $txnType,
        $amount,
        $txnDate,
        $reference,
        $payeeName,
        $description,
        $createdBy,
    ]);
    return (int) $pdo->lastInsertId();
}

function sum_transactions(
    PDO $pdo,
    string $txnType,
    ?int $companyId = null,
    ?int $projectId = null,
    ?string $section = null,
    ?string $from = null,
    ?string $to = null
): float {
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
    apply_date_range($sql, $params, $from, $to);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

function sum_by_category_slug(
    PDO $pdo,
    string $section,
    string $slug,
    ?int $companyId = null,
    ?string $from = null,
    ?string $to = null
): float {
    $sql = 'SELECT COALESCE(SUM(t.amount),0)
            FROM transactions t
            JOIN categories c ON c.id = t.category_id
            WHERE c.section = ? AND c.slug = ?';
    $params = [$section, $slug];
    if ($companyId) {
        $sql .= ' AND t.company_id = ?';
        $params[] = $companyId;
    }
    apply_date_range($sql, $params, $from, $to);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

function summary_totals(PDO $pdo, ?int $companyId = null, ?string $from = null, ?string $to = null): array
{
    static $cache = [];
    $cacheKey = summary_totals_cache_key($companyId, $from, $to);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $creditInvestment = sum_by_category_slug($pdo, 'credit', 'investment', $companyId, $from, $to)
        + sum_by_category_slug($pdo, 'credit', 'daily_credit', $companyId, $from, $to)
        + sum_by_category_slug($pdo, 'credit', 'monthly_credit', $companyId, $from, $to);
    $creditPartner = sum_by_category_slug($pdo, 'credit', 'partner', $companyId, $from, $to)
        + sum_by_category_slug($pdo, 'credit', 'partner_capital', $companyId, $from, $to)
        + sum_by_category_slug($pdo, 'credit', 'partner_advance', $companyId, $from, $to);
    $creditBooking = sum_by_category_slug($pdo, 'credit', 'booking', $companyId, $from, $to);
    $expenses = sum_transactions($pdo, 'debit', $companyId, null, 'expense', $from, $to)
        + sum_transactions($pdo, 'debit', $companyId, null, 'land_purchase', $from, $to);
    $bankLoanCredits = sum_by_category_slug($pdo, 'credit', 'bank_loan', $companyId, $from, $to);

    $params = [];
    $loanSql = 'SELECT COALESCE(SUM(outstanding_amount),0) FROM bank_loans WHERE status = "active"';
    $assetSql = 'SELECT COALESCE(SUM(COALESCE(current_value, purchase_value)),0) FROM assets WHERE 1=1';
    $depositSql = 'SELECT COALESCE(SUM(amount),0) FROM deposits WHERE status = "active"';

    if ($companyId) {
        $loanSql .= ' AND company_id = ?';
        $assetSql .= ' AND company_id = ?';
        $depositSql .= ' AND company_id = ?';
        $params = [$companyId];
    }

    $outstandingLoans = scalar_sum($pdo, $loanSql, $params);
    $assets = scalar_sum($pdo, $assetSql, $params);
    $deposits = scalar_sum($pdo, $depositSql, $params);

    $bankBalance = total_bank_balance($pdo, $companyId);

    $credits = sum_transactions($pdo, 'credit', $companyId, null, null, $from, $to);
    $debits = sum_transactions($pdo, 'debit', $companyId, null, null, $from, $to);

    $cache[$cacheKey] = [
        'investment'   => $creditInvestment,
        'partner'      => $creditPartner,
        'booking'      => $creditBooking,
        'expense'      => $expenses,
        'bank_loans'   => $outstandingLoans > 0 ? $outstandingLoans : $bankLoanCredits,
        'loan_credits' => $bankLoanCredits,
        'assets'       => $assets,
        'deposits'     => $deposits,
        'bank_balance' => $bankBalance,
        'cash_balance' => cash_balance($pdo, $companyId),
        'profit'       => $credits - $debits,
        'credits'      => $credits,
        'debits'       => $debits,
    ];
    return $cache[$cacheKey];
}

function uploads_dir(): string
{
    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function save_transaction_uploads(PDO $pdo, int $transactionId, array $files, ?int $userId): int
{
    if (empty($files['name']) || !is_array($files['name'])) {
        return 0;
    }
    $allowed = ['image/jpeg','image/png','image/webp','image/gif','application/pdf'];
    $count = 0;
    $dir = uploads_dir();
    $n = count($files['name']);
    for ($i = 0; $i < $n; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        if (($files['size'][$i] ?? 0) > 5 * 1024 * 1024) {
            continue;
        }
        $tmp = $files['tmp_name'][$i];
        $mime = $files['type'][$i] ?? '';
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->file($tmp);
            if ($detected) {
                $mime = $detected;
            }
        }
        if (!in_array($mime, $allowed, true)) {
            continue;
        }
        $ext = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
        $ext = preg_replace('/[^a-zA-Z0-9]/', '', (string) $ext) ?: 'bin';
        $stored = 'txn_' . $transactionId . '_' . bin2hex(random_bytes(8)) . '.' . strtolower($ext);
        if (!move_uploaded_file($tmp, $dir . '/' . $stored)) {
            continue;
        }
        $stmt = $pdo->prepare('INSERT INTO attachments (transaction_id, original_name, stored_name, mime_type, size_bytes, uploaded_by) VALUES (?,?,?,?,?,?)');
        $stmt->execute([
            $transactionId,
            $files['name'][$i],
            $stored,
            $mime,
            (int) $files['size'][$i],
            $userId,
        ]);
        $count++;
    }
    return $count;
}

function refresh_loan_outstanding(PDO $pdo, int $loanId): void
{
    $loan = $pdo->prepare('SELECT loan_amount FROM bank_loans WHERE id = ?');
    $loan->execute([$loanId]);
    $loanAmount = (float) $loan->fetchColumn();

    $paid = $pdo->prepare('SELECT COALESCE(SUM(principal_paid),0) FROM loan_emis WHERE loan_id = ?');
    $paid->execute([$loanId]);
    $emiPrincipal = (float) $paid->fetchColumn();
    // Fallback if older EMI payments have no principal split
    if ($emiPrincipal <= 0) {
        $paid2 = $pdo->prepare('SELECT COALESCE(SUM(paid_amount),0) FROM loan_emis WHERE loan_id = ?');
        $paid2->execute([$loanId]);
        $emiPrincipal = (float) $paid2->fetchColumn();
    }

    $repayPaid = $pdo->prepare('SELECT COALESCE(SUM(principal_amount),0) FROM loan_repayments WHERE loan_id = ?');
    $repayPaid->execute([$loanId]);
    $repayPrincipal = (float) $repayPaid->fetchColumn();

    $principalPaid = $emiPrincipal + $repayPrincipal;
    $outstanding = max(0, $loanAmount - $principalPaid);
    $status = $outstanding <= 0.01 ? 'closed' : 'active';
    $pdo->prepare('UPDATE bank_loans SET outstanding_amount = ?, status = ? WHERE id = ?')
        ->execute([$outstanding, $status, $loanId]);
}

/**
 * Replaces a loan's borrower list. Pass an array of rows, each with keys:
 * name, account_number, loan_amount, outstanding_amount, interest_charges,
 * start_date, end_date, mortgage_noc_date, reconveyance_date.
 * Rows with a blank name are skipped.
 */
function sync_loan_borrowers(PDO $pdo, int $loanId, array $borrowers): void
{
    $pdo->prepare('DELETE FROM loan_borrowers WHERE loan_id = ?')->execute([$loanId]);
    $ins = $pdo->prepare(
        'INSERT INTO loan_borrowers
        (loan_id, name, account_number, loan_amount, outstanding_amount, interest_charges, start_date, end_date, mortgage_noc_date, reconveyance_date)
        VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    $num = function ($v) {
        return $v !== '' && $v !== null ? (float) $v : null;
    };
    $date = function ($v) {
        return $v !== '' && $v !== null ? $v : null;
    };
    foreach ($borrowers as $b) {
        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $accountNumber = trim((string) ($b['account_number'] ?? ''));
        $ins->execute([
            $loanId,
            $name,
            $accountNumber !== '' ? $accountNumber : null,
            $num($b['loan_amount'] ?? null),
            $num($b['outstanding_amount'] ?? null),
            $num($b['interest_charges'] ?? null),
            $date($b['start_date'] ?? null),
            $date($b['end_date'] ?? null),
            $date($b['mortgage_noc_date'] ?? null),
            $date($b['reconveyance_date'] ?? null),
        ]);
    }
}

function account_balance(PDO $pdo, int $accountId): float
{
    $map = account_balance_map($pdo);
    if (isset($map[$accountId])) {
        return $map[$accountId];
    }

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

/** Running cash-in-hand balance — transactions with no bank account linked. */
function cash_balance(PDO $pdo, ?int $companyId = null): float
{
    $sql = "SELECT
        COALESCE(SUM(CASE WHEN txn_type = 'credit' THEN amount ELSE 0 END),0) AS credits,
        COALESCE(SUM(CASE WHEN txn_type = 'debit' THEN amount ELSE 0 END),0) AS debits
        FROM transactions WHERE bank_account_id IS NULL";
    $params = [];
    if ($companyId) {
        $sql .= ' AND company_id = ?';
        $params[] = $companyId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch() ?: ['credits' => 0, 'debits' => 0];
    return (float) $row['credits'] - (float) $row['debits'];
}

function scalar_sum(PDO $pdo, string $sql, array $params = []): float
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

function project_profit(PDO $pdo, int $projectId): float
{
    return sum_transactions($pdo, 'credit', null, $projectId) - sum_transactions($pdo, 'debit', null, $projectId);
}

function company_profit(PDO $pdo, int $companyId): float
{
    return sum_transactions($pdo, 'credit', $companyId) - sum_transactions($pdo, 'debit', $companyId);
}

function section_breakdown(PDO $pdo, int $projectId, string $section): array
{
    $stmt = $pdo->prepare(
        'SELECT c.id, c.name, c.slug, c.sort_order,
                COALESCE(SUM(t.amount),0) AS total,
                COALESCE(SUM(CASE WHEN t.bank_account_id IS NULL THEN t.amount ELSE 0 END),0) AS cash_total,
                COALESCE(SUM(CASE WHEN t.bank_account_id IS NOT NULL THEN t.amount ELSE 0 END),0) AS bank_total
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
        "SELECT
            COALESCE(SUM(CASE WHEN c.slug IN ('partner','partner_capital') AND t.txn_type = 'credit' THEN t.amount ELSE 0 END),0) -
            COALESCE(SUM(CASE WHEN c.slug = 'partner_capital_withdrawal' AND t.txn_type = 'debit' THEN t.amount ELSE 0 END),0) AS total
         FROM transactions t
         JOIN categories c ON c.id = t.category_id
         WHERE t.partner_id = ?"
    );
    $stmt->execute([$partnerId]);
    $total = (float) $stmt->fetchColumn();
    $upd = $pdo->prepare('UPDATE partners SET invested_amount = ? WHERE id = ?');
    $upd->execute([$total, $partnerId]);
}

function sync_partner_advance(PDO $pdo, int $partnerId): void
{
    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN c.slug = 'partner_advance' AND t.txn_type = 'credit' THEN t.amount ELSE 0 END),0) -
            COALESCE(SUM(CASE WHEN c.slug = 'partner_advance_return' AND t.txn_type = 'debit' THEN t.amount ELSE 0 END),0) AS total
         FROM transactions t
         JOIN categories c ON c.id = t.category_id
         WHERE t.partner_id = ?"
    );
    $stmt->execute([$partnerId]);
    $total = (float) $stmt->fetchColumn();
    $upd = $pdo->prepare('UPDATE partners SET advance_amount = ? WHERE id = ?');
    $upd->execute([$total, $partnerId]);
}
