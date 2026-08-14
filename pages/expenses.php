<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$filterCompany = (int) get('company_id', 0);
$filterProject = (int) get('project_id', 0);

[$from, $to, $month, $year] = period_from_request();

$sql = "SELECT t.*, c.name AS company_name, p.name AS project_name, cat.name AS category_name, cat.section
        FROM transactions t
        JOIN categories cat ON cat.id = t.category_id
        JOIN companies c ON c.id = t.company_id
        LEFT JOIN projects p ON p.id = t.project_id
        WHERE t.txn_type = 'debit' AND cat.section IN ('expense','land_purchase')";
$params = [];
if ($filterCompany) { $sql .= ' AND t.company_id = ?'; $params[] = $filterCompany; }
if ($filterProject) { $sql .= ' AND t.project_id = ?'; $params[] = $filterProject; }
apply_date_range($sql, $params, $from, $to);
$sql .= ' ORDER BY t.txn_date DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$total = array_sum(array_map(fn($r) => (float)$r['amount'], $rows));

$companyName = 'All companies';
if ($filterCompany) {
    $cn = $pdo->prepare('SELECT name FROM companies WHERE id = ?');
    $cn->execute([$filterCompany]);
    $companyName = (string) ($cn->fetchColumn() ?: 'Company #' . $filterCompany);
}
$projectName = 'All projects';
if ($filterProject) {
    $pn = $pdo->prepare('SELECT name FROM projects WHERE id = ?');
    $pn->execute([$filterProject]);
    $projectName = (string) ($pn->fetchColumn() ?: 'Project #' . $filterProject);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('export_action', ''), ['csv', 'excel', 'pdf'], true)) {
    verify_csrf();
    $byCategory = [];
    foreach ($rows as $r) {
        $key = (string) $r['category_name'];
        if (!isset($byCategory[$key])) {
            $byCategory[$key] = ['section' => $r['section'], 'count' => 0, 'amount' => 0.0];
        }
        $byCategory[$key]['count']++;
        $byCategory[$key]['amount'] += (float) $r['amount'];
    }
    ksort($byCategory);

    $entryRows = [];
    foreach ($rows as $i => $r) {
        $entryRows[] = [
            (string) ($i + 1),
            report_plain_date($r['txn_date'] ?? null),
            $r['company_name'] ?? '',
            $r['project_name'] ?? '',
            $r['category_name'] ?? '',
            ucwords(str_replace('_', ' ', (string) $r['section'])),
            $r['payee_name'] ?? '',
            $r['description'] ?? '',
            (float) $r['amount'],
        ];
    }
    $catRows = [];
    $n = 0;
    foreach ($byCategory as $name => $info) {
        $n++;
        $catRows[] = [
            (string) $n,
            $name,
            ucwords(str_replace('_', ' ', (string) $info['section'])),
            $info['count'],
            $info['amount'],
        ];
    }

    report_download(post('export_action'), [
        'filename' => 'expense_register',
        'title' => 'Expense Register',
        'orientation' => 'landscape',
        'meta' => [
            ['Period', report_display_period($from, $to, $month, $year)],
            ['Company', $companyName],
            ['Project', $projectName],
        ],
        'summary' => [
            ['Total expenses', $total, 'money'],
            ['Entries', count($rows), 'int'],
            ['Categories', count($byCategory), 'int'],
        ],
        'tables' => [
            [
                'title' => 'Expense entries',
                'columns' => [
                    ['label' => 'Sr No', 'type' => 'text', 'width' => '5%', 'xls_width' => 35],
                    ['label' => 'Date', 'type' => 'text', 'width' => '9%', 'xls_width' => 80],
                    ['label' => 'Company', 'type' => 'text', 'width' => '14%', 'xls_width' => 140],
                    ['label' => 'Project', 'type' => 'text', 'width' => '12%', 'xls_width' => 120],
                    ['label' => 'Category', 'type' => 'text', 'width' => '12%', 'xls_width' => 110],
                    ['label' => 'Section', 'type' => 'text', 'width' => '10%', 'xls_width' => 90],
                    ['label' => 'Paid to', 'type' => 'text', 'width' => '12%', 'xls_width' => 110],
                    ['label' => 'Particulars', 'type' => 'text', 'width' => '14%', 'xls_width' => 160],
                    ['label' => 'Amount (INR)', 'type' => 'money', 'width' => '12%', 'xls_width' => 100],
                ],
                'rows' => $entryRows,
                'totals' => ['', 'TOTAL', '', '', '', '', '', '', $total],
            ],
            [
                'title' => 'Category totals',
                'columns' => [
                    ['label' => 'Sr No', 'type' => 'text', 'width' => '8%', 'xls_width' => 35],
                    ['label' => 'Category', 'type' => 'text', 'width' => '40%', 'xls_width' => 180],
                    ['label' => 'Section', 'type' => 'text', 'width' => '22%', 'xls_width' => 110],
                    ['label' => 'Entries', 'type' => 'int', 'width' => '12%', 'xls_width' => 70],
                    ['label' => 'Amount (INR)', 'type' => 'money', 'width' => '18%', 'xls_width' => 110],
                ],
                'rows' => $catRows,
                'totals' => ['', 'TOTAL', '', count($rows), $total],
            ],
        ],
        'notes' => [
            'System-generated expense register. Amounts are in INR and match the selected filters at export time.',
            'Includes Expense and Land Purchase debit entries.',
            'Confidential — internal use only.',
        ],
    ]);
    redirect('pages/expenses.php');
}

