<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

[$from, $to, $month, $year] = period_from_request();
$type = get('type', 'pnl'); // pnl | project | bank | monthly
$companyId = get('company_id') !== '' ? (int) get('company_id') : null;
$projectId = get('project_id') !== '' ? (int) get('project_id') : null;
$bankId = get('bank_account_id') !== '' ? (int) get('bank_account_id') : null;

$pageTitle = 'Reports & PDF';
$pageSub = 'Print or Save as PDF from the browser. Choose report type and date range.';
$pageActions = '<button class="btn btn-primary no-print" type="button" onclick="window.print()">Print / Save PDF</button>';
require __DIR__ . '/../includes/header.php';

$periodText = period_label($from, $to, $month, $year);
?>
<link rel="stylesheet" href="<?= e(base_url('assets/css/print.css')) ?>">
<div class="card no-print" style="margin-bottom:16px">
  <form method="get" class="filter-bar filters">
    <div class="field">
      <label>Report</label>
      <select name="type" onchange="this.form.submit()">
        <option value="pnl" <?= $type==='pnl'?'selected':'' ?>>Period P&amp;L</option>
        <option value="project" <?= $type==='project'?'selected':'' ?>>Project report</option>
        <option value="bank" <?= $type==='bank'?'selected':'' ?>>Bank statement</option>
        <option value="monthly" <?= $type==='monthly'?'selected':'' ?>>Company monthly summary</option>
      </select>
    </div>
    <?= period_filter_fields($month, $year) ?>
    <div class="field">
      <label>Company</label>
      <select name="company_id"><option value="">All</option><?= company_options($pdo, $companyId) ?></select>
    </div>
    <?php if ($type === 'project'): ?>
    <div class="field">
      <label>Project</label>
      <select name="project_id"><option value="">Select…</option>
        <?php foreach ($pdo->query('SELECT id, name FROM projects WHERE status != "on_hold" ORDER BY name') as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $projectId===(int)$p['id']?'selected':'' ?>><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <?php if ($type === 'bank'): ?>
    <div class="field">
      <label>Bank account</label>
      <select name="bank_account_id">
        <option value="">Select…</option>
        <?php foreach ($pdo->query('SELECT id, account_name FROM bank_accounts WHERE status="active" ORDER BY account_name') as $b): ?>
          <option value="<?= (int)$b['id'] ?>" <?= $bankId===(int)$b['id']?'selected':'' ?>><?= e($b['account_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="field" style="flex:0"><label>&nbsp;</label><button class="btn btn-primary" type="submit">Generate</button></div>
  </form>
</div>

<div class="print-sheet card">
  <div class="print-header report-header">
    <div>
      <div class="print-brand" style="font-family:Sora,sans-serif;font-weight:800;font-size:1.35rem;color:var(--teal-700,#0f766e)">Sai Kuber Developers</div>
      <div class="print-meta report-meta" style="text-align:left"><?= e(ucfirst($type)) ?> report · <?= e($periodText) ?></div>
    </div>
    <div class="print-meta report-meta">Generated <?= e(date('d M Y H:i')) ?><br><?= e(current_user()['name'] ?? '') ?></div>
  </div>

<?php if ($type === 'pnl' || $type === 'monthly'):
  $companies = $pdo->query('SELECT * FROM companies WHERE status != "archived" ORDER BY FIELD(type,"main","sub"), name')->fetchAll();
  if ($companyId) {
      $companies = array_values(array_filter($companies, fn($c) => (int)$c['id'] === $companyId));
  }
?>
  <h2 class="section-title card-title" style="margin-top:1rem">Profit &amp; Loss</h2>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Company</th><th class="num">Credits in</th><th class="num">Debits out</th><th class="num">Net P&amp;L</th></tr></thead>
      <tbody>
      <?php
      $tc = $td = 0.0;
      foreach ($companies as $c):
        $cid = (int)$c['id'];
        $cr = sum_transactions($pdo, 'credit', $cid, null, null, $from, $to);
        $dr = sum_transactions($pdo, 'debit', $cid, null, null, $from, $to);
        $tc += $cr; $td += $dr;
      ?>
        <tr>
          <td><?= e($c['name']) ?></td>
          <td class="num text-success"><?= money($cr) ?></td>
          <td class="num text-danger"><?= money($dr) ?></td>
          <td class="num <?= ($cr-$dr)>=0?'text-success':'text-danger' ?>"><?= money($cr - $dr) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td><strong>Total</strong></td><td class="num"><strong><?= money($tc) ?></strong></td><td class="num"><strong><?= money($td) ?></strong></td><td class="num"><strong><?= money($tc-$td) ?></strong></td></tr>
      </tfoot>
    </table>
  </div>

<?php elseif ($type === 'project'):
  if (!$projectId):
    echo '<p class="empty">Select a project and Generate.</p>';
  else:
    $p = $pdo->prepare('SELECT p.*, c.name company_name FROM projects p JOIN companies c ON c.id = p.company_id WHERE p.id = ?');
    $p->execute([$projectId]);
    $proj = $p->fetch();
    if (!$proj) {
      echo '<p class="empty">Project not found.</p>';
    } else {
      $cid = (int)$proj['company_id'];
      $credit = sum_transactions($pdo, 'credit', $cid, $projectId, null, $from, $to);
      $expense = sum_transactions($pdo, 'debit', $cid, $projectId, 'expense', $from, $to)
               + sum_transactions($pdo, 'debit', $cid, $projectId, 'land_purchase', $from, $to);
      $land = sum_transactions($pdo, 'debit', $cid, $projectId, 'land_purchase', $from, $to);
      $sql = 'SELECT t.*, cat.name category_name FROM transactions t JOIN categories cat ON cat.id = t.category_id WHERE t.project_id = ?';
      $params = [$projectId];
      apply_date_range($sql, $params, $from, $to);
      $sql .= ' ORDER BY t.txn_date, t.id';
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      $rows = $stmt->fetchAll();
?>
  <h2 class="card-title" style="margin-top:1rem"><?= e($proj['name']) ?></h2>
  <p class="muted"><?= e($proj['company_name']) ?> · Code <?= e($proj['code'] ?? '—') ?></p>
  <div class="stat-grid" style="margin:16px 0">
    <div class="stat-card"><div class="stat-label">Credit</div><div class="stat-value text-success"><?= money($credit) ?></div></div>
    <div class="stat-card"><div class="stat-label">Land</div><div class="stat-value text-danger"><?= money($land) ?></div></div>
    <div class="stat-card"><div class="stat-label">Expenses</div><div class="stat-value text-danger"><?= money($expense) ?></div></div>
    <div class="stat-card"><div class="stat-label">Profit</div><div class="stat-value <?= ($credit-$expense)>=0?'text-success':'text-danger' ?>"><?= money($credit-$expense) ?></div></div>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Date</th><th>Category</th><th>Description</th><th class="num">Credit</th><th class="num">Debit</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e(format_date($r['txn_date'])) ?></td>
          <td><?= e($r['category_name']) ?></td>
          <td><?= e($r['description'] ?? '') ?></td>
          <td class="num"><?= $r['txn_type']==='credit' ? money((float)$r['amount']) : '—' ?></td>
          <td class="num"><?= $r['txn_type']==='debit' ? money((float)$r['amount']) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="5" class="empty">No entries in range.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
<?php } endif; ?>

<?php elseif ($type === 'bank'):
  if (!$bankId):
    echo '<p class="empty">Select a bank account and Generate.</p>';
  else:
    $b = $pdo->prepare('SELECT * FROM bank_accounts WHERE id = ?');
    $b->execute([$bankId]);
    $bank = $b->fetch();
    if (!$bank) {
      echo '<p class="empty">Account not found.</p>';
    } else {
      $sql = 'SELECT t.*, cat.name category_name, c.name company_name FROM transactions t JOIN categories cat ON cat.id = t.category_id JOIN companies c ON c.id = t.company_id WHERE t.bank_account_id = ?';
      $params = [$bankId];
      apply_date_range($sql, $params, $from, $to);
      $sql .= ' ORDER BY t.txn_date, t.id';
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      $rows = $stmt->fetchAll();
      $bal = account_balance($pdo, $bankId);
?>
  <h2 class="card-title" style="margin-top:1rem">Bank statement — <?= e($bank['account_name']) ?></h2>
  <p class="muted"><?= e($bank['bank_name'] ?? '') ?> · <?= e($bank['account_number'] ?? '') ?> · Ledger balance <?= money($bal) ?></p>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Date</th><th>Company</th><th>Category</th><th>Description</th><th class="num">In</th><th class="num">Out</th></tr></thead>
      <tbody>
      <?php $tin=0;$tout=0; foreach ($rows as $r):
        $in = $r['txn_type']==='credit' ? (float)$r['amount'] : 0;
        $out = $r['txn_type']==='debit' ? (float)$r['amount'] : 0;
        $tin += $in; $tout += $out;
      ?>
        <tr>
          <td><?= e(format_date($r['txn_date'])) ?></td>
          <td><?= e($r['company_name']) ?></td>
          <td><?= e($r['category_name']) ?></td>
          <td><?= e($r['description'] ?? '') ?></td>
          <td class="num text-success"><?= $in ? money($in) : '—' ?></td>
          <td class="num text-danger"><?= $out ? money($out) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="empty">No movements in range.</td></tr><?php endif; ?>
      </tbody>
      <tfoot><tr><td colspan="4"><strong>Period totals</strong></td><td class="num"><strong><?= money($tin) ?></strong></td><td class="num"><strong><?= money($tout) ?></strong></td></tr></tfoot>
    </table>
  </div>
<?php } endif; endif; ?>
  <p class="muted" style="margin-top:1.5rem;font-size:0.8rem">Confidential — Sai Kuber Developers internal use</p>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
