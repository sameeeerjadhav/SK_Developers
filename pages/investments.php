<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

const INVESTMENT_CATEGORY_SQL = "((cat.section = 'credit' AND cat.slug = 'investment') OR (cat.section = 'general' AND cat.slug = 'investment_withdrawal'))";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('export_action', ''), ['csv', 'pdf'], true)) {
    verify_csrf();
    $exportAction = post('export_action');
    $selectedIds = array_values(array_unique(array_filter(array_map('intval', $_POST['txn_ids'] ?? []), fn($id) => $id > 0)));

    if (!$selectedIds) {
        flash('error', 'Select at least one transaction to export.');
        redirect('pages/investments.php');
    }

    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
    $expStmt = $pdo->prepare(
        "SELECT t.*, c.name AS company_name, p.name AS project_name, cat.name AS category_name,
                ba.account_name, ba.bank_name, u.name AS created_by_name
         FROM transactions t
         JOIN categories cat ON cat.id = t.category_id
         JOIN companies c ON c.id = t.company_id
         LEFT JOIN projects p ON p.id = t.project_id
         LEFT JOIN bank_accounts ba ON ba.id = t.bank_account_id
         LEFT JOIN users u ON u.id = t.created_by
         WHERE t.id IN ($placeholders) AND " . INVESTMENT_CATEGORY_SQL . "
         ORDER BY t.txn_date DESC, t.id DESC"
    );
    $expStmt->execute($selectedIds);
    $exportRows = $expStmt->fetchAll();

    if (!$exportRows) {
        flash('error', 'Selected transactions were not found.');
        redirect('pages/investments.php');
    }

    $exportIn = array_sum(array_map(fn($r) => $r['txn_type'] === 'credit' ? (float) $r['amount'] : 0, $exportRows));
    $exportOut = array_sum(array_map(fn($r) => $r['txn_type'] === 'debit' ? (float) $r['amount'] : 0, $exportRows));

    if ($exportAction === 'csv') {
        $filename = 'investments_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so ₹ and names open correctly in Excel
        fputcsv($out, ['Date', 'Company', 'Project', 'Type', 'Category', 'Amount', 'Reference No', 'Bank Account', 'Recorded By', 'Description']);
        foreach ($exportRows as $r) {
            fputcsv($out, [
                $r['txn_date'],
                $r['company_name'],
                $r['project_name'] ?? '',
                ucfirst($r['txn_type']),
                $r['category_name'],
                number_format((float) $r['amount'], 2, '.', ''),
                $r['reference_no'] ?? '',
                $r['account_name'] ? ($r['account_name'] . ' - ' . $r['bank_name']) : '',
                $r['created_by_name'] ?? '',
                $r['description'] ?? '',
            ]);
        }
        fputcsv($out, []);
        fputcsv($out, ['', '', '', '', '', '', '', '', 'Total invested', number_format($exportIn, 2, '.', '')]);
        fputcsv($out, ['', '', '', '', '', '', '', '', 'Total withdrawn', number_format($exportOut, 2, '.', '')]);
        fputcsv($out, ['', '', '', '', '', '', '', '', 'Net', number_format($exportIn - $exportOut, 2, '.', '')]);
        fclose($out);
        exit;
    }

    // PDF: render a print-ready sheet — user saves via the browser's Print dialog, same as Reports.
    $pageTitle = 'Investment export';
    $pageSub = count($exportRows) . ' selected transaction(s).';
    $pageActions = '<button class="btn btn-primary no-print" type="button" onclick="window.print()">Print / Save PDF</button>';
    require __DIR__ . '/../includes/header.php';
    ?>
    <link rel="stylesheet" href="<?= e(base_url('assets/css/print.css')) ?>">
    <div class="print-sheet card">
      <div class="print-header report-header">
        <div>
          <div class="print-brand" style="font-family:Sora,sans-serif;font-weight:800;font-size:1.35rem;color:var(--teal-700,#0f766e)">Sai Kuber Developers</div>
          <div class="print-meta report-meta" style="text-align:left">Investment export · <?= count($exportRows) ?> entries</div>
        </div>
        <div class="print-meta report-meta">Generated <?= e(date('d M Y H:i')) ?><br><?= e(current_user()['name'] ?? '') ?></div>
      </div>
      <div class="stat-grid" style="margin:16px 0">
        <div class="stat-card"><div class="stat-label">Total invested</div><div class="stat-value text-success"><?= money($exportIn) ?></div></div>
        <div class="stat-card"><div class="stat-label">Total withdrawn</div><div class="stat-value text-danger"><?= money($exportOut) ?></div></div>
        <div class="stat-card"><div class="stat-label">Net</div><div class="stat-value <?= ($exportIn - $exportOut) >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($exportIn - $exportOut) ?></div></div>
      </div>
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr><th>Date</th><th>Company</th><th>Project</th><th>Category</th><th>Type</th><th>Note</th><th class="num">Amount</th></tr>
          </thead>
          <tbody>
            <?php foreach ($exportRows as $r): ?>
              <tr>
                <td><?= e(format_date($r['txn_date'])) ?></td>
                <td><?= e($r['company_name']) ?></td>
                <td><?= e($r['project_name'] ?? '—') ?></td>
                <td><?= e($r['category_name']) ?></td>
                <td><?= txn_type_chip($r['txn_type']) ?></td>
                <td><?= e($r['description'] ?? '') ?></td>
                <td class="num <?= $r['txn_type'] === 'credit' ? 'text-success' : 'text-danger' ?>"><?= $r['txn_type'] === 'credit' ? '+' : '−' ?><?= money($r['amount']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr><td colspan="6"><strong>Net</strong></td><td class="num"><strong><?= money($exportIn - $exportOut) ?></strong></td></tr>
          </tfoot>
        </table>
      </div>
      <p class="muted" style="margin-top:1.5rem;font-size:0.8rem">Confidential — Sai Kuber Developers internal use</p>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$filterCompany = (int) get('company_id', 0);
$filterFrom = get('from', '');
$filterTo = get('to', '');
[$fromMonth, $toMonth, $month, $year] = period_from_request();
if ($month !== '' || $year !== '') {
    if ($filterFrom === '' && $filterTo === '') {
        $filterFrom = $fromMonth ?: '';
        $filterTo = $toMonth ?: '';
    }
}

$pageTitle = 'Investment';
$pageSub = 'All investment credits and withdrawals across companies and projects.';
$pageActions = '<a class="btn btn-primary" href="' . e(base_url('pages/transactions.php?action=add&section=credit&slug=investment')) . '">+ Add investment</a>'
    . '<a class="btn btn-outline" href="' . e(base_url('pages/transactions.php?action=add&section=general&slug=investment_withdrawal')) . '">+ Add withdrawal</a>';

$sql = "SELECT t.*, c.name AS company_name, p.name AS project_name, cat.name AS category_name,
               ba.account_name, ba.bank_name, u.name AS created_by_name
        FROM transactions t
        JOIN categories cat ON cat.id = t.category_id
        JOIN companies c ON c.id = t.company_id
        LEFT JOIN projects p ON p.id = t.project_id
        LEFT JOIN bank_accounts ba ON ba.id = t.bank_account_id
        LEFT JOIN users u ON u.id = t.created_by
        WHERE " . INVESTMENT_CATEGORY_SQL;
$params = [];
if ($filterCompany) {
    $sql .= ' AND t.company_id = ?';
    $params[] = $filterCompany;
}
apply_date_range($sql, $params, $filterFrom !== '' ? $filterFrom : null, $filterTo !== '' ? $filterTo : null);
$sql .= ' ORDER BY t.txn_date DESC, t.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$totalIn = array_sum(array_map(fn($r) => $r['txn_type'] === 'credit' ? (float)$r['amount'] : 0, $rows));
$totalOut = array_sum(array_map(fn($r) => $r['txn_type'] === 'debit' ? (float)$r['amount'] : 0, $rows));

$attachmentsByTxn = [];
if ($rows) {
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $attStmt = $pdo->prepare("SELECT * FROM attachments WHERE transaction_id IN ($placeholders) ORDER BY id");
    $attStmt->execute($ids);
    foreach ($attStmt->fetchAll() as $att) {
        $attachmentsByTxn[(int) $att['transaction_id']][] = $att;
    }
}

// Group entries by company so each company shows once, with its transactions in a dropdown
$companies = [];
foreach ($rows as $row) {
    $cid = (int) $row['company_id'];
    if (!isset($companies[$cid])) {
        $companies[$cid] = [
            'id' => $cid,
            'name' => $row['company_name'],
            'rows' => [],
            'in' => 0.0,
            'out' => 0.0,
        ];
    }
    $companies[$cid]['rows'][] = $row;
    if ($row['txn_type'] === 'credit') {
        $companies[$cid]['in'] += (float) $row['amount'];
    } else {
        $companies[$cid]['out'] += (float) $row['amount'];
    }
}
uasort($companies, fn($a, $b) => strcmp($a['name'], $b['name']));

require __DIR__ . '/../includes/header.php';
?>
<div class="stat-grid" style="grid-template-columns:repeat(4,1fr)">
  <div class="stat-card">
    <div class="stat-label">Total invested</div>
    <div class="stat-value text-success"><?= money($totalIn) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total withdrawn</div>
    <div class="stat-value text-danger"><?= money($totalOut) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Net investment</div>
    <div class="stat-value <?= ($totalIn - $totalOut) >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($totalIn - $totalOut) ?></div>
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
    <select name="company_id" onchange="this.form.submit()">
      <option value="">All</option>
      <?php foreach ($pdo->query('SELECT id, name FROM companies ORDER BY type, name') as $co): ?>
        <option value="<?= (int)$co['id'] ?>" <?= $filterCompany === (int)$co['id'] ? 'selected' : '' ?>><?= e($co['name']) ?></option>
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
<div class="card">
  <?php if (!$companies): ?>
    <div class="empty"><strong>No investments yet</strong><p>Add an investment (credit) or a withdrawal (debit) entry.</p></div>
  <?php else: ?>
    <form id="investmentsExportForm" method="post" action="<?= e(base_url('pages/investments.php' . ($filterCompany || $filterFrom || $filterTo ? '?' . http_build_query(['company_id' => $filterCompany ?: null, 'from' => $filterFrom ?: null, 'to' => $filterTo ?: null]) : ''))) ?>">
      <?= csrf_field() ?>
      <div class="export-toolbar no-print">
        <label class="select-all-label">
          <input type="checkbox" id="selectAllTxns">
          Select all
        </label>
        <span id="selectedCount" class="muted">0 selected</span>
        <div class="export-actions">
          <button class="btn btn-outline btn-sm" type="submit" name="export_action" value="csv" id="exportCsvBtn" disabled>Export CSV</button>
          <button class="btn btn-outline btn-sm" type="submit" name="export_action" value="pdf" id="exportPdfBtn" disabled>Export PDF</button>
        </div>
      </div>
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr>
              <th>Company</th>
              <th>Entries</th>
              <th class="num">Invested</th>
              <th class="num">Withdrawn</th>
              <th class="num">Net</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($companies as $co):
              $coDetailId = 'co-detail-' . $co['id'];
              $net = $co['in'] - $co['out'];
            ?>
              <tr class="row-clickable" data-row-toggle="<?= e($coDetailId) ?>">
                <td><span class="row-caret">▸</span><strong><?= e($co['name']) ?></strong></td>
                <td><?= count($co['rows']) ?></td>
                <td class="num text-success"><?= money($co['in']) ?></td>
                <td class="num text-danger"><?= money($co['out']) ?></td>
                <td class="num <?= $net >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($net) ?></td>
              </tr>
              <tr class="row-detail" id="<?= e($coDetailId) ?>" hidden>
                <td colspan="5">
                  <div class="table-wrap">
                    <table class="data">
                      <thead>
                        <tr>
                          <th class="select-col"></th>
                          <th>Date</th>
                          <th>Project</th>
                          <th>Type</th>
                          <th>Note</th>
                          <th class="num">Amount</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($co['rows'] as $row):
                          $detailId = 'inv-detail-' . (int) $row['id'];
                          $atts = $attachmentsByTxn[(int) $row['id']] ?? [];
                        ?>
                          <tr class="row-clickable" data-row-toggle="<?= e($detailId) ?>">
                            <td class="select-col"><input type="checkbox" class="txn-checkbox" name="txn_ids[]" value="<?= (int) $row['id'] ?>"></td>
                            <td><span class="row-caret">▸</span><?= e($row['txn_date']) ?></td>
                            <td><?= e($row['project_name'] ?? '—') ?></td>
                            <td><?= txn_type_chip($row['txn_type']) ?></td>
                            <td><?= e($row['description'] ?? '') ?></td>
                            <td class="num <?= $row['txn_type'] === 'credit' ? 'text-success' : 'text-danger' ?>">
                              <?= $row['txn_type'] === 'credit' ? '+' : '−' ?><?= money($row['amount']) ?>
                            </td>
                          </tr>
                          <tr class="row-detail" id="<?= e($detailId) ?>" hidden>
                            <td colspan="6">
                              <table class="detail-table">
                                <tbody>
                                  <tr>
                                    <td>Category</td>
                                    <td><?= e($row['category_name']) ?></td>
                                  </tr>
                                  <tr>
                                    <td>Reference no.</td>
                                    <td><?= e($row['reference_no'] ?: '—') ?></td>
                                  </tr>
                                  <tr>
                                    <td>Bank account</td>
                                    <td><?= $row['account_name'] ? e($row['account_name'] . ' — ' . $row['bank_name']) : '—' ?></td>
                                  </tr>
                                  <tr>
                                    <td>Recorded by</td>
                                    <td><?= e($row['created_by_name'] ?? '—') ?></td>
                                  </tr>
                                  <tr>
                                    <td>Added on</td>
                                    <td><?= e(format_date($row['created_at'] ?? null)) ?></td>
                                  </tr>
                                  <tr>
                                    <td>Description</td>
                                    <td style="font-weight:500"><?= nl2br(e($row['description'] ?: '—')) ?></td>
                                  </tr>
                                </tbody>
                              </table>
                              <?php if ($atts): ?>
                                <div class="detail-attachments">
                                  <?php foreach ($atts as $att): ?>
                                    <a class="chip chip-primary" href="<?= e(base_url('pages/attachment.php?id=' . $att['id'])) ?>" target="_blank"><?= e($att['original_name']) ?></a>
                                  <?php endforeach; ?>
                                </div>
                              <?php endif; ?>
                              <div class="form-actions" style="margin-top:0.9rem">
                                <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/transactions.php?action=edit&id=' . $row['id'])) ?>">Edit transaction</a>
                              </div>
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
    </form>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
