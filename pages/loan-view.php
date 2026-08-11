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
        'id' => $bid,
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

    $exportBorrowerId = (int) post('borrower_id', 0);
    $isBorrowerExport = false;
    $exportBorrower = null;
    if ($exportBorrowerId > 0) {
        foreach ($borrowerReportRows as $br) {
            if ((int) ($br['id'] ?? 0) === $exportBorrowerId) {
                $exportBorrower = $br;
                break;
            }
        }
        if (!$exportBorrower) {
            flash('error', 'Borrower not found on this loan.');
            redirect('pages/loan-view.php?id=' . $id . '&mode=' . $modeQs);
        }
        $isBorrowerExport = true;
        $borrowerReportRows = [$exportBorrower];
        $repayments = array_values(array_filter(
            $repayments,
            static fn($r) => (int) ($r['borrower_id'] ?? 0) === $exportBorrowerId
        ));
        $totalRepaid = (float) $exportBorrower['total_repaid'];
        $principalRepaid = (float) $exportBorrower['principal_repaid'];
        $interestRepaid = (float) $exportBorrower['interest_repaid'];
        $borrowersTotal = (float) ($exportBorrower['loan_amount'] ?? 0);
        $borrowersOutstandingTotal = (float) ($exportBorrower['outstanding'] ?? 0);
    }

    $reportTitle = $isBorrowerExport ? 'BORROWER LOAN REPORT' : 'BANK LOAN REPORT';
    $pdfDocTitle = $isBorrowerExport ? 'Borrower Loan Report' : 'Bank Loan Report';
    $safeBorrower = $isBorrowerExport
        ? trim(preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $exportBorrower['name']) ?: 'borrower', '_')
        : '';
    $fileBase = $isBorrowerExport
        ? 'loan_' . $safeLender . '_borrower_' . $safeBorrower . '_' . $exportBorrowerId
        : 'loan_' . $safeLender . '_' . $id;
    $summaryLoanAmount = $isBorrowerExport ? $exportBorrower['loan_amount'] : $loan['loan_amount'];
    $summaryOutstanding = $isBorrowerExport ? $exportBorrower['outstanding'] : $loan['outstanding_amount'];
    $summaryInterestCharges = $isBorrowerExport ? $exportBorrower['interest_charges'] : $loan['interest_charges'];
    $pdfSubtitle = $isBorrowerExport
        ? ($exportBorrower['name'] . ($exportBorrower['account_number'] !== '' ? ' · A/C ' . $exportBorrower['account_number'] : '') . ' · ' . $loan['lender_name'])
        : ($loan['lender_name'] . ' · ' . $loan['company_name'] . ($loan['project_name'] ? ' · ' . $loan['project_name'] : ''));

    if ($exportAction === 'csv') {
        $filename = $fileBase . '_' . $stamp . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        $csvDate = static function (?string $d): string {
            if (!$d) {
                return '';
            }
            $ts = strtotime($d);
            return $ts ? date('d-m-Y', $ts) : $d;
        };
        $amt = static function ($n): string {
            return number_format((float) $n, 2, '.', '');
        };
        $amtOrBlank = static function ($n) use ($amt): string {
            return $n === null || $n === '' ? '' : $amt($n);
        };

        // Cover / identity
        fputcsv($out, ['SAI KUBER DEVELOPERS']);
        fputcsv($out, [$reportTitle]);
        fputcsv($out, ['Report generated', date('d-m-Y h:i A')]);
        fputcsv($out, ['Generated by', current_user()['name'] ?? '']);
        fputcsv($out, ['Loan ID', (string) $id]);
        fputcsv($out, []);

        // Loan particulars
        fputcsv($out, ['LOAN PARTICULARS']);
        fputcsv($out, ['Field', 'Value']);
        fputcsv($out, ['Lender / Bank', $loan['lender_name']]);
        fputcsv($out, ['Company', $loan['company_name']]);
        fputcsv($out, ['Project', $loan['project_name'] ?? '']);
        fputcsv($out, ['Status', ucfirst((string) $loan['status'])]);
        if ($isBorrowerExport) {
            fputcsv($out, ['Borrower', $exportBorrower['name']]);
            fputcsv($out, ['Account no.', $exportBorrower['account_number']]);
            fputcsv($out, ['Start date', $csvDate($exportBorrower['start_date'] ?? null)]);
            fputcsv($out, ['End date', $csvDate($exportBorrower['end_date'] ?? null)]);
            fputcsv($out, ['Mortgage NOC', $csvDate($exportBorrower['mortgage_noc_date'] ?? null)]);
            fputcsv($out, ['Reconveyance', $csvDate($exportBorrower['reconveyance_date'] ?? null)]);
        } else {
            fputcsv($out, ['Start date', $csvDate($loan['start_date'] ?? null)]);
            fputcsv($out, ['End date', $csvDate($loan['end_date'] ?? null)]);
        }
        fputcsv($out, []);

        // Financial summary
        fputcsv($out, ['FINANCIAL SUMMARY (INR)']);
        fputcsv($out, ['Metric', 'Amount']);
        fputcsv($out, ['Total loan amount', $amtOrBlank($summaryLoanAmount)]);
        fputcsv($out, ['Total outstanding', $amtOrBlank($summaryOutstanding)]);
        fputcsv($out, ['Interest + charges', $amtOrBlank($summaryInterestCharges)]);
        fputcsv($out, ['Total repaid', $amt($totalRepaid)]);
        fputcsv($out, ['Principal repaid', $amt($principalRepaid)]);
        fputcsv($out, ['Interest repaid', $amt($interestRepaid)]);
        fputcsv($out, ['Repayment entries', (string) count($repayments)]);
        fputcsv($out, ['Borrowers count', (string) count($borrowerReportRows)]);
        fputcsv($out, []);

        // Borrowers
        fputcsv($out, ['BORROWERS / GUARANTORS']);
        fputcsv($out, [
            'Sr No',
            'Borrower Name',
            'Account No',
            'Loan Amount (INR)',
            'Outstanding (INR)',
            'Principal Repaid (INR)',
            'Interest Repaid (INR)',
            'Total Repaid (INR)',
            'Repayment Count',
            'Interest + Charges (INR)',
            'Start Date',
            'End Date',
            'Mortgage NOC',
            'Reconveyance',
        ]);
        foreach ($borrowerReportRows as $i => $br) {
            fputcsv($out, [
                (string) ($i + 1),
                $br['name'],
                $br['account_number'],
                $amtOrBlank($br['loan_amount']),
                $amtOrBlank($br['outstanding']),
                $amt($br['principal_repaid']),
                $amt($br['interest_repaid']),
                $amt($br['total_repaid']),
                (string) $br['repay_count'],
                $amtOrBlank($br['interest_charges']),
                $csvDate($br['start_date'] ?? null),
                $csvDate($br['end_date'] ?? null),
                $csvDate($br['mortgage_noc_date'] ?? null),
                $csvDate($br['reconveyance_date'] ?? null),
            ]);
        }
        if ($borrowerReportRows) {
            fputcsv($out, [
                '',
                'TOTAL',
                '',
                $amt($borrowersTotal),
                $amt($borrowersOutstandingTotal),
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
            ]);
        }
        fputcsv($out, []);

        // Repayments
        fputcsv($out, ['REPAYMENT HISTORY']);
        fputcsv($out, [
            'Sr No',
            'Payment Date',
            'Borrower',
            'Bank Account',
            'Amount (INR)',
            'Principal (INR)',
            'Interest (INR)',
            'Notes',
        ]);
        foreach ($repayments as $i => $r) {
            fputcsv($out, [
                (string) ($i + 1),
                $csvDate($r['payment_date'] ?? null),
                $r['borrower_name'] ?? '',
                $r['account_name'] ? trim($r['account_name'] . ' - ' . ($r['bank_name'] ?? '')) : '',
                $amt($r['amount']),
                $amt($r['principal_amount']),
                $amt($r['interest_amount']),
                $r['notes'] ?? '',
            ]);
        }
        fputcsv($out, [
            '',
            'TOTAL',
            '',
            '',
            $amt($totalRepaid),
            $amt($principalRepaid),
            $amt($interestRepaid),
            '',
        ]);
        fputcsv($out, []);
        fputcsv($out, ['Notes']);
        fputcsv($out, ['All amounts are in Indian Rupees (INR) with 2 decimal places.']);
        fputcsv($out, ['Figures match the loan screen at the time of export.']);
        fputcsv($out, ['Confidential — for internal use only.']);
        fclose($out);
        exit;
    }

    if ($exportAction === 'excel') {
        $filename = $fileBase . '_' . $stamp . '.xls';
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $xmlEsc = static fn($v): string => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xlsDate = static function (?string $d): string {
            if (!$d) {
                return '';
            }
            $ts = strtotime($d);
            return $ts ? date('d-m-Y', $ts) : $d;
        };
        $cell = static function ($value, string $type = 'String', string $style = '') use ($xmlEsc): string {
            $styleAttr = $style !== '' ? ' ss:StyleID="' . $style . '"' : '';
            if ($type === 'Number' && ($value === null || $value === '')) {
                return '<Cell' . $styleAttr . '/>';
            }
            if ($type === 'Number') {
                return '<Cell' . $styleAttr . '><Data ss:Type="Number">' . $xmlEsc(number_format((float) $value, 2, '.', '')) . '</Data></Cell>';
            }
            return '<Cell' . $styleAttr . '><Data ss:Type="String">' . $xmlEsc((string) $value) . '</Data></Cell>';
        };
        $row = static function (array $cells): string {
            return '<Row>' . implode('', $cells) . '</Row>';
        };

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
            . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
            . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";
        echo '<DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">'
            . '<Title>' . $xmlEsc($pdfDocTitle) . '</Title>'
            . '<Author>Sai Kuber Developers</Author>'
            . '<Created>' . date('c') . '</Created>'
            . '</DocumentProperties>' . "\n";
        echo '<Styles>'
            . '<Style ss:ID="Default" ss:Name="Normal"><Font ss:FontName="Calibri" ss:Size="11"/></Style>'
            . '<Style ss:ID="Brand"><Font ss:FontName="Calibri" ss:Size="16" ss:Bold="1" ss:Color="#0F766E"/></Style>'
            . '<Style ss:ID="DocTitle"><Font ss:FontName="Calibri" ss:Size="13" ss:Bold="1"/><Alignment ss:Horizontal="Left"/></Style>'
            . '<Style ss:ID="Section"><Font ss:FontName="Calibri" ss:Size="12" ss:Bold="1" ss:Color="#0F766E"/></Style>'
            . '<Style ss:ID="Label"><Font ss:Bold="1"/><Interior ss:Color="#F3FAF8" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="Header"><Font ss:Bold="1" ss:Color="#FFFFFF" ss:Size="10"/>'
            . '<Interior ss:Color="#0F766E" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/></Style>'
            . '<Style ss:ID="Money"><NumberFormat ss:Format="#,##0.00"/><Alignment ss:Horizontal="Right"/></Style>'
            . '<Style ss:ID="MoneyTotal"><Font ss:Bold="1"/><NumberFormat ss:Format="#,##0.00"/>'
            . '<Interior ss:Color="#EEF8F5" ss:Pattern="Solid"/><Alignment ss:Horizontal="Right"/></Style>'
            . '<Style ss:ID="TotalLabel"><Font ss:Bold="1"/><Interior ss:Color="#EEF8F5" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="Muted"><Font ss:Color="#5B6F6B" ss:Size="10"/></Style>'
            . '<Style ss:ID="Text"><Alignment ss:Vertical="Center" ss:WrapText="1"/></Style>'
            . '</Styles>' . "\n";

        // ---- Sheet 1: Summary ----
        echo '<Worksheet ss:Name="Summary"><Table>'
            . '<Column ss:Width="28"/>'
            . '<Column ss:Width="160"/>'
            . '<Column ss:Width="140"/>'
            . '<Column ss:Width="120"/>' . "\n";
        echo $row([$cell('SAI KUBER DEVELOPERS', 'String', 'Brand')]);
        echo $row([$cell($reportTitle, 'String', 'DocTitle')]);
        echo $row([$cell('Report generated', 'String', 'Label'), $cell(date('d-m-Y h:i A'))]);
        echo $row([$cell('Generated by', 'String', 'Label'), $cell(current_user()['name'] ?? '')]);
        echo $row([$cell('Loan ID', 'String', 'Label'), $cell((string) $id)]);
        echo $row([$cell('')]);
        echo $row([$cell('LOAN PARTICULARS', 'String', 'Section')]);
        echo $row([$cell('Field', 'String', 'Header'), $cell('Value', 'String', 'Header')]);
        echo $row([$cell('Lender / Bank', 'String', 'Label'), $cell($loan['lender_name'])]);
        echo $row([$cell('Company', 'String', 'Label'), $cell($loan['company_name'])]);
        echo $row([$cell('Project', 'String', 'Label'), $cell($loan['project_name'] ?? '')]);
        echo $row([$cell('Status', 'String', 'Label'), $cell(ucfirst((string) $loan['status']))]);
        if ($isBorrowerExport) {
            echo $row([$cell('Borrower', 'String', 'Label'), $cell($exportBorrower['name'])]);
            echo $row([$cell('Account no.', 'String', 'Label'), $cell($exportBorrower['account_number'])]);
            echo $row([$cell('Start date', 'String', 'Label'), $cell($xlsDate($exportBorrower['start_date'] ?? null))]);
            echo $row([$cell('End date', 'String', 'Label'), $cell($xlsDate($exportBorrower['end_date'] ?? null))]);
            echo $row([$cell('Mortgage NOC', 'String', 'Label'), $cell($xlsDate($exportBorrower['mortgage_noc_date'] ?? null))]);
            echo $row([$cell('Reconveyance', 'String', 'Label'), $cell($xlsDate($exportBorrower['reconveyance_date'] ?? null))]);
        } else {
            echo $row([$cell('Start date', 'String', 'Label'), $cell($xlsDate($loan['start_date'] ?? null))]);
            echo $row([$cell('End date', 'String', 'Label'), $cell($xlsDate($loan['end_date'] ?? null))]);
        }
        echo $row([$cell('')]);
        echo $row([$cell('FINANCIAL SUMMARY (INR)', 'String', 'Section')]);
        echo $row([$cell('Metric', 'String', 'Header'), $cell('Amount (INR)', 'String', 'Header')]);
        echo $row([$cell('Total loan amount', 'String', 'Label'), $summaryLoanAmount !== null ? $cell($summaryLoanAmount, 'Number', 'Money') : $cell('')]);
        echo $row([$cell('Total outstanding', 'String', 'Label'), $summaryOutstanding !== null ? $cell($summaryOutstanding, 'Number', 'Money') : $cell('')]);
        echo $row([$cell('Interest + charges', 'String', 'Label'), $summaryInterestCharges !== null ? $cell($summaryInterestCharges, 'Number', 'Money') : $cell('')]);
        echo $row([$cell('Total repaid', 'String', 'Label'), $cell($totalRepaid, 'Number', 'Money')]);
        echo $row([$cell('Principal repaid', 'String', 'Label'), $cell($principalRepaid, 'Number', 'Money')]);
        echo $row([$cell('Interest repaid', 'String', 'Label'), $cell($interestRepaid, 'Number', 'Money')]);
        echo $row([$cell('Repayment entries', 'String', 'Label'), $cell((string) count($repayments))]);
        echo $row([$cell('Borrowers count', 'String', 'Label'), $cell((string) count($borrowerReportRows))]);
        echo $row([$cell('')]);
        echo $row([$cell('Confidential — for internal use only. Figures match the loan screen at export time.', 'String', 'Muted')]);
        echo '</Table></Worksheet>' . "\n";

        // ---- Sheet 2: Borrowers ----
        echo '<Worksheet ss:Name="Borrowers"><Table>'
            . '<Column ss:Width="35"/>'
            . '<Column ss:Width="180"/>'
            . '<Column ss:Width="70"/>'
            . '<Column ss:Width="100"/>'
            . '<Column ss:Width="100"/>'
            . '<Column ss:Width="110"/>'
            . '<Column ss:Width="110"/>'
            . '<Column ss:Width="100"/>'
            . '<Column ss:Width="70"/>'
            . '<Column ss:Width="110"/>'
            . '<Column ss:Width="80"/>'
            . '<Column ss:Width="80"/>'
            . '<Column ss:Width="90"/>'
            . '<Column ss:Width="90"/>' . "\n";
        echo $row([$cell('BORROWERS / GUARANTORS', 'String', 'Section')]);
        echo $row([$cell('Lender', 'String', 'Label'), $cell($loan['lender_name'])]);
        echo $row([$cell('')]);
        echo $row([
            $cell('Sr No', 'String', 'Header'),
            $cell('Borrower Name', 'String', 'Header'),
            $cell('Account No', 'String', 'Header'),
            $cell('Loan Amount', 'String', 'Header'),
            $cell('Outstanding', 'String', 'Header'),
            $cell('Principal Repaid', 'String', 'Header'),
            $cell('Interest Repaid', 'String', 'Header'),
            $cell('Total Repaid', 'String', 'Header'),
            $cell('Count', 'String', 'Header'),
            $cell('Interest + Charges', 'String', 'Header'),
            $cell('Start Date', 'String', 'Header'),
            $cell('End Date', 'String', 'Header'),
            $cell('Mortgage NOC', 'String', 'Header'),
            $cell('Reconveyance', 'String', 'Header'),
        ]);
        foreach ($borrowerReportRows as $i => $br) {
            echo $row([
                $cell((string) ($i + 1)),
                $cell($br['name'], 'String', 'Text'),
                $cell($br['account_number']),
                $br['loan_amount'] !== null ? $cell($br['loan_amount'], 'Number', 'Money') : $cell(''),
                $br['outstanding'] !== null ? $cell($br['outstanding'], 'Number', 'Money') : $cell(''),
                $cell($br['principal_repaid'], 'Number', 'Money'),
                $cell($br['interest_repaid'], 'Number', 'Money'),
                $cell($br['total_repaid'], 'Number', 'Money'),
                $cell((string) $br['repay_count']),
                $br['interest_charges'] !== null ? $cell($br['interest_charges'], 'Number', 'Money') : $cell(''),
                $cell($xlsDate($br['start_date'] ?? null)),
                $cell($xlsDate($br['end_date'] ?? null)),
                $cell($xlsDate($br['mortgage_noc_date'] ?? null)),
                $cell($xlsDate($br['reconveyance_date'] ?? null)),
            ]);
        }
        if ($borrowerReportRows) {
            echo $row([
                $cell(''),
                $cell('TOTAL', 'String', 'TotalLabel'),
                $cell('', 'String', 'TotalLabel'),
                $cell($borrowersTotal, 'Number', 'MoneyTotal'),
                $cell($borrowersOutstandingTotal, 'Number', 'MoneyTotal'),
                $cell('', 'String', 'TotalLabel'),
                $cell('', 'String', 'TotalLabel'),
                $cell('', 'String', 'TotalLabel'),
                $cell('', 'String', 'TotalLabel'),
                $cell('', 'String', 'TotalLabel'),
                $cell('', 'String', 'TotalLabel'),
                $cell('', 'String', 'TotalLabel'),
                $cell('', 'String', 'TotalLabel'),
                $cell('', 'String', 'TotalLabel'),
            ]);
        }
        echo '</Table>'
            . '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><FreezePanes/><FrozenNoSplit/>'
            . '<SplitHorizontal>4</SplitHorizontal><TopRowBottomPane>4</TopRowBottomPane>'
            . '<ActivePane>2</ActivePane></WorksheetOptions>'
            . '</Worksheet>' . "\n";

        // ---- Sheet 3: Repayments ----
        echo '<Worksheet ss:Name="Repayments"><Table>'
            . '<Column ss:Width="35"/>'
            . '<Column ss:Width="90"/>'
            . '<Column ss:Width="160"/>'
            . '<Column ss:Width="170"/>'
            . '<Column ss:Width="100"/>'
            . '<Column ss:Width="100"/>'
            . '<Column ss:Width="100"/>'
            . '<Column ss:Width="180"/>' . "\n";
        echo $row([$cell('REPAYMENT HISTORY', 'String', 'Section')]);
        echo $row([$cell('Lender', 'String', 'Label'), $cell($loan['lender_name'])]);
        echo $row([$cell('')]);
        echo $row([
            $cell('Sr No', 'String', 'Header'),
            $cell('Payment Date', 'String', 'Header'),
            $cell('Borrower', 'String', 'Header'),
            $cell('Bank Account', 'String', 'Header'),
            $cell('Amount', 'String', 'Header'),
            $cell('Principal', 'String', 'Header'),
            $cell('Interest', 'String', 'Header'),
            $cell('Notes', 'String', 'Header'),
        ]);
        foreach ($repayments as $i => $r) {
            echo $row([
                $cell((string) ($i + 1)),
                $cell($xlsDate($r['payment_date'] ?? null)),
                $cell($r['borrower_name'] ?? '', 'String', 'Text'),
                $cell($r['account_name'] ? trim($r['account_name'] . ' - ' . ($r['bank_name'] ?? '')) : '', 'String', 'Text'),
                $cell($r['amount'], 'Number', 'Money'),
                $cell($r['principal_amount'], 'Number', 'Money'),
                $cell($r['interest_amount'], 'Number', 'Money'),
                $cell($r['notes'] ?? '', 'String', 'Text'),
            ]);
        }
        echo $row([
            $cell(''),
            $cell('TOTAL', 'String', 'TotalLabel'),
            $cell('', 'String', 'TotalLabel'),
            $cell('', 'String', 'TotalLabel'),
            $cell($totalRepaid, 'Number', 'MoneyTotal'),
            $cell($principalRepaid, 'Number', 'MoneyTotal'),
            $cell($interestRepaid, 'Number', 'MoneyTotal'),
            $cell('', 'String', 'TotalLabel'),
        ]);
        echo '</Table>'
            . '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><FreezePanes/><FrozenNoSplit/>'
            . '<SplitHorizontal>4</SplitHorizontal><TopRowBottomPane>4</TopRowBottomPane>'
            . '<ActivePane>2</ActivePane></WorksheetOptions>'
            . '</Worksheet>' . "\n";

        echo '</Workbook>';
        exit;
    }

    // PDF: real Dompdf download (not browser print)
    $generatedAt = date('d-m-Y, h:i A');
    $generatedBy = current_user()['name'] ?? '';
    $interestChargesLabel = $summaryInterestCharges !== null ? money($summaryInterestCharges) : '—';

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
        <div class="doc-title"><?= pdf_e($pdfDocTitle) ?></div>
        <div class="meta"><?= pdf_e($pdfSubtitle) ?></div>
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
        <div class="value"><?= pdf_e($summaryLoanAmount !== null ? money($summaryLoanAmount) : '—') ?></div>
      </td>
      <td>
        <div class="label">Outstanding</div>
        <div class="value"><?= pdf_e($summaryOutstanding !== null ? money($summaryOutstanding) : '—') ?></div>
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
  <h3><?= $isBorrowerExport ? 'Borrower particulars' : 'Borrowers / guarantors (' . count($borrowerReportRows) . ')' ?></h3>
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
    <p>System-generated <?= $isBorrowerExport ? 'borrower' : 'loan' ?> report. Figures match the loan screen at export time (loan #<?= (int) $id ?><?= $isBorrowerExport ? ', borrower #' . (int) $exportBorrowerId : '' ?>).</p>
    <p>Interest + charges: <?= pdf_e($interestChargesLabel) ?>.</p>
    <p>Confidential — internal use only.</p>
  </div>
</body>
</html>
    <?php
    $html = ob_get_clean();
    $pdfName = $fileBase . '_' . $stamp . '.pdf';
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
    <p class="muted" style="margin:0;font-size:0.8rem;width:100%">Top PDF / Excel / CSV = whole loan. Buttons on a card = that person only.</p>
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
        <form method="post" style="display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap;margin-top:0.9rem;padding-top:0.85rem;border-top:1px solid var(--border)">
          <?= csrf_field() ?>
          <input type="hidden" name="borrower_id" value="<?= $bid ?>">
          <span class="muted" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;margin-right:0.15rem">This person</span>
          <button class="btn btn-primary btn-sm" type="submit" name="export_action" value="pdf">PDF</button>
          <button class="btn btn-outline btn-sm" type="submit" name="export_action" value="excel">Excel</button>
          <button class="btn btn-outline btn-sm" type="submit" name="export_action" value="csv">CSV</button>
        </form>
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