<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_login();

$pageTitle = 'Dashboard';
$pageSub = 'Overview of Sai Kuber Developers and all sub companies.';
$pageActions = '<a class="btn btn-primary" href="' . e(base_url('pages/transactions.php?action=add')) . '">+ Add transaction</a>';

$totals = summary_totals($pdo);
$companies = $pdo->query('SELECT * FROM companies WHERE status = "active" ORDER BY type ASC, id ASC')->fetchAll();
$projectCount = (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
$accountCount = (int) $pdo->query('SELECT COUNT(*) FROM bank_accounts WHERE status = "active"')->fetchColumn();

$recent = $pdo->query(
    'SELECT t.*, c.name AS company_name, cat.name AS category_name, p.name AS project_name
     FROM transactions t
     JOIN companies c ON c.id = t.company_id
     JOIN categories cat ON cat.id = t.category_id
     LEFT JOIN projects p ON p.id = t.project_id
     ORDER BY t.txn_date DESC, t.id DESC
     LIMIT 8'
)->fetchAll();

$companyCards = [];
foreach ($companies as $co) {
    $s = summary_totals($pdo, (int) $co['id']);
    $pc = $pdo->prepare('SELECT COUNT(*) FROM projects WHERE company_id = ?');
    $pc->execute([(int) $co['id']]);
    $companyCards[] = [
        'company' => $co,
        'summary' => $s,
        'projects' => (int) $pc->fetchColumn(),
    ];
}

require __DIR__ . '/includes/header.php';
?>

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
    <?php foreach ($companyCards as $item): $co = $item['company']; $s = $item['summary']; ?>
      <a class="company-card" href="<?= e(base_url('pages/projects.php?company_id=' . $co['id'])) ?>">
        <div class="kicker"><?= $co['type'] === 'main' ? 'Main company' : 'Sub company' ?></div>
        <h3><?= e($co['name']) ?></h3>
        <div class="meta"><?= (int) $item['projects'] ?> projects</div>
        <div class="ledger-total" style="margin-top:0.85rem;border:0;padding:0">
          <span class="muted">Profit</span>
          <span class="<?= $s['profit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($s['profit']) ?></span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="grid-2" style="margin-top:1rem">
  <div class="card">
    <div class="card-head">
      <h2 class="card-title">Recent transactions</h2>
      <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/transactions.php')) ?>">View all</a>
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
      <a class="btn btn-outline" href="<?= e(base_url('pages/bank-accounts.php')) ?>">Bank Accounts</a>
      <a class="btn btn-outline" href="<?= e(base_url('pages/investments.php')) ?>">Investments</a>
      <a class="btn btn-outline" href="<?= e(base_url('pages/expenses.php')) ?>">Expenses</a>
      <a class="btn btn-outline" href="<?= e(base_url('pages/partners.php')) ?>">Partners</a>
      <a class="btn btn-outline" href="<?= e(base_url('pages/bank-loans.php')) ?>">Bank Loans</a>
    </div>
    <div class="highlight-box" style="margin-top:1rem">
      Tip: Open a <strong>project</strong> to see Credit, Land Purchase, Expenses and Profit exactly like your whiteboard layout.
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
