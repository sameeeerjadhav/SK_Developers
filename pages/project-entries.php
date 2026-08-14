<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

const PROJECT_TXN_LIMITS = [25, 50, 75, 100];

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
            ba.account_name, ba.bank_name
     FROM transactions t
     JOIN categories cat ON cat.id = t.category_id
     LEFT JOIN bank_accounts ba ON ba.id = t.bank_account_id
     WHERE t.project_id = ?
     ORDER BY t.txn_date DESC, t.id DESC'
);
$allTxnStmt->execute([$id]);
$allTxns = $allTxnStmt->fetchAll();

$txnCount = count($allTxns);
$txnLimit = (int) get('limit', 25);
if (!in_array($txnLimit, PROJECT_TXN_LIMITS, true)) {
    $txnLimit = 25;
}
$txnPages = max(1, (int) ceil($txnCount / $txnLimit));
$txnPage = min(max(1, (int) get('page', 1)), $txnPages);
$txnOffset = ($txnPage - 1) * $txnLimit;
$ledgerPage = array_slice($allTxns, $txnOffset, $txnLimit);

$pageTitle = 'Project entries';
$pageSub = $project['name'] . ' · ' . (($project['company_type'] === 'main' ? 'Main company' : 'Sub company') . ' · ' . $project['company_name']);
$pageActions = '<a class="btn btn-outline" href="' . e(base_url('pages/project-view.php?id=' . $id)) . '">Back to project</a>'
    . '<a class="btn btn-primary" href="' . e(base_url('pages/transactions.php?action=add&project_id=' . $id . '&company_id=' . $project['company_id'] . '&from=entries')) . '">+ Add entry</a>';

require __DIR__ . '/../includes/header.php';
?>
<div class="card" id="entries">
  <div class="card-head">
    <h2 class="card-title">Project entries</h2>
    <form method="get" action="<?= e(base_url('pages/project-entries.php')) ?>#entries" style="display:inline-flex;align-items:center;gap:0.45rem;margin:0">
      <input type="hidden" name="id" value="<?= (int) $id ?>">
      <label for="entry-limit" class="muted" style="margin:0;font-size:0.78rem;white-space:nowrap">Show</label>
      <select id="entry-limit" name="limit" onchange="this.form.submit()" style="width:auto;min-width:4.5rem">
        <?php foreach (PROJECT_TXN_LIMITS as $opt): ?>
          <option value="<?= $opt ?>" <?= $txnLimit === $opt ? 'selected' : '' ?>><?= $opt ?></option>
        <?php endforeach; ?>
      </select>
      <span class="muted" style="font-size:0.78rem;white-space:nowrap">per page</span>
    </form>
  </div>
  <?php if (!$ledgerPage): ?>
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
          <?php foreach ($ledgerPage as $row):
            $mgmtPage = null;
            if (in_array($row['category_slug'], ['booking', 'booking_refund'], true)) {
                $mgmtPage = ['bookings.php', 'Bookings'];
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
    <?php
        $urlFor = static function (int $p) use ($id, $txnLimit): string {
            return base_url('pages/project-entries.php?' . http_build_query([
                'id' => $id,
                'limit' => $txnLimit,
                'page' => $p,
            ]) . '#entries');
        };
        $shownFrom = $txnOffset + 1;
        $shownTo = $txnOffset + count($ledgerPage);
    ?>
      <div class="pager">
        <?php if ($txnPage > 1): ?>
          <a class="btn btn-outline btn-sm" href="<?= e($urlFor($txnPage - 1)) ?>">← Prev</a>
        <?php else: ?>
          <span class="btn btn-outline btn-sm" aria-disabled="true">← Prev</span>
        <?php endif; ?>
        <span class="pager-info">Showing <?= $shownFrom ?>–<?= $shownTo ?> of <?= $txnCount ?> · Page <?= $txnPage ?> of <?= $txnPages ?></span>
        <?php if ($txnPage < $txnPages): ?>
          <a class="btn btn-outline btn-sm" href="<?= e($urlFor($txnPage + 1)) ?>">Next →</a>
        <?php else: ?>
          <span class="btn btn-outline btn-sm" aria-disabled="true">Next →</span>
        <?php endif; ?>
      </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
