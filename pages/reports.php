<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

[$from, $to, $month] = period_from_request();
$filterCompany = (int) get('company_id', 0);
$type = get('type', 'summary'); // summary | expenses | loans
$print = get('print', '') === '1';

$companyName = 'All companies';
if ($filterCompany) {
    $stmt = $pdo->prepare('SELECT name FROM companies WHERE id = ?');
    $stmt->execute([$filterCompany]);
    $companyName = $stmt->fetchColumn() ?: $companyName;
}
$periodLabel = $month ? date('F Y', strtotime($month . '-01')) : 'All time';
$totals = summary_totals($pdo, $filterCompany ?: null, $from, $to);

$pageTitle = 'Reports';
$pageSub = 'Print or save as PDF from your browser.';
$pageActions = '<button class="btn btn-primary" type="button" onclick="window.print()">Print / Save PDF</button>';

if ($print) {
    // Minimal print shell
    ?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><title>Report — <?= e($periodLabel) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(base_url('assets/css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(base_url('assets/css/print.css')) ?>">
</head><body class="print-report" onload="window.print()">
<?php
} else {
    require __DIR__ . '/../includes/header.php';
}
?>

<?php if (!$print): ?>
<form class="filters no-print" method="get">
  <?= month_filter_fields($month) ?>
  <div class="field">
    <label>Company</label>
    <select name="company_id">
      <option value="">All</option>
      <?php foreach ($pdo->query('SELECT id, name FROM companies ORDER BY type, name') as $co): ?>
        <option value="<?= (int)$co['id'] ?>" <?= $filterCompany === (int)$co['id'] ? 'selected' : '' ?>><?= e($co['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label>Report type</label>
    <select name="type">
      <option value="summary" <?= $type === 'summary' ? 'selected' : '' ?>>Financial summary</option>
      <option value="expenses" <?= $type === 'expenses' ? 'selected' : '' ?>>Expense register</option>
      <option value="loans" <?= $type === 'loans' ? 'selected' : '' ?>>Loan & EMI</option>
    </select>
  </div>
  <div class="field" style="flex:0"><label>&nbsp;</label><button class="btn btn-outline" type="submit">Apply</button></div>
</form>
<?php endif; ?>

<div class="card report-sheet">
  <div class="report-header">
    <div>
      <div class="login-kicker" style="margin:0">Sai Kuber Developers</div>
      <h2 class="card-title" style="margin:0.35rem 0 0;font-size:1.35rem">
        <?= e(ucfirst($type)) ?> report
      </h2>
    </div>
    <div class="report-meta">
      <div><strong>Scope:</strong> <?= e($companyName) ?></div>
      <div><strong>Period:</strong> <?= e($periodLabel) ?></div>
      <div><strong>Generated:</strong> <?= e(date('d M Y, h:i A')) ?></div>
    </div>
  </div>

  <?php if ($type === 'summary'): ?>
    <div class="stat-grid dense" style="margin-top:1rem">
      <div class="stat-card"><div class="stat-label">Investment</div><div class="stat-value"><?= money($totals['investment']) ?></div></div>
      <div class="stat-card"><div class="stat-label">Partner</div><div class="stat-value"><?= money($totals['partner']) ?></div></div>
      <div class="stat-card"><div class="stat-label">Booking</div><div class="stat-value"><?= money($totals['booking']) ?></div></div>
      <div class="stat-card"><div class="stat-label">Expense</div><div class="stat-value"><?= money($totals['expense']) ?></div></div>
      <div class="stat-card"><div class="stat-label">Credits</div><div class="stat-value text-success"><?= money($totals['credits']) ?></div></div>
      <div class="stat-card"><div class="stat-label">Debits</div><div class="stat-value text-danger"><?= money($totals['debits']) ?></div></div>
      <div class="stat-card"><div class="stat-label">Profit</div><div class="stat-value <?= $totals['profit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($totals['profit']) ?></div></div>
      <div class="stat-card"><div class="stat-label">Bank balance</div><div class="stat-value"><?= money($totals['bank_balance']) ?></div></div>
    </div>
  <?php elseif ($type === 'expenses'):
    $sql = "SELECT t.*, c.name AS company_name, p.name AS project_name, cat.name AS category_name
            FROM transactions t
            JOIN companies c ON c.id = t.company_id
            JOIN categories cat ON cat.id = t.category_id
            LEFT JOIN projects p ON p.id = t.project_id
            WHERE t.txn_type = 'debit'";
    $params = [];
    if ($filterCompany) { $sql .= ' AND t.company_id = ?'; $params[] = $filterCompany; }
    apply_date_range($sql, $params, $from, $to);
    $sql .= ' ORDER BY t.txn_date DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
  ?>
    <div class="table-wrap" style="margin-top:1rem">
      <table class="data">
        <thead><tr><th>Date</th><th>Company</th><th>Project</th><th>Category</th><th class="num">Amount</th></tr></thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="5">No expenses in this period.</td></tr>
          <?php else: foreach ($rows as $row): ?>
            <tr>
              <td><?= e($row['txn_date']) ?></td>
              <td><?= e($row['company_name']) ?></td>
              <td><?= e($row['project_name'] ?? '—') ?></td>
              <td><?= e($row['category_name']) ?></td>
              <td class="num"><?= money($row['amount']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
        <tfoot>
          <tr><td colspan="4"><strong>Total</strong></td><td class="num"><strong><?= money($totals['expense']) ?></strong></td></tr>
        </tfoot>
      </table>
    </div>
  <?php else:
    $sql = 'SELECT l.*, c.name AS company_name FROM bank_loans l JOIN companies c ON c.id = l.company_id WHERE 1=1';
    $params = [];
    if ($filterCompany) { $sql .= ' AND l.company_id = ?'; $params[] = $filterCompany; }
    $sql .= ' ORDER BY l.status, l.lender_name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $loans = $stmt->fetchAll();
  ?>
    <div class="table-wrap" style="margin-top:1rem">
      <table class="data">
        <thead><tr><th>Lender</th><th>Company</th><th class="num">Loan</th><th class="num">Outstanding</th><th class="num">EMI</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($loans as $l): ?>
            <tr>
              <td><?= e($l['lender_name']) ?></td>
              <td><?= e($l['company_name']) ?></td>
              <td class="num"><?= money($l['loan_amount']) ?></td>
              <td class="num"><?= money($l['outstanding_amount']) ?></td>
              <td class="num"><?= $l['emi_amount'] !== null ? money($l['emi_amount']) : '—' ?></td>
              <td><?= e($l['status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php
if ($print) {
    echo '</body></html>';
} else {
    require __DIR__ . '/../includes/footer.php';
}
?>
