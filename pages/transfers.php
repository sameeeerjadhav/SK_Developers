<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $companyId = (int) post('company_id', 0);
    $fromId = (int) post('from_account_id', 0);
    $toId = (int) post('to_account_id', 0);
    $amount = (float) post('amount', 0);
    $date = post('transfer_date', date('Y-m-d'));
    $ref = post('reference_no', '');
    $notes = post('notes', '');

    if (!$companyId || !$fromId || !$toId || $fromId === $toId || $amount <= 0) {
        flash('error', 'Select company, two different accounts, and a positive amount.');
        redirect('pages/transfers.php?action=add');
    }

    $bal = account_balance($pdo, $fromId);
    if ($bal < $amount) {
        flash('error', 'Insufficient balance in source account (' . money($bal) . ').');
        redirect('pages/transfers.php?action=add');
    }

    $catId = category_id_by_slug($pdo, 'general', 'bank_transfer');
    if (!$catId) {
        flash('error', 'Transfer category missing. Refresh once to migrate schema.');
        redirect('pages/transfers.php');
    }

    $userId = current_user()['id'] ?? null;
    $desc = $notes !== '' ? $notes : 'Bank transfer';

    $debitId = create_transaction($pdo, $companyId, $catId, 'debit', $amount, $date, null, $fromId, null, $ref, $desc . ' (out)', $userId ? (int)$userId : null);
    $creditId = create_transaction($pdo, $companyId, $catId, 'credit', $amount, $date, null, $toId, null, $ref, $desc . ' (in)', $userId ? (int)$userId : null);

    $stmt = $pdo->prepare('INSERT INTO bank_transfers (company_id, from_account_id, to_account_id, amount, transfer_date, reference_no, notes, debit_txn_id, credit_txn_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$companyId, $fromId, $toId, $amount, $date, $ref, $notes, $debitId, $creditId, $userId]);
    $tid = (int) $pdo->lastInsertId();
    audit_log($pdo, 'create', 'bank_transfer', $tid, 'Transferred ' . money($amount) . ' between accounts');
    flash('success', 'Transfer completed. Both account balances updated.');
    redirect('pages/transfers.php');
}

if ($action === 'add') {
    $pageTitle = 'Bank transfer';
    $pageSub = 'Move money between accounts without booking a fake expense.';
    $pageActions = '<a class="btn btn-outline" href="' . e(base_url('pages/transfers.php')) . '">Back</a>';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="card" style="max-width:720px">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <div>
          <label>Company</label>
          <select name="company_id" required data-company-accounts="from_account_id" data-accounts-url="<?= e(base_url('api/bank-accounts.php')) ?>" id="transfer_company">
            <?= company_options($pdo) ?>
          </select>
        </div>
        <div>
          <label>Date</label>
          <input type="date" name="transfer_date" required value="<?= e(date('Y-m-d')) ?>">
        </div>
        <div>
          <label>From account</label>
          <select name="from_account_id" id="from_account_id" required><?= bank_account_options($pdo) ?></select>
        </div>
        <div>
          <label>To account</label>
          <select name="to_account_id" id="to_account_id" required><?= bank_account_options($pdo) ?></select>
        </div>
        <div>
          <label>Amount (₹)</label>
          <input type="number" step="0.01" min="0.01" name="amount" required>
        </div>
        <div>
          <label>Reference</label>
          <input type="text" name="reference_no">
        </div>
        <div class="full">
          <label>Notes</label>
          <textarea name="notes"></textarea>
        </div>
        <div class="full highlight-box">Creates a linked debit on the source account and credit on the destination — not counted as business expense/profit noise when filtered by transfer category.</div>
        <div class="full form-actions"><button class="btn btn-primary" type="submit">Transfer</button></div>
      </form>
    </div>
    <script>
      document.getElementById('transfer_company')?.addEventListener('change', function(){
        // also refresh to-account via second fetch
        var companyId = this.value;
        var url = <?= json_encode(base_url('api/bank-accounts.php')) ?> + '?company_id=' + encodeURIComponent(companyId);
        fetch(url,{credentials:'same-origin'}).then(r=>r.json()).then(function(rows){
          var html = '<option value="">None</option>';
          rows.forEach(function(row){ html += '<option value="'+row.id+'">'+(row.account_name||'')+' — '+(row.bank_name||'')+'</option>'; });
          document.getElementById('from_account_id').innerHTML = html;
          document.getElementById('to_account_id').innerHTML = html;
        });
      });
    </script>
    <?php require __DIR__ . '/../includes/footer.php'; exit;
}

$pageTitle = 'Bank transfers';
$pageSub = 'Internal account-to-account movements.';
$pageActions = '<a class="btn btn-primary" href="' . e(base_url('pages/transfers.php?action=add')) . '">+ New transfer</a>';
$rows = $pdo->query(
    'SELECT tr.*, c.name AS company_name, fa.account_name AS from_name, ta.account_name AS to_name
     FROM bank_transfers tr
     JOIN companies c ON c.id = tr.company_id
     JOIN bank_accounts fa ON fa.id = tr.from_account_id
     JOIN bank_accounts ta ON ta.id = tr.to_account_id
     ORDER BY tr.transfer_date DESC, tr.id DESC LIMIT 100'
)->fetchAll();
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
  <?php if (!$rows): ?>
    <div class="empty"><strong>No transfers yet</strong><p>Move funds between company bank accounts cleanly.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Date</th><th>Company</th><th>From</th><th>To</th><th class="num">Amount</th><th>Ref</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= e($r['transfer_date']) ?></td>
              <td><?= e($r['company_name']) ?></td>
              <td><?= e($r['from_name']) ?></td>
              <td><?= e($r['to_name']) ?></td>
              <td class="num"><?= money($r['amount']) ?></td>
              <td><?= e($r['reference_no'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
