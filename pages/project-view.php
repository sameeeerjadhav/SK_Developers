<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$id = (int) get('id', 0);
$stmt = $pdo->prepare(
    'SELECT p.*, c.name AS company_name, c.type AS company_type
     FROM projects p JOIN companies c ON c.id = p.company_id WHERE p.id = ?'
);
$stmt->execute([$id]);
$project = $stmt->fetch();
if (!$project) {
    flash('error', 'Project not found.');
    redirect('pages/projects.php');
}

$credits = section_breakdown($pdo, $id, 'credit');
$land = section_breakdown($pdo, $id, 'land_purchase');
$partnerDebits = slug_breakdown($pdo, $id, ['partner_advance_return', 'partner_capital_withdrawal']);
$debits = array_merge($land, $partnerDebits);
$expenses = section_breakdown($pdo, $id, 'expense');

$creditTotal = array_sum(array_map(fn($r) => (float)$r['total'], $credits));
$landTotal = array_sum(array_map(fn($r) => (float)$r['total'], $land));
$partnerDebitTotal = array_sum(array_map(fn($r) => (float)$r['total'], $partnerDebits));
$debitTotal = $landTotal + $partnerDebitTotal;
$expenseTotal = array_sum(array_map(fn($r) => (float)$r['total'], $expenses));
$profit = $creditTotal - $debitTotal - $expenseTotal;

$allTxnStmt = $pdo->prepare(
    'SELECT t.*, cat.name AS category_name, cat.slug AS category_slug, cat.section,
            ba.account_name, ba.bank_name
     FROM transactions t
     JOIN categories cat ON cat.id = t.category_id
     LEFT JOIN bank_accounts ba ON ba.id = t.bank_account_id
     WHERE t.project_id = ?
     ORDER BY t.txn_date DESC, t.id DESC'
);
$allTxnStmt->execute([$id]);
$allTxns = $allTxnStmt->fetchAll();

$txnsByCat = [];
foreach ($allTxns as $t) {
    $txnsByCat[(int) $t['category_id']][] = $t;
}

