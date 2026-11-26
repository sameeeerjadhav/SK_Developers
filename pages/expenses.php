<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

// "Add category": create an Expense / Land Purchase category. On a validation error we fall through and
// re-render the page with the modal open and the typed values kept; on success it's post-redirect-get.
$catError = null;
$catForm = ['name' => '', 'section' => 'expense'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action', '') === 'add_category') {
    verify_csrf();
    $catForm = ['name' => (string) post('category_name', ''), 'section' => (string) post('category_section', 'expense')];
    $created = create_category($pdo, $catForm['section'], $catForm['name']);
    if ($created['ok']) {
        $sectionLabel = $created['section'] === 'land_purchase' ? 'Land Purchase' : 'Expense';
        audit_log($pdo, 'create', 'category', $created['id'], 'Added ' . $sectionLabel . ' category "' . $created['name'] . '"', null, [
            'name' => $created['name'], 'section' => $created['section'],
        ]);
        flash('success', 'Category “' . $created['name'] . '” added under ' . $sectionLabel . '. It is now in the Add expense dropdown.');
        redirect(list_return_url('expenses.php', [], ''));
    }
    $catError = (string) $created['error'];
}

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
    . '<button type="button" class="btn btn-outline" id="openCategoryModal">Add category</button>'
    . '<a class="btn btn-primary" href="' . e(base_url('pages/transactions.php?action=add&section=expense&slug=office_expenses')) . '">+ Add expense</a>';

// Existing categories per type, shown in the modal so duplicates are obvious before saving.
$catByType = ['expense' => [], 'land_purchase' => []];
foreach ($pdo->query("SELECT name, section FROM categories WHERE section IN ('expense','land_purchase') ORDER BY sort_order, name") as $c) {
    $catByType[$c['section']][] = $c['name'];
}

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

<div id="addCategoryModal" class="sk-modal-overlay" <?= $catError ? '' : 'style="display:none"' ?>>
  <div class="sk-modal" role="dialog" aria-modal="true" aria-labelledby="addCategoryTitle">
    <button type="button" class="sk-modal-close" id="closeCategoryModal" aria-label="Close">&times;</button>
    <div class="sk-modal-title" id="addCategoryTitle">Add category</div>
    <form method="post" class="form-grid" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_category">
      <div class="full">
        <label for="cat_name">Category name</label>
        <input type="text" id="cat_name" name="category_name" maxlength="120" required value="<?= e($catForm['name']) ?>" placeholder="e.g. Site survey, Fuel, Legal fees">
      </div>
      <div class="full">
        <label for="cat_section">Type</label>
        <select id="cat_section" name="category_section">
          <option value="expense" <?= $catForm['section'] === 'expense' ? 'selected' : '' ?>>Expense</option>
          <option value="land_purchase" <?= $catForm['section'] === 'land_purchase' ? 'selected' : '' ?>>Land Purchase</option>
        </select>
        <p class="muted" style="font-size:0.78rem;margin:0.4rem 0 0">Both types appear in the Add expense dropdown and count toward expense totals.</p>
      </div>
      <div class="full">
        <label>Already under this type</label>
        <p class="muted" id="cat_existing" style="font-size:0.82rem;margin:0;line-height:1.5;overflow-wrap:anywhere"></p>
      </div>
      <div class="full" id="cat_error" style="<?= $catError ? '' : 'display:none;' ?>color:var(--danger,#dc2626);font-size:0.85rem;font-weight:600;overflow-wrap:anywhere"><?= e((string) $catError) ?></div>
      <div class="full form-actions">
        <button type="submit" class="btn btn-primary" id="cat_save">Save category</button>
        <button type="button" class="btn btn-outline" id="cancelCategoryModal">Cancel</button>
      </div>
    </form>
  </div>
</div>
<script>
  (function () {
    var CATS = <?= json_encode($catByType, JSON_UNESCAPED_UNICODE) ?>;
    var modal = document.getElementById('addCategoryModal');
    // .content's fill-mode transform animation would otherwise become the containing block for this fixed overlay.
    document.body.appendChild(modal);
    var nameEl = document.getElementById('cat_name');
    var sectionEl = document.getElementById('cat_section');
    var existingEl = document.getElementById('cat_existing');
    var errorEl = document.getElementById('cat_error');
    var saveBtn = document.getElementById('cat_save');

    // Same rule as the server: names are the same if they match ignoring case, or normalise to the same latin text.
    function norm(s) { return s.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, ''); }
    function refresh() {
      var list = CATS[sectionEl.value] || [];
      existingEl.textContent = list.length ? list.join(' · ') : 'Nothing yet.';
      var value = nameEl.value.trim().replace(/\s+/g, ' ');
      var shape = norm(value);
      var dup = '';
      if (value) {
        list.forEach(function (x) {
          if (!dup && (x.toLowerCase() === value.toLowerCase() || (shape && norm(x) === shape))) dup = x;
        });
      }
      if (dup) {
        errorEl.textContent = '“' + dup + '” already exists under this type.';
        errorEl.style.display = '';
      } else if (!<?= $catError ? 'true' : 'false' ?> || nameEl.dataset.touched) {
        errorEl.style.display = 'none';
      }
      saveBtn.disabled = !!dup || !value;
    }
    function openModal() { modal.style.display = ''; refresh(); nameEl.focus(); }
    function closeModal() { modal.style.display = 'none'; }

    document.getElementById('openCategoryModal').addEventListener('click', openModal);
    document.getElementById('closeCategoryModal').addEventListener('click', closeModal);
    document.getElementById('cancelCategoryModal').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.style.display !== 'none') closeModal(); });
    nameEl.addEventListener('input', function () { nameEl.dataset.touched = '1'; refresh(); });
    sectionEl.addEventListener('change', refresh);
    refresh();
    <?php if ($catError): ?>nameEl.focus();<?php endif; ?>
  })();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
