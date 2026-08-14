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
        $bankAccountId = post('bank_account_id') !== '' ? (int) post('bank_account_id') : null;
        $title = post('title', '');
        $amount = (float) post('amount', 0);
        $depositDate = post('deposit_date') ?: null;
        $maturity = post('maturity_date') ?: null;
        $rate = post('interest_rate') !== '' ? (float) post('interest_rate') : null;
        $status = post('status', 'active');
        $notes = post('notes', '');
        $postToLedger = !empty($_POST['post_to_ledger']);
        $editId = (int) post('id', 0);
        if (!$companyId || $title === '') {
            flash('error', 'Company and title are required.');
            redirect('pages/deposits.php?action=add');
        }
        if ($editId) {
            $stmt = $pdo->prepare('UPDATE deposits SET company_id=?, bank_account_id=?, title=?, amount=?, deposit_date=?, maturity_date=?, interest_rate=?, status=?, notes=? WHERE id=?');
            $stmt->execute([$companyId, $bankAccountId, $title, $amount, $depositDate, $maturity, $rate, $status, $notes, $editId]);
            flash('success', 'Deposit updated.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO deposits (company_id, bank_account_id, title, amount, deposit_date, maturity_date, interest_rate, status, notes) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$companyId, $bankAccountId, $title, $amount, $depositDate, $maturity, $rate, $status, $notes]);

            if ($postToLedger && $amount > 0 && $bankAccountId) {
                $depCat = category_id_by_slug($pdo, 'general', 'deposit');
                if ($depCat) {
                    create_transaction(
                        $pdo,
                        $companyId,
                        $depCat,
                        'debit',
                        $amount,
                        $depositDate ?: date('Y-m-d'),
                        null,
                        $bankAccountId,
                        null,
                        null,
                        'Deposit placed — ' . $title,
                        current_user()['id'] ?? null
                    );
                }
            }
            flash('success', 'Deposit added' . ($postToLedger && $bankAccountId ? ' and bank balance updated.' : '.'));
        }
        redirect('pages/deposits.php');
    }
    if ($postAction === 'delete') {
        if (!can_delete()) {
            flash('error', 'Only admins can delete deposits.');
            redirect('pages/deposits.php');
        }
        $delId = (int) post('id', 0);
        $rowStmt = $pdo->prepare('SELECT * FROM deposits WHERE id = ?');
        $rowStmt->execute([$delId]);
        $deposit = $rowStmt->fetch();
        if ($deposit) {
            delete_ledger_for_record(
                $pdo,
                (int) $deposit['company_id'],
                'general',
                'deposit',
                (float) $deposit['amount'],
                'Deposit placed — ' . $deposit['title'],
                $deposit['bank_account_id'] ? (int) $deposit['bank_account_id'] : null
            );
            $pdo->prepare('DELETE FROM deposits WHERE id = ?')->execute([$delId]);
            audit_log($pdo, 'delete', 'deposit', $delId, 'Deleted deposit ' . $deposit['title']);
        }
        flash('success', 'Deposit deleted.');
        redirect('pages/deposits.php');
    }
}

