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

    if ($postAction === 'generate_schedule') {
        $emi = (float) ($loan['emi_amount'] ?? 0);
        $tenure = (int) ($loan['tenure_months'] ?? 0);
        $start = $loan['emi_start_date'] ?: ($loan['start_date'] ?: date('Y-m-d'));
        if ($emi <= 0 || $tenure <= 0) {
            flash('error', 'Set EMI amount and tenure months on the loan first.');
            redirect('pages/bank-loans.php?action=edit&id=' . $id);
        }
        generate_loan_emis($pdo, $id, $emi, $tenure, $start);
        flash('success', 'EMI schedule generated.');
        redirect('pages/loan-view.php?id=' . $id);
    }

    if ($postAction === 'pay_emi') {
        $emiId = (int) post('emi_id', 0);
        $paidAmount = (float) post('paid_amount', 0);
        $paidDate = post('paid_date', date('Y-m-d'));
        $bankAccountId = post('bank_account_id') !== '' ? (int) post('bank_account_id') : null;
        $postLedger = !empty($_POST['post_to_ledger']);

        $emiStmt = $pdo->prepare('SELECT * FROM loan_emis WHERE id = ? AND loan_id = ?');
        $emiStmt->execute([$emiId, $id]);
        $emi = $emiStmt->fetch();
        if (!$emi || $paidAmount <= 0) {
            flash('error', 'Invalid EMI payment.');
            redirect('pages/loan-view.php?id=' . $id);
        }

        $txnId = null;
        if ($postLedger) {
            $catId = category_id_by_slug($pdo, 'expense', 'interest_paid');
            if ($catId) {
                $txnId = create_transaction(
                    $pdo,
                    (int) $loan['company_id'],
                    $catId,
                    'debit',
                    $paidAmount,
                    $paidDate,
                    $loan['project_id'] ? (int) $loan['project_id'] : null,
                    $bankAccountId,
                    null,
                    'EMI-' . $emi['installment_no'],
                    'EMI payment — ' . $loan['lender_name'] . ' #' . $emi['installment_no'],
                    current_user()['id'] ?? null
                );
            }
        }

        $newPaid = (float) $emi['paid_amount'] + $paidAmount;
        $status = $newPaid + 0.009 >= (float) $emi['amount'] ? 'paid' : 'partial';
        $pdo->prepare('UPDATE loan_emis SET paid_amount=?, paid_date=?, status=?, transaction_id=COALESCE(?, transaction_id) WHERE id=?')
            ->execute([$newPaid, $paidDate, $status, $txnId, $emiId]);
        refresh_loan_outstanding($pdo, $id);
        flash('success', 'EMI payment recorded.');
        redirect('pages/loan-view.php?id=' . $id);
    }
}

$emis = $pdo->prepare('SELECT * FROM loan_emis WHERE loan_id = ? ORDER BY installment_no');
$emis->execute([$id]);
$schedule = $emis->fetchAll();
$paidTotal = array_sum(array_map(fn($e) => (float)$e['paid_amount'], $schedule));
$pendingCount = count(array_filter($schedule, fn($e) => $e['status'] === 'pending' || $e['status'] === 'partial'));

$pageTitle = $loan['lender_name'];
$pageSub = 'Loan EMI tracking · ' . $loan['company_name'];
$pageActions =
    '<a class="btn btn-outline" href="' . e(base_url('pages/bank-loans.php?action=edit&id=' . $id)) . '">Edit loan</a>' .
    '<a class="btn btn-outline" href="' . e(base_url('pages/bank-loans.php')) . '">Back</a>';

require __DIR__ . '/../includes/header.php';
?>

<div class="stat-grid" style="grid-template-columns:repeat(4,1fr)">
  <div class="stat-card"><div class="stat-label">Loan amount</div><div class="stat-value"><?= money($loan['loan_amount']) ?></div></div>
  <div class="stat-card"><div class="stat-label">Outstanding</div><div class="stat-value"><?= money($loan['outstanding_amount']) ?></div></div>
  <div class="stat-card"><div class="stat-label">EMI</div><div class="stat-value"><?= $loan['emi_amount'] !== null ? money($loan['emi_amount']) : '—' ?></div><div class="stat-hint"><?= (int)($loan['tenure_months'] ?? 0) ?> months</div></div>
  <div class="stat-card"><div class="stat-label">Paid / pending</div><div class="stat-value"><?= money($paidTotal) ?></div><div class="stat-hint"><?= $pendingCount ?> EMI(s) open · <?= status_chip($loan['status']) ?></div></div>
</div>

<?php if (!$schedule): ?>
  <div class="card">
    <div class="empty">
      <strong>No EMI schedule yet</strong>
      <p>Generate installments from EMI amount + tenure on this loan.</p>
      <form method="post" style="margin-top:1rem">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="generate_schedule">
        <button class="btn btn-primary" type="submit">Generate EMI schedule</button>
      </form>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-head">
      <h2 class="card-title">EMI schedule</h2>
      <form method="post" onsubmit="return confirm('Regenerate pending EMIs? Paid ones stay.')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="generate_schedule">
        <button class="btn btn-outline btn-sm" type="submit">Regenerate pending</button>
      </form>
    </div>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>#</th>
            <th>Due</th>
            <th class="num">EMI</th>
            <th class="num">Paid</th>
            <th>Status</th>
            <th class="actions">Record payment</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($schedule as $emi): ?>
            <tr>
              <td><?= (int)$emi['installment_no'] ?></td>
              <td><?= e($emi['due_date']) ?></td>
              <td class="num"><?= money($emi['amount']) ?></td>
              <td class="num"><?= money($emi['paid_amount']) ?></td>
              <td><?= status_chip($emi['status']) ?></td>
              <td class="actions">
                <?php if ($emi['status'] !== 'paid'): ?>
                  <form method="post" class="emi-pay-form" style="display:flex;gap:0.35rem;flex-wrap:wrap;justify-content:flex-end;align-items:center">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="pay_emi">
                    <input type="hidden" name="emi_id" value="<?= (int)$emi['id'] ?>">
                    <input type="number" step="0.01" name="paid_amount" value="<?= e((string)max(0, (float)$emi['amount'] - (float)$emi['paid_amount'])) ?>" style="width:110px" required>
                    <input type="date" name="paid_date" value="<?= e(date('Y-m-d')) ?>" style="width:140px">
                    <select name="bank_account_id" style="width:150px">
                      <?= bank_account_options($pdo, (int)$loan['company_id']) ?>
                    </select>
                    <label style="font-size:0.72rem;display:flex;gap:0.25rem;align-items:center;margin:0;font-weight:600">
                      <input type="checkbox" name="post_to_ledger" value="1" checked style="width:auto"> Ledger
                    </label>
                    <button class="btn btn-primary btn-sm" type="submit">Pay</button>
                  </form>
                <?php else: ?>
                  <span class="muted"><?= e($emi['paid_date'] ?? '') ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
