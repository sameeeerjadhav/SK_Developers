<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$action = get('action', 'list');
$id = (int) get('id', 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $postAction = post('action', '');
    if ($postAction === 'save') {
        $companyId = (int) post('company_id', 0);
        $projectId = post('project_id') !== '' ? (int) post('project_id') : null;
        $lender = post('lender_name', '');
        $loanAmount = (float) post('loan_amount', 0);
        $outstanding = (float) post('outstanding_amount', 0);
        $rate = post('interest_rate') !== '' ? (float) post('interest_rate') : null;
        $start = post('start_date') ?: null;
        $end = post('end_date') ?: null;
        $status = post('status', 'active');
        $notes = post('notes', '');
        $editId = (int) post('id', 0);
        if (!$companyId || $lender === '') {
            flash('error', 'Company and lender name are required.');
            redirect('pages/bank-loans.php?action=add');
        }
        if ($editId) {
            $stmt = $pdo->prepare('UPDATE bank_loans SET company_id=?, project_id=?, lender_name=?, loan_amount=?, outstanding_amount=?, interest_rate=?, start_date=?, end_date=?, status=?, notes=? WHERE id=?');
            $stmt->execute([$companyId, $projectId, $lender, $loanAmount, $outstanding, $rate, $start, $end, $status, $notes, $editId]);
            flash('success', 'Bank loan updated.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO bank_loans (company_id, project_id, lender_name, loan_amount, outstanding_amount, interest_rate, start_date, end_date, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$companyId, $projectId, $lender, $loanAmount, $outstanding, $rate, $start, $end, $status, $notes]);
            flash('success', 'Bank loan added.');
        }
        redirect('pages/bank-loans.php');
    }
    if ($postAction === 'delete') {
        $pdo->prepare('DELETE FROM bank_loans WHERE id = ?')->execute([(int) post('id', 0)]);
        flash('success', 'Bank loan deleted.');
        redirect('pages/bank-loans.php');
    }
}

if ($action === 'add' || $action === 'edit') {
    $row = null;
    if ($action === 'edit' && $id) {
        $stmt = $pdo->prepare('SELECT * FROM bank_loans WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
    }
    $pageTitle = $action === 'edit' ? 'Edit bank loan' : 'Add bank loan';
    $pageActions = '<a class="btn btn-outline" href="' . e(base_url('pages/bank-loans.php')) . '">Back</a>';
    $preCompany = (int) ($row['company_id'] ?? 0);
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="card" style="max-width:780px">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>">
        <div>
          <label>Company</label>
          <select name="company_id" required data-company-projects="project_id" data-projects-url="<?= e(base_url('api/projects.php')) ?>">
            <?= company_options($pdo, $preCompany) ?>
          </select>
        </div>
        <div>
          <label>Project (optional)</label>
          <select name="project_id" id="project_id"><?= project_options($pdo, $preCompany ?: null, (int)($row['project_id'] ?? 0)) ?></select>
        </div>
        <div class="full">
          <label>Lender / bank name</label>
          <input type="text" name="lender_name" required value="<?= e($row['lender_name'] ?? '') ?>">
        </div>
        <div>
          <label>Loan amount (₹)</label>
          <input type="number" step="0.01" name="loan_amount" value="<?= e((string)($row['loan_amount'] ?? '0')) ?>">
        </div>
        <div>
          <label>Outstanding (₹)</label>
          <input type="number" step="0.01" name="outstanding_amount" value="<?= e((string)($row['outstanding_amount'] ?? '0')) ?>">
        </div>
        <div>
          <label>Interest rate %</label>
          <input type="number" step="0.01" name="interest_rate" value="<?= e((string)($row['interest_rate'] ?? '')) ?>">
        </div>
        <div>
          <label>Status</label>
          <select name="status">
            <option value="active" <?= (($row['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
            <option value="closed" <?= (($row['status'] ?? '') === 'closed') ? 'selected' : '' ?>>Closed</option>
          </select>
        </div>
        <div>
          <label>Start date</label>
          <input type="date" name="start_date" value="<?= e($row['start_date'] ?? '') ?>">
        </div>
        <div>
          <label>End date</label>
          <input type="date" name="end_date" value="<?= e($row['end_date'] ?? '') ?>">
        </div>
        <div class="full">
          <label>Notes</label>
          <textarea name="notes"><?= e($row['notes'] ?? '') ?></textarea>
        </div>
        <div class="full form-actions"><button class="btn btn-primary" type="submit">Save loan</button></div>
      </form>
    </div>
    <?php require __DIR__ . '/../includes/footer.php'; exit;
}

$pageTitle = 'Bank Loans';
$pageSub = 'Loan register with outstanding balances.';
$pageActions = '<a class="btn btn-primary" href="' . e(base_url('pages/bank-loans.php?action=add')) . '">+ Add loan</a>';
$loans = $pdo->query(
    'SELECT l.*, c.name AS company_name, p.name AS project_name
     FROM bank_loans l
     JOIN companies c ON c.id = l.company_id
     LEFT JOIN projects p ON p.id = l.project_id
     ORDER BY l.status, l.created_at DESC'
)->fetchAll();
$outstanding = array_sum(array_map(fn($l) => $l['status'] === 'active' ? (float)$l['outstanding_amount'] : 0, $loans));
require __DIR__ . '/../includes/header.php';
?>
<div class="stat-grid" style="grid-template-columns:repeat(2,1fr)">
  <div class="stat-card"><div class="stat-label">Active outstanding</div><div class="stat-value"><?= money($outstanding) ?></div></div>
  <div class="stat-card"><div class="stat-label">Loans</div><div class="stat-value"><?= count($loans) ?></div></div>
</div>
<div class="card">
  <?php if (!$loans): ?>
    <div class="empty"><strong>No bank loans</strong><p>Register loans against companies or projects.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Lender</th><th>Company</th><th>Project</th><th class="num">Loan</th><th class="num">Outstanding</th><th>Status</th><th class="actions">Actions</th></tr></thead>
        <tbody>
          <?php foreach ($loans as $l): ?>
            <tr>
              <td><strong><?= e($l['lender_name']) ?></strong><?php if ($l['interest_rate']): ?><div class="muted" style="font-size:0.72rem"><?= e((string)$l['interest_rate']) ?>%</div><?php endif; ?></td>
              <td><?= e($l['company_name']) ?></td>
              <td><?= e($l['project_name'] ?? '—') ?></td>
              <td class="num"><?= money($l['loan_amount']) ?></td>
              <td class="num"><?= money($l['outstanding_amount']) ?></td>
              <td><?= status_chip($l['status']) ?></td>
              <td class="actions">
                <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/bank-loans.php?action=edit&id=' . $l['id'])) ?>">Edit</a>
                <form method="post" style="display:inline" onsubmit="return confirm('Delete loan?')">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit">Delete</button>
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
