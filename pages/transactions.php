<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$action = get('action', 'list');
$id = (int) get('id', 0);
$q = get('q', '');
$filterCompany = (int) get('company_id', 0);
$filterProject = (int) get('project_id', 0);
$filterType = get('txn_type', '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $postAction = post('action', '');

    if ($postAction === 'save') {
        $companyId = (int) post('company_id', 0);
        $projectId = post('project_id') !== '' ? (int) post('project_id') : null;
        $bankAccountId = post('bank_account_id') !== '' ? (int) post('bank_account_id') : null;
        $categoryId = (int) post('category_id', 0);
        $amount = (float) post('amount', 0);
        $txnDate = post('txn_date', date('Y-m-d'));
        $reference = post('reference_no', '');
        $description = post('description', '');
        $editId = (int) post('id', 0);

        $cat = $pdo->prepare('SELECT section FROM categories WHERE id = ?');
        $cat->execute([$categoryId]);
        $section = $cat->fetchColumn();
        if (!$section) {
            flash('error', 'Invalid category.');
            redirect('pages/transactions.php?action=add');
        }
        $txnType = $section === 'credit' ? 'credit' : 'debit';

        if (!$companyId || !$categoryId || $amount <= 0) {
            flash('error', 'Company, category and a positive amount are required.');
            redirect('pages/transactions.php?action=add');
        }

        $userId = current_user()['id'] ?? null;

        if ($editId) {
            $stmt = $pdo->prepare('UPDATE transactions SET company_id=?, project_id=?, bank_account_id=?, category_id=?, txn_type=?, amount=?, txn_date=?, reference_no=?, description=? WHERE id=?');
            $stmt->execute([$companyId, $projectId, $bankAccountId, $categoryId, $txnType, $amount, $txnDate, $reference, $description, $editId]);
            flash('success', 'Transaction updated.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO transactions (company_id, project_id, bank_account_id, category_id, txn_type, amount, txn_date, reference_no, description, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$companyId, $projectId, $bankAccountId, $categoryId, $txnType, $amount, $txnDate, $reference, $description, $userId]);
            flash('success', 'Transaction added.');
        }

        if ($projectId) {
            redirect('pages/project-view.php?id=' . $projectId);
        }
        redirect('pages/transactions.php');
    }

    if ($postAction === 'delete') {
        $delId = (int) post('id', 0);
        $stmt = $pdo->prepare('DELETE FROM transactions WHERE id = ?');
        $stmt->execute([$delId]);
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

    $pageTitle = $action === 'edit' ? 'Edit transaction' : 'Add transaction';
    $pageSub = 'Record credit (in) or debit (land / expense) against a company and project.';
    $pageActions = '<a class="btn btn-outline" href="' . e(base_url('pages/transactions.php')) . '">Back</a>';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="card" style="max-width:820px">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($txn['id'] ?? 0) ?>">
        <div>
          <label>Company</label>
          <select name="company_id" id="company_id" required data-company-projects="project_id" data-projects-url="<?= e(base_url('api/projects.php')) ?>">
            <?= company_options($pdo, $preCompany) ?>
          </select>
        </div>
        <div>
          <label>Project</label>
          <select name="project_id" id="project_id">
            <?= project_options($pdo, $preCompany ?: null, $preProject) ?>
          </select>
        </div>
        <div class="full">
          <label>Category</label>
          <select name="category_id" required>
            <?= category_options($pdo, null, (int) ($txn['category_id'] ?? 0)) ?>
          </select>
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
          <select name="bank_account_id">
            <?= bank_account_options($pdo, $preCompany ?: null, (int) ($txn['bank_account_id'] ?? 0)) ?>
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
        <div class="full highlight-box">
          Credit categories increase money in. Land purchase &amp; expense categories are debits. Linking a bank account updates its live balance.
        </div>
        <div class="full form-actions">
          <button class="btn btn-primary" type="submit">Save transaction</button>
        </div>
      </form>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$pageTitle = 'Transactions';
$pageSub = 'Full ledger across companies and projects.';
$pageActions = '<a class="btn btn-primary" href="' . e(base_url('pages/transactions.php?action=add')) . '">+ Add transaction</a>';

$sql = 'SELECT t.*, c.name AS company_name, cat.name AS category_name, cat.section, p.name AS project_name
        FROM transactions t
        JOIN companies c ON c.id = t.company_id
        JOIN categories cat ON cat.id = t.category_id
        LEFT JOIN projects p ON p.id = t.project_id
        WHERE 1=1';
$params = [];
if ($filterCompany) { $sql .= ' AND t.company_id = ?'; $params[] = $filterCompany; }
if ($filterProject) { $sql .= ' AND t.project_id = ?'; $params[] = $filterProject; }
if ($filterType !== '') { $sql .= ' AND t.txn_type = ?'; $params[] = $filterType; }
if ($q !== '') {
    $sql .= ' AND (t.description LIKE ? OR t.reference_no LIKE ? OR cat.name LIKE ? OR c.name LIKE ? OR p.name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
$sql .= ' ORDER BY t.txn_date DESC, t.id DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<form class="filters" method="get">
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
  <div class="field">
    <label>Type</label>
    <select name="txn_type">
      <option value="">All</option>
      <option value="credit" <?= $filterType === 'credit' ? 'selected' : '' ?>>Credit</option>
      <option value="debit" <?= $filterType === 'debit' ? 'selected' : '' ?>>Debit</option>
    </select>
  </div>
  <div class="field" style="flex:0">
    <label>&nbsp;</label>
    <button class="btn btn-outline" type="submit">Filter</button>
  </div>
</form>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty"><strong>No transactions</strong><p>Add investment, booking, land purchase or expense entries.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>Date</th>
            <th>Company / Project</th>
            <th>Category</th>
            <th>Type</th>
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
                <div class="muted" style="font-size:0.75rem"><?= e($row['project_name'] ?? 'No project') ?></div>
              </td>
              <td>
                <?= e($row['category_name']) ?>
                <div class="muted" style="font-size:0.72rem"><?= e(ucwords(str_replace('_',' ',$row['section']))) ?></div>
              </td>
              <td><?= $row['txn_type'] === 'credit' ? status_chip('active') : status_chip('on_hold') ?></td>
              <td class="num <?= $row['txn_type'] === 'credit' ? 'text-success' : 'text-danger' ?>">
                <?= $row['txn_type'] === 'credit' ? '+' : '−' ?><?= money($row['amount']) ?>
              </td>
              <td class="actions">
                <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/transactions.php?action=edit&id=' . $row['id'])) ?>">Edit</a>
                <form method="post" style="display:inline" data-confirm="Delete this transaction?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete this transaction?">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