$pageTitle = $project['name'];
$pageSub = ($project['company_type'] === 'main' ? 'Main company' : 'Sub company') . ' · ' . $project['company_name'];
$pageActions = report_export_buttons()
    . '<a class="btn btn-outline" href="' . e(base_url('pages/project-entries.php?id=' . $id)) . '">Project entries</a>'
    . '<a class="btn btn-primary" href="' . e(base_url('pages/transactions.php?action=add&project_id=' . $id . '&company_id=' . $project['company_id'])) . '">+ Add entry</a>'
    . '<a class="btn btn-outline" href="' . e(base_url('pages/projects.php?action=edit&id=' . $id)) . '">Edit</a>';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('export_action', ''), ['csv', 'excel', 'pdf'], true)) {
    verify_csrf();
    $catCols = [
        ['label' => 'Sr No', 'type' => 'text', 'width' => '8%', 'xls_width' => 40],
        ['label' => 'Particulars', 'type' => 'text', 'width' => '40%', 'xls_width' => 200],
        ['label' => 'Cash (INR)', 'type' => 'money', 'width' => '17%', 'xls_width' => 110],
        ['label' => 'Bank (INR)', 'type' => 'money', 'width' => '17%', 'xls_width' => 110],
        ['label' => 'Total (INR)', 'type' => 'money', 'width' => '18%', 'xls_width' => 110],
    ];
    $mapCats = static function (array $rows): array {
        $out = [];
        foreach ($rows as $i => $row) {
            $out[] = [
                (string) ($i + 1),
                $row['name'] ?? '',
                (float) $row['cash_total'],
                (float) $row['bank_total'],
                (float) $row['total'],
            ];
        }
        return $out;
    };
    $creditRows = $mapCats($credits);
    $debitRows = $mapCats($debits);
    $expenseRows = $mapCats($expenses);

    $ledgerStmt = $pdo->prepare(
        'SELECT t.*, cat.name AS category_name, cat.section, ba.account_name, ba.bank_name
         FROM transactions t
         JOIN categories cat ON cat.id = t.category_id
         LEFT JOIN bank_accounts ba ON ba.id = t.bank_account_id
         WHERE t.project_id = ?
         ORDER BY t.txn_date ASC, t.id ASC'
    );
    $ledgerStmt->execute([$id]);
    $ledger = $ledgerStmt->fetchAll();
    $ledgerRows = [];
    $creditSum = 0.0;
    $debitSum = 0.0;
    foreach ($ledger as $i => $r) {
        $isCredit = ($r['txn_type'] ?? '') === 'credit';
        $amt = (float) $r['amount'];
        if ($isCredit) {
            $creditSum += $amt;
        } else {
            $debitSum += $amt;
        }
        $bank = $r['account_name'] ? trim($r['account_name'] . ($r['bank_name'] ? ' - ' . $r['bank_name'] : '')) : 'Cash';
        $ledgerRows[] = [
            (string) ($i + 1),
            report_plain_date($r['txn_date'] ?? null),
            ucwords(str_replace('_', ' ', (string) ($r['section'] ?? ''))),
            $r['category_name'] ?? '',
            $bank,
            $r['description'] ?? '',
            $isCredit ? $amt : null,
            $isCredit ? null : $amt,
        ];
    }

    $landMeta = [];
    if (!empty($project['deed_name'])) {
        $landMeta[] = ['Deed', (string) $project['deed_name']];
    }
    if (!empty($project['party_name'])) {
        $landMeta[] = ['Party', (string) $project['party_name']];
    }
    if (!empty($project['survey_no'])) {
        $landMeta[] = ['Survey no.', (string) $project['survey_no']];
    }
    if ($project['area_sqft'] !== null && $project['area_sqft'] !== '') {
        $landMeta[] = ['Area', (string) $project['area_sqft'] . ' sqft'];
    }

    report_download(post('export_action'), [
        'filename' => 'project_' . preg_replace('/[^\w.\-]+/', '_', (string) ($project['code'] ?? $project['name'])) . '_report',
        'title' => 'Project Report — ' . ($project['name'] ?? ''),
        'orientation' => 'landscape',
        'meta' => array_merge([
            ['Project', (string) ($project['name'] ?? '')],
            ['Code', (string) ($project['code'] ?? '—')],
            ['Company', (string) ($project['company_name'] ?? '')],
            ['Status', ucfirst((string) ($project['status'] ?? ''))],
        ], $landMeta),
        'summary' => [
            ['Credit', $creditTotal, 'money'],
            ['Debit (land + partner outflows)', $debitTotal, 'money'],
            ['Expenses', $expenseTotal, 'money'],
            ['Profit', $profit, 'money'],
        ],
        'tables' => [
            [
                'title' => 'Credit by category',
                'columns' => $catCols,
                'rows' => $creditRows,
                'totals' => ['', 'TOTAL', '', '', $creditTotal],
            ],
            [
                'title' => 'Debit by category (land + partner outflows)',
                'columns' => $catCols,
                'rows' => $debitRows,
                'totals' => ['', 'TOTAL', '', '', $debitTotal],
            ],
            [
                'title' => 'Expenses by category',
                'columns' => $catCols,
                'rows' => $expenseRows,
                'totals' => ['', 'TOTAL', '', '', $expenseTotal],
            ],
            [
                'title' => 'Project ledger',
                'columns' => [
                    ['label' => 'Sr No', 'type' => 'text', 'width' => '5%', 'xls_width' => 35],
                    ['label' => 'Date', 'type' => 'text', 'width' => '9%', 'xls_width' => 80],
                    ['label' => 'Section', 'type' => 'text', 'width' => '12%', 'xls_width' => 100],
                    ['label' => 'Category', 'type' => 'text', 'width' => '14%', 'xls_width' => 120],
                    ['label' => 'Account', 'type' => 'text', 'width' => '14%', 'xls_width' => 130],
                    ['label' => 'Particulars', 'type' => 'text', 'width' => '18%', 'xls_width' => 160],
                    ['label' => 'Credit (INR)', 'type' => 'money', 'width' => '14%', 'xls_width' => 110],
                    ['label' => 'Debit (INR)', 'type' => 'money', 'width' => '14%', 'xls_width' => 110],
                ],
                'rows' => $ledgerRows,
                'totals' => ['', 'TOTAL', '', '', '', '', $creditSum, $debitSum],
            ],
        ],
        'notes' => [
            'System-generated project report. Category tables include every particular (including ₹0). Ledger is the full project history.',
            'Profit = credit − debit (land + partner advance return / capital withdrawal) − expenses.',
            'Confidential — internal use only.',
        ],
    ]);
    redirect('pages/project-view.php?id=' . $id);
}

require __DIR__ . '/../includes/header.php';

