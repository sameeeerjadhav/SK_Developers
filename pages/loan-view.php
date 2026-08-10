<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$id = (int) get('id', 0);
$viewOnly = get('mode', 'view') !== 'repay';
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
    $exportAction = post('export_action', '');
    $isExport = in_array($exportAction, ['csv', 'excel', 'pdf'], true);
    $postAction = post('action', '');

    if (!$isExport && $viewOnly) {
        flash('error', 'Open Repayments to record or edit payments.');
        redirect('pages/loan-view.php?id=' . $id . '&mode=view');
    }

    if (!$isExport && $postAction === 'record_repayment') {
        $amount = (float) post('amount', 0);
        $interestAmount = (float) post('interest_amount', 0);
        $paymentDate = post('payment_date', date('Y-m-d'));
        $bankAccountId = post('bank_account_id') !== '' ? (int) post('bank_account_id') : null;
        $borrowerId = post('borrower_id') !== '' ? (int) post('borrower_id') : null;
        $notes = post('notes', '');
        $postLedger = !empty($_POST['post_to_ledger']);

        if ($amount <= 0) {
            flash('error', 'Enter a positive repayment amount.');
            redirect('pages/loan-view.php?id=' . $id . '&mode=repay');
        }
        $bc = $pdo->prepare('SELECT COUNT(*) FROM loan_borrowers WHERE loan_id = ?');
        $bc->execute([$id]);
        if ((int) $bc->fetchColumn() > 0 && !$borrowerId) {
            flash('error', 'Select which borrower paid this amount.');
            redirect('pages/loan-view.php?id=' . $id . '&mode=repay');
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
        redirect('pages/loan-view.php?id=' . $id . '&mode=repay');
    }

    if (!$isExport && $postAction === 'edit_repayment') {
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
            redirect('pages/loan-view.php?id=' . $id . '&mode=repay');
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
        redirect('pages/loan-view.php?id=' . $id . '&mode=repay');
    }

    if (!$isExport && $postAction === 'delete_repayment') {
        if (!can_delete()) {
            flash('error', 'Only admins can delete repayments.');
            redirect('pages/loan-view.php?id=' . $id . '&mode=repay');
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
        redirect('pages/loan-view.php?id=' . $id . '&mode=repay');
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

// Build borrower report rows with the same outstanding math shown on screen
$borrowerReportRows = [];
foreach ($borrowers as $b) {
    $bid = (int) $b['id'];
    $paid = $paidByBorrower[$bid] ?? ['total' => 0.0, 'principal' => 0.0, 'interest' => 0.0, 'count' => 0];
    $loanAmt = $b['loan_amount'] !== null ? (float) $b['loan_amount'] : null;
    $outstanding = $b['outstanding_amount'] !== null
        ? (float) $b['outstanding_amount']
        : ($loanAmt !== null ? max(0, $loanAmt - (float) $paid['principal']) : null);
    $borrowerReportRows[] = [
        'name' => (string) ($b['name'] ?? ''),
        'account_number' => (string) ($b['account_number'] ?? ''),
        'loan_amount' => $loanAmt,
        'outstanding' => $outstanding,
        'principal_repaid' => (float) $paid['principal'],
        'interest_repaid' => (float) $paid['interest'],
        'total_repaid' => (float) $paid['total'],
        'repay_count' => (int) $paid['count'],
        'interest_charges' => $b['interest_charges'] !== null ? (float) $b['interest_charges'] : null,
        'start_date' => $b['start_date'] ?? null,
        'end_date' => $b['end_date'] ?? null,
        'mortgage_noc_date' => $b['mortgage_noc_date'] ?? null,
        'reconveyance_date' => $b['reconveyance_date'] ?? null,
    ];
}

$exportAction = ($_SERVER['REQUEST_METHOD'] === 'POST') ? post('export_action', '') : '';
if (in_array($exportAction, ['csv', 'excel', 'pdf'], true)) {
    $modeQs = $viewOnly ? 'view' : 'repay';
    $safeLender = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $loan['lender_name']) ?: 'loan';
    $safeLender = trim($safeLender, '_');
    $stamp = date('Ymd_His');
    $plain = static fn($n): string => number_format((float) $n, 2, '.', '');
    $plainOrBlank = static fn($n): string => $n === null ? '' : number_format((float) $n, 2, '.', '');

    if ($exportAction === 'csv') {
        $filename = 'loan_' . $safeLender . '_' . $id . '_' . $stamp . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, ['Loan Report']);
        fputcsv($out, ['Lender', $loan['lender_name']]);
        fputcsv($out, ['Company', $loan['company_name']]);
        fputcsv($out, ['Project', $loan['project_name'] ?? '']);
        fputcsv($out, ['Status', $loan['status']]);
        fputcsv($out, ['Generated', date('Y-m-d H:i:s')]);
        fputcsv($out, []);

        fputcsv($out, ['Summary']);
        fputcsv($out, ['Metric', 'Amount (INR)']);
        fputcsv($out, ['Total loan amount', $plain($loan['loan_amount'])]);
        fputcsv($out, ['Total outstanding', $plain($loan['outstanding_amount'])]);
        fputcsv($out, ['Interest + charges', $plainOrBlank($loan['interest_charges'])]);
        fputcsv($out, ['Total repaid', $plain($totalRepaid)]);
        fputcsv($out, ['Principal repaid', $plain($principalRepaid)]);
        fputcsv($out, ['Interest repaid', $plain($interestRepaid)]);
        fputcsv($out, ['Repayment entries', (string) count($repayments)]);
        fputcsv($out, []);

        fputcsv($out, ['Borrowers / guarantors']);
        fputcsv($out, [
            'Name', 'A/C', 'Loan amount', 'Outstanding', 'Principal repaid', 'Interest repaid',
            'Total repaid', 'Entries', 'Interest + charges', 'Start date', 'End date', 'Mortgage NOC', 'Reconveyance',
        ]);
        foreach ($borrowerReportRows as $br) {
            fputcsv($out, [
                $br['name'],
                $br['account_number'],
                $plainOrBlank($br['loan_amount']),
                $plainOrBlank($br['outstanding']),
                $plain($br['principal_repaid']),
                $plain($br['interest_repaid']),
                $plain($br['total_repaid']),
                (string) $br['repay_count'],
                $plainOrBlank($br['interest_charges']),
                $br['start_date'] ?? '',
                $br['end_date'] ?? '',
                $br['mortgage_noc_date'] ?? '',
                $br['reconveyance_date'] ?? '',
            ]);
        }
        if ($borrowerReportRows) {
            fputcsv($out, [
                'TOTAL', '', $plain($borrowersTotal), $plain($borrowersOutstandingTotal),
                '', '', '', '', '', '', '', '', '',
            ]);
        }
        fputcsv($out, []);

        fputcsv($out, ['Repayment history']);
        fputcsv($out, ['Date', 'Amount', 'Principal', 'Interest', 'Bank account', 'Borrower', 'Notes']);
        foreach ($repayments as $r) {
            fputcsv($out, [
                $r['payment_date'],
                $plain($r['amount']),
                $plain($r['principal_amount']),
                $plain($r['interest_amount']),
                $r['account_name'] ? ($r['account_name'] . ' - ' . $r['bank_name']) : '',
                $r['borrower_name'] ?? '',
                $r['notes'] ?? '',
            ]);
        }
        fputcsv($out, [
            'TOTAL',
            $plain($totalRepaid),
            $plain($principalRepaid),
            $plain($interestRepaid),
            '', '', '',
        ]);
        fclose($out);
        exit;
    }

    if ($exportAction === 'excel') {
        $filename = 'loan_' . $safeLender . '_' . $id . '_' . $stamp . '.xls';
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $xmlEsc = static fn($v): string => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $rowString = static function (array $cells) use ($xmlEsc): string {
            $html = '<Row>';
            foreach ($cells as $cell) {
                $html .= '<Cell><Data ss:Type="String">' . $xmlEsc($cell) . '</Data></Cell>';
            }
            return $html . '</Row>';
        };
        $rowMixed = static function (array $cells) use ($xmlEsc): string {
            $html = '<Row>';
            foreach ($cells as $cell) {
                if (is_array($cell) && ($cell['t'] ?? '') === 'n') {
                    $html .= '<Cell><Data ss:Type="Number">' . $xmlEsc($cell['v']) . '</Data></Cell>';
                } else {
                    $html .= '<Cell><Data ss:Type="String">' . $xmlEsc(is_array($cell) ? ($cell['v'] ?? '') : $cell) . '</Data></Cell>';
                }
            }
            return $html . '</Row>';
        };
        $numCell = static fn($n): array => ['t' => 'n', 'v' => number_format((float) $n, 2, '.', '')];
        $numOrBlank = static function ($n) use ($numCell) {
            return $n === null ? '' : $numCell($n);
        };

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
        echo '<Worksheet ss:Name="Loan Report"><Table>' . "\n";

        echo $rowString(['Loan Report']);
        echo $rowString(['Lender', $loan['lender_name']]);
        echo $rowString(['Company', $loan['company_name']]);
        echo $rowString(['Project', $loan['project_name'] ?? '']);
        echo $rowString(['Status', $loan['status']]);
        echo $rowString(['Generated', date('Y-m-d H:i:s')]);
        echo $rowString(['']);
        echo $rowString(['Summary']);
        echo $rowString(['Metric', 'Amount (INR)']);
        echo $rowMixed(['Total loan amount', $numCell($loan['loan_amount'])]);
        echo $rowMixed(['Total outstanding', $numCell($loan['outstanding_amount'])]);
        echo $rowMixed(['Interest + charges', $numOrBlank($loan['interest_charges'])]);
        echo $rowMixed(['Total repaid', $numCell($totalRepaid)]);
        echo $rowMixed(['Principal repaid', $numCell($principalRepaid)]);
        echo $rowMixed(['Interest repaid', $numCell($interestRepaid)]);
        echo $rowMixed(['Repayment entries', ['t' => 'n', 'v' => (string) count($repayments)]]);
        echo $rowString(['']);

        echo $rowString(['Borrowers / guarantors']);
        echo $rowString([
            'Name', 'A/C', 'Loan amount', 'Outstanding', 'Principal repaid', 'Interest repaid',
            'Total repaid', 'Entries', 'Interest + charges', 'Start date', 'End date', 'Mortgage NOC', 'Reconveyance',
        ]);
        foreach ($borrowerReportRows as $br) {
            echo $rowMixed([
                $br['name'],
                $br['account_number'],
                $numOrBlank($br['loan_amount']),
                $numOrBlank($br['outstanding']),
                $numCell($br['principal_repaid']),
                $numCell($br['interest_repaid']),
                $numCell($br['total_repaid']),
                ['t' => 'n', 'v' => (string) $br['repay_count']],
                $numOrBlank($br['interest_charges']),
                $br['start_date'] ?? '',
                $br['end_date'] ?? '',
                $br['mortgage_noc_date'] ?? '',
                $br['reconveyance_date'] ?? '',
            ]);
        }
        if ($borrowerReportRows) {
            echo $rowMixed([
                'TOTAL', '', $numCell($borrowersTotal), $numCell($borrowersOutstandingTotal),
                '', '', '', '', '', '', '', '', '',
            ]);
        }
        echo $rowString(['']);

        echo $rowString(['Repayment history']);
        echo $rowString(['Date', 'Amount', 'Principal', 'Interest', 'Bank account', 'Borrower', 'Notes']);
        foreach ($repayments as $r) {
            echo $rowMixed([
                $r['payment_date'],
                $numCell($r['amount']),
                $numCell($r['principal_amount']),
                $numCell($r['interest_amount']),
                $r['account_name'] ? ($r['account_name'] . ' - ' . $r['bank_name']) : '',
                $r['borrower_name'] ?? '',
                $r['notes'] ?? '',
            ]);
        }
        echo $rowMixed([
            'TOTAL',
            $numCell($totalRepaid),
            $numCell($principalRepaid),
            $numCell($interestRepaid),
            '', '', '',
        ]);

        echo '</Table></Worksheet></Workbook>';
        exit;
    }

    // PDF: real Dompdf download (not browser print)
    $generatedAt = date('d-m-Y, h:i A');
    $generatedBy = current_user()['name'] ?? '';
    $interestChargesLabel = $loan['interest_charges'] !== null ? money($loan['interest_charges']) : '—';

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  @page { margin: 14mm 12mm; }
  * { box-sizing: border-box; }
  body {
    font-family: DejaVu Sans, sans-serif;
    font-size: 9pt;
    color: #0b1f1c;
    margin: 0;
    line-height: 1.35;
  }
  .brand { font-size: 16pt; font-weight: 700; color: #0f766e; margin: 0 0 2px; }
  .doc-title {
    font-size: 11pt; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.06em; margin: 0 0 4px;
  }
  .meta { font-size: 8pt; color: #5b6f6b; }
  .header-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
  .header-table td { vertical-align: top; padding: 0; }
  .header-table td.right { text-align: right; }
  .summary {
    width: 100%;
    border-collapse: collapse;
    margin: 8px 0 12px;
    border: 1px solid #cfe3df;
  }
  .summary td {
    width: 25%;
    padding: 8px 10px;
    border-right: 1px solid #cfe3df;
    background: #f7fcfb;
  }
  .summary td:last-child { border-right: none; }
  .summary .label {
    font-size: 7pt; text-transform: uppercase; letter-spacing: 0.05em;
    color: #5b6f6b; font-weight: 700; margin-bottom: 3px;
  }
  .summary .value { font-size: 11pt; font-weight: 700; }
  .ok { color: #047857; }
  .bad { color: #b91c1c; }
  h3 {
    font-size: 10pt; margin: 14px 0 6px; color: #0f766e;
    border-bottom: 1px solid #cfe3df; padding-bottom: 3px;
  }
  table.data {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    font-size: 8pt;
  }
  table.data th, table.data td {
    border: 1px solid #d7e6e2;
    padding: 4px 5px;
    vertical-align: top;
    word-wrap: break-word;
  }
  table.data th {
    background: #eef8f5;
    font-size: 7pt;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    text-align: left;
  }
  table.data td.num, table.data th.num { text-align: right; }
  table.data tfoot td {
    background: #eef8f5;
    font-weight: 700;
    border-top: 2px solid #9ec5bd;
  }
  .footnote {
    margin-top: 14px;
    padding-top: 8px;
    border-top: 1px solid #cfe3df;
    font-size: 7.5pt;
    color: #5b6f6b;
  }
  .footnote p { margin: 2px 0; }
</style>
</head>
<body>
  <table class="header-table">
    <tr>
      <td>
        <div class="brand">Sai Kuber Developers</div>
        <div class="doc-title">Bank Loan Report</div>
        <div class="meta">
          <?= pdf_e($loan['lender_name']) ?> · <?= pdf_e($loan['company_name']) ?>
          <?= $loan['project_name'] ? ' · ' . pdf_e($loan['project_name']) : '' ?>
        </div>
      </td>
      <td class="right meta">
        Generated <?= pdf_e($generatedAt) ?><br>
        By <?= pdf_e($generatedBy) ?><br>
        Status: <?= pdf_e(ucfirst((string) $loan['status'])) ?>
      </td>
    </tr>
  </table>

  <table class="summary">
    <tr>
      <td>
        <div class="label">Loan amount</div>
        <div class="value"><?= pdf_e(money($loan['loan_amount'])) ?></div>
      </td>
      <td>
        <div class="label">Outstanding</div>
        <div class="value"><?= pdf_e(money($loan['outstanding_amount'])) ?></div>
      </td>
      <td>
        <div class="label">Total repaid</div>
        <div class="value"><?= pdf_e(money($totalRepaid)) ?></div>
      </td>
      <td>
        <div class="label">Principal / Interest</div>
        <div class="value" style="font-size:9.5pt">
          <span class="ok"><?= pdf_e(money($principalRepaid)) ?></span>
          /
          <span class="bad"><?= pdf_e(money($interestRepaid)) ?></span>
        </div>
      </td>
    </tr>
  </table>

  <?php if ($borrowerReportRows): ?>
  <h3>Borrowers / guarantors (<?= count($borrowerReportRows) ?>)</h3>
  <table class="data">
    <thead>
      <tr>
        <th style="width:4%">#</th>
        <th style="width:22%">Name</th>
        <th style="width:8%">A/C</th>
        <th class="num" style="width:11%">Loan</th>
        <th class="num" style="width:11%">Outstanding</th>
        <th class="num" style="width:12%">Principal repaid</th>
        <th class="num" style="width:12%">Interest repaid</th>
        <th class="num" style="width:20%">Total repaid</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($borrowerReportRows as $i => $br): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= pdf_e($br['name']) ?></td>
        <td><?= pdf_e($br['account_number'] !== '' ? $br['account_number'] : '—') ?></td>
        <td class="num"><?= $br['loan_amount'] !== null ? pdf_e(indian_number_format($br['loan_amount'], 2)) : '—' ?></td>
        <td class="num"><?= $br['outstanding'] !== null ? pdf_e(indian_number_format($br['outstanding'], 2)) : '—' ?></td>
        <td class="num ok"><?= pdf_e(indian_number_format($br['principal_repaid'], 2)) ?></td>
        <td class="num bad"><?= pdf_e(indian_number_format($br['interest_repaid'], 2)) ?></td>
        <td class="num"><?= pdf_e(indian_number_format($br['total_repaid'], 2)) ?><?= $br['repay_count'] ? ' (' . $br['repay_count'] . ')' : '' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="3">TOTAL</td>
        <td class="num"><?= pdf_e(indian_number_format($borrowersTotal, 2)) ?></td>
        <td class="num"><?= pdf_e(indian_number_format($borrowersOutstandingTotal, 2)) ?></td>
        <td colspan="3"></td>
      </tr>
    </tfoot>
  </table>
  <?php endif; ?>

  <h3>Repayment history (<?= count($repayments) ?>)</h3>
  <?php if (!$repayments): ?>
    <p class="meta">No repayments recorded.</p>
  <?php else: ?>
  <table class="data">
    <thead>
      <tr>
        <th style="width:4%">#</th>
        <th style="width:10%">Date</th>
        <th style="width:18%">Borrower</th>
        <th style="width:18%">Bank account</th>
        <th class="num" style="width:12%">Amount</th>
        <th class="num" style="width:12%">Principal</th>
        <th class="num" style="width:12%">Interest</th>
        <th style="width:14%">Notes</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($repayments as $i => $r): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= pdf_e(format_date($r['payment_date'])) ?></td>
        <td><?= pdf_e($r['borrower_name'] ?: '—') ?></td>
        <td><?= $r['account_name'] ? pdf_e($r['account_name'] . ' — ' . $r['bank_name']) : '—' ?></td>
        <td class="num"><?= pdf_e(indian_number_format((float) $r['amount'], 2)) ?></td>
        <td class="num ok"><?= pdf_e(indian_number_format((float) $r['principal_amount'], 2)) ?></td>
        <td class="num bad"><?= pdf_e(indian_number_format((float) $r['interest_amount'], 2)) ?></td>
        <td><?= pdf_e($r['notes'] ?: '') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="4">TOTAL</td>
        <td class="num"><?= pdf_e(indian_number_format($totalRepaid, 2)) ?></td>
        <td class="num"><?= pdf_e(indian_number_format($principalRepaid, 2)) ?></td>
        <td class="num"><?= pdf_e(indian_number_format($interestRepaid, 2)) ?></td>
        <td></td>
      </tr>
    </tfoot>
  </table>
  <?php endif; ?>

  <div class="footnote">
    <p>System-generated loan report. Figures match the loan screen at export time (loan #<?= (int) $id ?>).</p>
    <p>Interest + charges on loan: <?= pdf_e($interestChargesLabel) ?>.</p>
    <p>Confidential — internal use only.</p>
  </div>
</body>
</html>
    <?php
    $html = ob_get_clean();
    $pdfName = 'loan_' . $safeLender . '_' . $id . '_' . $stamp . '.pdf';
    try {
        pdf_download($html, $pdfName, 'landscape', 'A4');
    } catch (Throwable $e) {
        flash('error', 'PDF generation failed: ' . $e->getMessage());
        redirect('pages/loan-view.php?id=' . $id . '&mode=' . $modeQs);
    }
}

$borrowerOptions = function (?int $selected = null) use ($borrowers): string {
    $html = '<option value="">Whole loan / unspecified</option>';
    foreach ($borrowers as $bo) {
        $sel = $selected === (int) $bo['id'] ? ' selected' : '';
        $html .= '<option value="' . (int) $bo['id'] . '"' . $sel . '>' . e($bo['name']) . '</option>';
    }
    return $html;
};

$exportButtons =
    '<form method="post" class="no-print" style="display:inline-flex;gap:0.35rem;flex-wrap:wrap;align-items:center">' .
    csrf_field() .
    '<button class="btn btn-outline" type="submit" name="export_action" value="pdf">PDF</button>' .
    '<button class="btn btn-outline" type="submit" name="export_action" value="excel">Excel</button>' .
    '<button class="btn btn-outline" type="submit" name="export_action" value="csv">CSV</button>' .
    '</form>';

$pageTitle = $loan['lender_name'];
if ($viewOnly) {
    $pageSub = 'Loan overview — ' . $loan['company_name'] . '.';
    $pageActions =
        $exportButtons .
        '<a class="btn btn-primary" href="' . e(base_url('pages/loan-view.php?id=' . $id . '&mode=repay')) . '">Repayments</a>' .
        '<a class="btn btn-outline" href="' . e(base_url('pages/bank-loans.php?action=edit&id=' . $id)) . '">Edit loan</a>' .
        '<a class="btn btn-outline" href="' . e(base_url('pages/bank-loans.php')) . '">Back</a>';
} else {
    $pageSub = 'Loan repayments — ' . $loan['company_name'] . '. Amounts vary, so each repayment is entered manually.';
    $pageActions =
        $exportButtons .
        '<a class="btn btn-outline" href="' . e(base_url('pages/loan-view.php?id=' . $id . '&mode=view')) . '">View</a>' .
        '<a class="btn btn-outline" href="' . e(base_url('pages/bank-loans.php?action=edit&id=' . $id)) . '">Edit loan</a>' .
        '<a class="btn btn-outline" href="' . e(base_url('pages/bank-loans.php')) . '">Back</a>';
}

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

<?php if (!$viewOnly): ?>
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
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <h2 class="card-title">Repayment history</h2>
    <span class="muted" style="font-size:0.8rem"><?= count($repayments) ?> entries</span>
  </div>
  <?php if (!$repayments): ?>
    <div class="empty"><strong>No repayments recorded yet</strong><p><?= $viewOnly ? 'Open Repayments to record a payment.' : 'Use the form above whenever a repayment is made — amounts don\'t need to be fixed or scheduled.' ?></p></div>
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
            <tr class="<?= $viewOnly ? '' : 'row-clickable ' ?>repay-row" <?= $viewOnly ? '' : 'data-row-toggle="' . e($repayEditId) . '" ' ?>data-search="<?= e($repaySearch) ?>" data-borrower-id="<?= (int) ($r['borrower_id'] ?? 0) ?>" <?= $viewOnly ? '' : 'title="Click to edit"' ?>>
              <td><?= $viewOnly ? '' : '<span class="row-caret">▸</span>' ?><?= e(format_date($r['payment_date'])) ?></td>
              <td class="num"><?= money($r['amount']) ?></td>
              <td class="num text-success"><?= money($r['principal_amount']) ?></td>
              <td class="num text-danger"><?= money($r['interest_amount']) ?></td>
              <td><?= $r['account_name'] ? e($r['account_name'] . ' — ' . $r['bank_name']) : '—' ?></td>
              <td><?= e($r['borrower_name'] ?: '—') ?></td>
              <td><?= e($r['notes'] ?: '') ?></td>
            </tr>
            <?php if (!$viewOnly): ?>
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
            <?php endif; ?>
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