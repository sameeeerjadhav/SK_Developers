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
        $projectId = post('project_id') !== '' ? (int) post('project_id') : null;
        $lender = post('lender_name', '');
        $loanAmount = (float) post('loan_amount', 0);
        $outstanding = (float) post('outstanding_amount', 0);
        $interestCharges = post('interest_charges') !== '' ? (float) post('interest_charges') : null;
        $status = post('status', 'active');
        $notes = post('notes', '');
        $bankAccountId = post('bank_account_id') !== '' ? (int) post('bank_account_id') : null;
        $postToLedger = !empty($_POST['post_to_ledger']);
        $editId = (int) post('id', 0);
        $borrowerNames = $_POST['borrower_name'] ?? [];
        $borrowersData = [];
        foreach ($borrowerNames as $i => $bn) {
            $borrowersData[] = [
                'id' => (int) ($_POST['borrower_id'][$i] ?? 0),
                'name' => $bn,
                'account_number' => $_POST['borrower_account_number'][$i] ?? '',
                'loan_amount' => $_POST['borrower_loan_amount'][$i] ?? '',
                'outstanding_amount' => $_POST['borrower_outstanding_amount'][$i] ?? '',
                'interest_charges' => $_POST['borrower_interest_charges'][$i] ?? '',
                'start_date' => $_POST['borrower_start_date'][$i] ?? '',
                'end_date' => $_POST['borrower_end_date'][$i] ?? '',
                'mortgage_noc_date' => $_POST['borrower_mortgage_noc_date'][$i] ?? '',
                'reconveyance_date' => $_POST['borrower_reconveyance_date'][$i] ?? '',
            ];
        }
        if (!$companyId || $lender === '') {
            flash('error', 'Company and lender name are required.');
            redirect('pages/bank-loans.php?action=add');
        }
        if ($editId) {
            $stmt = $pdo->prepare('UPDATE bank_loans SET company_id=?, project_id=?, lender_name=?, loan_amount=?, outstanding_amount=?, interest_charges=?, status=?, notes=? WHERE id=?');
            $stmt->execute([$companyId, $projectId, $lender, $loanAmount, $outstanding, $interestCharges, $status, $notes, $editId]);
            sync_loan_borrowers($pdo, $editId, $borrowersData);
            flash('success', 'Bank loan updated.');
            redirect('pages/loan-view.php?id=' . $editId . '&mode=view');
        } else {
            $stmt = $pdo->prepare('INSERT INTO bank_loans (company_id, project_id, lender_name, loan_amount, outstanding_amount, interest_charges, status, notes) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([$companyId, $projectId, $lender, $loanAmount, $outstanding ?: $loanAmount, $interestCharges, $status, $notes]);
            $newLoanId = (int) $pdo->lastInsertId();
            sync_loan_borrowers($pdo, $newLoanId, $borrowersData);

            if ($postToLedger && $loanAmount > 0) {
                $catId = category_id_by_slug($pdo, 'credit', 'bank_loan');
                if ($catId) {
                    create_transaction(
                        $pdo,
                        $companyId,
                        $catId,
                        'credit',
                        $loanAmount,
                        date('Y-m-d'),
                        $projectId,
                        $bankAccountId,
                        null,
                        null,
                        'Bank loan from ' . $lender,
                        current_user()['id'] ?? null
                    );
                }
            }
            flash('success', 'Bank loan added' . ($postToLedger ? ' and posted to ledger.' : '.'));
            redirect('pages/loan-view.php?id=' . $newLoanId . '&mode=view');
        }
        redirect('pages/bank-loans.php');
    }
    if ($postAction === 'delete') {
        if (!can_delete()) {
            flash('error', 'Only admins can delete loans.');
            redirect('pages/bank-loans.php');
        }
        $loanId = (int) post('id', 0);
        $loanStmt = $pdo->prepare('SELECT * FROM bank_loans WHERE id = ?');
        $loanStmt->execute([$loanId]);
        $loanRow = $loanStmt->fetch();
        if (!$loanRow) {
            flash('error', 'Loan not found.');
            redirect('pages/bank-loans.php');
        }
        try {
            $pdo->beginTransaction();
            $txnIds = [];
            $rTxn = $pdo->prepare('SELECT transaction_id FROM loan_repayments WHERE loan_id = ? AND transaction_id IS NOT NULL');
            $rTxn->execute([$loanId]);
            foreach ($rTxn->fetchAll(PDO::FETCH_COLUMN) as $tid) {
                $txnIds[] = (int) $tid;
            }
            try {
                $eTxn = $pdo->prepare('SELECT transaction_id FROM loan_emis WHERE loan_id = ? AND transaction_id IS NOT NULL');
                $eTxn->execute([$loanId]);
                foreach ($eTxn->fetchAll(PDO::FETCH_COLUMN) as $tid) {
                    $txnIds[] = (int) $tid;
                }
            } catch (Throwable $e) {
            }
            $catId = category_id_by_slug($pdo, 'credit', 'bank_loan');
            if ($catId) {
                $openSql = 'SELECT id FROM transactions WHERE category_id = ? AND company_id = ? AND amount = ? AND description = ?';
                $openParams = [$catId, $loanRow['company_id'], $loanRow['loan_amount'], 'Bank loan from ' . $loanRow['lender_name']];
                if (!empty($loanRow['project_id'])) {
                    $openSql .= ' AND project_id = ?';
                    $openParams[] = $loanRow['project_id'];
                } else {
                    $openSql .= ' AND project_id IS NULL';
                }
                $openStmt = $pdo->prepare($openSql);
                $openStmt->execute($openParams);
                foreach ($openStmt->fetchAll(PDO::FETCH_COLUMN) as $tid) {
                    $txnIds[] = (int) $tid;
                }
            }
            $txnIds = array_values(array_unique(array_filter($txnIds)));
            $pdo->prepare('DELETE FROM loan_repayments WHERE loan_id = ?')->execute([$loanId]);
            try {
                $pdo->prepare('DELETE FROM loan_emis WHERE loan_id = ?')->execute([$loanId]);
            } catch (Throwable $e) {
            }
            $pdo->prepare('DELETE FROM loan_borrowers WHERE loan_id = ?')->execute([$loanId]);
            $pdo->prepare('DELETE FROM bank_loans WHERE id = ?')->execute([$loanId]);
            if ($txnIds) {
                $in = implode(',', array_fill(0, count($txnIds), '?'));
                try {
                    $atts = $pdo->prepare("SELECT stored_name FROM attachments WHERE transaction_id IN ($in)");
                    $atts->execute($txnIds);
                    foreach ($atts->fetchAll() as $att) {
                        $path = uploads_dir() . '/' . $att['stored_name'];
                        if (is_file($path)) {
                            @unlink($path);
                        }
                    }
                    $pdo->prepare("DELETE FROM attachments WHERE transaction_id IN ($in)")->execute($txnIds);
                } catch (Throwable $e) {
                }
                $pdo->prepare("DELETE FROM transactions WHERE id IN ($in)")->execute($txnIds);
            }
            $pdo->commit();
            audit_log($pdo, 'delete', 'bank_loan', $loanId, 'Deleted loan ' . $loanRow['lender_name']);
            flash('success', 'Bank loan deleted.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', 'Could not delete loan.');
        }
        redirect('pages/bank-loans.php');
    }
}

