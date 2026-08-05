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
                        </tr>
                        <tr class="row-detail" id="<?= e($repayEditId) ?>" hidden>
                          <td colspan="8">
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $postAction = post('action', '');

    if (in_array(post('export_action', ''), ['csv', 'pdf'], true)) {
        $exportAction = post('export_action');
        $selectedIds = array_values(array_unique(array_filter(array_map('intval', $_POST['repayment_ids'] ?? []), fn($id) => $id > 0)));

        if (!$selectedIds) {
            flash('error', 'Select at least one repayment to export.');
            redirect('pages/loan-repayments.php');
        }

        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $expStmt = $pdo->prepare(
            "SELECT lr.*, bl.lender_name, c.name AS company_name, ba.account_name, ba.bank_name, lb.name AS borrower_name
             FROM loan_repayments lr
             JOIN bank_loans bl ON bl.id = lr.loan_id
             JOIN companies c ON c.id = bl.company_id
             LEFT JOIN bank_accounts ba ON ba.id = lr.bank_account_id
             LEFT JOIN loan_borrowers lb ON lb.id = lr.borrower_id
             WHERE lr.id IN ($placeholders)
             ORDER BY lr.payment_date DESC, lr.id DESC"
        );
        $expStmt->execute($selectedIds);
        $exportRows = $expStmt->fetchAll();

        if (!$exportRows) {
            flash('error', 'Selected repayments were not found.');
            redirect('pages/loan-repayments.php');
        }

        $exportTotal = array_sum(array_map(fn($r) => (float) $r['amount'], $exportRows));
        $exportPrincipal = array_sum(array_map(fn($r) => (float) $r['principal_amount'], $exportRows));
        $exportInterest = array_sum(array_map(fn($r) => (float) $r['interest_amount'], $exportRows));

        if ($exportAction === 'csv') {
            $filename = 'loan_repayments_' . date('Ymd_His') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Lender', 'Company', 'Amount', 'Principal', 'Interest', 'Bank Account', 'Borrower', 'Notes']);
            foreach ($exportRows as $r) {
                fputcsv($out, [
                    $r['payment_date'],
                    $r['lender_name'],
                    $r['company_name'],
                    number_format((float) $r['amount'], 2, '.', ''),
                    number_format((float) $r['principal_amount'], 2, '.', ''),
                    number_format((float) $r['interest_amount'], 2, '.', ''),
                    $r['account_name'] ? ($r['account_name'] . ' - ' . $r['bank_name']) : '',
                    $r['borrower_name'] ?? '',
                    $r['notes'] ?? '',
                ]);
            }
            fputcsv($out, []);
            fputcsv($out, ['', '', 'Total', number_format($exportTotal, 2, '.', ''), number_format($exportPrincipal, 2, '.', ''), number_format($exportInterest, 2, '.', ''), '', '', '']);
            fclose($out);
            exit;
        }

        // PDF: formal print-ready report sheet, same pattern as Investments/Reports.
        $entryWord = count($exportRows) === 1 ? 'entry' : 'entries';
        $datesCovered = array_unique(array_map(fn($r) => $r['payment_date'], $exportRows));
        sort($datesCovered);
        $rangeLabel = count($datesCovered) > 1
            ? format_date($datesCovered[0]) . ' – ' . format_date(end($datesCovered))
            : format_date($datesCovered[0] ?? null);

        $pageTitle = 'Loan repayments export';
        $pageSub = count($exportRows) . ' selected ' . $entryWord . '.';
        $pageActions = '<button class="btn btn-primary no-print" type="button" onclick="window.print()">Print / Save PDF</button>';
        require __DIR__ . '/../includes/header.php';
        ?>
        <link rel="stylesheet" href="<?= e(base_url('assets/css/print.css')) ?>">
        <div class="print-sheet card">
          <div class="print-header report-header">
            <div>
              <div class="print-brand" style="font-family:Sora,sans-serif;font-weight:800;font-size:1.35rem;color:var(--teal-700,#0f766e)">Sai Kuber Developers</div>
              <div class="report-doc-title">Loan Repayments Report</div>
              <div class="print-meta report-meta" style="text-align:left"><?= count($exportRows) ?> <?= e($entryWord) ?> · <?= e($rangeLabel) ?></div>
            </div>
            <div class="print-meta report-meta">Generated <?= e(date('d-m-Y, h:i A')) ?><br>By <?= e(current_user()['name'] ?? '') ?></div>
          </div>

          <div class="report-summary">
            <div>
              <div class="label">Total repaid</div>
              <div class="value"><?= money($exportTotal) ?></div>
            </div>
            <div>
              <div class="label">Principal</div>
              <div class="value text-success"><?= money($exportPrincipal) ?></div>
            </div>
            <div>
              <div class="label">Interest</div>
              <div class="value text-danger"><?= money($exportInterest) ?></div>
            </div>
          </div>

          <div class="table-wrap">
            <table class="data">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Date</th>
                  <th>Lender</th>
                  <th>Company</th>
                  <th>Bank account</th>
                  <th>Borrower</th>
                  <th class="num">Amount (₹)</th>
                  <th class="num">Principal (₹)</th>
                  <th class="num">Interest (₹)</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($exportRows as $i => $r): ?>
                  <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= e(format_date($r['payment_date'])) ?></td>
                    <td><?= e($r['lender_name']) ?></td>
                    <td><?= e($r['company_name']) ?></td>
                    <td><?= $r['account_name'] ? e($r['account_name'] . ' — ' . $r['bank_name']) : '—' ?></td>
                    <td><?= e($r['borrower_name'] ?: '—') ?></td>
                    <td class="num"><?= indian_number_format((float) $r['amount'], 2) ?></td>
                    <td class="num"><?= indian_number_format((float) $r['principal_amount'], 2) ?></td>
                    <td class="num"><?= indian_number_format((float) $r['interest_amount'], 2) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr>
                  <td colspan="6">TOTAL</td>
                  <td class="num"><?= indian_number_format((float) $exportTotal, 2) ?></td>
                  <td class="num"><?= indian_number_format((float) $exportPrincipal, 2) ?></td>
                  <td class="num"><?= indian_number_format((float) $exportInterest, 2) ?></td>
                </tr>
              </tfoot>
            </table>
          </div>

          <div class="report-footnote">
            <p>This is a system-generated report from the Sai Kuber Developers finance system. Figures reflect the repayments selected at export time.</p>
            <p>Confidential — internal use only.</p>
          </div>
        </div>
        <?php
        require __DIR__ . '/../includes/footer.php';
        exit;
    }

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
$pageActions = '<a class="btn btn-primary" href="' . e(base_url('pages/bank-loans.php')) . '">+ Go to loans</a>';

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
        <button class="btn btn-outline btn-sm export-csv-btn" type="submit" name="export_action" value="csv" disabled>Export CSV</button>
        <button class="btn btn-outline btn-sm export-pdf-btn" type="submit" name="export_action" value="pdf" disabled>Export PDF</button>
      </div>
    </div>
  </form>
  <div class="card">
    <?php render_repayment_loans($pdo, $loanGroups); ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
