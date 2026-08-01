<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

/** Renders one Credit/Debit column: table of rows plus a totals row at the end. */
function render_txn_column(array $rows, string $type, float $total): void
{
    $isCredit = $type === 'credit';
    ?>
    <div class="card">
      <div class="card-head">
        <h2 class="card-title"><?= txn_type_chip($type) ?> <?= $isCredit ? 'Credit' : 'Debit' ?></h2>
        <span class="muted" style="font-size:0.8rem"><?= count($rows) ?> entries</span>
      </div>
      <?php if (!$rows): ?>
        <div class="empty"><strong>No <?= $isCredit ? 'credit' : 'debit' ?> entries</strong><p>Nothing recorded for the current filters.</p></div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data">
            <thead>
              <tr>
                <th>Date</th>
                <th>Company / Project</th>
                <th>Category</th>
                <th class="num">Amount</th>
                <th class="actions">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= e($row['txn_date']) ?></td>
                  <td>
                    <strong><?= e($row['company_name']) ?></strong>
                    <div class="muted" style="font-size:0.75rem"><?= e($row['project_name'] ?? 'No project') ?><?= $row['partner_name'] ? ' · ' . e($row['partner_name']) : '' ?></div>
                  </td>
                  <td>
                    <?= e($row['category_name']) ?>
                    <div class="muted" style="font-size:0.72rem"><?= e(ucwords(str_replace('_', ' ', $row['section']))) ?></div>
                  </td>
                  <td class="num <?= $isCredit ? 'text-success' : 'text-danger' ?>">
                    <?= $isCredit ? '+' : '−' ?><?= money($row['amount']) ?>
                  </td>
                  <td class="actions">
                    <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/transactions.php?action=edit&id=' . $row['id'])) ?>">Edit</a>
                    <?php if (can_delete()): ?>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                      <button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete this transaction?">Delete</button>
                    </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="3">Total <?= $isCredit ? 'credit' : 'debit' ?></td>
                <td class="num <?= $isCredit ? 'text-success' : 'text-danger' ?>"><?= money($total) ?></td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <?php
}

$action = get('action', 'list');
$id = (int) get('id', 0);
$q = get('q', '');
$filterCompany = (int) get('company_id', 0);
$filterProject = (int) get('project_id', 0);
$filterType = get('txn_type', '');
$filterFrom = get('from', '');
$filterTo = get('to', '');
$preCategory = (int) get('category_id', 0);
$preSlug = get('slug', '');
$preSection = get('section', '');

