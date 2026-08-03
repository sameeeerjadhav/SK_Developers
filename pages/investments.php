<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

const INVESTMENT_CATEGORY_SQL = "((cat.section = 'credit' AND cat.slug IN ('investment','daily_credit','monthly_credit')) OR (cat.section = 'general' AND cat.slug IN ('investment_withdrawal','daily_debit','monthly_debit')))";
const INVESTMENT_FIXED_SLUGS = ['investment', 'investment_withdrawal'];
const INVESTMENT_REGULAR_SLUGS = ['daily_credit', 'monthly_credit', 'daily_debit', 'monthly_debit'];

function investment_segment_label(string $slug): string
{
    return in_array($slug, INVESTMENT_FIXED_SLUGS, true) ? 'Fixed' : 'Regular';
}

/** Groups rows by company for the drill-down table; keeps invested/withdrawn running totals. */
function group_investment_rows_by_company(array $rows): array
{
    $groups = [];
    foreach ($rows as $row) {
        $cid = (int) $row['company_id'];
        if (!isset($groups[$cid])) {
            $groups[$cid] = ['id' => $cid, 'name' => $row['company_name'], 'rows' => [], 'in' => 0.0, 'out' => 0.0];
        }
        $groups[$cid]['rows'][] = $row;
        if ($row['txn_type'] === 'credit') {
            $groups[$cid]['in'] += (float) $row['amount'];
        } else {
            $groups[$cid]['out'] += (float) $row['amount'];
        }
    }
    uasort($groups, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $groups;
}

/** Renders the company -> transaction -> detail drill-down table for one section. */
function render_investment_companies(array $companies, array $attachmentsByTxn, string $idPrefix): void
{
    if (!$companies) {
        echo '<div class="empty"><strong>Nothing here yet</strong><p>No entries in this section for the current filters.</p></div>';
        return;
    }
    ?>
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
            $coDetailId = $idPrefix . '-co-detail-' . $co['id'];
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
                        <th>Investor</th>
                        <th>Project</th>
                        <th>Category</th>
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
                          <td class="select-col"><input type="checkbox" class="bulk-checkbox" name="txn_ids[]" value="<?= (int) $row['id'] ?>"></td>
                          <td><span class="row-caret">▸</span><?= e($row['txn_date']) ?></td>
                          <td><?= e($row['investor_name'] ?? '—') ?></td>
                          <td><?= e($row['project_name'] ?? '—') ?></td>
                          <td><?= e($row['category_name']) ?></td>
                          <td><?= txn_type_chip($row['txn_type']) ?></td>
                          <td><?= e($row['description'] ?? '') ?></td>
                          <td class="num <?= $row['txn_type'] === 'credit' ? 'text-success' : 'text-danger' ?>">
                            <?= $row['txn_type'] === 'credit' ? '+' : '−' ?><?= money($row['amount']) ?>
                          </td>
                        </tr>
                        <tr class="row-detail" id="<?= e($detailId) ?>" hidden>
                          <td colspan="8">
                            <table class="detail-table">
                              <tbody>
                                <tr>
                                  <td>Investor</td>
                                  <td><?= e($row['investor_name'] ?? '—') ?><?= $row['investor_phone'] ? ' — ' . e($row['investor_phone']) : '' ?></td>
                                </tr>
                                <tr>
                                  <td>Category</td>
                                  <td><?= e($row['category_name']) ?></td>
                                </tr>
                                <tr>
                                  <td>Interest</td>
                                  <td><?= $row['interest_amount'] !== null ? money($row['interest_amount']) : '—' ?></td>
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
                              <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/investments.php?action=edit&id=' . $row['id'])) ?>">Edit entry</a>
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
    <?php
}

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
        "SELECT t.*, c.name AS company_name, p.name AS project_name, cat.name AS category_name, cat.slug AS category_slug,
                ba.account_name, ba.bank_name, u.name AS created_by_name, inv.name AS investor_name
         FROM transactions t
         JOIN categories cat ON cat.id = t.category_id
         JOIN companies c ON c.id = t.company_id
         LEFT JOIN projects p ON p.id = t.project_id
         LEFT JOIN bank_accounts ba ON ba.id = t.bank_account_id
         LEFT JOIN users u ON u.id = t.created_by
         LEFT JOIN investors inv ON inv.id = t.investor_id
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
        fputcsv($out, ['Date', 'Investor', 'Segment', 'Company', 'Project', 'Type', 'Category', 'Amount', 'Interest', 'Reference No', 'Bank Account', 'Recorded By', 'Description']);
        foreach ($exportRows as $r) {
            fputcsv($out, [
                $r['txn_date'],
                $r['investor_name'] ?? '',
                investment_segment_label($r['category_slug']),
                $r['company_name'],
                $r['project_name'] ?? '',
                ucfirst($r['txn_type']),
                $r['category_name'],
                number_format((float) $r['amount'], 2, '.', ''),
                $r['interest_amount'] !== null ? number_format((float) $r['interest_amount'], 2, '.', '') : '',
                $r['reference_no'] ?? '',
                $r['account_name'] ? ($r['account_name'] . ' - ' . $r['bank_name']) : '',
                $r['created_by_name'] ?? '',
                $r['description'] ?? '',
            ]);
        }
        fputcsv($out, []);
        fputcsv($out, ['', '', '', '', '', '', '', 'Total invested', number_format($exportIn, 2, '.', '')]);
        fputcsv($out, ['', '', '', '', '', '', '', 'Total withdrawn', number_format($exportOut, 2, '.', '')]);
        fputcsv($out, ['', '', '', '', '', '', '', 'Net', number_format($exportIn - $exportOut, 2, '.', '')]);
        fclose($out);
        exit;
    }

    // PDF: render a formal print-ready report sheet — user saves via the browser's Print dialog, same as Reports.
    $entryWord = count($exportRows) === 1 ? 'entry' : 'entries';
    $datesCovered = array_unique(array_map(fn($r) => $r['txn_date'], $exportRows));
    sort($datesCovered);
    $rangeLabel = count($datesCovered) > 1
        ? format_date($datesCovered[0]) . ' – ' . format_date(end($datesCovered))
        : format_date($datesCovered[0] ?? null);

    $pageTitle = 'Investment export';
    $pageSub = count($exportRows) . ' selected ' . $entryWord . '.';
    $pageActions = '<button class="btn btn-primary no-print" type="button" onclick="window.print()">Print / Save PDF</button>';
    require __DIR__ . '/../includes/header.php';
    ?>
    <link rel="stylesheet" href="<?= e(base_url('assets/css/print.css')) ?>">
    <div class="print-sheet card">
      <div class="print-header report-header">
        <div>
          <div class="print-brand" style="font-family:Sora,sans-serif;font-weight:800;font-size:1.35rem;color:var(--teal-700,#0f766e)">Sai Kuber Developers</div>
          <div class="report-doc-title">Investment Report</div>
          <div class="print-meta report-meta" style="text-align:left"><?= count($exportRows) ?> <?= e($entryWord) ?> · <?= e($rangeLabel) ?></div>
        </div>
        <div class="print-meta report-meta">Generated <?= e(date('d M Y, h:i A')) ?><br>By <?= e(current_user()['name'] ?? '') ?></div>
      </div>

      <div class="report-summary">
        <div>
          <div class="label">Total invested</div>
          <div class="value text-success"><?= money($exportIn) ?></div>
        </div>
        <div>
          <div class="label">Total withdrawn</div>
          <div class="value text-danger"><?= money($exportOut) ?></div>
        </div>
        <div>
          <div class="label">Net investment</div>
          <div class="value <?= ($exportIn - $exportOut) >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($exportIn - $exportOut) ?></div>
        </div>
      </div>

      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr>
              <th>#</th>
              <th>Date</th>
              <th>Investor</th>
              <th>Segment</th>
              <th>Company</th>
              <th>Project</th>
              <th>Category</th>
              <th>Description</th>
              <th class="num">Invested (₹)</th>
              <th class="num">Withdrawn (₹)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($exportRows as $i => $r): ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td><?= e(format_date($r['txn_date'])) ?></td>
                <td><?= e($r['investor_name'] ?? '—') ?></td>
                <td><?= e(investment_segment_label($r['category_slug'])) ?></td>
                <td><?= e($r['company_name']) ?></td>
                <td><?= e($r['project_name'] ?? '—') ?></td>
                <td><?= e($r['category_name']) ?></td>
                <td><?= e($r['description'] ?: '—') ?></td>
                <td class="num"><?= $r['txn_type'] === 'credit' ? number_format((float) $r['amount'], 2) : '—' ?></td>
                <td class="num"><?= $r['txn_type'] === 'debit' ? number_format((float) $r['amount'], 2) : '—' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="8">TOTAL</td>
              <td class="num"><?= number_format($exportIn, 2) ?></td>
              <td class="num"><?= number_format($exportOut, 2) ?></td>
            </tr>
            <tr>
              <td colspan="8">NET INVESTMENT</td>
              <td class="num" colspan="2"><?= money($exportIn - $exportOut) ?></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div class="report-footnote">
        <p>This is a system-generated report from the Sai Kuber Developers finance system. Figures reflect the transactions selected at export time.</p>
        <p>Confidential — internal use only.</p>
      </div>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$action = get('action', 'list');
