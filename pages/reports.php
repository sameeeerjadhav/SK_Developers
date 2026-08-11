<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$type = get('type', 'pnl'); // pnl | project | bank | monthly
if (!in_array($type, ['pnl', 'project', 'bank', 'monthly'], true)) {
    $type = 'pnl';
}
$companyId = get('company_id') !== '' ? (int) get('company_id') : null;
$projectId = get('project_id') !== '' ? (int) get('project_id') : null;
$bankId = get('bank_account_id') !== '' ? (int) get('bank_account_id') : null;

$filterFrom = get('from', '');
$filterTo = get('to', '');
[$fromMonth, $toMonth, $month, $year] = period_from_request();
if ($month !== '' || $year !== '') {
    if ($filterFrom === '' && $filterTo === '') {
        $filterFrom = $fromMonth ?: '';
        $filterTo = $toMonth ?: '';
    }
}
$from = $filterFrom !== '' ? $filterFrom : null;
$to = $filterTo !== '' ? $filterTo : null;
$periodText = period_label($from, $to, $month, $year);
$periodPlain = report_display_period($from, $to, $month, $year);

$reportPayload = null;
$preview = ['kind' => $type, 'error' => null];

if ($type === 'pnl' || $type === 'monthly') {
    $companies = $pdo->query('SELECT * FROM companies WHERE status != "archived" ORDER BY FIELD(type,"main","sub"), name')->fetchAll();
    if ($companyId) {
        $companies = array_values(array_filter($companies, fn($c) => (int) $c['id'] === $companyId));
    }
    $pnlRows = [];
    $tc = 0.0;
    $td = 0.0;
    foreach ($companies as $i => $c) {
        $cid = (int) $c['id'];
        $cr = sum_transactions($pdo, 'credit', $cid, null, null, $from, $to);
        $dr = sum_transactions($pdo, 'debit', $cid, null, null, $from, $to);
        $tc += $cr;
        $td += $dr;
        $pnlRows[] = [
            (string) ($i + 1),
            $c['name'] ?? '',
            $c['type'] === 'main' ? 'Main' : 'Sub',
            $cr,
            $dr,
            $cr - $dr,
        ];
    }
    $scope = 'All companies';
    if ($companyId && $companies) {
        $scope = (string) $companies[0]['name'];
    }
    $title = $type === 'monthly' ? 'Company Monthly Summary' : 'Period Profit & Loss';
    $preview['rows'] = $pnlRows;
    $preview['credit_total'] = $tc;
    $preview['debit_total'] = $td;
    $reportPayload = [
        'filename' => $type === 'monthly' ? 'company_monthly_summary' : 'period_profit_and_loss',
        'title' => $title,
        'orientation' => 'landscape',
        'meta' => [
            ['Period', $periodPlain],
            ['Scope', $scope],
        ],
        'summary' => [
            ['Total credits', $tc, 'money'],
            ['Total debits', $td, 'money'],
            ['Net P&L', $tc - $td, 'money'],
            ['Companies', count($pnlRows), 'int'],
        ],
        'tables' => [[
            'title' => 'Company-wise profit and loss',
            'columns' => [
                ['label' => 'Sr No', 'type' => 'text', 'width' => '8%', 'xls_width' => 40],
                ['label' => 'Company', 'type' => 'text', 'width' => '28%', 'xls_width' => 180],
                ['label' => 'Type', 'type' => 'text', 'width' => '10%', 'xls_width' => 60],
                ['label' => 'Credits in (INR)', 'type' => 'money', 'width' => '18%', 'xls_width' => 120],
                ['label' => 'Debits out (INR)', 'type' => 'money', 'width' => '18%', 'xls_width' => 120],
                ['label' => 'Net P&L (INR)', 'type' => 'money', 'width' => '18%', 'xls_width' => 120],
            ],
            'rows' => $pnlRows,
            'totals' => ['', 'TOTAL', '', $tc, $td, $tc - $td],
        ]],
        'notes' => [
            'System-generated P&L for the selected period. Net = credits minus debits.',
            'Confidential — internal use only.',
        ],
    ];
} elseif ($type === 'project') {
    if (!$projectId) {
        $preview['error'] = 'Select a project and Generate.';
    } else {
        $p = $pdo->prepare('SELECT p.*, c.name company_name FROM projects p JOIN companies c ON c.id = p.company_id WHERE p.id = ?');
        $p->execute([$projectId]);
        $proj = $p->fetch();
        if (!$proj) {
            $preview['error'] = 'Project not found.';
        } else {
            $cid = (int) $proj['company_id'];
            $credit = sum_transactions($pdo, 'credit', $cid, $projectId, null, $from, $to);
            $landAmt = sum_transactions($pdo, 'debit', $cid, $projectId, 'land_purchase', $from, $to);
            $expenseOnly = sum_transactions($pdo, 'debit', $cid, $projectId, 'expense', $from, $to);
            $expense = $expenseOnly + $landAmt;
            $sql = 'SELECT t.*, cat.name category_name, cat.section FROM transactions t JOIN categories cat ON cat.id = t.category_id WHERE t.project_id = ?';
            $params = [$projectId];
            apply_date_range($sql, $params, $from, $to);
            $sql .= ' ORDER BY t.txn_date, t.id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $ledgerRows = [];
            $creditSum = 0.0;
            $debitSum = 0.0;
            foreach ($rows as $i => $r) {
                $isCredit = ($r['txn_type'] ?? '') === 'credit';
                $amt = (float) $r['amount'];
                if ($isCredit) {
                    $creditSum += $amt;
                } else {
                    $debitSum += $amt;
                }
                $ledgerRows[] = [
                    (string) ($i + 1),
                    report_plain_date($r['txn_date'] ?? null),
                    ucwords(str_replace('_', ' ', (string) ($r['section'] ?? ''))),
                    $r['category_name'] ?? '',
                    $r['description'] ?? '',
                    $isCredit ? $amt : null,
                    $isCredit ? null : $amt,
                ];
            }
            $preview['proj'] = $proj;
            $preview['credit'] = $credit;
            $preview['land'] = $landAmt;
            $preview['expense'] = $expense;
            $preview['rows'] = $rows;
            $reportPayload = [
                'filename' => 'project_report_' . preg_replace('/[^\w.\-]+/', '_', (string) ($proj['code'] ?? $proj['name'])),
                'title' => 'Project Report — ' . ($proj['name'] ?? ''),
                'orientation' => 'landscape',
                'meta' => [
                    ['Period', $periodPlain],
                    ['Project', (string) ($proj['name'] ?? '')],
                    ['Code', (string) ($proj['code'] ?? '—')],
                    ['Company', (string) ($proj['company_name'] ?? '')],
                ],
                'summary' => [
                    ['Credit', $credit, 'money'],
                    ['Land purchase', $landAmt, 'money'],
                    ['Expenses (incl. land)', $expense, 'money'],
                    ['Profit', $credit - $expense, 'money'],
                ],
                'tables' => [[
                    'title' => 'Project ledger',
                    'columns' => [
                        ['label' => 'Sr No', 'type' => 'text', 'width' => '6%', 'xls_width' => 35],
                        ['label' => 'Date', 'type' => 'text', 'width' => '10%', 'xls_width' => 80],
                        ['label' => 'Section', 'type' => 'text', 'width' => '12%', 'xls_width' => 100],
                        ['label' => 'Category', 'type' => 'text', 'width' => '16%', 'xls_width' => 130],
                        ['label' => 'Particulars', 'type' => 'text', 'width' => '24%', 'xls_width' => 180],
                        ['label' => 'Credit (INR)', 'type' => 'money', 'width' => '16%', 'xls_width' => 110],
                        ['label' => 'Debit (INR)', 'type' => 'money', 'width' => '16%', 'xls_width' => 110],
                    ],
                    'rows' => $ledgerRows,
                    'totals' => ['', 'TOTAL', '', '', '', $creditSum, $debitSum],
                ]],
                'notes' => [
                    'System-generated project report for the selected period.',
                    'Profit = credit − land purchase − expenses.',
                    'Confidential — internal use only.',
                ],
            ];
        }
    }
} elseif ($type === 'bank') {
    if (!$bankId) {
        $preview['error'] = 'Select a bank account and Generate.';
    } else {
        $b = $pdo->prepare(
            'SELECT ba.*, c.name AS company_name FROM bank_accounts ba JOIN companies c ON c.id = ba.company_id WHERE ba.id = ?'
        );
        $b->execute([$bankId]);
        $bank = $b->fetch();
        if (!$bank) {
            $preview['error'] = 'Account not found.';
        } else {
            $opening = (float) $bank['opening_balance'];
            if ($from) {
                $pre = $pdo->prepare(
                    "SELECT
                        COALESCE(SUM(CASE WHEN txn_type = 'credit' THEN amount ELSE 0 END),0) AS credits,
                        COALESCE(SUM(CASE WHEN txn_type = 'debit' THEN amount ELSE 0 END),0) AS debits
                     FROM transactions WHERE bank_account_id = ? AND txn_date < ?"
                );
                $pre->execute([$bankId, $from]);
                $preRow = $pre->fetch() ?: ['credits' => 0, 'debits' => 0];
                $opening += (float) $preRow['credits'] - (float) $preRow['debits'];
            }
            $sql = 'SELECT t.*, cat.name category_name, c.name company_name FROM transactions t JOIN categories cat ON cat.id = t.category_id JOIN companies c ON c.id = t.company_id WHERE t.bank_account_id = ?';
            $params = [$bankId];
            apply_date_range($sql, $params, $from, $to);
            $sql .= ' ORDER BY t.txn_date, t.id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $running = $opening;
            $tin = 0.0;
            $tout = 0.0;
            $stmtRows = [[
                '1',
                $from ? report_plain_date($from) : '',
                'Opening balance',
                '',
                '',
                null,
                null,
                $opening,
            ]];
            $n = 1;
            foreach ($rows as $r) {
                $n++;
                $isCredit = ($r['txn_type'] ?? '') === 'credit';
                $amt = (float) $r['amount'];
                if ($isCredit) {
                    $tin += $amt;
                    $running += $amt;
                } else {
                    $tout += $amt;
                    $running -= $amt;
                }
                $stmtRows[] = [
                    (string) $n,
                    report_plain_date($r['txn_date'] ?? null),
                    $r['description'] ?? '',
                    $r['company_name'] ?? '',
                    $r['category_name'] ?? '',
                    $isCredit ? $amt : null,
                    $isCredit ? null : $amt,
                    $running,
                ];
            }
            $maskedAc = (string) ($bank['account_number'] ?? '');
            if (strlen($maskedAc) > 4) {
                $maskedAc = str_repeat('X', max(0, strlen($maskedAc) - 4)) . substr($maskedAc, -4);
            }
            $preview['bank'] = $bank;
            $preview['rows'] = $rows;
            $preview['in_total'] = $tin;
            $preview['out_total'] = $tout;
            $preview['opening'] = $opening;
            $preview['closing'] = $running;
            $reportPayload = [
                'filename' => 'bank_statement_' . preg_replace('/[^\w.\-]+/', '_', (string) $bank['account_name']),
                'title' => 'Bank Account Statement',
                'orientation' => 'landscape',
                'meta' => [
                    ['Period', $periodPlain],
                    ['Account', (string) ($bank['account_name'] ?? '')],
                    ['Bank', (string) ($bank['bank_name'] ?? '')],
                    ['Company', (string) ($bank['company_name'] ?? '')],
                    ['A/C no.', $maskedAc !== '' ? $maskedAc : '—'],
                ],
                'summary' => [
                    ['Opening', $opening, 'money'],
                    ['Credits in', $tin, 'money'],
                    ['Debits out', $tout, 'money'],
                    ['Closing', $running, 'money'],
                ],
                'tables' => [[
                    'title' => 'Statement of account',
                    'columns' => [
                        ['label' => 'Sr No', 'type' => 'text', 'width' => '6%', 'xls_width' => 35],
                        ['label' => 'Date', 'type' => 'text', 'width' => '10%', 'xls_width' => 80],
                        ['label' => 'Particulars', 'type' => 'text', 'width' => '24%', 'xls_width' => 180],
                        ['label' => 'Company', 'type' => 'text', 'width' => '14%', 'xls_width' => 130],
                        ['label' => 'Category', 'type' => 'text', 'width' => '14%', 'xls_width' => 120],
                        ['label' => 'Credit (INR)', 'type' => 'money', 'width' => '11%', 'xls_width' => 110],
                        ['label' => 'Debit (INR)', 'type' => 'money', 'width' => '11%', 'xls_width' => 110],
                        ['label' => 'Balance (INR)', 'type' => 'money', 'width' => '10%', 'xls_width' => 110],
                    ],
                    'rows' => $stmtRows,
                    'totals' => ['', 'TOTAL', '', '', '', $tin, $tout, $running],
                ]],
                'notes' => [
                    'System-generated bank statement. Opening is the balance at the start of the selected period.',
                    'Entries are chronological with a running balance.',
                    'Confidential — internal use only.',
                ],
            ];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('export_action', ''), ['csv', 'excel', 'pdf'], true)) {
    verify_csrf();
    if (!$reportPayload) {
        flash('error', $preview['error'] ?: 'Generate the report first, then export.');
        redirect('pages/reports.php?' . http_build_query(array_filter([
            'type' => $type,
            'company_id' => $companyId,
            'project_id' => $projectId,
            'bank_account_id' => $bankId,
            'from' => $filterFrom ?: null,
            'to' => $filterTo ?: null,
            'month' => $month ?: null,
            'year' => $year ?: null,
        ])));
    }
    report_download(post('export_action'), $reportPayload);
    redirect('pages/reports.php');
}

$pageTitle = 'Reports';
$pageSub = 'Download a formal PDF, Excel or CSV register for the selected report and period.';
$pageActions = $reportPayload ? report_export_buttons() : '';
require __DIR__ . '/../includes/header.php';
?>
<div class="card" style="margin-bottom:16px">
  <form method="get" class="filter-bar filters">
    <div class="field">
      <label>Report</label>
      <select name="type" onchange="this.form.submit()">
        <option value="pnl" <?= $type==='pnl'?'selected':'' ?>>Period P&amp;L</option>
        <option value="project" <?= $type==='project'?'selected':'' ?>>Project report</option>
        <option value="bank" <?= $type==='bank'?'selected':'' ?>>Bank statement</option>
        <option value="monthly" <?= $type==='monthly'?'selected':'' ?>>Company monthly summary</option>
      </select>
    </div>
    <?= period_filter_fields($month, $year) ?>
    <div class="field">
      <label>From</label>
      <input type="date" name="from" value="<?= e($filterFrom) ?>">
    </div>
    <div class="field">
      <label>To</label>
      <input type="date" name="to" value="<?= e($filterTo) ?>">
    </div>
    <div class="field">
      <label>Company</label>
      <select name="company_id"><option value="">All</option><?= company_options($pdo, $companyId) ?></select>
    </div>
    <?php if ($type === 'project'): ?>
    <div class="field">
      <label>Project</label>
      <select name="project_id"><option value="">Select…</option>
        <?php foreach ($pdo->query('SELECT id, name FROM projects WHERE status != "on_hold" ORDER BY name') as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $projectId===(int)$p['id']?'selected':'' ?>><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <?php if ($type === 'bank'): ?>
    <div class="field">
      <label>Bank account</label>
      <select name="bank_account_id">
        <option value="">Select…</option>
        <?php foreach ($pdo->query('SELECT id, account_name FROM bank_accounts WHERE status="active" ORDER BY account_name') as $b): ?>
          <option value="<?= (int)$b['id'] ?>" <?= $bankId===(int)$b['id']?'selected':'' ?>><?= e($b['account_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="field" style="flex:0"><label>&nbsp;</label><button class="btn btn-primary" type="submit">Generate</button></div>
  </form>
</div>

<div class="card">
  <?php if ($preview['error']): ?>
    <p class="empty"><?= e($preview['error']) ?></p>
  <?php elseif ($type === 'pnl' || $type === 'monthly'): ?>
    <h2 class="card-title">Profit &amp; Loss preview</h2>
    <p class="muted"><?= e($periodText) ?> — download PDF / Excel / CSV from the buttons above for the formal register.</p>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Company</th><th>Type</th><th class="num">Credits in</th><th class="num">Debits out</th><th class="num">Net P&amp;L</th></tr></thead>
        <tbody>
        <?php foreach ($preview['rows'] as $row): ?>
          <tr>
            <td><?= e((string) $row[1]) ?></td>
            <td><?= e((string) $row[2]) ?></td>
            <td class="num text-success"><?= money($row[3]) ?></td>
            <td class="num text-danger"><?= money($row[4]) ?></td>
            <td class="num <?= ((float) $row[5]) >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($row[5]) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="2"><strong>Total</strong></td>
            <td class="num"><strong><?= money($preview['credit_total']) ?></strong></td>
            <td class="num"><strong><?= money($preview['debit_total']) ?></strong></td>
            <td class="num"><strong><?= money($preview['credit_total'] - $preview['debit_total']) ?></strong></td>
          </tr>
        </tfoot>
      </table>
    </div>
  <?php elseif ($type === 'project'):
    $proj = $preview['proj'];
    $credit = $preview['credit'];
    $landAmt = $preview['land'];
    $expense = $preview['expense'];
  ?>
    <h2 class="card-title"><?= e($proj['name']) ?></h2>
    <p class="muted"><?= e($proj['company_name']) ?> · Code <?= e($proj['code'] ?? '—') ?> · <?= e($periodText) ?></p>
    <div class="stat-grid" style="margin:16px 0">
      <div class="stat-card"><div class="stat-label">Credit</div><div class="stat-value text-success"><?= money($credit) ?></div></div>
      <div class="stat-card"><div class="stat-label">Land</div><div class="stat-value text-danger"><?= money($landAmt) ?></div></div>
      <div class="stat-card"><div class="stat-label">Expenses</div><div class="stat-value text-danger"><?= money($expense) ?></div></div>
      <div class="stat-card"><div class="stat-label">Profit</div><div class="stat-value <?= ($credit-$expense)>=0?'text-success':'text-danger' ?>"><?= money($credit-$expense) ?></div></div>
    </div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Date</th><th>Category</th><th>Description</th><th class="num">Credit</th><th class="num">Debit</th></tr></thead>
        <tbody>
        <?php foreach ($preview['rows'] as $r): ?>
          <tr>
            <td><?= e(format_date($r['txn_date'])) ?></td>
            <td><?= e($r['category_name']) ?></td>
            <td><?= e($r['description'] ?? '') ?></td>
            <td class="num"><?= $r['txn_type']==='credit' ? money((float)$r['amount']) : '—' ?></td>
            <td class="num"><?= $r['txn_type']==='debit' ? money((float)$r['amount']) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$preview['rows']): ?><tr><td colspan="5" class="empty">No entries in range.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php elseif ($type === 'bank'):
    $bank = $preview['bank'];
  ?>
    <h2 class="card-title">Bank statement — <?= e($bank['account_name']) ?></h2>
    <p class="muted"><?= e($bank['bank_name'] ?? '') ?> · <?= e($periodText) ?> · Opening <?= money($preview['opening']) ?> · Closing <?= money($preview['closing']) ?></p>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Date</th><th>Company</th><th>Category</th><th>Description</th><th class="num">In</th><th class="num">Out</th></tr></thead>
        <tbody>
        <?php foreach ($preview['rows'] as $r):
          $in = $r['txn_type']==='credit' ? (float)$r['amount'] : 0;
          $out = $r['txn_type']==='debit' ? (float)$r['amount'] : 0;
        ?>
          <tr>
            <td><?= e(format_date($r['txn_date'])) ?></td>
            <td><?= e($r['company_name']) ?></td>
            <td><?= e($r['category_name']) ?></td>
            <td><?= e($r['description'] ?? '') ?></td>
            <td class="num text-success"><?= $in ? money($in) : '—' ?></td>
            <td class="num text-danger"><?= $out ? money($out) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$preview['rows']): ?><tr><td colspan="6" class="empty">No movements in range.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr><td colspan="4"><strong>Period totals</strong></td><td class="num"><strong><?= money($preview['in_total']) ?></strong></td><td class="num"><strong><?= money($preview['out_total']) ?></strong></td></tr></tfoot>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