if ($preCategory <= 0 && $preSlug !== '') {
    $section = $preSection !== '' ? $preSection : 'credit';
    $preCategory = (int) (category_id_by_slug($pdo, $section, $preSlug) ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $postAction = post('action', '');

    if ($postAction === 'save') {
        $companyId = (int) post('company_id', 0);
        $projectId = post('project_id') !== '' ? (int) post('project_id') : null;
        $bankAccountId = post('bank_account_id') !== '' ? (int) post('bank_account_id') : null;
        $partnerId = post('partner_id') !== '' ? (int) post('partner_id') : null;
        $categoryId = (int) post('category_id', 0);
        $amount = (float) post('amount', 0);
        $txnDate = post('txn_date', date('Y-m-d'));
        $reference = post('reference_no', '');
        $description = post('description', '');
        $editId = (int) post('id', 0);

        $cat = $pdo->prepare('SELECT section, slug FROM categories WHERE id = ?');
        $cat->execute([$categoryId]);
        $catRow = $cat->fetch();
        if (!$catRow) {
            flash('error', 'Invalid category.');
            redirect('pages/transactions.php?action=add');
        }
        $txnType = $catRow['section'] === 'credit' ? 'credit' : 'debit';

        if (!$companyId || !$categoryId || $amount <= 0) {
            flash('error', 'Company, category and a positive amount are required.');
            redirect('pages/transactions.php?action=add');
        }

        // Only attach partner on partner credits
        if ($catRow['slug'] !== 'partner') {
            $partnerId = null;
        }

        $userId = current_user()['id'] ?? null;
        $txnId = $editId;
        $before = null;

        if ($editId) {
            $beforeStmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ?');
            $beforeStmt->execute([$editId]);
            $before = $beforeStmt->fetch() ?: null;
            $stmt = $pdo->prepare('UPDATE transactions SET company_id=?, project_id=?, bank_account_id=?, category_id=?, partner_id=?, txn_type=?, amount=?, txn_date=?, reference_no=?, description=? WHERE id=?');
            $stmt->execute([$companyId, $projectId, $bankAccountId, $categoryId, $partnerId, $txnType, $amount, $txnDate, $reference, $description, $editId]);
            audit_log($pdo, 'update', 'transaction', $editId, 'Updated txn #' . $editId . ' to ' . money($amount), $before, [
                'amount' => $amount, 'txn_date' => $txnDate, 'category_id' => $categoryId, 'description' => $description,
            ]);
            flash('success', 'Transaction updated.');
        } else {
            $txnId = create_transaction($pdo, $companyId, $categoryId, $txnType, $amount, $txnDate, $projectId, $bankAccountId, $partnerId, $reference, $description, $userId ? (int) $userId : null);
            audit_log($pdo, 'create', 'transaction', $txnId, 'Created ' . $txnType . ' ' . money($amount));
            flash('success', 'Transaction added.');
        }

        if (!empty($_FILES['attachments']) && $txnId) {
            $uploaded = save_transaction_uploads($pdo, (int) $txnId, $_FILES['attachments'], $userId ? (int) $userId : null);
            if ($uploaded > 0) {
                flash('success', ($editId ? 'Transaction updated' : 'Transaction added') . " with {$uploaded} attachment(s).");
            }
        }

        if ($partnerId) {
            sync_partner_invested($pdo, $partnerId);
        }

        if ($projectId) {
            redirect('pages/project-view.php?id=' . $projectId);
        }
        redirect('pages/transactions.php');
    }

    if ($postAction === 'delete') {
        if (!can_delete()) {
            flash('error', 'Only admins can delete transactions.');
            redirect('pages/transactions.php');
        }
        $delId = (int) post('id', 0);
        $stmt = $pdo->prepare('SELECT partner_id FROM transactions WHERE id = ?');
        $stmt->execute([$delId]);
        $partnerId = $stmt->fetchColumn();
        // remove files
        $atts = $pdo->prepare('SELECT stored_name FROM attachments WHERE transaction_id = ?');
        $atts->execute([$delId]);
        foreach ($atts->fetchAll() as $att) {
            $path = uploads_dir() . '/' . $att['stored_name'];
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $pdo->prepare('DELETE FROM transactions WHERE id = ?')->execute([$delId]);
        audit_log($pdo, 'delete', 'transaction', $delId, 'Deleted transaction #' . $delId);
        if ($partnerId) {
            sync_partner_invested($pdo, (int) $partnerId);
        }
        flash('success', 'Transaction deleted.');
        redirect('pages/transactions.php');
    }
}

if ($action === 'add' || $action === 'edit') {
    $txn = null;
    if ($action === 'edit' && $id) {
        $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ?');
        $stmt->execute([$id]);
        $txn = $stmt->fetch();
        if (!$txn) {
            flash('error', 'Transaction not found.');
            redirect('pages/transactions.php');
        }
    }

    $preCompany = (int) ($txn['company_id'] ?? $filterCompany ?: 0);
    $preProject = (int) ($txn['project_id'] ?? $filterProject ?: 0);
    $selectedCategory = (int) ($txn['category_id'] ?? $preCategory ?: 0);

    // Categories for the Type (Credit/Debit) -> Category picker. Debit categories are grouped by
    // their original section (Land Purchase / Expense / General) via <optgroup> so nothing is hidden.
    $creditCats = [];
    $debitGroups = ['land_purchase' => [], 'expense' => [], 'general' => []];
    $catGroupStmt = $pdo->query(
        "SELECT id, name, section FROM categories
         WHERE section IN ('credit','land_purchase','expense')
            OR (section = 'general' AND slug IN ('investment_withdrawal','daily_debit','monthly_debit'))
         ORDER BY FIELD(section,'credit','land_purchase','expense','general'), sort_order"
    );
    foreach ($catGroupStmt->fetchAll() as $c) {
        if ($c['section'] === 'credit') {
            $creditCats[] = ['id' => (int) $c['id'], 'name' => $c['name']];
        } else {
            $debitGroups[$c['section']][] = ['id' => (int) $c['id'], 'name' => $c['name']];
        }
    }
    $catData = ['credit' => $creditCats, 'debit' => $debitGroups];
    $debitGroupLabels = ['land_purchase' => 'Land Purchase', 'expense' => 'Expense', 'general' => 'General'];

    $selectedType = 'credit';
    if ($selectedCategory) {
        $secStmt = $pdo->prepare('SELECT section FROM categories WHERE id = ?');
        $secStmt->execute([$selectedCategory]);
        $selectedType = ($secStmt->fetchColumn() === 'credit') ? 'credit' : 'debit';
    }

    $pageTitle = $action === 'edit' ? 'Edit transaction' : 'Add transaction';
    $pageSub = 'Record credit (in) or debit (land / expense) against a company and project.';
    $pageActions = '<a class="btn btn-outline" href="' . e(base_url('pages/transactions.php')) . '">Back</a>';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="card" style="max-width:860px">
      <form method="post" class="form-grid" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($txn['id'] ?? 0) ?>">
        <div>
          <label>Company</label>
          <select name="company_id" id="company_id" required
            data-company-projects="project_id"
            data-company-accounts="bank_account_id"
            data-company-partners="partner_id"
            data-projects-url="<?= e(base_url('api/projects.php')) ?>"
            data-accounts-url="<?= e(base_url('api/bank-accounts.php')) ?>"
            data-partners-url="<?= e(base_url('api/partners.php')) ?>">
            <?= company_options($pdo, $preCompany) ?>
          </select>
        </div>
        <div>
          <label>Project</label>
          <select name="project_id" id="project_id">
            <?= project_options($pdo, $preCompany ?: null, $preProject) ?>
          </select>
        </div>
        <div>
          <label>Type</label>
          <select id="txn_type_select">
            <option value="credit" <?= $selectedType === 'credit' ? 'selected' : '' ?>>Credit (money in)</option>
            <option value="debit" <?= $selectedType === 'debit' ? 'selected' : '' ?>>Debit (money out)</option>
          </select>
        </div>
        <div class="full">
          <label>Category</label>
          <select name="category_id" id="txn_category_id" required></select>
        </div>
        <div>
          <label>Amount (₹)</label>
          <input type="number" step="0.01" min="0.01" name="amount" required value="<?= e((string) ($txn['amount'] ?? '')) ?>">
        </div>
        <div>
          <label>Date</label>
          <input type="date" name="txn_date" required value="<?= e($txn['txn_date'] ?? date('Y-m-d')) ?>">
        </div>
        <div>
          <label>Bank account (optional)</label>
          <select name="bank_account_id" id="bank_account_id">
            <?= bank_account_options($pdo, $preCompany ?: null, (int) ($txn['bank_account_id'] ?? 0)) ?>
          </select>
        </div>
        <div>
          <label>Partner (for Partner credits)</label>
          <select name="partner_id" id="partner_id">
            <?= partner_options($pdo, $preCompany ?: null, (int) ($txn['partner_id'] ?? 0)) ?>
          </select>
        </div>
        <div>
          <label>Reference no.</label>
          <input type="text" name="reference_no" value="<?= e($txn['reference_no'] ?? '') ?>">
        </div>
        <div class="full">
          <label>Description</label>
          <textarea name="description"><?= e($txn['description'] ?? '') ?></textarea>
        </div>
        <div class="full">
          <label>Attachments (PDF / images, max 5MB each)</label>
          <input type="file" name="attachments[]" accept=".pdf,image/*" multiple>
          <?php
          if (!empty($txn['id'])) {
              $attStmt = $pdo->prepare('SELECT * FROM attachments WHERE transaction_id = ? ORDER BY id');
              $attStmt->execute([(int)$txn['id']]);
              $existingAtts = $attStmt->fetchAll();
              if ($existingAtts) {
                  echo '<div style="margin-top:0.65rem;display:flex;flex-wrap:wrap;gap:0.45rem">';
                  foreach ($existingAtts as $att) {
                      echo '<a class="chip chip-primary" href="' . e(base_url('pages/attachment.php?id=' . $att['id'])) . '" target="_blank">' . e($att['original_name']) . '</a>';
                  }
                  echo '</div>';
              }
          }
          ?>
        </div>
        <div class="full highlight-box">
          Credit categories increase money in. Land purchase &amp; expense categories are debits.
          Linking a bank account updates its live balance. Partner field syncs partner invested totals.
        </div>
        <div class="full form-actions">
          <button class="btn btn-primary" type="submit">Save transaction</button>
        </div>
      </form>
    </div>
    <script>
      (function () {
        var CATEGORY_DATA = <?= json_encode($catData) ?>;
        var DEBIT_GROUP_LABELS = <?= json_encode($debitGroupLabels) ?>;
        var preselectId = <?= (int) $selectedCategory ?>;
        var typeEl = document.getElementById('txn_type_select');
        var categoryEl = document.getElementById('txn_category_id');

        function addOption(parent, opt, selectId, state) {
          var o = document.createElement('option');
          o.value = String(opt.id);
          o.textContent = opt.name;
          if (selectId && Number(selectId) === opt.id) {
            o.selected = true;
            state.matched = true;
          }
          parent.appendChild(o);
        }

        function populateCategories(selectId) {
          categoryEl.innerHTML = '';
          var placeholder = document.createElement('option');
          placeholder.value = '';
          placeholder.textContent = 'Select category';
          categoryEl.appendChild(placeholder);

          var state = { matched: false };
          if (typeEl.value === 'credit') {
            (CATEGORY_DATA.credit || []).forEach(function (opt) {
              addOption(categoryEl, opt, selectId, state);
            });
          } else {
            Object.keys(CATEGORY_DATA.debit || {}).forEach(function (key) {
              var items = CATEGORY_DATA.debit[key] || [];
              if (!items.length) return;
              var group = document.createElement('optgroup');
              group.label = DEBIT_GROUP_LABELS[key] || key;
              items.forEach(function (opt) {
                addOption(group, opt, selectId, state);
              });
              categoryEl.appendChild(group);
            });
          }
          if (!state.matched) placeholder.selected = true;
        }

        typeEl.addEventListener('change', function () { populateCategories(null); });
        populateCategories(preselectId);
      })();
    </script>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

[$fromMonth, $toMonth, $month, $year] = period_from_request();
if ($month && $filterFrom === '' && $filterTo === '') {
    $filterFrom = $fromMonth ?: '';
    $filterTo = $toMonth ?: '';
}

$pageTitle = 'Transactions';
$pageSub = 'Full ledger across companies and projects.';
$pageActions = '<a class="btn btn-primary" href="' . e(base_url('pages/transactions.php?action=add')) . '">+ Add transaction</a>';

$sql = 'SELECT t.*, c.name AS company_name, cat.name AS category_name, cat.section, p.name AS project_name, pr.name AS partner_name
        FROM transactions t
        JOIN companies c ON c.id = t.company_id
        JOIN categories cat ON cat.id = t.category_id
        LEFT JOIN projects p ON p.id = t.project_id
        LEFT JOIN partners pr ON pr.id = t.partner_id
        WHERE 1=1';
$params = [];
if ($filterCompany) { $sql .= ' AND t.company_id = ?'; $params[] = $filterCompany; }
if ($filterProject) { $sql .= ' AND t.project_id = ?'; $params[] = $filterProject; }
if ($filterType !== '') { $sql .= ' AND t.txn_type = ?'; $params[] = $filterType; }
if ($filterFrom !== '') { $sql .= ' AND t.txn_date >= ?'; $params[] = $filterFrom; }
if ($filterTo !== '') { $sql .= ' AND t.txn_date <= ?'; $params[] = $filterTo; }
if ($q !== '') {
    $sql .= ' AND (t.description LIKE ? OR t.reference_no LIKE ? OR cat.name LIKE ? OR c.name LIKE ? OR p.name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
$sql .= ' ORDER BY t.txn_date DESC, t.id DESC LIMIT 300';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$creditRows = array_values(array_filter($rows, fn($r) => $r['txn_type'] === 'credit'));
$debitRows = array_values(array_filter($rows, fn($r) => $r['txn_type'] === 'debit'));
$creditTotal = array_sum(array_map(fn($r) => (float) $r['amount'], $creditRows));
$debitTotal = array_sum(array_map(fn($r) => (float) $r['amount'], $debitRows));

require __DIR__ . '/../includes/header.php';
?>

<form class="filters" method="get">
  <?= period_filter_fields($month, $year) ?>
  <div class="field">
    <label>Search</label>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Reference, description…">
  </div>
  <div class="field">
    <label>Company</label>
    <select name="company_id">
      <option value="">All</option>
      <?php foreach ($pdo->query('SELECT id, name FROM companies ORDER BY type, name') as $co): ?>
        <option value="<?= (int)$co['id'] ?>" <?= $filterCompany === (int)$co['id'] ? 'selected' : '' ?>><?= e($co['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($filterProject): ?>
    <input type="hidden" name="project_id" value="<?= $filterProject ?>">
  <?php endif; ?>
  <div class="field">
    <label>Type</label>
    <select name="txn_type">
      <option value="">All</option>
      <option value="credit" <?= $filterType === 'credit' ? 'selected' : '' ?>>Credit</option>
      <option value="debit" <?= $filterType === 'debit' ? 'selected' : '' ?>>Debit</option>
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

<div class="txn-split">
  <?php
  render_txn_column($creditRows, 'credit', $creditTotal);
  render_txn_column($debitRows, 'debit', $debitTotal);
  ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
