<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

/** Groups repayment rows by loan (lender) for the drill-down table. */
function group_repayments_by_loan(array $rows): array
{
    $groups = [];
    foreach ($rows as $row) {
        $lid = (int) $row['loan_id'];
        if (!isset($groups[$lid])) {
            $groups[$lid] = [
                'id' => $lid,
                'name' => $row['lender_name'],
                'company_id' => (int) $row['company_id'],
                'company_name' => $row['company_name'],
                'rows' => [],
                'total' => 0.0,
                'principal' => 0.0,
                'interest' => 0.0,
            ];
        }
        $groups[$lid]['rows'][] = $row;
        $groups[$lid]['total'] += (float) $row['amount'];
        $groups[$lid]['principal'] += (float) $row['principal_amount'];
        $groups[$lid]['interest'] += (float) $row['interest_amount'];
    }
    uasort($groups, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $groups;
}

/** Renders the lender -> repayment drill-down table, each repayment row click-to-edit with a checkbox for export. */
function render_repayment_loans(PDO $pdo, array $loans): void
{
    if (!$loans) {
        echo '<div class="empty"><strong>No repayments yet</strong><p>Record repayments from a loan\'s page — they will show up here grouped by bank.</p></div>';
        return;
    }
    ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>Lender / Bank</th>
            <th>Company</th>
            <th>Entries</th>
            <th class="num">Total</th>
            <th class="num">Principal</th>
            <th class="num">Interest</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($loans as $loan):
            $loanDetailId = 'loan-detail-' . $loan['id'];
            $borrowerStmt = $pdo->prepare('SELECT id, name FROM loan_borrowers WHERE loan_id = ? ORDER BY id');
            $borrowerStmt->execute([$loan['id']]);
            $loanBorrowers = $borrowerStmt->fetchAll();
          ?>
            <tr class="row-clickable" data-row-toggle="<?= e($loanDetailId) ?>">
              <td><span class="row-caret">▸</span><strong><?= e($loan['name']) ?></strong></td>
              <td><?= e($loan['company_name']) ?></td>
              <td><?= count($loan['rows']) ?></td>
              <td class="num"><?= money($loan['total']) ?></td>
              <td class="num text-success"><?= money($loan['principal']) ?></td>
              <td class="num text-danger"><?= money($loan['interest']) ?></td>
            </tr>
            <tr class="row-detail" id="<?= e($loanDetailId) ?>" hidden>
              <td colspan="6">
                <div class="table-wrap">
                  <table class="data">
                    <thead>
                      <tr>
                        <th class="select-col"></th>
                        <th>Date</th>
                        <th class="num">Amount</th>
                        <th class="num">Principal</th>
                        <th class="num">Interest</th>
                        <th>Bank account</th>
                        <th>Borrower</th>
                        <th>Notes</th>
                        <?php if (can_delete()): ?><th class="actions">Actions</th><?php endif; ?>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($loan['rows'] as $r):
                        $repayEditId = 'repay-edit-' . $r['id'];
                      ?>
                        <tr class="row-clickable" data-row-toggle="<?= e($repayEditId) ?>" title="Click to edit">
                          <td class="select-col"><input type="checkbox" class="bulk-checkbox" form="loanRepaymentsExportForm" name="repayment_ids[]" value="<?= (int) $r['id'] ?>"></td>
                          <td><span class="row-caret">▸</span><?= e(format_date($r['payment_date'])) ?></td>
                          <td class="num"><?= money($r['amount']) ?></td>
                          <td class="num text-success"><?= money($r['principal_amount']) ?></td>
                          <td class="num text-danger"><?= money($r['interest_amount']) ?></td>
                          <td><?= $r['account_name'] ? e($r['account_name'] . ' — ' . $r['bank_name']) : '—' ?></td>
                          <td><?= e($r['borrower_name'] ?: '—') ?></td>
                          <td><?= e($r['notes'] ?: '') ?></td>
                          <?php if (can_delete()): ?>
                          <td class="actions">
                            <form method="post" style="display:inline">
                              <?= csrf_field() ?>
                              <input type="hidden" name="action" value="delete_repayment">
                              <input type="hidden" name="repayment_id" value="<?= (int) $r['id'] ?>">
                              <button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete this repayment entry? Outstanding will be recalculated.">Delete</button>
                            </form>
                          </td>
                          <?php endif; ?>
                        </tr>
                        <tr class="row-detail" id="<?= e($repayEditId) ?>" hidden>
                          <td colspan="<?= can_delete() ? 9 : 8 ?>">
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
                                <select name="bank_account_id"><?= bank_account_options($pdo, $loan['company_id'], (int) ($r['bank_account_id'] ?? 0), 'Cash') ?></select>
                              </div>
                              <?php if ($loanBorrowers): ?>
                              <div>
                                <label>Borrower</label>
                                <select name="borrower_id">
                                  <option value="">Whole loan / unspecified</option>
                                  <?php foreach ($loanBorrowers as $lb): ?>
                                    <option value="<?= (int) $lb['id'] ?>" <?= (int) ($r['borrower_id'] ?? 0) === (int) $lb['id'] ? 'selected' : '' ?>><?= e($lb['name']) ?></option>
                                  <?php endforeach; ?>
                                </select>
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
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}

/** Real Dompdf download — same register style as loan-view. Never browser print. */
function loan_repayments_output_pdf(
    array $rows,
    array $meta,
    float $total,
    float $principal,
    float $interest,
    array $byLender,
    string $scopeNote
): void {
    if (function_exists('ini_set')) {
        @ini_set('memory_limit', '256M');
    }
    $generatedAt = date('d-m-Y, h:i A');
    $generatedBy = current_user()['name'] ?? '';
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  @page { margin: 12mm 10mm; }
  * { box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 8pt; color: #0b1f1c; margin: 0; line-height: 1.3; }
  .brand { font-size: 15pt; font-weight: 700; color: #0f766e; margin: 0 0 2px; }
  .doc-title { font-size: 11pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; margin: 0 0 4px; }
  .meta { font-size: 8pt; color: #5b6f6b; }
  .header-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
  .header-table td { vertical-align: top; padding: 0; }
  .header-table td.right { text-align: right; }
  .summary { width: 100%; border-collapse: collapse; margin: 6px 0 10px; border: 1px solid #cfe3df; }
  .summary td { width: 25%; padding: 7px 9px; border-right: 1px solid #cfe3df; background: #f7fcfb; }
  .summary td:last-child { border-right: none; }
  .summary .label { font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.05em; color: #5b6f6b; font-weight: 700; margin-bottom: 2px; }
  .summary .value { font-size: 10.5pt; font-weight: 700; }
  .ok { color: #047857; }
  .bad { color: #b91c1c; }
  h3 { font-size: 9.5pt; margin: 12px 0 5px; color: #0f766e; border-bottom: 1px solid #cfe3df; padding-bottom: 3px; }
  table.data { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 7.5pt; }
  table.data th, table.data td { border: 1px solid #d7e6e2; padding: 3px 4px; vertical-align: top; }
  table.data th { background: #eef8f5; font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.03em; text-align: left; }
  table.data td.num, table.data th.num { text-align: right; white-space: nowrap; }
  table.data thead { display: table-header-group; }
  table.data tr { page-break-inside: avoid; }
  table.data tfoot td { background: #eef8f5; font-weight: 700; border-top: 2px solid #9ec5bd; }
  .filters { width: 100%; border-collapse: collapse; margin-bottom: 6px; font-size: 8pt; }
  .filters td { padding: 1px 8px 1px 0; }
  .filters .k { color: #5b6f6b; width: 16%; }
  .footnote { margin-top: 12px; padding-top: 8px; border-top: 1px solid #cfe3df; font-size: 7pt; color: #5b6f6b; }
  .footnote p { margin: 2px 0; }
</style>
</head>
<body>
  <table class="header-table">
    <tr>
      <td>
        <div class="brand">Sai Kuber Developers</div>
        <div class="doc-title">Loan Repayment Register</div>
      </td>
      <td class="right meta">
        Generated <?= pdf_e($generatedAt) ?><br>
        By <?= pdf_e($generatedBy) ?>
      </td>
    </tr>
  </table>
  <table class="filters">
    <?php foreach ($meta as $row): ?>
      <tr><td class="k"><?= pdf_e($row[0] ?? '') ?></td><td><?= pdf_e($row[1] ?? '') ?></td></tr>
    <?php endforeach; ?>
  </table>
  <table class="summary">
    <tr>
      <td>
        <div class="label">Total repaid</div>
        <div class="value"><?= pdf_e(money($total)) ?></div>
      </td>
      <td>
        <div class="label">Principal</div>
        <div class="value ok"><?= pdf_e(money($principal)) ?></div>
      </td>
      <td>
        <div class="label">Interest</div>
        <div class="value bad"><?= pdf_e(money($interest)) ?></div>
      </td>
      <td>
        <div class="label">Entries</div>
        <div class="value"><?= (int) count($rows) ?></div>
      </td>
    </tr>
  </table>

  <h3>Totals by lender</h3>
  <table class="data">
    <thead>
      <tr>
        <th style="width:6%">#</th>
        <th style="width:38%">Lender / Bank</th>
        <th class="num" style="width:10%">Entries</th>
        <th class="num" style="width:15%">Amount</th>
        <th class="num" style="width:15%">Principal</th>
        <th class="num" style="width:16%">Interest</th>
      </tr>
    </thead>
    <tbody>
      <?php $n = 0; foreach ($byLender as $name => $info): $n++; ?>
      <tr>
        <td><?= $n ?></td>
        <td><?= pdf_e((string) $name) ?></td>
        <td class="num"><?= (int) $info['count'] ?></td>
        <td class="num"><?= pdf_e(indian_number_format((float) $info['total'], 2)) ?></td>
        <td class="num ok"><?= pdf_e(indian_number_format((float) $info['principal'], 2)) ?></td>
        <td class="num bad"><?= pdf_e(indian_number_format((float) $info['interest'], 2)) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="2">TOTAL</td>
        <td class="num"><?= count($rows) ?></td>
        <td class="num"><?= pdf_e(indian_number_format($total, 2)) ?></td>
        <td class="num"><?= pdf_e(indian_number_format($principal, 2)) ?></td>
        <td class="num"><?= pdf_e(indian_number_format($interest, 2)) ?></td>
      </tr>
    </tfoot>
  </table>

  <h3>Repayment register (<?= count($rows) ?>)</h3>
  <table class="data">
    <thead>
      <tr>
        <th style="width:4%">#</th>
        <th style="width:8%">Date</th>
        <th style="width:18%">Lender / Bank</th>
        <th style="width:14%">Company</th>
        <th style="width:14%">Borrower</th>
        <th style="width:12%">Account</th>
        <th class="num" style="width:10%">Amount</th>
        <th class="num" style="width:10%">Principal</th>
        <th class="num" style="width:10%">Interest</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $i => $r):
          $account = trim((string) ($r['account_name'] ?? ''));
          if ($account === '') {
              $account = 'Cash';
          }
      ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= pdf_e(report_plain_date($r['payment_date'] ?? null)) ?></td>
        <td><?= pdf_e((string) ($r['lender_name'] ?? '')) ?></td>
        <td><?= pdf_e((string) ($r['company_name'] ?? '')) ?></td>
        <td><?= pdf_e((string) ($r['borrower_name'] ?: '—')) ?></td>
        <td><?= pdf_e($account) ?></td>
        <td class="num"><?= pdf_e(indian_number_format((float) $r['amount'], 2)) ?></td>
        <td class="num ok"><?= pdf_e(indian_number_format((float) $r['principal_amount'], 2)) ?></td>
        <td class="num bad"><?= pdf_e(indian_number_format((float) $r['interest_amount'], 2)) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="6">TOTAL</td>
        <td class="num"><?= pdf_e(indian_number_format($total, 2)) ?></td>
        <td class="num"><?= pdf_e(indian_number_format($principal, 2)) ?></td>
        <td class="num"><?= pdf_e(indian_number_format($interest, 2)) ?></td>
      </tr>
    </tfoot>
  </table>
  <div class="footnote">
    <p><?= pdf_e($scopeNote) ?></p>
    <p>System-generated repayment register. Amount = principal + interest. Landscape A4 — not a print of the screen.</p>
    <p>Confidential — internal use only.</p>
  </div>
</body>
</html>
    <?php
    $html = ob_get_clean();
    $filename = 'loan_repayments_register_' . date('Ymd_His') . '.pdf';
    try {
        pdf_download($html, $filename, 'landscape', 'A4');
    } catch (Throwable $e) {
        flash('error', 'PDF generation failed: ' . $e->getMessage());
        redirect('pages/loan-repayments.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $postAction = post('action', '');


    if ($postAction === 'edit_repayment') {
        $repayId = (int) post('repayment_id', 0);
        $amount = (float) post('amount', 0);
        $interestAmount = (float) post('interest_amount', 0);
        $paymentDate = post('payment_date', date('Y-m-d'));
        $bankAccountId = post('bank_account_id') !== '' ? (int) post('bank_account_id') : null;
        $borrowerId = post('borrower_id') !== '' ? (int) post('borrower_id') : null;
        $notes = post('notes', '');

        $rStmt = $pdo->prepare('SELECT lr.*, bl.lender_name FROM loan_repayments lr JOIN bank_loans bl ON bl.id = lr.loan_id WHERE lr.id = ?');
        $rStmt->execute([$repayId]);
        $repayment = $rStmt->fetch();
        if (!$repayment || $amount <= 0) {
            flash('error', 'Invalid repayment.');
            redirect('pages/loan-repayments.php');
        }
        if ($interestAmount > $amount) {
            $interestAmount = $amount;
        }
        $principalAmount = round($amount - $interestAmount, 2);

        $borrowerName = null;
        if ($borrowerId) {
            $bnStmt = $pdo->prepare('SELECT name FROM loan_borrowers WHERE id = ? AND loan_id = ?');
            $bnStmt->execute([$borrowerId, (int) $repayment['loan_id']]);
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
                    'Loan repayment — ' . $repayment['lender_name'] . $descSuffix . ' — P ' . money($principalAmount) . ' / I ' . money($interestAmount),
                    $repayment['transaction_id'],
                ]);
        }

        $pdo->prepare('UPDATE loan_repayments SET amount=?, principal_amount=?, interest_amount=?, payment_date=?, bank_account_id=?, borrower_id=?, notes=? WHERE id=?')
            ->execute([$amount, $principalAmount, $interestAmount, $paymentDate, $bankAccountId, $borrowerId, $notes, $repayId]);

        refresh_loan_outstanding($pdo, (int) $repayment['loan_id']);
        audit_log($pdo, 'update', 'loan_repayment', (int) $repayment['loan_id'], 'Edited repayment #' . $repayId . ' to ' . money($amount) . ' (P ' . money($principalAmount) . ' / I ' . money($interestAmount) . ')' . $descSuffix);
        flash('success', 'Repayment updated.');
        redirect('pages/loan-repayments.php');
    }

    if ($postAction === 'delete_repayment') {
        if (!can_delete()) {
            flash('error', 'Only admins can delete repayments.');
            redirect('pages/loan-repayments.php');
        }
        $repayId = (int) post('repayment_id', 0);
        $rStmt = $pdo->prepare('SELECT transaction_id, loan_id FROM loan_repayments WHERE id = ?');
        $rStmt->execute([$repayId]);
        $repayment = $rStmt->fetch();
        if ($repayment) {
            $pdo->prepare('DELETE FROM loan_repayments WHERE id = ?')->execute([$repayId]);
            if ($repayment['transaction_id']) {
                $pdo->prepare('DELETE FROM transactions WHERE id = ?')->execute([(int) $repayment['transaction_id']]);
            }
            refresh_loan_outstanding($pdo, (int) $repayment['loan_id']);
            audit_log($pdo, 'delete', 'loan_repayment', (int) $repayment['loan_id'], 'Deleted repayment #' . $repayId);
        }
        flash('success', 'Repayment deleted.');
        redirect('pages/loan-repayments.php');
    }
}

$filterCompany = (int) get('company_id', 0);
$filterLoan = (int) get('loan_id', 0);
$filterFrom = get('from', '');
$filterTo = get('to', '');
[$fromMonth, $toMonth, $month, $year] = period_from_request();
if ($month !== '' || $year !== '') {
    if ($filterFrom === '' && $filterTo === '') {
        $filterFrom = $fromMonth ?: '';
        $filterTo = $toMonth ?: '';
    }
}

$pageTitle = 'Loan Repayments';
$pageSub = 'Every repayment across all bank loans, grouped by lender.';
$pageActions = report_export_buttons()
    . '<a class="btn btn-primary" href="' . e(base_url('pages/bank-loans.php')) . '">+ Go to loans</a>';

$sql = "SELECT lr.*, bl.lender_name, bl.company_id, c.name AS company_name, ba.account_name, ba.bank_name, lb.name AS borrower_name
        FROM loan_repayments lr
        JOIN bank_loans bl ON bl.id = lr.loan_id
        JOIN companies c ON c.id = bl.company_id
        LEFT JOIN bank_accounts ba ON ba.id = lr.bank_account_id
        LEFT JOIN loan_borrowers lb ON lb.id = lr.borrower_id
        WHERE 1=1";
$params = [];
if ($filterCompany) { $sql .= ' AND bl.company_id = ?'; $params[] = $filterCompany; }
if ($filterLoan) { $sql .= ' AND lr.loan_id = ?'; $params[] = $filterLoan; }
apply_date_range($sql, $params, $filterFrom !== '' ? $filterFrom : null, $filterTo !== '' ? $filterTo : null, 'lr.payment_date');
$sql .= ' ORDER BY lr.payment_date DESC, lr.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$totalRepaid = array_sum(array_map(fn($r) => (float) $r['amount'], $rows));
$totalPrincipal = array_sum(array_map(fn($r) => (float) $r['principal_amount'], $rows));
$totalInterest = array_sum(array_map(fn($r) => (float) $r['interest_amount'], $rows));

$loanGroups = group_repayments_by_loan($rows);
$allLoans = $pdo->query('SELECT id, lender_name FROM bank_loans ORDER BY lender_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('export_action', ''), ['csv', 'excel', 'pdf'], true)) {
    verify_csrf();
    $selectedIds = array_values(array_unique(array_filter(array_map('intval', $_POST['repayment_ids'] ?? []), fn($id) => $id > 0)));
    $companyName = 'All companies';
    if ($filterCompany) {
        $cn = $pdo->prepare('SELECT name FROM companies WHERE id = ?');
        $cn->execute([$filterCompany]);
        $companyName = (string) ($cn->fetchColumn() ?: 'Company #' . $filterCompany);
    }
    $lenderName = 'All lenders';
    if ($filterLoan) {
        $ln = $pdo->prepare('SELECT lender_name FROM bank_loans WHERE id = ?');
        $ln->execute([$filterLoan]);
        $lenderName = (string) ($ln->fetchColumn() ?: 'Loan #' . $filterLoan);
    }
    $period = report_display_period($filterFrom !== '' ? $filterFrom : null, $filterTo !== '' ? $filterTo : null, $month, $year);

    if ($selectedIds) {
        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $expStmt = $pdo->prepare(
            "SELECT lr.*, bl.lender_name, c.name AS company_name, ba.account_name, ba.bank_name, lb.name AS borrower_name
             FROM loan_repayments lr
             JOIN bank_loans bl ON bl.id = lr.loan_id
             JOIN companies c ON c.id = bl.company_id
             LEFT JOIN bank_accounts ba ON ba.id = lr.bank_account_id
             LEFT JOIN loan_borrowers lb ON lb.id = lr.borrower_id
             WHERE lr.id IN ($placeholders)
             ORDER BY lr.payment_date ASC, lr.id ASC"
        );
        $expStmt->execute($selectedIds);
        $exportRows = $expStmt->fetchAll();
        if (!$exportRows) {
            flash('error', 'Selected repayments were not found.');
            redirect('pages/loan-repayments.php');
        }
        $scopeNote = 'Figures reflect the selected repayments at export time.';
        $meta = [
            ['Scope', 'Selected repayments'],
            ['Entries', (string) count($exportRows)],
        ];
    } else {
        $exportRows = $rows;
        $scopeNote = 'Figures match the current filters at export time.';
        $meta = [
            ['Period', $period],
            ['Company', $companyName],
            ['Lender', $lenderName],
            ['Entries', (string) count($exportRows)],
        ];
    }

    usort($exportRows, static function ($a, $b) {
        $da = (string) ($a['payment_date'] ?? '');
        $db = (string) ($b['payment_date'] ?? '');
        if ($da === $db) {
            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        }
        return $da <=> $db;
    });
    $dates = array_values(array_filter(array_map(static fn($r) => (string) ($r['payment_date'] ?? ''), $exportRows)));
    if ($dates) {
        $meta[] = ['Date range', report_plain_date($dates[0]) . ' to ' . report_plain_date($dates[count($dates) - 1])];
    }

    $exportTotal = 0.0;
    $exportPrincipal = 0.0;
    $exportInterest = 0.0;
    $repayRows = [];
    foreach ($exportRows as $i => $r) {
        $amt = (float) $r['amount'];
        $prin = (float) $r['principal_amount'];
        $int = (float) $r['interest_amount'];
        $exportTotal += $amt;
        $exportPrincipal += $prin;
        $exportInterest += $int;
        $account = trim((string) ($r['account_name'] ?? ''));
        if ($account === '') {
            $account = 'Cash';
        }
        $repayRows[] = [
            (string) ($i + 1),
            report_plain_date($r['payment_date'] ?? null),
            $r['lender_name'] ?? '',
            $r['company_name'] ?? '',
            $r['borrower_name'] ?: '—',
            $account,
            $amt,
            $prin,
            $int,
            $r['notes'] ?? '',
        ];
    }

    $byLender = [];
    foreach ($exportRows as $r) {
        $key = (string) ($r['lender_name'] ?? '—');
        if (!isset($byLender[$key])) {
            $byLender[$key] = ['count' => 0, 'total' => 0.0, 'principal' => 0.0, 'interest' => 0.0];
        }
        $byLender[$key]['count']++;
        $byLender[$key]['total'] += (float) $r['amount'];
        $byLender[$key]['principal'] += (float) $r['principal_amount'];
        $byLender[$key]['interest'] += (float) $r['interest_amount'];
    }
    ksort($byLender);
    $lenderRows = [];
    $n = 0;
    foreach ($byLender as $name => $info) {
        $n++;
        $lenderRows[] = [(string) $n, $name, $info['count'], $info['total'], $info['principal'], $info['interest']];
    }

    $format = post('export_action');
    if ($format === 'pdf') {
        loan_repayments_output_pdf(
            $exportRows,
            $meta,
            $exportTotal,
            $exportPrincipal,
            $exportInterest,
            $byLender,
            $scopeNote
        );
    }

    report_download($format, [
        'filename' => 'loan_repayments_register',
        'title' => 'Loan Repayment Register',
        'orientation' => 'landscape',
        'meta' => $meta,
        'summary' => [
            ['Total repaid', $exportTotal, 'money'],
            ['Principal', $exportPrincipal, 'money'],
            ['Interest', $exportInterest, 'money'],
            ['Entries', count($exportRows), 'int'],
        ],
        'tables' => [
            [
                'title' => 'Repayment register',
                'columns' => [
                    ['label' => 'Sr', 'type' => 'text', 'width' => '4%', 'xls_width' => 35],
                    ['label' => 'Date', 'type' => 'text', 'width' => '8%', 'xls_width' => 80],
                    ['label' => 'Lender / Bank', 'type' => 'text', 'width' => '18%', 'xls_width' => 160],
                    ['label' => 'Company', 'type' => 'text', 'width' => '14%', 'xls_width' => 130],
                    ['label' => 'Borrower', 'type' => 'text', 'width' => '14%', 'xls_width' => 130],
                    ['label' => 'Account', 'type' => 'text', 'width' => '12%', 'xls_width' => 120],
                    ['label' => 'Amount (INR)', 'type' => 'money', 'width' => '10%', 'xls_width' => 100],
                    ['label' => 'Principal (INR)', 'type' => 'money', 'width' => '10%', 'xls_width' => 100],
                    ['label' => 'Interest (INR)', 'type' => 'money', 'width' => '10%', 'xls_width' => 100],
                    ['label' => 'Notes', 'type' => 'text', 'xls_width' => 140, 'pdf' => false],
                ],
                'rows' => $repayRows,
                'totals' => ['', 'TOTAL', '', '', '', '', $exportTotal, $exportPrincipal, $exportInterest, ''],
            ],
            [
                'title' => 'Totals by lender',
                'columns' => [
                    ['label' => 'Sr No', 'type' => 'text', 'width' => '8%', 'xls_width' => 40],
                    ['label' => 'Lender / Bank', 'type' => 'text', 'width' => '28%', 'xls_width' => 180],
                    ['label' => 'Entries', 'type' => 'int', 'width' => '12%', 'xls_width' => 70],
                    ['label' => 'Amount (INR)', 'type' => 'money', 'width' => '18%', 'xls_width' => 110],
                    ['label' => 'Principal (INR)', 'type' => 'money', 'width' => '17%', 'xls_width' => 110],
                    ['label' => 'Interest (INR)', 'type' => 'money', 'width' => '17%', 'xls_width' => 110],
                ],
                'rows' => $lenderRows,
                'totals' => ['', 'TOTAL', count($exportRows), $exportTotal, $exportPrincipal, $exportInterest],
            ],
        ],
        'notes' => [
            $scopeNote,
            'System-generated loan repayment register. Amount = principal + interest.',
            'Confidential — internal use only.',
        ],
    ]);
    redirect('pages/loan-repayments.php');
}

$loanPage = paginate_list(array_values($loanGroups));

require __DIR__ . '/../includes/header.php';
?>
<div class="stat-grid" style="grid-template-columns:repeat(4,1fr)">
  <div class="stat-card">
    <div class="stat-label">Total repaid</div>
    <div class="stat-value"><?= money($totalRepaid) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Principal</div>
    <div class="stat-value text-success"><?= money($totalPrincipal) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Interest</div>
    <div class="stat-value text-danger"><?= money($totalInterest) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Entries</div>
    <div class="stat-value"><?= count($rows) ?></div>
  </div>
</div>
<form class="filters" method="get">
  <?= list_limit_hidden() ?>
  <?= period_filter_fields($month, $year) ?>
  <div class="field">
    <label>Company</label>
    <select name="company_id">
      <option value="">All</option>
      <?php foreach ($pdo->query('SELECT id, name FROM companies ORDER BY type, name') as $co): ?>
        <option value="<?= (int) $co['id'] ?>" <?= $filterCompany === (int) $co['id'] ? 'selected' : '' ?>><?= e($co['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label>Bank / Lender</label>
    <select name="loan_id">
      <option value="">All</option>
      <?php foreach ($allLoans as $ln): ?>
        <option value="<?= (int) $ln['id'] ?>" <?= $filterLoan === (int) $ln['id'] ? 'selected' : '' ?>><?= e($ln['lender_name']) ?></option>
      <?php endforeach; ?>
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

<?php if (!$rows): ?>
  <div class="card">
    <div class="empty"><strong>No repayments yet</strong><p>Record repayments from a loan's page — they will show up here grouped by bank.</p></div>
  </div>
<?php else: ?>
  <form id="loanRepaymentsExportForm" class="bulk-export-form" method="post">
    <?= csrf_field() ?>
    <div class="export-toolbar no-print">
      <label class="select-all-label">
        <input type="checkbox" class="select-all-toggle">
        Select all
      </label>
      <span class="selected-count muted">0 selected</span>
      <div class="export-actions">
        <button class="btn btn-outline btn-sm export-csv-btn" type="submit" name="export_action" value="csv" disabled>Download CSV</button>
        <button class="btn btn-outline btn-sm" type="submit" name="export_action" value="excel" disabled>Download Excel</button>
        <button class="btn btn-outline btn-sm export-pdf-btn" type="submit" name="export_action" value="pdf" disabled>Download PDF</button>
      </div>
    </div>
  </form>
  <div class="card" id="list">
    <div class="card-head">
      <h2 class="card-title">Repayments by lender</h2>
      <?php render_limit_control('loan-repayments.php'); ?>
    </div>
    <?php render_repayment_loans($pdo, $loanPage['rows']); ?>
    <?php render_pager('loan-repayments.php', $loanPage); ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
