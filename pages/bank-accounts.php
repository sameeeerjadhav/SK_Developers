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
        $accountName = post('account_name', '');
        $bankName = post('bank_name', '');
        $accountNumber = post('account_number', '');
        $ifsc = post('ifsc', '');
        $opening = (float) post('opening_balance', 0);
        $notes = post('notes', '');
        $status = post('status', 'active');
        $editId = (int) post('id', 0);
        if (!$companyId || $accountName === '' || $bankName === '') {
            flash('error', 'Company, account name and bank name are required.');
            redirect('pages/bank-accounts.php?action=add');
        }
        if ($editId) {
            $stmt = $pdo->prepare('UPDATE bank_accounts SET company_id=?, account_name=?, bank_name=?, account_number=?, ifsc=?, opening_balance=?, notes=?, status=? WHERE id=?');
            $stmt->execute([$companyId, $accountName, $bankName, $accountNumber, $ifsc, $opening, $notes, $status, $editId]);
            flash('success', 'Bank account updated.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO bank_accounts (company_id, account_name, bank_name, account_number, ifsc, opening_balance, notes, status) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([$companyId, $accountName, $bankName, $accountNumber, $ifsc, $opening, $notes, $status]);
            flash('success', 'Bank account added.');
        }
        redirect('pages/bank-accounts.php');
    }
    if ($postAction === 'delete') {
        $pdo->prepare('DELETE FROM bank_accounts WHERE id = ?')->execute([(int) post('id', 0)]);
        flash('success', 'Bank account deleted.');
        redirect('pages/bank-accounts.php');
    }
}

if ($action === 'add' || $action === 'edit') {
    $row = null;
    if ($action === 'edit' && $id) {
        $stmt = $pdo->prepare('SELECT * FROM bank_accounts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
    }
    $pageTitle = $action === 'edit' ? 'Edit bank account' : 'Add bank account';
    $pageActions = '<a class="btn btn-outline" href="' . e(base_url('pages/bank-accounts.php')) . '">Back</a>';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="card" style="max-width:720px">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>">
        <div>
          <label>Company</label>
          <select name="company_id" required><?= company_options($pdo, (int)($row['company_id'] ?? 0)) ?></select>
        </div>
        <div>
          <label>Status</label>
          <select name="status">
            <option value="active" <?= (($row['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
            <option value="closed" <?= (($row['status'] ?? '') === 'closed') ? 'selected' : '' ?>>Closed</option>
          </select>
        </div>
        <div>
          <label>Account name</label>
          <input type="text" name="account_name" required value="<?= e($row['account_name'] ?? '') ?>">
        </div>
        <div>
          <label>Bank name</label>
          <input type="text" name="bank_name" required value="<?= e($row['bank_name'] ?? '') ?>">
        </div>
        <div>
          <label>Account number</label>
          <input type="text" name="account_number" value="<?= e($row['account_number'] ?? '') ?>">
        </div>
        <div>
          <label>IFSC</label>
          <input type="text" name="ifsc" value="<?= e($row['ifsc'] ?? '') ?>">
        </div>
        <div>
          <label>Opening balance (₹)</label>
          <input type="number" step="0.01" name="opening_balance" value="<?= e((string)($row['opening_balance'] ?? '0')) ?>">
        </div>
        <div class="full">
          <label>Notes</label>
          <textarea name="notes"><?= e($row['notes'] ?? '') ?></textarea>
        </div>
        <div class="full form-actions"><button class="btn btn-primary" type="submit">Save account</button></div>
      </form>
    </div>
    <?php require __DIR__ . '/../includes/footer.php'; exit;
}

$pageTitle = 'Bank Accounts';
$pageSub = 'Balances update from linked credit and debit transactions.';
$pageActions = '<a class="btn btn-primary" href="' . e(base_url('pages/bank-accounts.php?action=add')) . '">+ Add account</a>';
$accounts = $pdo->query(
    'SELECT ba.*, c.name AS company_name FROM bank_accounts ba JOIN companies c ON c.id = ba.company_id ORDER BY ba.status, ba.account_name'
)->fetchAll();
$totalBalance = 0;
foreach ($accounts as &$acc) {
    $acc['balance'] = account_balance($pdo, (int) $acc['id']);
    if ($acc['status'] === 'active') {
        $totalBalance += $acc['balance'];
    }
}
unset($acc);

require __DIR__ . '/../includes/header.php';
?>
<div class="stat-grid" style="grid-template-columns:repeat(2,1fr)">
  <div class="stat-card"><div class="stat-label">Combined balance</div><div class="stat-value"><?= money($totalBalance) ?></div></div>
  <div class="stat-card"><div class="stat-label">Active accounts</div><div class="stat-value"><?= count(array_filter($accounts, fn($a) => $a['status'] === 'active')) ?></div></div>
</div>
<div class="card">
  <?php if (!$accounts): ?>
    <div class="empty"><strong>No bank accounts</strong><p>Add accounts for main and sub companies to track spent vs balance.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Account</th><th>Company</th><th>Bank</th><th class="num">Opening</th><th class="num">Live balance</th><th>Status</th><th class="actions">Actions</th></tr></thead>
        <tbody>
          <?php foreach ($accounts as $a): ?>
            <tr>
              <td>
                <strong><?= e($a['account_name']) ?></strong>
                <div class="muted" style="font-size:0.72rem"><?= e($a['account_number'] ?? '') ?></div>
              </td>
              <td><?= e($a['company_name']) ?></td>
              <td><?= e($a['bank_name']) ?></td>
              <td class="num"><?= money($a['opening_balance']) ?></td>
              <td class="num <?= $a['balance'] >= 0 ? 'text-success' : 'text-danger' ?>"><strong><?= money($a['balance']) ?></strong></td>
              <td><?= status_chip($a['status']) ?></td>
              <td class="actions">
                <a class="btn btn-primary btn-sm" href="<?= e(base_url('pages/bank-account-view.php?id=' . $a['id'])) ?>">Statement</a>
                <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/bank-accounts.php?action=edit&id=' . $a['id'])) ?>">Edit</a>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete account?">Delete</button>
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
