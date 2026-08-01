<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$id = (int) get('id', 0);
$stmt = $pdo->prepare(
    'SELECT l.*, c.name AS company_name, p.name AS project_name
     FROM bank_loans l
     JOIN companies c ON c.id = l.company_id
     LEFT JOIN projects p ON p.id = l.project_id
     WHERE l.id = ?'
);
$stmt->execute([$id]);
$loan = $stmt->fetch();
if (!$loan) {
    flash('error', 'Loan not found.');
    redirect('pages/bank-loans.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $postAction = post('action', '');

    if ($postAction === 'record_repayment') {
        $amount = (float) post('amount', 0);
        $interestAmount = (float) post('interest_amount', 0);
        $paymentDate = post('payment_date', date('Y-m-d'));
        $bankAccountId = post('bank_account_id') !== '' ? (int) post('bank_account_id') : null;
        $notes = post('notes', '');
        $postLedger = !empty($_POST['post_to_ledger']);

        if ($amount <= 0) {
            flash('error', 'Enter a positive repayment amount.');
            redirect('pages/loan-view.php?id=' . $id);
        }
        if ($interestAmount > $amount) {
            $interestAmount = $amount;
        }
        $principalAmount = round($amount - $interestAmount, 2);

        $txnId = null;
        if ($postLedger) {
            $catId = category_id_by_slug($pdo, 'expense', 'loan_repayment');
            if ($catId) {
                $txnId = create_transaction(
                    $pdo,
                    (int) $loan['company_id'],
                    $catId,
                    'debit',
                    $amount,
                    $paymentDate,
                    $loan['project_id'] ? (int) $loan['project_id'] : null,
                    $bankAccountId,
                    null,
                    null,
                    'Loan repayment — ' . $loan['lender_name'] . ' — P ' . money($principalAmount) . ' / I ' . money($interestAmount),
                    current_user()['id'] ?? null
                );
            }
        }

        $userId = current_user()['id'] ?? null;
        $pdo->prepare('INSERT INTO loan_repayments (loan_id, amount, principal_amount, interest_amount, payment_date, bank_account_id, transaction_id, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $amount, $principalAmount, $interestAmount, $paymentDate, $bankAccountId, $txnId, $notes, $userId]);

        refresh_loan_outstanding($pdo, $id);
        audit_log($pdo, 'create', 'loan_repayment', $id, 'Recorded repayment ' . money($amount) . ' (P ' . money($principalAmount) . ' / I ' . money($interestAmount) . ') for ' . $loan['lender_name']);
        flash('success', 'Repayment recorded.');
        redirect('pages/loan-view.php?id=' . $id);
    }

    if ($postAction === 'delete_repayment') {
        if (!can_delete()) {
            flash('error', 'Only admins can delete repayments.');
            redirect('pages/loan-view.php?id=' . $id);
        }
        $repayId = (int) post('repayment_id', 0);
        $rStmt = $pdo->prepare('SELECT transaction_id FROM loan_repayments WHERE id = ? AND loan_id = ?');
        $rStmt->execute([$repayId, $id]);
        $txnId = $rStmt->fetchColumn();
        $pdo->prepare('DELETE FROM loan_repayments WHERE id = ?')->execute([$repayId]);
        if ($txnId) {
            $pdo->prepare('DELETE FROM transactions WHERE id = ?')->execute([(int) $txnId]);
        }
        refresh_loan_outstanding($pdo, $id);
        audit_log($pdo, 'delete', 'loan_repayment', $id, 'Deleted repayment #' . $repayId . ' for ' . $loan['lender_name']);
        flash('success', 'Repayment deleted.');
        redirect('pages/loan-view.php?id=' . $id);
    }
}

$repayStmt = $pdo->prepare('SELECT lr.*, ba.account_name, ba.bank_name FROM loan_repayments lr LEFT JOIN bank_accounts ba ON ba.id = lr.bank_account_id WHERE lr.loan_id = ? ORDER BY lr.payment_date DESC, lr.id DESC');
$repayStmt->execute([$id]);
$repayments = $repayStmt->fetchAll();
$totalRepaid = array_sum(array_map(fn($r) => (float) $r['amount'], $repayments));
$principalRepaid = array_sum(array_map(fn($r) => (float) $r['principal_amount'], $repayments));
$interestRepaid = array_sum(array_map(fn($r) => (float) $r['interest_amount'], $repayments));

$pageTitle = $loan['lender_name'];
$pageSub = 'Loan repayments — ' . $loan['company_name'] . '. Amounts vary, so each repayment is entered manually.';
$pageActions =
    '<a class="btn btn-outline" href="' . e(base_url('pages/bank-loans.php?action=edit&id=' . $id)) . '">Edit loan</a>' .
    '<a class="btn btn-outline" href="' . e(base_url('pages/bank-loans.php')) . '">Back</a>';

require __DIR__ . '/../includes/header.php';
?>

<div class="stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
  <div class="stat-card"><div class="stat-label">Loan amount</div><div class="stat-value"><?= money($loan['loan_amount']) ?></div></div>
  <div class="stat-card"><div class="stat-label">Outstanding</div><div class="stat-value"><?= money($loan['outstanding_amount']) ?></div><div class="stat-hint"><?= status_chip($loan['status']) ?></div></div>
  <div class="stat-card"><div class="stat-label">Interest + charges</div><div class="stat-value"><?= $loan['interest_charges'] !== null ? money($loan['interest_charges']) : '—' ?></div></div>
  <div class="stat-card"><div class="stat-label">Total repaid</div><div class="stat-value"><?= money($totalRepaid) ?></div></div>
  <div class="stat-card"><div class="stat-label">Principal repaid</div><div class="stat-value text-success"><?= money($principalRepaid) ?></div></div>
  <div class="stat-card"><div class="stat-label">Interest repaid</div><div class="stat-value text-danger"><?= money($interestRepaid) ?></div></div>
</div>

<div class="card">
  <div class="card-head">
    <h2 class="card-title">Record a repayment</h2>
  </div>
  <form method="post" class="form-grid" id="repaymentForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="record_repayment">
    <div>
      <label>Amount paid (₹)</label>
      <input type="number" step="0.01" min="0.01" name="amount" id="repay_amount" required>
    </div>
    <div>
      <label>Of which interest (₹, optional)</label>
      <input type="number" step="0.01" min="0" name="interest_amount" id="repay_interest" value="0">
    </div>
    <div>
      <label>Principal portion (₹)</label>
      <input type="text" id="repay_principal_preview" readonly value="₹0.00">
    </div>
    <div>
      <label>Date</label>
      <input type="date" name="payment_date" required value="<?= e(date('Y-m-d')) ?>">
    </div>
    <div>
      <label>Bank account (optional)</label>
      <select name="bank_account_id"><?= bank_account_options($pdo, (int) $loan['company_id']) ?></select>
    </div>
    <div class="full">
      <label>Notes</label>
      <input type="text" name="notes" placeholder="Reference no., cheque no., etc.">
    </div>
    <div class="full highlight-box">
      <label style="display:flex;gap:0.5rem;align-items:flex-start;margin:0;font-weight:600;color:var(--text)">
        <input type="checkbox" name="post_to_ledger" value="1" checked style="width:auto;margin-top:0.2rem">
        <span>Also post this as a <strong>Debit → Loan Repayment</strong> transaction (updates bank balance).</span>
      </label>
    </div>
    <div class="full form-actions">
      <button class="btn btn-primary" type="submit">Record repayment</button>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-head">
    <h2 class="card-title">Repayment history</h2>
    <span class="muted" style="font-size:0.8rem"><?= count($repayments) ?> entries</span>
  </div>
  <?php if (!$repayments): ?>
    <div class="empty"><strong>No repayments recorded yet</strong><p>Use the form above whenever a repayment is made — amounts don't need to be fixed or scheduled.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>Date</th>
            <th class="num">Amount</th>
            <th class="num">Principal</th>
            <th class="num">Interest</th>
            <th>Bank account</th>
            <th>Notes</th>
            <th class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($repayments as $r): ?>
            <tr>
              <td><?= e(format_date($r['payment_date'])) ?></td>
              <td class="num"><?= money($r['amount']) ?></td>
              <td class="num text-success"><?= money($r['principal_amount']) ?></td>
              <td class="num text-danger"><?= money($r['interest_amount']) ?></td>
              <td><?= $r['account_name'] ? e($r['account_name'] . ' — ' . $r['bank_name']) : '—' ?></td>
              <td><?= e($r['notes'] ?: '') ?></td>
              <td class="actions">
                <?php if (can_delete()): ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_repayment">
                  <input type="hidden" name="repayment_id" value="<?= (int) $r['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete this repayment entry?">Delete</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td>Total</td>
            <td class="num"><?= money($totalRepaid) ?></td>
            <td class="num"><?= money($principalRepaid) ?></td>
            <td class="num"><?= money($interestRepaid) ?></td>
            <td></td>
            <td></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
  <?php endif; ?>
</div>

<script>
  (function () {
    var amountEl = document.getElementById('repay_amount');
    var interestEl = document.getElementById('repay_interest');
    var previewEl = document.getElementById('repay_principal_preview');

    function recalc() {
      var amount = parseFloat(amountEl.value) || 0;
      var interest = parseFloat(interestEl.value) || 0;
      if (interest > amount) interest = amount;
      var principal = Math.max(0, amount - interest);
      previewEl.value = '₹' + principal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    amountEl.addEventListener('input', recalc);
    interestEl.addEventListener('input', recalc);
  })();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
