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

$allTxnStmt = $pdo->prepare(
    'SELECT t.*, cat.name AS category_name, cat.slug AS category_slug, cat.section,
            ba.account_name, ba.bank_name, bp.booking_id
     FROM transactions t
     JOIN categories cat ON cat.id = t.category_id
     LEFT JOIN bank_accounts ba ON ba.id = t.bank_account_id
     LEFT JOIN (
        SELECT transaction_id, MIN(booking_id) AS booking_id
        FROM booking_payments
        WHERE transaction_id IS NOT NULL
        GROUP BY transaction_id
     ) bp ON bp.transaction_id = t.id
     WHERE t.project_id = ?
     ORDER BY t.txn_date DESC, t.id DESC'
);
$allTxnStmt->execute([$id]);
$allTxns = $allTxnStmt->fetchAll();
$list = paginate_list($allTxns);

$pageTitle = 'Project entries';
$pageSub = $project['name'] . ' · ' . (($project['company_type'] === 'main' ? 'Main company' : 'Sub company') . ' · ' . $project['company_name']);
$pageActions = '<a class="btn btn-outline" href="' . e(base_url('pages/project-view.php?id=' . $id)) . '">Back to project</a>'
    . '<a class="btn btn-primary" href="' . e(base_url('pages/transactions.php?action=add&project_id=' . $id . '&company_id=' . $project['company_id'] . '&from=entries')) . '">+ Add entry</a>';

require __DIR__ . '/../includes/header.php';
?>
<div class="card" id="list">
  <div class="card-head">
    <h2 class="card-title">Project entries</h2>
    <?php render_limit_control('project-entries.php'); ?>
  </div>
  <?php if (!$list['total']): ?>
    <div class="empty"><strong>No entries yet</strong><p>Add credits (investment, partner, booking…) or expenses for this project.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>Date</th>
            <th>Section</th>
            <th>Category</th>
            <th>Account</th>
            <th>Particulars</th>
            <th>Type</th>
            <th class="num">Amount</th>
            <th class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($list['rows'] as $row):
            $mgmtPage = null;
            if (in_array($row['category_slug'], ['booking', 'booking_refund'], true)) {
                $bookingMatch = booking_match_for_transaction($pdo, $row);
                $bookingId = (int) $bookingMatch['booking_id'];
                $mgmtPage = ['bookings.php' . ($bookingId ? ('?expand=' . $bookingId) : ''), 'Bookings'];
            } elseif (in_array($row['category_slug'], ['investment', 'daily_credit', 'monthly_credit', 'investment_withdrawal', 'daily_debit', 'monthly_debit'], true)) {
                $mgmtPage = ['investments.php', 'Investments'];
            }
            $account = $row['account_name']
                ? trim($row['account_name'] . ($row['bank_name'] ? ' — ' . $row['bank_name'] : ''))
                : 'Cash';
          ?>
            <tr>
              <td><?= e(format_date($row['txn_date'])) ?></td>
              <td><?= e(ucwords(str_replace('_', ' ', $row['section']))) ?></td>
              <td><?= e($row['category_name']) ?></td>
              <td><?= e($account) ?></td>
              <td><?= e($row['description'] ?? '') ?></td>
              <td><?= txn_type_chip($row['txn_type']) ?></td>
              <td class="num <?= $row['txn_type'] === 'credit' ? 'text-success' : 'text-danger' ?>"><?= money($row['amount']) ?></td>
              <td class="actions">
                <?php if ($mgmtPage): ?>
                  <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/' . $mgmtPage[0])) ?>">Manage in <?= e($mgmtPage[1]) ?></a>
                <?php else: ?>
                  <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/transactions.php?action=edit&id=' . $row['id'] . '&from=entries')) ?>">Edit</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php render_pager('project-entries.php', $list); ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
