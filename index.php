<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_login();

[$from, $to, $month, $year] = period_from_request();
$setup = setup_progress($pdo);
$notes = system_notifications($pdo);

$pageTitle = 'Dashboard';
$pageSub = period_label($from, $to, $month, $year) === 'All time'
    ? 'Overview of Sai Kuber Developers and all sub companies.'
    : 'Overview for ' . period_label($from, $to, $month, $year) . '.';
$qs = http_build_query(array_filter(['month' => $month ?: null, 'year' => $year ?: null]));
$pageActions =
    '<a class="btn btn-outline" href="' . e(base_url('pages/reports.php' . ($qs ? '?' . $qs : ''))) . '">PDF report</a>' .
    '<a class="btn btn-primary" href="' . e(base_url('pages/transactions.php?action=add')) . '">+ Add transaction</a>';

$totals = summary_totals($pdo, null, $from, $to);
$companies = $pdo->query('SELECT * FROM companies WHERE status = "active" ORDER BY type ASC, id ASC')->fetchAll();
$projectCount = (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
$accountCount = (int) $pdo->query('SELECT COUNT(*) FROM bank_accounts WHERE status = "active"')->fetchColumn();

$recentSql = 'SELECT t.*, c.name AS company_name, cat.name AS category_name, p.name AS project_name
     FROM transactions t
     JOIN companies c ON c.id = t.company_id
     JOIN categories cat ON cat.id = t.category_id
     LEFT JOIN projects p ON p.id = t.project_id
     WHERE 1=1';
$recentParams = [];
apply_date_range($recentSql, $recentParams, $from, $to);
$recentSql .= ' ORDER BY t.txn_date DESC, t.id DESC LIMIT 8';
$recentStmt = $pdo->prepare($recentSql);
$recentStmt->execute($recentParams);
$recent = $recentStmt->fetchAll();

$companyCards = [];
$profitsByCompany = company_profits_bulk($pdo, $from, $to);
$projectCounts = company_project_counts($pdo);
foreach ($companies as $co) {
    $cid = (int) $co['id'];
    $companyCards[] = [
        'company' => $co,
        'profit' => $profitsByCompany[$cid] ?? 0.0,
        'projects' => $projectCounts[$cid] ?? 0,
    ];
}

require __DIR__ . '/includes/header.php';
?>

<form class="filters" method="get">
  <?= period_filter_fields($month, $year) ?>
</form>

<?php if (!$setup['complete']): ?>
<div class="card setup-wizard">
  <div class="card-head">
    <h2 class="card-title">Get started</h2>
    <span class="chip chip-primary"><?= (int)$setup['done'] ?> / <?= (int)$setup['total'] ?></span>
  </div>
  <p class="muted" style="margin:0 0 1rem">Add your first bank account, project, and entry to unlock the full dashboard.</p>
  <ol class="setup-steps">
    <?php foreach ($setup['steps'] as $step): ?>
      <li class="<?= $step['done'] ? 'done' : '' ?>">
        <span class="setup-check"><?= $step['done'] ? '✓' : (array_search($step, $setup['steps'], true) + 1) ?></span>
        <?php if ($step['done']): ?>
          <span><?= e($step['label']) ?></span>
        <?php else: ?>
          <a href="<?= e(base_url($step['href'])) ?>"><?= e($step['label']) ?> →</a>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
</div>
<?php endif; ?>

<?php if ($notes): ?>
<div class="card" style="margin-bottom:1rem">
  <div class="card-head">
    <h2 class="card-title">Alerts</h2>
    <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/notifications.php')) ?>">View all</a>
  </div>
  <div style="display:grid;gap:0.5rem">
    <?php foreach (array_slice($notes, 0, 4) as $n): ?>
      <a href="<?= e(base_url($n['href'])) ?>" class="muted" style="display:block;color:inherit;font-weight:600;font-size:0.9rem"><?= e($n['title']) ?> — <?= e($n['body']) ?></a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-label">Total Investment</div>
    <div class="stat-value"><?= money($totals['investment']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total Expenses</div>
    <div class="stat-value"><?= money($totals['expense']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Bank Loans</div>
    <div class="stat-value"><?= money($totals['bank_loans']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Profit</div>
    <div class="stat-value <?= $totals['profit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($totals['profit']) ?></div>
    <div class="stat-hint"><?= $projectCount ?> projects · <?= $accountCount ?> bank accounts</div>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <h2 class="card-title">Companies</h2>
    <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/companies.php')) ?>">Manage</a>
  </div>
  <div class="company-grid">
    <?php foreach ($companyCards as $item): $co = $item['company']; $profit = (float) $item['profit']; ?>
      <a class="company-card" href="<?= e(base_url('pages/projects.php?company_id=' . $co['id'])) ?>">
        <div class="kicker"><?= $co['type'] === 'main' ? 'Main company' : 'Sub company' ?></div>
        <h3><?= e($co['name']) ?></h3>
        <div class="meta"><?= (int) $item['projects'] ?> projects</div>
        <div class="ledger-total" style="margin-top:0.85rem;border:0;padding:0">
          <span class="muted">Profit</span>
          <span class="<?= $profit >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($profit) ?></span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="grid-2" style="margin-top:1rem">
  <div class="card">
    <div class="card-head">
      <h2 class="card-title">Recent transactions</h2>
      <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/transactions.php' . ($qs ? '?' . $qs : ''))) ?>">View all</a>
    </div>
    <?php if (!$recent): ?>
      <div class="empty"><strong>No transactions yet</strong><p>Add your first credit or expense entry.</p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr>
              <th>Date</th>
              <th>Company</th>
              <th>Category</th>
              <th>Type</th>
              <th class="num">Amount</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent as $row): ?>
              <tr>
                <td><?= e($row['txn_date']) ?></td>
                <td>
                  <div><?= e($row['company_name']) ?></div>
                  <div class="muted" style="font-size:0.75rem"><?= e($row['project_name'] ?? '—') ?></div>
                </td>
                <td><?= e($row['category_name']) ?></td>
                <td><?= txn_type_chip($row['txn_type']) ?></td>
                <td class="num <?= $row['txn_type'] === 'credit' ? 'text-success' : 'text-danger' ?>">
                  <?= $row['txn_type'] === 'credit' ? '+' : '−' ?><?= money($row['amount']) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-head">
      <h2 class="card-title">Quick links</h2>
    </div>
    <div class="grid-2" style="gap:0.65rem">
      <a class="btn btn-outline" href="<?= e(base_url('pages/summary.php')) ?>">Total Summary</a>
      <a class="btn btn-outline" href="<?= e(base_url('pages/transfers.php')) ?>">Bank transfer</a>
      <a class="btn btn-outline" href="<?= e(base_url('pages/import.php')) ?>">CSV import</a>
      <a class="btn btn-outline" href="<?= e(base_url('pages/bank-loans.php')) ?>">Bank Loans / EMI</a>
      <a class="btn btn-outline" href="<?= e(base_url('pages/audit.php')) ?>">Audit log</a>
      <a class="btn btn-outline" href="<?= e(base_url('pages/expenses.php')) ?>">Expenses</a>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