$allowedInvestmentSlugs = array_merge(INVESTMENT_FIXED_SLUGS, INVESTMENT_REGULAR_SLUGS);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action', '') === 'save_investment') {
    verify_csrf();
    $editId = (int) post('id', 0);
    $investorId = (int) post('investor_id', 0);
    $companyId = (int) post('company_id', 0);
    $projectId = post('project_id') !== '' ? (int) post('project_id') : null;
    $categoryId = (int) post('category_id', 0);
    $amount = (float) post('amount', 0);
    $interestAmount = post('interest_amount') !== '' ? (float) post('interest_amount') : null;
    $txnDate = post('txn_date', date('Y-m-d'));
    $bankAccountId = post('bank_account_id') !== '' ? (int) post('bank_account_id') : null;
    $reference = post('reference_no', '');
    $description = post('description', '');
    $investorName = trim(post('investor_name', ''));
    $investorPhone = post('investor_phone', '');
    $investorEmail = post('investor_email', '');
    $investorAddress = post('investor_address', '');

    $slugPh = implode(',', array_fill(0, count($allowedInvestmentSlugs), '?'));
    $catStmt = $pdo->prepare("SELECT section FROM categories WHERE id = ? AND slug IN ($slugPh)");
    $catStmt->execute(array_merge([$categoryId], $allowedInvestmentSlugs));
    $catRow = $catStmt->fetch();

    if ($investorName === '' || !$catRow || !$companyId || $amount <= 0) {
        flash('error', 'Investor name, category, company and a positive amount are required.');
        redirect('pages/investments.php?action=' . ($editId ? 'edit&id=' . $editId : 'add'));
    }

    if ($investorId) {
        $pdo->prepare('UPDATE investors SET name=?, phone=?, email=?, address=? WHERE id=?')
            ->execute([$investorName, $investorPhone, $investorEmail, $investorAddress, $investorId]);
    } else {
        $iIns = $pdo->prepare('INSERT INTO investors (name, phone, email, address) VALUES (?,?,?,?)');
        $iIns->execute([$investorName, $investorPhone, $investorEmail, $investorAddress]);
        $investorId = (int) $pdo->lastInsertId();
    }

    $txnType = $catRow['section'] === 'credit' ? 'credit' : 'debit';
    $userId = current_user()['id'] ?? null;

    if ($editId) {
        $beforeStmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ?');
        $beforeStmt->execute([$editId]);
        $before = $beforeStmt->fetch() ?: null;
        $stmt = $pdo->prepare('UPDATE transactions SET company_id=?, project_id=?, bank_account_id=?, category_id=?, investor_id=?, interest_amount=?, txn_type=?, amount=?, txn_date=?, reference_no=?, description=? WHERE id=?');
        $stmt->execute([$companyId, $projectId, $bankAccountId, $categoryId, $investorId, $interestAmount, $txnType, $amount, $txnDate, $reference, $description, $editId]);
        audit_log($pdo, 'update', 'transaction', $editId, 'Updated investment #' . $editId . ' to ' . money($amount), $before, [
            'amount' => $amount, 'txn_date' => $txnDate, 'category_id' => $categoryId, 'investor_id' => $investorId,
        ]);
        flash('success', 'Investment entry updated.');
    } else {
        $newId = create_transaction(
            $pdo, $companyId, $categoryId, $txnType, $amount, $txnDate,
            $projectId, $bankAccountId, null, $reference, $description,
            $userId ? (int) $userId : null, $investorId, $interestAmount
        );
        audit_log($pdo, 'create', 'transaction', $newId, 'Created investment ' . $txnType . ' ' . money($amount) . ' for investor #' . $investorId);
        flash('success', 'Investment entry added.');
    }
    redirect('pages/investments.php');
}

