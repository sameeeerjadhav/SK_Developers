<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$filterCompany = (int) get('company_id', 0);
[$from, $to, $month, $year] = period_from_request();

$overall = summary_totals($pdo, $filterCompany ?: null, $from, $to);
$companies = $pdo->query('SELECT * FROM companies WHERE status = "active" ORDER BY type ASC, id ASC')->fetchAll();

$scopeName = 'All companies (group total)';
if ($filterCompany) {
    foreach ($companies as $co) {
        if ((int) $co['id'] === $filterCompany) {
            $scopeName = (string) $co['name'];
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('export_action', ''), ['csv', 'excel', 'pdf'], true)) {
    verify_csrf();
    $metricRows = [
        ['1', 'Investment', (float) $overall['investment']],
        ['2', 'Partner', (float) $overall['partner']],
        ['3', 'Booking', (float) $overall['booking']],
        ['4', 'Expense', (float) $overall['expense']],
        ['5', 'Bank loans (outstanding)', (float) $overall['bank_loans']],
        ['6', 'Bank balance', (float) $overall['bank_balance']],
        ['7', 'Cash balance', (float) $overall['cash_balance']],
        ['8', 'Assets', (float) $overall['assets']],
        ['9', 'Deposits', (float) $overall['deposits']],
        ['10', 'Total credits', (float) $overall['credits']],
        ['11', 'Total debits', (float) $overall['debits']],
        ['12', 'Profit (credits − debits)', (float) $overall['profit']],
    ];
    $tables = [[
        'title' => 'Headline figures',
        'columns' => [
            ['label' => 'Sr No', 'type' => 'text', 'width' => '10%', 'xls_width' => 40],
            ['label' => 'Particulars', 'type' => 'text', 'width' => '55%', 'xls_width' => 220],
            ['label' => 'Amount (INR)', 'type' => 'money', 'width' => '35%', 'xls_width' => 120],
        ],
        'rows' => $metricRows,
    ]];
    if (!$filterCompany) {
        $coRows = [];
        foreach ($companies as $i => $co) {
            $s = summary_totals($pdo, (int) $co['id'], $from, $to);
            $coRows[] = [
                (string) ($i + 1),
                $co['name'],
                $co['type'] === 'main' ? 'Main' : 'Sub',
                (float) $s['investment'],
                (float) $s['partner'],
                (float) $s['booking'],
                (float) $s['expense'],
                (float) $s['bank_loans'],
                (float) $s['bank_balance'],
                (float) $s['cash_balance'],
                (float) $s['profit'],
            ];
        }
        $tables[] = [
            'title' => 'Company-wise summary',
            'columns' => [
                ['label' => 'Sr No', 'type' => 'text', 'width' => '5%', 'xls_width' => 35],
                ['label' => 'Company', 'type' => 'text', 'width' => '16%', 'xls_width' => 150],
                ['label' => 'Type', 'type' => 'text', 'width' => '7%', 'xls_width' => 50],
                ['label' => 'Investment', 'type' => 'money', 'width' => '9%', 'xls_width' => 95],
                ['label' => 'Partner', 'type' => 'money', 'width' => '9%', 'xls_width' => 90],
                ['label' => 'Booking', 'type' => 'money', 'width' => '9%', 'xls_width' => 90],
                ['label' => 'Expense', 'type' => 'money', 'width' => '9%', 'xls_width' => 90],
                ['label' => 'Loans', 'type' => 'money', 'width' => '9%', 'xls_width' => 90],
                ['label' => 'Bank bal.', 'type' => 'money', 'width' => '9%', 'xls_width' => 90],
                ['label' => 'Cash bal.', 'type' => 'money', 'width' => '9%', 'xls_width' => 90],
                ['label' => 'Profit', 'type' => 'money', 'width' => '9%', 'xls_width' => 95],
            ],
            'rows' => $coRows,
        ];
    }
    report_download(post('export_action'), [
        'filename' => 'total_summary',
        'title' => 'Total Summary',
        'orientation' => 'landscape',
        'meta' => [
            ['Period', report_display_period($from, $to, $month, $year)],
            ['Scope', $scopeName],
        ],
        'summary' => [
            ['Profit', $overall['profit'], 'money'],
            ['Credits', $overall['credits'], 'money'],
            ['Debits', $overall['debits'], 'money'],
            ['Bank balance', $overall['bank_balance'], 'money'],
        ],
        'tables' => $tables,
        'notes' => [
            'System-generated management summary for the selected period and company scope.',
            'Profit = total credits minus total debits.',
            'Confidential — internal use only.',
        ],
    ]);
    redirect('pages/summary.php');
}

$pageTitle = 'Total Summary';
$pageSub = 'Aggregated investment, partner, expense, bank loans, assets, deposits and profit — ' . period_label($from, $to, $month, $year) . '.';
$pageActions = report_export_buttons()
    . '<a class="btn btn-outline" href="' . e(base_url('pages/reports.php?' . http_build_query(array_filter(['type' => 'pnl', 'month' => $month ?: null, 'year' => $year ?: null, 'company_id' => $filterCompany ?: null])))) . '">P&amp;L report</a>';

require __DIR__ . '/../includes/header.php';
?>

<form class="filters" method="get">
  <?= period_filter_fields($month, $year) ?>
  <div class="field">
    <label>Scope</label>
    <select name="company_id" onchange="this.form.submit()">
      <option value="">All companies (group total)</option>
      <?php foreach ($companies as $co): ?>
        <option value="<?= (int)$co['id'] ?>" <?= $filterCompany === (int)$co['id'] ? 'selected' : '' ?>><?= e($co['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</form>

<div class="stat-grid dense">
  <div class="stat-card">
    <div class="stat-label">Investment</div>
    <div class="stat-value"><?= money($overall['investment']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Partner</div>
    <div class="stat-value"><?= money($overall['partner']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Booking</div>
    <div class="stat-value"><?= money($overall['booking']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Expense</div>
    <div class="stat-value"><?= money($overall['expense']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Bank Loans (outstanding)</div>
    <div class="stat-value"><?= money($overall['bank_loans']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Bank Balance</div>
    <div class="stat-value"><?= money($overall['bank_balance']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Cash Balance</div>
    <div class="stat-value"><?= money($overall['cash_balance']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Assets</div>
    <div class="stat-value"><?= money($overall['assets']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Deposits</div>
    <div class="stat-value"><?= money($overall['deposits']) ?></div>
  </div>
</div>

<div class="card" style="margin-bottom:1rem">
  <div class="profit-row" style="margin:0">
    <span>Profit (Credits − Debits)</span>
    <span class="<?= $overall['profit'] >= 0 ? 'text-success' : 'text-danger' ?>" style="font-size:1.25rem"><?= money($overall['profit']) ?></span>
  </div>
  <div class="grid-2" style="margin-top:1rem;gap:0.75rem">
    <div class="highlight-box">Total credits: <strong><?= money($overall['credits']) ?></strong></div>
    <div class="highlight-box" style="background:#fef2f2;border-color:#fecaca">Total debits: <strong><?= money($overall['debits']) ?></strong></div>
  </div>
</div>

<?php if (!$filterCompany): ?>
<div class="card">
  <h2 class="card-title">By company</h2>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Company</th>
          <th class="num">Investment</th>
          <th class="num">Partner</th>
          <th class="num">Expense</th>
          <th class="num">Loans</th>
          <th class="num">Bank bal.</th>
          <th class="num">Profit</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($companies as $co):
          $s = summary_totals($pdo, (int) $co['id'], $from, $to);
        ?>
          <tr>
            <td>
              <strong><?= e($co['name']) ?></strong>
              <div class="muted" style="font-size:0.72rem"><?= $co['type'] === 'main' ? 'Main' : 'Sub' ?></div>
            </td>
            <td class="num"><?= money($s['investment']) ?></td>
            <td class="num"><?= money($s['partner']) ?></td>
            <td class="num"><?= money($s['expense']) ?></td>
            <td class="num"><?= money($s['bank_loans']) ?></td>
            <td class="num"><?= money($s['bank_balance']) ?></td>
            <td class="num <?= $s['profit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($s['profit']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