if ($action === 'add' || $action === 'edit') {
    $row = null;
    if ($action === 'edit' && $id) {
        $stmt = $pdo->prepare('SELECT * FROM deposits WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
    }
    $pageTitle = $action === 'edit' ? 'Edit deposit' : 'Add deposit';
    $pageActions = '<a class="btn btn-outline" href="' . e(base_url('pages/deposits.php')) . '">Back</a>';
    $preCompany = (int) ($row['company_id'] ?? 0);
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="card" style="max-width:720px">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>">
        <div>
          <label>Company</label>
          <select name="company_id" required data-company-accounts="bank_account_id" data-accounts-url="<?= e(base_url('api/bank-accounts.php')) ?>">
            <?= company_options($pdo, $preCompany) ?>
          </select>
        </div>
        <div>
          <label>Linked bank account</label>
          <select name="bank_account_id" id="bank_account_id"><?= bank_account_options($pdo, $preCompany ?: null, (int)($row['bank_account_id'] ?? 0)) ?></select>
        </div>
        <div class="full">
          <label>Title</label>
          <input type="text" name="title" required value="<?= e($row['title'] ?? '') ?>" placeholder="FD / RD / Security deposit">
        </div>
        <div>
          <label>Amount (₹)</label>
          <input type="number" step="0.01" name="amount" value="<?= e((string)($row['amount'] ?? '0')) ?>">
        </div>
        <div>
          <label>Interest rate %</label>
          <input type="number" step="0.01" name="interest_rate" value="<?= e((string)($row['interest_rate'] ?? '')) ?>">
        </div>
        <div>
          <label>Deposit date</label>
          <input type="date" name="deposit_date" value="<?= e($row['deposit_date'] ?? '') ?>">
        </div>
        <div>
          <label>Maturity date</label>
          <input type="date" name="maturity_date" value="<?= e($row['maturity_date'] ?? '') ?>">
        </div>
        <div>
          <label>Status</label>
          <select name="status">
            <?php foreach (['active','matured','withdrawn'] as $st): ?>
              <option value="<?= $st ?>" <?= (($row['status'] ?? 'active') === $st) ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="full">
          <label>Notes</label>
          <textarea name="notes"><?= e($row['notes'] ?? '') ?></textarea>
        </div>
        <?php if (!$row): ?>
        <div class="full highlight-box">
          <label style="display:flex;gap:0.5rem;align-items:flex-start;margin:0;font-weight:600;color:var(--text)">
            <input type="checkbox" name="post_to_ledger" value="1" checked style="width:auto;margin-top:0.2rem">
            <span>Debit linked bank account when placing this deposit (reduces live balance).</span>
          </label>
        </div>
        <?php endif; ?>
        <div class="full form-actions"><button class="btn btn-primary" type="submit">Save deposit</button></div>
      </form>
    </div>
    <?php require __DIR__ . '/../includes/footer.php'; exit;
}

$pageTitle = 'Deposits';
$pageSub = 'Fixed deposits, security deposits and related holdings.';
$pageActions = report_export_buttons()
    . '<a class="btn btn-primary" href="' . e(base_url('pages/deposits.php?action=add')) . '">+ Add deposit</a>';
$deposits = $pdo->query(
    'SELECT d.*, c.name AS company_name, ba.account_name
     FROM deposits d
     JOIN companies c ON c.id = d.company_id
     LEFT JOIN bank_accounts ba ON ba.id = d.bank_account_id
     ORDER BY d.status, d.deposit_date DESC'
)->fetchAll();
$activeTotal = array_sum(array_map(fn($d) => $d['status'] === 'active' ? (float)$d['amount'] : 0, $deposits));
$allTotal = array_sum(array_map(fn($d) => (float) $d['amount'], $deposits));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('export_action', ''), ['csv', 'excel', 'pdf'], true)) {
    verify_csrf();
    $depRows = [];
    foreach ($deposits as $i => $d) {
        $depRows[] = [
            (string) ($i + 1),
            $d['title'] ?? '',
            $d['company_name'] ?? '',
            $d['account_name'] ?? '',
            report_plain_date($d['deposit_date'] ?? null),
            report_plain_date($d['maturity_date'] ?? null),
            $d['interest_rate'] !== null ? (string) $d['interest_rate'] : '',
            ucfirst((string) ($d['status'] ?? '')),
            (float) $d['amount'],
            $d['notes'] ?? '',
        ];
    }
    report_download(post('export_action'), [
        'filename' => 'deposits_register',
        'title' => 'Deposit Register',
        'orientation' => 'landscape',
        'meta' => [
            ['Records', (string) count($deposits)],
        ],
        'summary' => [
            ['Active deposits', $activeTotal, 'money'],
            ['All deposits', $allTotal, 'money'],
            ['Records', count($deposits), 'int'],
        ],
        'tables' => [[
            'title' => 'Deposits',
            'columns' => [
                ['label' => 'Sr No', 'type' => 'text', 'width' => '5%', 'xls_width' => 35],
                ['label' => 'Title', 'type' => 'text', 'width' => '16%', 'xls_width' => 150],
                ['label' => 'Company', 'type' => 'text', 'width' => '14%', 'xls_width' => 130],
                ['label' => 'Bank account', 'type' => 'text', 'width' => '12%', 'xls_width' => 120],
                ['label' => 'Deposit date', 'type' => 'text', 'width' => '9%', 'xls_width' => 90],
                ['label' => 'Maturity', 'type' => 'text', 'width' => '9%', 'xls_width' => 90],
                ['label' => 'Rate %', 'type' => 'text', 'width' => '7%', 'xls_width' => 60],
                ['label' => 'Status', 'type' => 'text', 'width' => '8%', 'xls_width' => 70],
                ['label' => 'Amount (INR)', 'type' => 'money', 'width' => '10%', 'xls_width' => 110],
                ['label' => 'Notes', 'type' => 'text', 'width' => '10%', 'xls_width' => 140],
            ],
            'rows' => $depRows,
            'totals' => ['', 'TOTAL', '', '', '', '', '', '', $allTotal, ''],
        ]],
        'notes' => [
            'System-generated deposit register including active and closed records.',
            'Confidential — internal use only.',
        ],
    ]);
    redirect('pages/deposits.php');
}

require __DIR__ . '/../includes/header.php';
?>
<div class="stat-grid" style="grid-template-columns:repeat(2,1fr)">
  <div class="stat-card"><div class="stat-label">Active deposits</div><div class="stat-value"><?= money($activeTotal) ?></div></div>
  <div class="stat-card"><div class="stat-label">Records</div><div class="stat-value"><?= count($deposits) ?></div></div>
</div>
<div class="card">
  <?php if (!$deposits): ?>
    <div class="empty"><strong>No deposits</strong><p>Track FDs and other deposits by company.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Title</th><th>Company</th><th>Account</th><th class="num">Amount</th><th>Maturity</th><th>Status</th><th class="actions">Actions</th></tr></thead>
        <tbody>
          <?php foreach ($deposits as $d): ?>
            <tr>
              <td><strong><?= e($d['title']) ?></strong></td>
              <td><?= e($d['company_name']) ?></td>
              <td><?= e($d['account_name'] ?? '—') ?></td>
              <td class="num"><?= money($d['amount']) ?></td>
              <td><?= e($d['maturity_date'] ?? '—') ?></td>
              <td><?= status_chip($d['status']) ?></td>
              <td class="actions">
                <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/deposits.php?action=edit&id=' . $d['id'])) ?>">Edit</a>
                <?php if (can_delete()): ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete this deposit and its bank ledger entry?">Delete</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
