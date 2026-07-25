<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$id = (int) get('id', 0);
$stmt = $pdo->prepare(
    'SELECT p.*, c.name AS company_name, c.type AS company_type
     FROM projects p JOIN companies c ON c.id = p.company_id WHERE p.id = ?'
);
$stmt->execute([$id]);
$project = $stmt->fetch();
if (!$project) {
    flash('error', 'Project not found.');
    redirect('pages/projects.php');
}

$credits = section_breakdown($pdo, $id, 'credit');
$land = section_breakdown($pdo, $id, 'land_purchase');
$expenses = section_breakdown($pdo, $id, 'expense');

$creditTotal = array_sum(array_map(fn($r) => (float)$r['total'], $credits));
$landTotal = array_sum(array_map(fn($r) => (float)$r['total'], $land));
$expenseTotal = array_sum(array_map(fn($r) => (float)$r['total'], $expenses));
$profit = $creditTotal - $landTotal - $expenseTotal;

$txns = $pdo->prepare(
    'SELECT t.*, cat.name AS category_name, cat.section
     FROM transactions t JOIN categories cat ON cat.id = t.category_id
     WHERE t.project_id = ? ORDER BY t.txn_date DESC, t.id DESC LIMIT 20'
);
$txns->execute([$id]);
$recent = $txns->fetchAll();

$pageTitle = $project['name'];
$pageSub = ($project['company_type'] === 'main' ? 'Main company' : 'Sub company') . ' · ' . $project['company_name'];
$pageActions =
    '<a class="btn btn-primary" href="' . e(base_url('pages/transactions.php?action=add&project_id=' . $id . '&company_id=' . $project['company_id'])) . '">+ Add entry</a>' .
    '<a class="btn btn-outline" href="' . e(base_url('pages/projects.php?action=edit&id=' . $id)) . '">Edit</a>';

require __DIR__ . '/../includes/header.php';

function render_ledger_rows(array $rows): void
{
    echo '<div class="table-wrap"><table class="data"><thead><tr><th>Particulars</th><th class="num">Amount</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td>' . e($row['name']) . '</td><td class="num">' . money($row['total']) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
?>

<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-label">Credits</div>
    <div class="stat-value text-success"><?= money($creditTotal) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Land Purchase</div>
    <div class="stat-value text-danger"><?= money($landTotal) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Expenses</div>
    <div class="stat-value text-danger"><?= money($expenseTotal) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Profit</div>
    <div class="stat-value <?= $profit >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($profit) ?></div>
    <div class="stat-hint"><?= status_chip($project['status']) ?></div>
  </div>
</div>

<div class="grid-3">
  <div class="card ledger-block credit">
    <h3>Credit</h3>
    <?php render_ledger_rows($credits); ?>
    <div class="ledger-total"><span>Total credit</span><span class="text-success"><?= money($creditTotal) ?></span></div>
  </div>
  <div class="card ledger-block debit">
    <h3>Land Purchase (Debit)</h3>
    <?php render_ledger_rows($land); ?>
    <div class="ledger-total"><span>Total land</span><span class="text-danger"><?= money($landTotal) ?></span></div>
  </div>
  <div class="card ledger-block expense">
    <h3>Expenses</h3>
    <?php render_ledger_rows($expenses); ?>
    <div class="ledger-total"><span>Total expenses</span><span class="text-danger"><?= money($expenseTotal) ?></span></div>
    <div class="profit-row">
      <span>Profit</span>
      <span class="<?= $profit >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($profit) ?></span>
    </div>
  </div>
</div>

<div class="card" style="margin-top:1rem">
  <div class="card-head">
    <h2 class="card-title">Recent project entries</h2>
    <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/transactions.php?project_id=' . $id)) ?>">All transactions</a>
  </div>
  <?php if (!$recent): ?>
    <div class="empty"><strong>No entries yet</strong><p>Add credits (investment, partner, booking…) or expenses for this project.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>Date</th>
            <th>Section</th>
            <th>Category</th>
            <th>Type</th>
            <th class="num">Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $row): ?>
            <tr>
              <td><?= e($row['txn_date']) ?></td>
              <td><?= e(ucwords(str_replace('_', ' ', $row['section']))) ?></td>
              <td><?= e($row['category_name']) ?></td>
              <td><?= txn_type_chip($row['txn_type']) ?></td>
              <td class="num <?= $row['txn_type'] === 'credit' ? 'text-success' : 'text-danger' ?>"><?= money($row['amount']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