if ($action === 'add' || $action === 'edit') {
    $row = null;
    $borrowers = [];
    if ($action === 'edit' && $id) {
        $stmt = $pdo->prepare('SELECT * FROM bank_loans WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $bStmt = $pdo->prepare('SELECT * FROM loan_borrowers WHERE loan_id = ? ORDER BY id');
        $bStmt->execute([$id]);
        $borrowers = $bStmt->fetchAll();
    }
    $blankBorrower = ['name' => '', 'account_number' => '', 'loan_amount' => '', 'outstanding_amount' => '', 'interest_charges' => '', 'start_date' => '', 'end_date' => '', 'mortgage_noc_date' => '', 'reconveyance_date' => ''];
    if (!$borrowers) {
        $borrowers = [$blankBorrower];
    }
    $knownBorrowerNames = $pdo->query('SELECT DISTINCT name FROM loan_borrowers ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    $pageTitle = $action === 'edit' ? 'Edit bank loan' : 'Add bank loan';
    $pageActions = ($row ? '<a class="btn btn-primary" href="' . e(base_url('pages/loan-view.php?id=' . $row['id'] . '&mode=repay')) . '">Record repayment</a>' : '')
        . '<a class="btn btn-outline" href="' . e(base_url('pages/bank-loans.php')) . '">Back</a>';
    $preCompany = (int) ($row['company_id'] ?? 0);
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="card">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>">
        <div>
          <label>Company</label>
          <select name="company_id" required
            data-company-projects="project_id"
            data-company-accounts="bank_account_id"
            data-accounts-empty-label="Cash"
            data-projects-url="<?= e(base_url('api/projects.php')) ?>"
            data-accounts-url="<?= e(base_url('api/bank-accounts.php')) ?>">
            <?= company_options($pdo, $preCompany) ?>
          </select>
        </div>
        <div>
          <label>Project (optional)</label>
          <select name="project_id" id="project_id"><?= project_options($pdo, $preCompany ?: null, (int)($row['project_id'] ?? 0)) ?></select>
        </div>
        <div class="full">
          <label>Lender / bank name</label>
          <input type="text" name="lender_name" required value="<?= e($row['lender_name'] ?? '') ?>">
        </div>
        <div class="full">
          <label>People on this loan (borrowers / guarantors) — each with their own details</label>
          <div data-repeat-container="borrowers">
            <?php foreach ($borrowers as $b): ?>
            <div class="repeat-row borrower-block" style="border:1px solid var(--border-strong);border-radius:14px;padding:0.8rem;margin-bottom:0.7rem">
              <input type="hidden" name="borrower_id[]" value="<?= (int) ($b['id'] ?? 0) ?>">
              <div style="display:flex;justify-content:flex-end;margin-bottom:0.4rem">
                <button type="button" class="btn btn-outline btn-sm" data-repeat-remove>&times; Remove person</button>
              </div>
              <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.6rem">
                <div>
                  <label>Name</label>
                  <input type="text" name="borrower_name[]" placeholder="Name" list="knownBorrowerNames" value="<?= e($b['name'] ?? '') ?>">
                </div>
                <div>
                  <label>Account number</label>
                  <input type="text" name="borrower_account_number[]" value="<?= e($b['account_number'] ?? '') ?>">
                </div>
                <div>
                  <label>Loan amount (₹)</label>
                  <input type="number" step="0.01" name="borrower_loan_amount[]" value="<?= e((string) ($b['loan_amount'] ?? '')) ?>">
                </div>
                <div>
                  <label>Outstanding (₹)</label>
                  <input type="number" step="0.01" name="borrower_outstanding_amount[]" value="<?= e((string) ($b['outstanding_amount'] ?? '')) ?>">
                </div>
                <div>
                  <label>Interest + charges (₹)</label>
                  <input type="number" step="0.01" name="borrower_interest_charges[]" value="<?= e((string) ($b['interest_charges'] ?? '')) ?>">
                </div>
                <div>
                  <label>Start date</label>
                  <input type="date" name="borrower_start_date[]" value="<?= e($b['start_date'] ?? '') ?>">
                </div>
                <div>
                  <label>End date</label>
                  <input type="date" name="borrower_end_date[]" value="<?= e($b['end_date'] ?? '') ?>">
                </div>
                <div>
                  <label>Mortgage NOC (optional)</label>
                  <input type="date" name="borrower_mortgage_noc_date[]" value="<?= e($b['mortgage_noc_date'] ?? '') ?>">
                </div>
                <div>
                  <label>Reconveyance date</label>
                  <input type="date" name="borrower_reconveyance_date[]" value="<?= e($b['reconveyance_date'] ?? '') ?>">
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <button type="button" class="btn btn-outline btn-sm" data-repeat-add="borrowers" data-repeat-template="borrowerRowTemplate">+ Add person</button>
          <datalist id="knownBorrowerNames">
            <?php foreach ($knownBorrowerNames as $bn): ?>
              <option value="<?= e($bn) ?>">
            <?php endforeach; ?>
          </datalist>
          <template id="borrowerRowTemplate">
            <div class="repeat-row borrower-block" style="border:1px solid var(--border-strong);border-radius:14px;padding:0.8rem;margin-bottom:0.7rem">
              <input type="hidden" name="borrower_id[]" value="0">
              <div style="display:flex;justify-content:flex-end;margin-bottom:0.4rem">
                <button type="button" class="btn btn-outline btn-sm" data-repeat-remove>&times; Remove person</button>
              </div>
              <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.6rem">
                <div>
                  <label>Name</label>
                  <input type="text" name="borrower_name[]" placeholder="Name" list="knownBorrowerNames">
                </div>
                <div>
                  <label>Account number</label>
                  <input type="text" name="borrower_account_number[]">
                </div>
                <div>
                  <label>Loan amount (₹)</label>
                  <input type="number" step="0.01" name="borrower_loan_amount[]">
                </div>
                <div>
                  <label>Outstanding (₹)</label>
                  <input type="number" step="0.01" name="borrower_outstanding_amount[]">
                </div>
                <div>
                  <label>Interest + charges (₹)</label>
                  <input type="number" step="0.01" name="borrower_interest_charges[]">
                </div>
                <div>
                  <label>Start date</label>
                  <input type="date" name="borrower_start_date[]">
                </div>
                <div>
                  <label>End date</label>
                  <input type="date" name="borrower_end_date[]">
                </div>
                <div>
                  <label>Mortgage NOC (optional)</label>
                  <input type="date" name="borrower_mortgage_noc_date[]">
                </div>
                <div>
                  <label>Reconveyance date</label>
                  <input type="date" name="borrower_reconveyance_date[]">
                </div>
              </div>
            </div>
          </template>
        </div>
        <div>
          <label>Total loan amount (₹)</label>
          <input type="number" step="0.01" name="loan_amount" value="<?= e((string)($row['loan_amount'] ?? '0')) ?>">
        </div>
        <div>
          <label>Total outstanding (₹)</label>
          <input type="number" step="0.01" name="outstanding_amount" value="<?= e((string)($row['outstanding_amount'] ?? '0')) ?>">
        </div>
        <div>
          <label>Interest + other charges (₹)</label>
          <input type="number" step="0.01" name="interest_charges" value="<?= e((string)($row['interest_charges'] ?? '')) ?>">
        </div>
        <div>
          <label>Status</label>
          <select name="status">
            <option value="active" <?= (($row['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
            <option value="closed" <?= (($row['status'] ?? '') === 'closed') ? 'selected' : '' ?>>Closed</option>
          </select>
        </div>
        <div class="full">
          <label>Notes</label>
          <textarea name="notes"><?= e($row['notes'] ?? '') ?></textarea>
        </div>
        <?php if (!$row): ?>
        <div>
          <label>Credit to bank account (optional)</label>
          <select name="bank_account_id" id="bank_account_id">
            <?= bank_account_options($pdo, $preCompany ?: null, null, 'Cash') ?>
          </select>
        </div>
        <div class="full highlight-box">
          <label style="display:flex;gap:0.5rem;align-items:flex-start;margin:0;font-weight:600;color:var(--text)">
            <input type="checkbox" name="post_to_ledger" value="1" checked style="width:auto;margin-top:0.2rem">
            <span>Also post loan amount as a <strong>Credit → Bank Loan</strong> transaction (updates project board &amp; bank balance).</span>
          </label>
        </div>
        <?php endif; ?>
        <div class="full form-actions"><button class="btn btn-primary" type="submit">Save loan</button></div>
      </form>
    </div>
    <?php require __DIR__ . '/../includes/footer.php'; exit;
}

$pageTitle = 'Bank Loans';
$pageSub = 'Loan register with outstanding balances.';
$pageActions = report_export_buttons()
    . '<a class="btn btn-primary" href="' . e(base_url('pages/bank-loans.php?action=add')) . '">+ Add loan</a>';
$loans = $pdo->query(
    'SELECT l.*, c.name AS company_name, p.name AS project_name
     FROM bank_loans l
     JOIN companies c ON c.id = l.company_id
     LEFT JOIN projects p ON p.id = l.project_id
     ORDER BY l.status, l.created_at DESC'
)->fetchAll();
$outstanding = array_sum(array_map(fn($l) => $l['status'] === 'active' ? (float)$l['outstanding_amount'] : 0, $loans));

$borrowersByLoan = [];
$loanIds = array_column($loans, 'id');
if ($loanIds) {
    $in = implode(',', array_fill(0, count($loanIds), '?'));
    $bStmt = $pdo->prepare("SELECT loan_id, name, loan_amount FROM loan_borrowers WHERE loan_id IN ($in) ORDER BY id");
    $bStmt->execute($loanIds);
    foreach ($bStmt->fetchAll() as $b) {
        $borrowersByLoan[(int) $b['loan_id']][] = $b['name'] . ($b['loan_amount'] !== null ? ' (' . money($b['loan_amount']) . ')' : '');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('export_action', ''), ['csv', 'excel', 'pdf'], true)) {
    verify_csrf();
    $loanTotal = 0.0;
    $activeOutstanding = 0.0;
    $closedCount = 0;
    $loanRows = [];
    foreach ($loans as $i => $l) {
        $loanTotal += (float) $l['loan_amount'];
        if (($l['status'] ?? '') === 'active') {
            $activeOutstanding += (float) $l['outstanding_amount'];
        } else {
            $closedCount++;
        }
        $loanRows[] = [
            (string) ($i + 1),
            $l['lender_name'] ?? '',
            implode(', ', $borrowersByLoan[(int) $l['id']] ?? []),
            $l['company_name'] ?? '',
            $l['project_name'] ?? '',
            ucfirst((string) ($l['status'] ?? '')),
            (float) $l['loan_amount'],
            (float) $l['outstanding_amount'],
            $l['interest_charges'] !== null ? (float) $l['interest_charges'] : null,
        ];
    }

    $borrowerRows = [];
    if ($loanIds) {
        $in = implode(',', array_fill(0, count($loanIds), '?'));
        $fullB = $pdo->prepare(
            "SELECT b.*, l.lender_name
             FROM loan_borrowers b
             JOIN bank_loans l ON l.id = b.loan_id
             WHERE b.loan_id IN ($in)
             ORDER BY l.lender_name, b.id"
        );
        $fullB->execute($loanIds);
        foreach ($fullB->fetchAll() as $i => $b) {
            $borrowerRows[] = [
                (string) ($i + 1),
                $b['lender_name'] ?? '',
                $b['name'] ?? '',
                $b['account_number'] ?? '',
                $b['loan_amount'] !== null ? (float) $b['loan_amount'] : null,
                $b['outstanding_amount'] !== null ? (float) $b['outstanding_amount'] : null,
                $b['interest_charges'] !== null ? (float) $b['interest_charges'] : null,
                report_plain_date($b['start_date'] ?? null),
                report_plain_date($b['end_date'] ?? null),
            ];
        }
    }

    $allOutstanding = array_sum(array_map(fn($l) => (float) $l['outstanding_amount'], $loans));
    $tables = [
        [
            'title' => 'Loan register',
            'columns' => [
                ['label' => 'Sr No', 'type' => 'text', 'width' => '5%', 'xls_width' => 35],
                ['label' => 'Lender / Bank', 'type' => 'text', 'width' => '16%', 'xls_width' => 160],
                ['label' => 'Borrowers', 'type' => 'text', 'width' => '18%', 'xls_width' => 180],
                ['label' => 'Company', 'type' => 'text', 'width' => '12%', 'xls_width' => 130],
                ['label' => 'Project', 'type' => 'text', 'width' => '12%', 'xls_width' => 120],
                ['label' => 'Status', 'type' => 'text', 'width' => '8%', 'xls_width' => 70],
                ['label' => 'Loan amount (INR)', 'type' => 'money', 'width' => '10%', 'xls_width' => 110],
                ['label' => 'Outstanding (INR)', 'type' => 'money', 'width' => '10%', 'xls_width' => 110],
                ['label' => 'Interest + charges (INR)', 'type' => 'money', 'width' => '9%', 'xls_width' => 110],
            ],
            'rows' => $loanRows,
            'totals' => ['', 'TOTAL', '', '', '', '', $loanTotal, $allOutstanding, ''],
        ],
    ];
    if ($borrowerRows) {
        $tables[] = [
            'title' => 'Borrowers by loan',
            'columns' => [
                ['label' => 'Sr No', 'type' => 'text', 'width' => '6%', 'xls_width' => 35],
                ['label' => 'Lender', 'type' => 'text', 'width' => '16%', 'xls_width' => 150],
                ['label' => 'Borrower', 'type' => 'text', 'width' => '16%', 'xls_width' => 150],
                ['label' => 'A/C', 'type' => 'text', 'width' => '10%', 'xls_width' => 70],
                ['label' => 'Loan amount (INR)', 'type' => 'money', 'width' => '12%', 'xls_width' => 110],
                ['label' => 'Outstanding (INR)', 'type' => 'money', 'width' => '12%', 'xls_width' => 110],
                ['label' => 'Interest + charges (INR)', 'type' => 'money', 'width' => '12%', 'xls_width' => 110],
                ['label' => 'Start date', 'type' => 'text', 'width' => '8%', 'xls_width' => 80],
                ['label' => 'End date', 'type' => 'text', 'width' => '8%', 'xls_width' => 80],
            ],
            'rows' => $borrowerRows,
        ];
    }

    report_download(post('export_action'), [
        'filename' => 'bank_loans_register',
        'title' => 'Bank Loan Register',
        'orientation' => 'landscape',
        'meta' => [
            ['Loans', (string) count($loans)],
            ['Closed / inactive', (string) $closedCount],
        ],
        'summary' => [
            ['Total loan amount', $loanTotal, 'money'],
            ['Total outstanding', $allOutstanding, 'money'],
            ['Active outstanding', $activeOutstanding, 'money'],
            ['Loans', count($loans), 'int'],
        ],
        'tables' => $tables,
        'notes' => [
            'System-generated loan register. Borrower outstanding is listed separately where recorded.',
            'Open an individual loan to export its full repayment history.',
            'Confidential — internal use only.',
        ],
    ]);
    redirect('pages/bank-loans.php');
}

$list = paginate_list($loans);

require __DIR__ . '/../includes/header.php';
?>
<div class="stat-grid" style="grid-template-columns:repeat(2,1fr)">
  <div class="stat-card"><div class="stat-label">Active outstanding</div><div class="stat-value"><?= money($outstanding) ?></div></div>
  <div class="stat-card"><div class="stat-label">Loans</div><div class="stat-value"><?= count($loans) ?></div></div>
</div>
<div class="card" id="list">
  <div class="card-head">
    <h2 class="card-title">Loans</h2>
    <?php render_limit_control('bank-loans.php'); ?>
  </div>
  <?php if (!$list['total']): ?>
    <div class="empty"><strong>No bank loans</strong><p>Register loans against companies or projects.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Lender</th><th>Borrowers</th><th>Company</th><th>Project</th><th class="num">Loan</th><th class="num">Outstanding</th><th class="num">Interest + Charges</th><th>Status</th><th class="actions">Actions</th></tr></thead>
        <tbody>
          <?php foreach ($list['rows'] as $l): ?>
            <tr>
              <td><strong><?= e($l['lender_name']) ?></strong></td>
              <td><?= e(implode(', ', $borrowersByLoan[(int) $l['id']] ?? []) ?: '—') ?></td>
              <td><?= e($l['company_name']) ?></td>
              <td><?= e($l['project_name'] ?? '—') ?></td>
              <td class="num"><?= money($l['loan_amount']) ?></td>
              <td class="num"><?= money($l['outstanding_amount']) ?></td>
              <td class="num"><?= $l['interest_charges'] !== null ? money($l['interest_charges']) : '—' ?></td>
              <td><?= status_chip($l['status']) ?></td>
              <td class="actions">
                <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/loan-view.php?id=' . $l['id'] . '&mode=view')) ?>">View</a>
                <a class="btn btn-primary btn-sm" href="<?= e(base_url('pages/loan-view.php?id=' . $l['id'] . '&mode=repay')) ?>">Repayments</a>
                <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/bank-loans.php?action=edit&id=' . $l['id'])) ?>">Edit</a>
                <?php if (can_delete()): ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete this loan and all its repayment entries?">Delete</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php render_pager('bank-loans.php', $list); ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
