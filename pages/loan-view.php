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
        $borrowerId = post('borrower_id') !== '' ? (int) post('borrower_id') : null;
        $notes = post('notes', '');
        $postLedger = !empty($_POST['post_to_ledger']);

        if ($amount <= 0) {
            flash('error', 'Enter a positive repayment amount.');
            redirect('pages/loan-view.php?id=' . $id);
        }
        $bc = $pdo->prepare('SELECT COUNT(*) FROM loan_borrowers WHERE loan_id = ?');
        $bc->execute([$id]);
        if ((int) $bc->fetchColumn() > 0 && !$borrowerId) {
            flash('error', 'Select which borrower paid this amount.');
            redirect('pages/loan-view.php?id=' . $id);
        }
        if ($interestAmount > $amount) {
            $interestAmount = $amount;
        }
        $principalAmount = round($amount - $interestAmount, 2);

        $borrowerName = null;
        if ($borrowerId) {
            $bnStmt = $pdo->prepare('SELECT name FROM loan_borrowers WHERE id = ? AND loan_id = ?');
            $bnStmt->execute([$borrowerId, $id]);
            $borrowerName = $bnStmt->fetchColumn() ?: null;
            if (!$borrowerName) {
                $borrowerId = null;
            }
        }
        $descSuffix = $borrowerName ? ' — ' . $borrowerName : '';

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
                    'Loan repayment — ' . $loan['lender_name'] . $descSuffix . ' — P ' . money($principalAmount) . ' / I ' . money($interestAmount),
                    current_user()['id'] ?? null
                );
            }
        }

        $userId = current_user()['id'] ?? null;
        $pdo->prepare('INSERT INTO loan_repayments (loan_id, amount, principal_amount, interest_amount, payment_date, bank_account_id, transaction_id, borrower_id, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $amount, $principalAmount, $interestAmount, $paymentDate, $bankAccountId, $txnId, $borrowerId, $notes, $userId]);

        refresh_loan_outstanding($pdo, $id);
        audit_log($pdo, 'create', 'loan_repayment', $id, 'Recorded repayment ' . money($amount) . ' (P ' . money($principalAmount) . ' / I ' . money($interestAmount) . ') for ' . $loan['lender_name'] . $descSuffix);
        flash('success', 'Repayment recorded.');
        redirect('pages/loan-view.php?id=' . $id);
    }

    if ($postAction === 'edit_repayment') {
        $repayId = (int) post('repayment_id', 0);
        $amount = (float) post('amount', 0);
        $interestAmount = (float) post('interest_amount', 0);
        $paymentDate = post('payment_date', date('Y-m-d'));
        $bankAccountId = post('bank_account_id') !== '' ? (int) post('bank_account_id') : null;
        $borrowerId = post('borrower_id') !== '' ? (int) post('borrower_id') : null;
        $notes = post('notes', '');

        $rStmt = $pdo->prepare('SELECT * FROM loan_repayments WHERE id = ? AND loan_id = ?');
        $rStmt->execute([$repayId, $id]);
        $repayment = $rStmt->fetch();
        if (!$repayment || $amount <= 0) {
            flash('error', 'Invalid repayment.');
            redirect('pages/loan-view.php?id=' . $id);
        }
        if ($interestAmount > $amount) {
            $interestAmount = $amount;
        }
        $principalAmount = round($amount - $interestAmount, 2);

        $borrowerName = null;
        if ($borrowerId) {
            $bnStmt = $pdo->prepare('SELECT name FROM loan_borrowers WHERE id = ? AND loan_id = ?');
            $bnStmt->execute([$borrowerId, $id]);
            $borrowerName = $bnStmt->fetchColumn() ?: null;
            if (!$borrowerName) {
                $borrowerId = null;
            }
        }
        $descSuffix = $borrowerName ? ' — ' . $borrowerName : '';

        if ($repayment['transaction_id']) {
            $pdo->prepare('UPDATE transactions SET amount=?, txn_date=?, bank_account_id=?, description=? WHERE id=?')
                ->execute([
                    $amount, $paymentDate, $bankAccountId,
                    'Loan repayment — ' . $loan['lender_name'] . $descSuffix . ' — P ' . money($principalAmount) . ' / I ' . money($interestAmount),
                    $repayment['transaction_id'],
                ]);
        }

        $pdo->prepare('UPDATE loan_repayments SET amount=?, principal_amount=?, interest_amount=?, payment_date=?, bank_account_id=?, borrower_id=?, notes=? WHERE id=?')
            ->execute([$amount, $principalAmount, $interestAmount, $paymentDate, $bankAccountId, $borrowerId, $notes, $repayId]);

        refresh_loan_outstanding($pdo, $id);
        audit_log($pdo, 'update', 'loan_repayment', $id, 'Edited repayment #' . $repayId . ' to ' . money($amount) . ' (P ' . money($principalAmount) . ' / I ' . money($interestAmount) . ')' . $descSuffix);
        flash('success', 'Repayment updated.');
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

$repayStmt = $pdo->prepare('SELECT lr.*, ba.account_name, ba.bank_name, lb.name AS borrower_name FROM loan_repayments lr LEFT JOIN bank_accounts ba ON ba.id = lr.bank_account_id LEFT JOIN loan_borrowers lb ON lb.id = lr.borrower_id WHERE lr.loan_id = ? ORDER BY lr.payment_date DESC, lr.id DESC');
$repayStmt->execute([$id]);
$repayments = $repayStmt->fetchAll();

// Keep borrower outstanding in sync with repayments (also re-links orphaned rows by name)
refresh_loan_outstanding($pdo, $id);
// Re-read loan + repayments after refresh (borrower_id may have been restored)
$stmt->execute([$id]);
$loan = $stmt->fetch() ?: $loan;
$repayStmt->execute([$id]);
$repayments = $repayStmt->fetchAll();

$borrowerStmt = $pdo->prepare('SELECT * FROM loan_borrowers WHERE loan_id = ? ORDER BY id');
$borrowerStmt->execute([$id]);
$borrowers = $borrowerStmt->fetchAll();

// Per-borrower repayment totals for the cards
$paidByBorrower = [];
foreach ($repayments as $r) {
    $bid = (int) ($r['borrower_id'] ?? 0);
    if ($bid <= 0) {
        continue;
    }
    if (!isset($paidByBorrower[$bid])) {
        $paidByBorrower[$bid] = ['total' => 0.0, 'principal' => 0.0, 'interest' => 0.0, 'count' => 0];
    }
    $paidByBorrower[$bid]['total'] += (float) $r['amount'];
    $paidByBorrower[$bid]['principal'] += (float) $r['principal_amount'];
    $paidByBorrower[$bid]['interest'] += (float) $r['interest_amount'];
    $paidByBorrower[$bid]['count']++;
}

$totalRepaid = array_sum(array_map(fn($r) => (float) $r['amount'], $repayments));
$principalRepaid = array_sum(array_map(fn($r) => (float) $r['principal_amount'], $repayments));
$interestRepaid = array_sum(array_map(fn($r) => (float) $r['interest_amount'], $repayments));

$borrowersTotal = array_sum(array_map(fn($b) => (float) ($b['loan_amount'] ?? 0), $borrowers));
$borrowersOutstandingTotal = array_sum(array_map(fn($b) => (float) ($b['outstanding_amount'] ?? 0), $borrowers));

$borrowerOptions = function (?int $selected = null) use ($borrowers): string {
    $html = '<option value="">Whole loan / unspecified</option>';
    foreach ($borrowers as $bo) {
        $sel = $selected === (int) $bo['id'] ? ' selected' : '';
        $html .= '<option value="' . (int) $bo['id'] . '"' . $sel . '>' . e($bo['name']) . '</option>';
    }
    return $html;
};

$pageTitle = $loan['lender_name'];
$pageSub = 'Loan repayments — ' . $loan['company_name'] . '. Amounts vary, so each repayment is entered manually.';
$pageActions =
    '<a class="btn btn-outline" href="' . e(base_url('pages/bank-loans.php?action=edit&id=' . $id)) . '">Edit loan</a>' .
    '<a class="btn btn-outline" href="' . e(base_url('pages/bank-loans.php')) . '">Back</a>';

require __DIR__ . '/../includes/header.php';
?>

<div class="stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
  <div class="stat-card"><div class="stat-label">Total loan amount</div><div class="stat-value"><?= money($loan['loan_amount']) ?></div></div>
  <div class="stat-card"><div class="stat-label">Total outstanding</div><div class="stat-value"><?= money($loan['outstanding_amount']) ?></div><div class="stat-hint"><?= status_chip($loan['status']) ?></div></div>
  <div class="stat-card"><div class="stat-label">Interest + charges</div><div class="stat-value"><?= $loan['interest_charges'] !== null ? money($loan['interest_charges']) : '—' ?></div></div>
  <div class="stat-card"><div class="stat-label">Total repaid</div><div class="stat-value"><?= money($totalRepaid) ?></div></div>
  <div class="stat-card"><div class="stat-label">Principal repaid</div><div class="stat-value text-success"><?= money($principalRepaid) ?></div></div>
  <div class="stat-card"><div class="stat-label">Interest repaid</div><div class="stat-value text-danger"><?= money($interestRepaid) ?></div></div>
</div>

<?php if ($borrowers): ?>
<div class="card" id="borrowersSection">
  <div class="card-head" style="flex-wrap:wrap;gap:0.75rem;align-items:flex-end">
    <h2 class="card-title" style="margin:0">Borrowers / guarantors</h2>
    <div class="field" style="flex:1;min-width:220px;max-width:360px;margin:0">
      <label for="borrowerSearch" style="margin-bottom:0.25rem">Search borrower</label>
      <input type="search" id="borrowerSearch" placeholder="Type name or A/C number…" autocomplete="off">
    </div>
    <span class="muted" id="borrowerSearchCount" style="font-size:0.8rem"><?= count($borrowers) ?> people</span>
  </div>
  <div id="borrowerGrid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1rem;margin-top:1rem">
    <?php foreach ($borrowers as $b):
      $bid = (int) $b['id'];
      $paid = $paidByBorrower[$bid] ?? ['total' => 0.0, 'principal' => 0.0, 'interest' => 0.0, 'count' => 0];
      $loanAmt = $b['loan_amount'] !== null ? (float) $b['loan_amount'] : null;
      $outstanding = $b['outstanding_amount'] !== null
          ? (float) $b['outstanding_amount']
          : ($loanAmt !== null ? max(0, $loanAmt - (float) $paid['principal']) : null);
      $searchKey = mb_strtolower(trim(($b['name'] ?? '') . ' ' . ($b['account_number'] ?? '')));
    ?>
      <div class="company-card borrower-card" style="cursor:default" data-borrower-id="<?= $bid ?>" data-search="<?= e($searchKey) ?>">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.6rem;margin-bottom:0.75rem">
          <div>
            <div class="kicker">Borrower</div>
            <h3 style="margin:0.2rem 0 0"><?= e($b['name']) ?></h3>
            <?php if ($b['account_number']): ?><div class="meta">A/C <?= e($b['account_number']) ?></div><?php endif; ?>
          </div>
          <div style="text-align:right">
            <div class="muted" style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.04em">Loan amount</div>
            <div style="font-size:1.1rem;font-weight:800;color:var(--primary-dark,#0f766e)"><?= $loanAmt !== null ? money($loanAmt) : '—' ?></div>
          </div>
        </div>
        <table class="detail-table">
          <tbody>
            <tr><td>Outstanding</td><td class="<?= $outstanding !== null && $outstanding > 0 ? 'text-danger' : 'text-success' ?>"><strong><?= $outstanding !== null ? money($outstanding) : '—' ?></strong></td></tr>
            <tr><td>Principal repaid</td><td class="text-success"><?= money($paid['principal']) ?></td></tr>
            <tr><td>Interest repaid</td><td class="text-danger"><?= money($paid['interest']) ?></td></tr>
            <tr><td>Total repaid</td><td><?= money($paid['total']) ?><?= $paid['count'] ? ' <span class="muted">(' . (int) $paid['count'] . ')</span>' : '' ?></td></tr>
            <tr><td>Interest + charges</td><td><?= $b['interest_charges'] !== null ? money($b['interest_charges']) : '—' ?></td></tr>
            <tr><td>Start date</td><td><?= $b['start_date'] ? e(format_date($b['start_date'])) : '—' ?></td></tr>
            <tr><td>End date</td><td><?= $b['end_date'] ? e(format_date($b['end_date'])) : '—' ?></td></tr>
            <tr><td>Mortgage NOC</td><td><?= $b['mortgage_noc_date'] ? e(format_date($b['mortgage_noc_date'])) : '—' ?></td></tr>
            <tr><td>Reconveyance</td><td><?= $b['reconveyance_date'] ? e(format_date($b['reconveyance_date'])) : '—' ?></td></tr>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>
  </div>
  <div id="borrowerSearchEmpty" class="empty" style="display:none;margin-top:1rem"><strong>No match</strong><p>Try another name or account number.</p></div>
  <?php if ($borrowersTotal > 0 || $borrowersOutstandingTotal > 0): ?>
  <div class="grid-2" style="margin-top:1rem;gap:0.75rem">
    <div class="highlight-box">Borrowers' total loan amount: <strong><?= money($borrowersTotal) ?></strong></div>
    <div class="highlight-box" style="background:#fef2f2;border-color:#fecaca">Borrowers' total outstanding: <strong><?= money($borrowersOutstandingTotal) ?></strong></div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <h2 class="card-title">Record a repayment</h2>
  </div>
  <form method="post" class="form-grid repay-edit-form" id="repaymentForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="record_repayment">
    <div>
      <label>Amount paid (₹)</label>
      <input type="number" step="0.01" min="0.01" name="amount" class="repay-calc-field" required>
    </div>
    <div>
      <label>Of which interest (₹, optional)</label>
      <input type="number" step="0.01" min="0" name="interest_amount" class="repay-calc-field" value="0">
    </div>
    <div>
      <label>Principal portion (₹)</label>
      <input type="text" class="repay-principal-preview" readonly value="₹0.00">
    </div>
    <div>
      <label>Date</label>
      <input type="date" name="payment_date" required value="<?= e(date('Y-m-d')) ?>">
    </div>
    <div>
      <label>Bank account (optional)</label>
      <select name="bank_account_id"><?= bank_account_options($pdo, (int) $loan['company_id'], null, 'Cash') ?></select>
    </div>
    <?php if ($borrowers): ?>
    <div>
      <label>Paid by (borrower)</label>
      <select name="borrower_id" required>
        <option value="">Select borrower…</option>
        <?php foreach ($borrowers as $bo): ?>
          <option value="<?= (int) $bo['id'] ?>"><?= e($bo['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="muted" style="font-size:0.75rem;margin:0.35rem 0 0">Select the borrower so this payment reduces their outstanding.</p>
    </div>
    <?php endif; ?>
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
            <th>Borrower</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($repayments as $r):
            $repayEditId = 'repay-edit-' . $r['id'];
            $repaySearch = mb_strtolower(trim(($r['borrower_name'] ?? '') . ' ' . ($r['notes'] ?? '') . ' ' . ($r['account_name'] ?? '')));
          ?>
            <tr class="row-clickable repay-row" data-row-toggle="<?= e($repayEditId) ?>" data-search="<?= e($repaySearch) ?>" data-borrower-id="<?= (int) ($r['borrower_id'] ?? 0) ?>" title="Click to edit">
              <td><span class="row-caret">▸</span><?= e(format_date($r['payment_date'])) ?></td>
              <td class="num"><?= money($r['amount']) ?></td>
              <td class="num text-success"><?= money($r['principal_amount']) ?></td>
              <td class="num text-danger"><?= money($r['interest_amount']) ?></td>
              <td><?= $r['account_name'] ? e($r['account_name'] . ' — ' . $r['bank_name']) : '—' ?></td>
              <td><?= e($r['borrower_name'] ?: '—') ?></td>
              <td><?= e($r['notes'] ?: '') ?></td>
            </tr>
            <tr class="row-detail repay-row-detail" id="<?= e($repayEditId) ?>" data-search="<?= e($repaySearch) ?>" data-borrower-id="<?= (int) ($r['borrower_id'] ?? 0) ?>" hidden>
              <td colspan="7">
                <form method="post" class="form-grid repay-edit-form" style="padding:0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="edit_repayment">
                  <input type="hidden" name="repayment_id" value="<?= (int) $r['id'] ?>">
                  <div>
                    <label>Amount paid (₹)</label>
                    <input type="number" step="0.01" min="0.01" name="amount" class="repay-calc-field" required value="<?= e((string) $r['amount']) ?>">
                  </div>
                  <div>
                    <label>Of which interest (₹)</label>
                    <input type="number" step="0.01" min="0" name="interest_amount" class="repay-calc-field" value="<?= e((string) $r['interest_amount']) ?>">
                  </div>
                  <div>
                    <label>Principal portion (₹)</label>
                    <input type="text" class="repay-principal-preview" readonly value="<?= e(money($r['principal_amount'])) ?>">
                  </div>
                  <div>
                    <label>Date</label>
                    <input type="date" name="payment_date" required value="<?= e($r['payment_date']) ?>">
                  </div>
                  <div>
                    <label>Bank account (optional)</label>
                    <select name="bank_account_id"><?= bank_account_options($pdo, (int) $loan['company_id'], (int) ($r['bank_account_id'] ?? 0), 'Cash') ?></select>
                  </div>
                  <?php if ($borrowers): ?>
                  <div>
                    <label>Borrower</label>
                    <select name="borrower_id"><?= $borrowerOptions((int) ($r['borrower_id'] ?? 0)) ?></select>
                  </div>
                  <?php endif; ?>
                  <div class="full">
                    <label>Notes</label>
                    <input type="text" name="notes" value="<?= e($r['notes'] ?? '') ?>">
                  </div>
                  <div class="full form-actions" style="justify-content:flex-start">
                    <button class="btn btn-primary btn-sm" type="submit">Save changes</button>
                  </div>
                </form>
                <?php if (can_delete()): ?>
                <form method="post" style="margin-top:0.5rem">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_repayment">
                  <input type="hidden" name="repayment_id" value="<?= (int) $r['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete this repayment entry?">Delete this entry</button>
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
  var input = document.getElementById('borrowerSearch');
  if (!input) return;
  var cards = Array.prototype.slice.call(document.querySelectorAll('.borrower-card'));
  var countEl = document.getElementById('borrowerSearchCount');
  var emptyEl = document.getElementById('borrowerSearchEmpty');
  var repayRows = Array.prototype.slice.call(document.querySelectorAll('.repay-row'));
  var repayDetails = Array.prototype.slice.call(document.querySelectorAll('.repay-row-detail'));
  var borrowerSelect = document.querySelector('#repaymentForm select[name="borrower_id"]');
  var totalPeople = cards.length;

  function applySearch() {
    var q = (input.value || '').trim().toLowerCase();
    var visible = 0;
    var matchedIds = [];

    cards.forEach(function (card) {
      var hay = card.getAttribute('data-search') || '';
      var show = !q || hay.indexOf(q) !== -1;
      card.style.display = show ? '' : 'none';
      if (show) {
        visible++;
        matchedIds.push(card.getAttribute('data-borrower-id'));
      }
    });

    repayRows.forEach(function (row) {
      var hay = row.getAttribute('data-search') || '';
      var bid = row.getAttribute('data-borrower-id') || '';
      var show = !q || hay.indexOf(q) !== -1 || (bid && matchedIds.indexOf(bid) !== -1);
      row.style.display = show ? '' : 'none';
      var detailId = row.getAttribute('data-row-toggle');
      var detail = detailId ? document.getElementById(detailId) : null;
      if (detail) {
        if (!show) {
          detail.hidden = true;
          detail.style.display = 'none';
        } else {
          detail.style.display = '';
        }
      }
    });

    if (countEl) {
      countEl.textContent = q ? (visible + ' of ' + totalPeople + ' people') : (totalPeople + ' people');
    }
    if (emptyEl) emptyEl.style.display = visible === 0 ? '' : 'none';

    if (borrowerSelect && q && matchedIds.length === 1) {
      borrowerSelect.value = matchedIds[0];
    }
  }

  input.addEventListener('input', applySearch);
  input.addEventListener('search', applySearch);
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>