if ($action === 'add' || $action === 'edit') {
    $txn = null;
    if ($action === 'edit' && $id = (int) get('id', 0)) {
        $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ?');
        $stmt->execute([$id]);
        $txn = $stmt->fetch();
        if (!$txn) {
            flash('error', 'Entry not found.');
            redirect('pages/investments.php');
        }
    }

    $investors = $pdo->query('SELECT id, name, phone, email, address FROM investors ORDER BY name')->fetchAll();
    $preInvestorId = (int) ($txn['investor_id'] ?? 0);
    $investorDetailsMap = [];
    foreach ($investors as $inv) {
        $investorDetailsMap[(int) $inv['id']] = [
            'name' => $inv['name'],
            'phone' => $inv['phone'] ?: '',
            'email' => $inv['email'] ?: '',
            'address' => $inv['address'] ?: '',
        ];
    }
    $preInvestor = $investorDetailsMap[$preInvestorId] ?? ['name' => '', 'phone' => '', 'email' => '', 'address' => ''];

    $slugPh = implode(',', array_fill(0, count($allowedInvestmentSlugs), '?'));
    $catStmt = $pdo->prepare("SELECT id, name, slug, section FROM categories WHERE slug IN ($slugPh)");
    $catStmt->execute($allowedInvestmentSlugs);
    $catMap = ['fixed' => ['credit' => [], 'debit' => []], 'regular' => ['credit' => [], 'debit' => []]];
    $catBySlug = [];
    foreach ($catStmt->fetchAll() as $c) {
        $segment = in_array($c['slug'], INVESTMENT_FIXED_SLUGS, true) ? 'fixed' : 'regular';
        $type = $c['section'] === 'credit' ? 'credit' : 'debit';
        $catMap[$segment][$type][] = ['id' => (int) $c['id'], 'name' => $c['name']];
        $catBySlug[$c['slug']] = ['id' => (int) $c['id'], 'segment' => $segment, 'type' => $type];
    }

    $preSegment = 'fixed';
    $preType = 'credit';
    $preCategoryId = 0;
    if ($txn) {
        $curCat = $pdo->prepare('SELECT slug FROM categories WHERE id = ?');
        $curCat->execute([$txn['category_id']]);
        $curSlug = $curCat->fetchColumn();
        if ($curSlug && isset($catBySlug[$curSlug])) {
            $preSegment = $catBySlug[$curSlug]['segment'];
            $preType = $catBySlug[$curSlug]['type'];
            $preCategoryId = $catBySlug[$curSlug]['id'];
        }
    } else {
        $preCategoryId = $catMap[$preSegment][$preType][0]['id'] ?? 0;
    }

    $preCompany = (int) ($txn['company_id'] ?? 0);
    $preProject = (int) ($txn['project_id'] ?? 0);

    $pageTitle = $action === 'edit' ? 'Edit investment entry' : 'Add investment entry';
    $pageSub = 'Investor, type and category, then company, amount and date.';
    $pageActions = '<a class="btn btn-outline" href="' . e(base_url('pages/investments.php')) . '">Back</a>';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="card" style="max-width:820px">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_investment">
        <input type="hidden" name="id" value="<?= (int) ($txn['id'] ?? 0) ?>">

        <div>
          <label>Investor</label>
          <select name="investor_id" id="investor_select">
            <option value="">+ New investor</option>
            <?php foreach ($investors as $inv): ?>
              <option value="<?= (int) $inv['id'] ?>" <?= $preInvestorId === (int) $inv['id'] ? 'selected' : '' ?>>
                <?= e($inv['name']) ?><?= $inv['phone'] ? ' — ' . e($inv['phone']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Company</label>
          <select name="company_id" id="company_id" required
            data-company-projects="project_id"
            data-company-accounts="bank_account_id"
            data-accounts-empty-label="Cash"
            data-projects-url="<?= e(base_url('api/projects.php')) ?>"
            data-accounts-url="<?= e(base_url('api/bank-accounts.php')) ?>">
            <?= company_options($pdo, $preCompany) ?>
          </select>
        </div>

        <div class="full">
          <div class="form-grid" style="padding:0">
            <div>
              <label>Investor name</label>
              <input type="text" name="investor_name" id="investor_name" required value="<?= e($preInvestor['name']) ?>">
            </div>
            <div>
              <label>Phone</label>
              <input type="text" name="investor_phone" id="investor_phone" value="<?= e($preInvestor['phone']) ?>">
            </div>
            <div>
              <label>Email (optional)</label>
              <input type="email" name="investor_email" id="investor_email" value="<?= e($preInvestor['email']) ?>">
            </div>
            <div class="full">
              <label>Address (optional)</label>
              <textarea name="investor_address" id="investor_address"><?= e($preInvestor['address']) ?></textarea>
            </div>
          </div>
          <p class="muted" id="investor_fields_hint" style="font-size:0.78rem;margin:0.5rem 0 0;display:<?= $preInvestorId ? '' : 'none' ?>">
            Editing these updates the selected investor's saved record.
          </p>
        </div>

        <div>
          <label>Project (optional)</label>
          <select name="project_id" id="project_id">
            <?= project_options($pdo, $preCompany ?: null, $preProject) ?>
          </select>
        </div>
        <div>
          <label>Investment type</label>
          <select id="inv_segment">
            <option value="fixed" <?= $preSegment === 'fixed' ? 'selected' : '' ?>>Fixed Investment</option>
            <option value="regular" <?= $preSegment === 'regular' ? 'selected' : '' ?>>Regular Investment</option>
          </select>
        </div>
        <div>
          <label>Credit or debit</label>
          <select id="inv_type">
            <option value="credit" <?= $preType === 'credit' ? 'selected' : '' ?>>Credit (money in)</option>
            <option value="debit" <?= $preType === 'debit' ? 'selected' : '' ?>>Debit (money out)</option>
          </select>
        </div>
        <div class="full">
          <label>Category</label>
          <select name="category_id" id="inv_category" required></select>
        </div>
        <div>
          <label>Amount (₹)</label>
          <input type="number" step="0.01" min="0.01" name="amount" required value="<?= e((string) ($txn['amount'] ?? '')) ?>">
        </div>
        <div>
          <label>Interest (₹, optional)</label>
          <input type="number" step="0.01" min="0" name="interest_amount" value="<?= e((string) ($txn['interest_amount'] ?? '')) ?>">
        </div>
        <div>
          <label>Date</label>
          <input type="date" name="txn_date" required value="<?= e($txn['txn_date'] ?? date('Y-m-d')) ?>">
        </div>
        <div>
          <label>Bank account (optional)</label>
          <select name="bank_account_id" id="bank_account_id">
            <?= bank_account_options($pdo, $preCompany ?: null, (int) ($txn['bank_account_id'] ?? 0), 'Cash') ?>
          </select>
        </div>
        <div class="full">
          <label>Description</label>
          <textarea name="description"><?= e($txn['description'] ?? '') ?></textarea>
        </div>
        <div class="full form-actions">
          <button class="btn btn-primary" type="submit">Save entry</button>
        </div>
      </form>
    </div>
    <script>
      (function () {
        var CATEGORY_MAP = <?= json_encode($catMap) ?>;
        var INVESTOR_DETAILS = <?= json_encode($investorDetailsMap) ?>;
        var preselectId = <?= (int) $preCategoryId ?>;

        var investorSelect = document.getElementById('investor_select');
        var nameEl = document.getElementById('investor_name');
        var phoneEl = document.getElementById('investor_phone');
        var emailEl = document.getElementById('investor_email');
        var addressEl = document.getElementById('investor_address');
        var hintEl = document.getElementById('investor_fields_hint');

        var segmentEl = document.getElementById('inv_segment');
        var typeEl = document.getElementById('inv_type');
        var categoryEl = document.getElementById('inv_category');

        function fillInvestorFields() {
          var iid = investorSelect.value;
          var d = INVESTOR_DETAILS[iid];
          if (iid && d) {
            nameEl.value = d.name || '';
            phoneEl.value = d.phone || '';
            emailEl.value = d.email || '';
            addressEl.value = d.address || '';
            hintEl.style.display = '';
          } else {
            nameEl.value = '';
            phoneEl.value = '';
            emailEl.value = '';
            addressEl.value = '';
            hintEl.style.display = 'none';
          }
        }

        function populateCategories(selectId) {
          categoryEl.innerHTML = '';
          var options = (CATEGORY_MAP[segmentEl.value] && CATEGORY_MAP[segmentEl.value][typeEl.value]) || [];
          options.forEach(function (opt) {
            var o = document.createElement('option');
            o.value = String(opt.id);
            o.textContent = opt.name;
            if (selectId && Number(selectId) === opt.id) o.selected = true;
            categoryEl.appendChild(o);
          });
        }

        investorSelect.addEventListener('change', fillInvestorFields);
        segmentEl.addEventListener('change', function () { populateCategories(null); });
        typeEl.addEventListener('change', function () { populateCategories(null); });

        populateCategories(preselectId);
      })();
    </script>
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

$filterInvestor = (int) get('investor_id', 0);

$pageTitle = 'Investment';
$pageSub = 'Fixed, long-term capital and regular (daily/monthly) investment movement — by investor and company.';
$pageActions = '<a class="btn btn-primary" href="' . e(base_url('pages/investments.php?action=add')) . '">+ Add investment</a>';

$sql = "SELECT t.*, c.name AS company_name, p.name AS project_name, cat.name AS category_name, cat.slug AS category_slug,
               ba.account_name, ba.bank_name, u.name AS created_by_name, inv.name AS investor_name, inv.phone AS investor_phone
        FROM transactions t
        JOIN categories cat ON cat.id = t.category_id
        JOIN companies c ON c.id = t.company_id
        LEFT JOIN projects p ON p.id = t.project_id
        LEFT JOIN bank_accounts ba ON ba.id = t.bank_account_id
        LEFT JOIN users u ON u.id = t.created_by
        LEFT JOIN investors inv ON inv.id = t.investor_id
        WHERE " . INVESTMENT_CATEGORY_SQL;
$params = [];
if ($filterCompany) {
    $sql .= ' AND t.company_id = ?';
    $params[] = $filterCompany;
}
if ($filterInvestor) {
    $sql .= ' AND t.investor_id = ?';
    $params[] = $filterInvestor;
}
apply_date_range($sql, $params, $filterFrom !== '' ? $filterFrom : null, $filterTo !== '' ? $filterTo : null);
$sql .= ' ORDER BY t.txn_date DESC, t.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$totalIn = array_sum(array_map(fn($r) => $r['txn_type'] === 'credit' ? (float)$r['amount'] : 0, $rows));
$totalOut = array_sum(array_map(fn($r) => $r['txn_type'] === 'debit' ? (float)$r['amount'] : 0, $rows));
$totalInterest = array_sum(array_map(fn($r) => (float) ($r['interest_amount'] ?? 0), $rows));

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

// Split into the two investment types the client asked to see separately
$fixedRows = array_values(array_filter($rows, fn($r) => in_array($r['category_slug'], INVESTMENT_FIXED_SLUGS, true)));
$regularRows = array_values(array_filter($rows, fn($r) => in_array($r['category_slug'], INVESTMENT_REGULAR_SLUGS, true)));

$fixedIn = array_sum(array_map(fn($r) => $r['txn_type'] === 'credit' ? (float) $r['amount'] : 0, $fixedRows));
$fixedOut = array_sum(array_map(fn($r) => $r['txn_type'] === 'debit' ? (float) $r['amount'] : 0, $fixedRows));
$fixedInterest = array_sum(array_map(fn($r) => (float) ($r['interest_amount'] ?? 0), $fixedRows));
$regularIn = array_sum(array_map(fn($r) => $r['txn_type'] === 'credit' ? (float) $r['amount'] : 0, $regularRows));
$regularOut = array_sum(array_map(fn($r) => $r['txn_type'] === 'debit' ? (float) $r['amount'] : 0, $regularRows));
$regularInterest = array_sum(array_map(fn($r) => (float) ($r['interest_amount'] ?? 0), $regularRows));

$fixedCompanies = group_investment_rows_by_company($fixedRows);
$regularCompanies = group_investment_rows_by_company($regularRows);

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
    <div class="stat-label">Interest amount</div>
    <div class="stat-value"><?= money($totalInterest) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Net investment</div>
    <div class="stat-value <?= ($totalIn - $totalOut) >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($totalIn - $totalOut) ?></div>
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
    <label>Investor</label>
    <select name="investor_id" onchange="this.form.submit()">
      <option value="">All</option>
      <?php foreach ($pdo->query('SELECT id, name FROM investors ORDER BY name') as $inv): ?>
        <option value="<?= (int) $inv['id'] ?>" <?= $filterInvestor === (int) $inv['id'] ? 'selected' : '' ?>><?= e($inv['name']) ?></option>
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
    <div class="empty"><strong>No investments yet</strong><p>Add an investment (credit) or a withdrawal (debit) entry.</p></div>
  </div>
<?php else: ?>
  <form id="investmentsExportForm" class="bulk-export-form" method="post" action="<?= e(base_url('pages/investments.php' . ($filterCompany || $filterFrom || $filterTo ? '?' . http_build_query(['company_id' => $filterCompany ?: null, 'from' => $filterFrom ?: null, 'to' => $filterTo ?: null]) : ''))) ?>">
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

    <div class="card investment-section investment-section-fixed">
      <div class="investment-section-head">
        <div class="investment-section-icon">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
        </div>
        <div>
          <div class="investment-section-title">Fixed Investments</div>
          <div class="investment-section-sub">Long-term capital — invested for years; withdrawals here are rare, one-off events.</div>
        </div>
        <span class="investment-section-badge">Long-term</span>
      </div>
      <div class="stat-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1rem">
        <div class="stat-card">
          <div class="stat-label">Invested</div>
          <div class="stat-value text-success"><?= money($fixedIn) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Withdrawn</div>
          <div class="stat-value text-danger"><?= money($fixedOut) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Interest amount</div>
          <div class="stat-value"><?= money($fixedInterest) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Net</div>
          <div class="stat-value <?= ($fixedIn - $fixedOut) >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($fixedIn - $fixedOut) ?></div>
        </div>
      </div>
      <?php render_investment_companies($fixedCompanies, $attachmentsByTxn, 'fixed'); ?>
    </div>

    <div class="card investment-section investment-section-regular">
      <div class="investment-section-head">
        <div class="investment-section-icon">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 2l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
        </div>
        <div>
          <div class="investment-section-title">Regular Investments</div>
          <div class="investment-section-sub">Daily &amp; monthly credits, plus withdrawals — frequent movement.</div>
        </div>
        <span class="investment-section-badge">Recurring</span>
      </div>
      <div class="stat-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1rem">
        <div class="stat-card">
          <div class="stat-label">Invested</div>
          <div class="stat-value text-success"><?= money($regularIn) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Withdrawn</div>
          <div class="stat-value text-danger"><?= money($regularOut) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Interest amount</div>
          <div class="stat-value"><?= money($regularInterest) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Net</div>
          <div class="stat-value <?= ($regularIn - $regularOut) >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($regularIn - $regularOut) ?></div>
        </div>
      </div>
      <?php render_investment_companies($regularCompanies, $attachmentsByTxn, 'regular'); ?>
    </div>
  </form>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
