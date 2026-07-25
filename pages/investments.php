<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$filterCompany = (int) get('company_id', 0);
$pageTitle = 'Investment';
$pageSub = 'All investment credits across companies and projects.';
$pageActions = '<a class="btn btn-primary" href="' . e(base_url('pages/transactions.php?action=add&section=credit&slug=investment')) . '">+ Add investment</a>';

$sql = "SELECT t.*, c.name AS company_name, p.name AS project_name
        FROM transactions t
        JOIN categories cat ON cat.id = t.category_id
        JOIN companies c ON c.id = t.company_id
        LEFT JOIN projects p ON p.id = t.project_id
        WHERE cat.section = 'credit' AND cat.slug = 'investment'";
$params = [];
if ($filterCompany) {
    $sql .= ' AND t.company_id = ?';
    $params[] = $filterCompany;
}
$sql .= ' ORDER BY t.txn_date DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$total = array_sum(array_map(fn($r) => (float)$r['amount'], $rows));

require __DIR__ . '/../includes/header.php';
?>
<div class="stat-grid" style="grid-template-columns:repeat(2,1fr)">
  <div class="stat-card">
    <div class="stat-label">Total investment</div>
    <div class="stat-value"><?= money($total) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Entries</div>
    <div class="stat-value"><?= count($rows) ?></div>
  </div>
</div>
<form class="filters" method="get">
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
    <div class="empty"><strong>No investments yet</strong><p>Add a transaction with category Credit → Investment.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Date</th><th>Company</th><th>Project</th><th>Note</th><th class="num">Amount</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><?= e($row['txn_date']) ?></td>
              <td><?= e($row['company_name']) ?></td>
              <td><?= e($row['project_name'] ?? '—') ?></td>
              <td><?= e($row['description'] ?? '') ?></td>
              <td class="num text-success"><?= money($row['amount']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
