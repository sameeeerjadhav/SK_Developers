<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$filterCompany = (int) get('company_id', 0);
$filterFrom = get('from', '');
$filterTo = get('to', '');
[$fromMonth, $toMonth, $month, $year] = period_from_request();
if ($month !== '' || $year !== '') {
    if ($filterFrom === '' && $filterTo === '') {
        $filterFrom = $fromMonth ?: '';
        $filterTo = $toMonth ?: '';
    }
}

$pageTitle = 'Investment';
$pageSub = 'All investment credits and withdrawals across companies and projects.';
$pageActions = '<a class="btn btn-primary" href="' . e(base_url('pages/transactions.php?action=add&section=credit&slug=investment')) . '">+ Add investment</a>'
    . '<a class="btn btn-outline" href="' . e(base_url('pages/transactions.php?action=add&section=general&slug=investment_withdrawal')) . '">+ Add withdrawal</a>';

$sql = "SELECT t.*, c.name AS company_name, p.name AS project_name, cat.name AS category_name,
               ba.account_name, ba.bank_name, u.name AS created_by_name
        FROM transactions t
        JOIN categories cat ON cat.id = t.category_id
        JOIN companies c ON c.id = t.company_id
        LEFT JOIN projects p ON p.id = t.project_id
        LEFT JOIN bank_accounts ba ON ba.id = t.bank_account_id
        LEFT JOIN users u ON u.id = t.created_by
        WHERE ((cat.section = 'credit' AND cat.slug = 'investment') OR (cat.section = 'general' AND cat.slug = 'investment_withdrawal'))";
$params = [];
if ($filterCompany) {
    $sql .= ' AND t.company_id = ?';
    $params[] = $filterCompany;
}
apply_date_range($sql, $params, $filterFrom !== '' ? $filterFrom : null, $filterTo !== '' ? $filterTo : null);
$sql .= ' ORDER BY t.txn_date DESC, t.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$totalIn = array_sum(array_map(fn($r) => $r['txn_type'] === 'credit' ? (float)$r['amount'] : 0, $rows));
$totalOut = array_sum(array_map(fn($r) => $r['txn_type'] === 'debit' ? (float)$r['amount'] : 0, $rows));

$attachmentsByTxn = [];
if ($rows) {
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $attStmt = $pdo->prepare("SELECT * FROM attachments WHERE transaction_id IN ($placeholders) ORDER BY id");
    $attStmt->execute($ids);
    foreach ($attStmt->fetchAll() as $att) {
        $attachmentsByTxn[(int) $att['transaction_id']][] = $att;
    }
}

require __DIR__ . '/../includes/header.php';
?>
<div class="stat-grid" style="grid-template-columns:repeat(4,1fr)">
  <div class="stat-card">
    <div class="stat-label">Total invested</div>
    <div class="stat-value text-success"><?= money($totalIn) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total withdrawn</div>
    <div class="stat-value text-danger"><?= money($totalOut) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Net investment</div>
    <div class="stat-value <?= ($totalIn - $totalOut) >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($totalIn - $totalOut) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Entries</div>
    <div class="stat-value"><?= count($rows) ?></div>
  </div>
</div>
<form class="filters" method="get">
  <?= period_filter_fields($month, $year) ?>
  <div class="field">
    <label>Company</label>
    <select name="company_id" onchange="this.form.submit()">
      <option value="">All</option>
      <?php foreach ($pdo->query('SELECT id, name FROM companies ORDER BY type, name') as $co): ?>
        <option value="<?= (int)$co['id'] ?>" <?= $filterCompany === (int)$co['id'] ? 'selected' : '' ?>><?= e($co['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label>From</label>
    <input type="date" name="from" value="<?= e($filterFrom) ?>">
  </div>
  <div class="field">
    <label>To</label>
    <input type="date" name="to" value="<?= e($filterTo) ?>">
  </div>
  <div class="field" style="flex:0">
    <label>&nbsp;</label>
    <button class="btn btn-outline" type="submit">Filter</button>
  </div>
</form>
<div class="card">
  <?php if (!$rows): ?>
    <div class="empty"><strong>No investments yet</strong><p>Add an investment (credit) or a withdrawal (debit) entry.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Date</th><th>Company</th><th>Project</th><th>Type</th><th>Note</th><th class="num">Amount</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $row):
            $detailId = 'inv-detail-' . (int) $row['id'];
            $atts = $attachmentsByTxn[(int) $row['id']] ?? [];
          ?>
            <tr class="row-clickable" data-row-toggle="<?= e($detailId) ?>">
              <td><span class="row-caret">▸</span><?= e($row['txn_date']) ?></td>
              <td><?= e($row['company_name']) ?></td>
              <td><?= e($row['project_name'] ?? '—') ?></td>
              <td><?= txn_type_chip($row['txn_type']) ?></td>
              <td><?= e($row['description'] ?? '') ?></td>
              <td class="num <?= $row['txn_type'] === 'credit' ? 'text-success' : 'text-danger' ?>">
                <?= $row['txn_type'] === 'credit' ? '+' : '−' ?><?= money($row['amount']) ?>
              </td>
            </tr>
            <tr class="row-detail" id="<?= e($detailId) ?>" hidden>
              <td colspan="6">
                <div class="detail-grid">
                <table class="detail-table">
                  <tbody>
                    <tr>
                      <td>Category</td>
                      <td><?= e($row['category_name']) ?></td>
                    </tr>
                    <tr>
                      <td>Reference no.</td>
                      <td><?= e($row['reference_no'] ?: '—') ?></td>
                    </tr>
                    <tr>
                      <td>Bank account</td>
                      <td><?= $row['account_name'] ? e($row['account_name'] . ' — ' . $row['bank_name']) : '—' ?></td>
                    </tr>
                    <tr>
                      <td>Recorded by</td>
                      <td><?= e($row['created_by_name'] ?? '—') ?></td>
                    </tr>
                    <tr>
                      <td>Added on</td>
                      <td><?= e(format_date($row['created_at'] ?? null)) ?></td>
                    </tr>
                    <tr>
                      <td>Description</td>
                      <td style="font-weight:500"><?= nl2br(e($row['description'] ?: '—')) ?></td>
                    </tr>
                  </tbody>
                </table>
                <?php if ($atts): ?>
                  <div class="detail-attachments">
                    <?php foreach ($atts as $att): ?>
                      <a class="chip chip-primary" href="<?= e(base_url('pages/attachment.php?id=' . $att['id'])) ?>" target="_blank"><?= e($att['original_name']) ?></a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
                <div class="form-actions" style="margin-top:0.9rem">
                  <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/transactions.php?action=edit&id=' . $row['id'])) ?>">Edit transaction</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
