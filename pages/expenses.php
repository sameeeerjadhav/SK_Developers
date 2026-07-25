<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$filterCompany = (int) get('company_id', 0);
$pageTitle = 'Expenses';
$pageSub = 'Office, material, salary, labour, interest and land-purchase related spends.';
$pageActions = '<a class="btn btn-outline" href="' . e(base_url('pages/reports.php?type=expenses')) . '">PDF</a><a class="btn btn-primary" href="' . e(base_url('pages/transactions.php?action=add&section=expense&slug=office_expenses')) . '">+ Add expense</a>';

[$from, $to, $month] = period_from_request();

$sql = "SELECT t.*, c.name AS company_name, p.name AS project_name, cat.name AS category_name, cat.section
        FROM transactions t
        JOIN categories cat ON cat.id = t.category_id
        JOIN companies c ON c.id = t.company_id
        LEFT JOIN projects p ON p.id = t.project_id
        WHERE t.txn_type = 'debit' AND cat.section IN ('expense','land_purchase')";
$params = [];
if ($filterCompany) { $sql .= ' AND t.company_id = ?'; $params[] = $filterCompany; }
apply_date_range($sql, $params, $from, $to);
$sql .= ' ORDER BY t.txn_date DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$total = array_sum(array_map(fn($r) => (float)$r['amount'], $rows));

require __DIR__ . '/../includes/header.php';
?>
<div class="stat-grid" style="grid-template-columns:repeat(2,1fr)">
  <div class="stat-card"><div class="stat-label">Total expenses</div><div class="stat-value text-danger"><?= money($total) ?></div></div>
  <div class="stat-card"><div class="stat-label">Entries</div><div class="stat-value"><?= count($rows) ?></div></div>
</div>
<form class="filters" method="get">
  <?= month_filter_fields($month) ?>
  <div class="field">
    <label>Company</label>
    <select name="company_id" onchange="this.form.submit()">
      <option value="">All</option>
      <?php foreach ($pdo->query('SELECT id, name FROM companies ORDER BY type, name') as $co): ?>
        <option value="<?= (int)$co['id'] ?>" <?= $filterCompany === (int)$co['id'] ? 'selected' : '' ?>><?= e($co['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</form>
<div class="card">
  <?php if (!$rows): ?>
    <div class="empty"><strong>No expenses recorded</strong><p>Add debit transactions under Land Purchase or Expenses categories.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Date</th><th>Company</th><th>Project</th><th>Category</th><th class="num">Amount</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><?= e($row['txn_date']) ?></td>
              <td><?= e($row['company_name']) ?></td>
              <td><?= e($row['project_name'] ?? '—') ?></td>
              <td><?= e($row['category_name']) ?> <span class="muted" style="font-size:0.72rem">(<?= e(str_replace('_',' ',$row['section'])) ?>)</span></td>
              <td class="num text-danger"><?= money($row['amount']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