/** Renders category totals; non-zero rows expand to Cash/Bank split plus every matching entry. */
function render_ledger_rows(array $rows, string $idPrefix, array $txnsByCat): void
{
    echo '<div class="table-wrap"><table class="data"><thead><tr><th>Particulars</th><th class="num">Amount</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $total = (float) $row['total'];
        $entries = $txnsByCat[(int) $row['id']] ?? [];
        $entryCount = count($entries);
        if ($total == 0.0 && $entryCount === 0) {
            echo '<tr><td>' . e($row['name']) . '</td><td class="num">' . money($total) . '</td></tr>';
            continue;
        }
        $detailId = $idPrefix . '-cat-' . $row['id'];
        echo '<tr class="row-clickable" data-row-toggle="' . e($detailId) . '"><td><span class="row-caret">▸</span>' . e($row['name']);
        if ($entryCount > 0) {
            echo ' <span class="muted" style="font-weight:600;font-size:0.72rem">(' . $entryCount . ($entryCount === 1 ? ' entry' : ' entries') . ')</span>';
        }
        echo '</td><td class="num">' . money($total) . '</td></tr>';
        echo '<tr class="row-detail" id="' . e($detailId) . '" hidden><td colspan="2">';
        echo '<table class="detail-table"><tbody>';
        echo '<tr><td>Cash</td><td>' . money($row['cash_total']) . '</td></tr>';
        echo '<tr><td>Bank account</td><td>' . money($row['bank_total']) . '</td></tr>';
        echo '</tbody></table>';
        if ($entries) {
            echo '<div class="table-wrap" style="max-height:22rem;overflow:auto;margin-top:0.6rem">';
            echo '<table class="detail-table"><thead><tr><th>Date</th><th>Account</th><th>Particulars</th><th class="num">Amount</th></tr></thead><tbody>';
            foreach ($entries as $t) {
                $account = $t['account_name']
                    ? trim($t['account_name'] . ($t['bank_name'] ? ' — ' . $t['bank_name'] : ''))
                    : 'Cash';
                $note = trim((string) ($t['description'] ?? ''));
                echo '<tr>';
                echo '<td>' . e(format_date($t['txn_date'])) . '</td>';
                echo '<td>' . e($account) . '</td>';
                echo '<td>' . e($note !== '' ? $note : ($t['category_name'] ?? '')) . '</td>';
                echo '<td class="num ' . (($t['txn_type'] ?? '') === 'credit' ? 'text-success' : 'text-danger') . '">' . money($t['amount']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}
?>

<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-label">Credits</div>
    <div class="stat-value text-success"><?= money($creditTotal) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Debit</div>
    <div class="stat-value text-danger"><?= money($debitTotal) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Expenses</div>
    <div class="stat-value text-danger"><?= money($expenseTotal) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Profit</div>
    <div class="stat-value <?= $profit >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($profit) ?></div>
    <div class="stat-hint"><?= status_chip($project['status']) ?></div>
  </div>
</div>

<?php if ($project['deed_name'] || $project['party_name'] || $project['survey_no'] || $project['area_sqft'] || $project['address']): ?>
<div class="card">
  <h2 class="card-title">Land record</h2>
  <div class="grid-2" style="gap:0.75rem">
    <div class="highlight-box"><span class="muted" style="font-size:0.72rem">Deed name</span><br><?= e($project['deed_name'] ?: '—') ?></div>
    <div class="highlight-box"><span class="muted" style="font-size:0.72rem">Party name</span><br><?= e($project['party_name'] ?: '—') ?></div>
    <div class="highlight-box"><span class="muted" style="font-size:0.72rem">Survey No. (S.No.)</span><br><?= e($project['survey_no'] ?: '—') ?></div>
    <div class="highlight-box"><span class="muted" style="font-size:0.72rem">Area</span><br><?= $project['area_sqft'] !== null ? e((string) $project['area_sqft']) . ' sqft' : '—' ?></div>
    <div class="highlight-box full" style="grid-column:1/-1"><span class="muted" style="font-size:0.72rem">Address</span><br><?= nl2br(e($project['address'] ?: '—')) ?></div>
  </div>
</div>
<?php endif; ?>

<div class="ledger-grid">
  <div class="card ledger-block credit">
    <h3>Credit</h3>
    <p class="muted" style="font-size:0.75rem;margin:-0.2rem 0 0.6rem">Click a line with ▸ to see every entry in that particular.</p>
    <?php render_ledger_rows($credits, 'credit', $txnsByCat); ?>
    <div class="ledger-total"><span>Total credit</span><span class="text-success"><?= money($creditTotal) ?></span></div>
  </div>
  <div class="card ledger-block debit">
    <h3>Debit</h3>
    <?php render_ledger_rows($debits, 'debit', $txnsByCat); ?>
    <div class="ledger-total"><span>Total debit</span><span class="text-danger"><?= money($debitTotal) ?></span></div>
  </div>
  <div class="card ledger-block expense">
    <h3>Expenses</h3>
    <?php render_ledger_rows($expenses, 'expense', $txnsByCat); ?>
    <div class="ledger-total"><span>Total expenses</span><span class="text-danger"><?= money($expenseTotal) ?></span></div>
    <div class="profit-row">
      <span>Profit</span>
      <span class="<?= $profit >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($profit) ?></span>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
