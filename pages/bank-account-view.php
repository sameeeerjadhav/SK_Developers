<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$id = (int) get('id', 0);
$stmt = $pdo->prepare(
    'SELECT ba.*, c.name AS company_name FROM bank_accounts ba
     JOIN companies c ON c.id = ba.company_id WHERE ba.id = ?'
);
$stmt->execute([$id]);
$account = $stmt->fetch();
if (!$account) {
    flash('error', 'Bank account not found.');
    redirect('pages/bank-accounts.php');
}

$balance = account_balance($pdo, $id);
$txns = $pdo->prepare(
    'SELECT t.*, cat.name AS category_name, p.name AS project_name
     FROM transactions t
     JOIN categories cat ON cat.id = t.category_id
     LEFT JOIN projects p ON p.id = t.project_id
     WHERE t.bank_account_id = ?
     ORDER BY t.txn_date DESC, t.id DESC'
);
$txns->execute([$id]);
$rows = $txns->fetchAll();

$pageTitle = $account['account_name'];
$pageSub = $account['bank_name'] . ' · ' . $account['company_name'];
$pageActions =
    '<a class="btn btn-primary" href="' . e(base_url('pages/transactions.php?action=add&company_id=' . $account['company_id'])) . '">+ Add entry</a>' .
    '<a class="btn btn-outline" href="' . e(base_url('pages/bank-accounts.php?action=edit&id=' . $id)) . '">Edit</a>';

require __DIR__ . '/../includes/header.php';
?>

<div class="stat-grid" style="grid-template-columns:repeat(3,1fr)">
  <div class="stat-card">
    <div class="stat-label">Opening balance</div>
    <div class="stat-value"><?= money($account['opening_balance']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Live balance</div>
    <div class="stat-value <?= $balance >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($balance) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Status</div>
    <div class="stat-value" style="font-size:1rem"><?= status_chip($account['status']) ?></div>
    <div class="stat-hint"><?= e($account['account_number'] ?? '') ?> <?= e($account['ifsc'] ?? '') ?></div>
  </div>
</div>

<div class="card">
  <h2 class="card-title">Account statement</h2>
  <?php if (!$rows): ?>
    <div class="empty"><strong>No linked transactions</strong><p>When adding transactions, select this bank account to affect the live balance.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>Date</th>
            <th>Category</th>
            <th>Project</th>
            <th>Type</th>
            <th class="num">Amount</th>
            <th>Note</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><?= e($row['txn_date']) ?></td>
              <td><?= e($row['category_name']) ?></td>
              <td><?= e($row['project_name'] ?? '—') ?></td>
              <td><?= txn_type_chip($row['txn_type']) ?></td>
              <td class="num <?= $row['txn_type'] === 'credit' ? 'text-success' : 'text-danger' ?>">
                <?= $row['txn_type'] === 'credit' ? '+' : '−' ?><?= money($row['amount']) ?>
              </td>
              <td><?= e($row['description'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