$list = paginate_list($rows);

$pageTitle = 'Expenses';
$pageSub = 'Office, material, salary, labour, interest and land-purchase related spends.';
$pageActions = report_export_buttons()
    . '<a class="btn btn-primary" href="' . e(base_url('pages/transactions.php?action=add&section=expense&slug=office_expenses')) . '">+ Add expense</a>';

require __DIR__ . '/../includes/header.php';
?>
<div class="stat-grid" style="grid-template-columns:repeat(2,1fr)">
  <div class="stat-card"><div class="stat-label">Total expenses</div><div class="stat-value text-danger"><?= money($total) ?></div></div>
  <div class="stat-card"><div class="stat-label">Entries</div><div class="stat-value"><?= count($rows) ?></div></div>
</div>
<form class="filters" method="get">
  <?= list_limit_hidden() ?>
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
    <label>Project</label>
    <select name="project_id" onchange="this.form.submit()">
      <option value="">All</option>
      <?= project_options($pdo, $filterCompany ?: null, $filterProject ?: null) ?>
    </select>
  </div>
  <div class="field">
    <label>From</label>
    <input type="date" name="from" value="<?= e($from) ?>">
  </div>
  <div class="field">
    <label>To</label>
    <input type="date" name="to" value="<?= e($to) ?>">
  </div>
  <div class="field" style="flex:0">
    <label>&nbsp;</label>
    <button class="btn btn-outline" type="submit">Filter</button>
  </div>
</form>
<div class="card" id="list">
  <div class="card-head">
    <h2 class="card-title">Expense entries</h2>
    <?php render_limit_control('expenses.php'); ?>
  </div>
  <?php if (!$list['total']): ?>
    <div class="empty"><strong>No expenses recorded</strong><p>Add debit transactions under Land Purchase or Expenses categories.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Date</th><th>Company</th><th>Project</th><th>Category</th><th class="num">Amount</th></tr></thead>
        <tbody>
          <?php foreach ($list['rows'] as $row): ?>
            <tr>
              <td><?= e(format_date($row['txn_date'])) ?></td>
              <td><?= e($row['company_name']) ?></td>
              <td><?= e($row['project_name'] ?? '—') ?></td>
              <td><?= e($row['category_name']) ?> <span class="muted" style="font-size:0.72rem">(<?= e(str_replace('_',' ',$row['section'])) ?>)</span></td>
              <td class="num text-danger"><?= money($row['amount']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php render_pager('expenses.php', $list); ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
