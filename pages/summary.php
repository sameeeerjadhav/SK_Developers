<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$filterCompany = (int) get('company_id', 0);
[$from, $to, $month, $year] = period_from_request();
$pageTitle = 'Total Summary';
$pageSub = 'Aggregated investment, partner, expense, bank loans, assets, deposits and profit — ' . period_label($from, $to, $month, $year) . '.';
$reportQs = http_build_query(array_filter(['type' => 'pnl', 'month' => $month ?: null, 'year' => $year ?: null, 'company_id' => $filterCompany ?: null]));
$pageActions = '<a class="btn btn-primary" href="' . e(base_url('pages/reports.php?' . $reportQs)) . '">Print PDF</a>';

$overall = summary_totals($pdo, $filterCompany ?: null, $from, $to);
$companies = $pdo->query('SELECT * FROM companies WHERE status = "active" ORDER BY type ASC, id ASC')->fetchAll();

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
