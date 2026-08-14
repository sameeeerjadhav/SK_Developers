<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

/**
 * Fetches one page of credit or debit transactions plus the grand total count/sum
 * across ALL matching rows (not just the current page) for that type.
 */
function fetch_txn_page(PDO $pdo, string $txnType, int $companyId, int $projectId, string $from, string $to, string $q, int $page, int $perPage): array
{
    $perPage = in_array($perPage, LIST_PAGE_LIMITS, true) ? $perPage : 25;
    $where = 'WHERE t.txn_type = ?';
    $params = [$txnType];
    if ($companyId) { $where .= ' AND t.company_id = ?'; $params[] = $companyId; }
    if ($projectId) { $where .= ' AND t.project_id = ?'; $params[] = $projectId; }
    if ($from !== '') { $where .= ' AND t.txn_date >= ?'; $params[] = $from; }
    if ($to !== '') { $where .= ' AND t.txn_date <= ?'; $params[] = $to; }
    if ($q !== '') {
        $where .= ' AND (t.description LIKE ? OR t.reference_no LIKE ? OR t.payee_name LIKE ? OR cat.name LIKE ? OR c.name LIKE ? OR p.name LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*), COALESCE(SUM(t.amount),0)
         FROM transactions t
         JOIN companies c ON c.id = t.company_id
         JOIN categories cat ON cat.id = t.category_id
         LEFT JOIN projects p ON p.id = t.project_id
         $where"
    );
    $countStmt->execute($params);
    [$total, $sum] = $countStmt->fetch(PDO::FETCH_NUM);
    $total = (int) $total;
    $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
    $page = min(max(1, $page), $totalPages);
    $offset = ($page - 1) * $perPage;

    $rowStmt = $pdo->prepare(
        "SELECT t.*, c.name AS company_name, cat.name AS category_name, cat.section, cat.slug AS category_slug,
                p.name AS project_name, pr.name AS partner_name, bp.booking_id
         FROM transactions t
         JOIN companies c ON c.id = t.company_id
         JOIN categories cat ON cat.id = t.category_id
         LEFT JOIN projects p ON p.id = t.project_id
         LEFT JOIN partners pr ON pr.id = t.partner_id
         LEFT JOIN (
            SELECT transaction_id, MIN(booking_id) AS booking_id
            FROM booking_payments
            WHERE transaction_id IS NOT NULL
            GROUP BY transaction_id
         ) bp ON bp.transaction_id = t.id
         $where
         ORDER BY t.txn_date DESC, t.id DESC
         LIMIT " . $perPage . " OFFSET $offset"
    );
    $rowStmt->execute($params);
    $rows = $rowStmt->fetchAll();
    $count = count($rows);

    return [
        'rows' => $rows,
        'total' => $total,
        'sum' => (float) $sum,
        'page' => $page,
        'totalPages' => $totalPages,
        'from' => $count ? $offset + 1 : 0,
        'to' => $offset + $count,
    ];
}

/** All matching ledger rows for export (not paginated). */
function fetch_txn_export(PDO $pdo, string $txnType, int $companyId, int $projectId, string $from, string $to, string $q): array
{
    $where = 'WHERE 1=1';
    $params = [];
    if ($txnType === 'credit' || $txnType === 'debit') {
        $where .= ' AND t.txn_type = ?';
        $params[] = $txnType;
    }
    if ($companyId) { $where .= ' AND t.company_id = ?'; $params[] = $companyId; }
    if ($projectId) { $where .= ' AND t.project_id = ?'; $params[] = $projectId; }
    if ($from !== '') { $where .= ' AND t.txn_date >= ?'; $params[] = $from; }
    if ($to !== '') { $where .= ' AND t.txn_date <= ?'; $params[] = $to; }
    if ($q !== '') {
        $where .= ' AND (t.description LIKE ? OR t.reference_no LIKE ? OR t.payee_name LIKE ? OR cat.name LIKE ? OR c.name LIKE ? OR p.name LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }

    $stmt = $pdo->prepare(
        "SELECT t.*, c.name AS company_name, cat.name AS category_name, cat.section,
                p.name AS project_name, pr.name AS partner_name, ba.account_name, ba.bank_name
         FROM transactions t
         JOIN companies c ON c.id = t.company_id
         JOIN categories cat ON cat.id = t.category_id
         LEFT JOIN projects p ON p.id = t.project_id
         LEFT JOIN partners pr ON pr.id = t.partner_id
         LEFT JOIN bank_accounts ba ON ba.id = t.bank_account_id
         $where
         ORDER BY t.txn_date ASC, t.id ASC"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Renders one Credit/Debit column: table of rows, a totals row, and pagination. */
function render_txn_column(array $data, string $type, string $pageParam, array $baseQueryParams): void
{
    $isCredit = $type === 'credit';
    $rows = $data['rows'];
    $anchor = $isCredit ? 'credit' : 'debit';
    ?>
    <div class="card" id="<?= $anchor ?>">
      <div class="card-head">
        <h2 class="card-title"><?= txn_type_chip($type) ?> <?= $isCredit ? 'Credit' : 'Debit' ?></h2>
        <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap">
          <span class="muted" style="font-size:0.8rem"><?= $data['total'] ?> entries</span>
          <?php render_limit_control('transactions.php', [], $anchor); ?>
        </div>
      </div>
      <?php if (!$rows): ?>
        <div class="empty"><strong>No <?= $isCredit ? 'credit' : 'debit' ?> entries</strong><p>Nothing recorded for the current filters.</p></div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data">
            <thead>
              <tr>
                <th>Date</th>
                <th>Company / Project</th>
                <th>Category</th>
                <th class="num">Amount</th>
                <th class="actions">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= e(format_date($row['txn_date'])) ?></td>
                  <td>
                    <strong><?= e($row['company_name']) ?></strong>
                    <div class="muted" style="font-size:0.75rem"><?= e($row['project_name'] ?? 'No project') ?><?= $row['partner_name'] ? ' · ' . e($row['partner_name']) : '' ?></div>
                  </td>
                  <td>
                    <?= e($row['category_name']) ?>
                    <div class="muted" style="font-size:0.72rem"><?= e(ucwords(str_replace('_', ' ', $row['section']))) ?><?= $row['payee_name'] ? ' · ' . e($row['payee_name']) : '' ?></div>
                  </td>
                  <td class="num <?= $isCredit ? 'text-success' : 'text-danger' ?>">
                    <?= $isCredit ? '+' : '−' ?><?= money($row['amount']) ?>
                  </td>
                  <td class="actions">
                    <?php
                    $mgmtPage = null;
                    if (in_array($row['category_slug'], ['booking', 'booking_refund'], true)) {
                        $bookingId = (int) ($row['booking_id'] ?? 0);
                        $mgmtPage = ['bookings.php' . ($bookingId ? ('?expand=' . $bookingId) : ''), 'Bookings'];
                    } elseif (in_array($row['category_slug'], ['investment', 'daily_credit', 'monthly_credit', 'investment_withdrawal', 'daily_debit', 'monthly_debit'], true)) {
                        $mgmtPage = ['investments.php', 'Investments'];
                    }
                    ?>
                    <?php if ($mgmtPage): ?>
                      <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/' . $mgmtPage[0])) ?>">Manage in <?= e($mgmtPage[1]) ?></a>
                    <?php else: ?>
                      <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/transactions.php?action=edit&id=' . $row['id'])) ?>">Edit</a>
                      <?php if (can_delete()): ?>
                      <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                        <button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete this transaction?">Delete</button>
                      </form>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="3">Total <?= $isCredit ? 'credit' : 'debit' ?></td>
                <td class="num <?= $isCredit ? 'text-success' : 'text-danger' ?>"><?= money($data['sum']) ?></td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
        <?php
          render_pager('transactions.php', [
              'total' => $data['total'],
              'page' => $data['page'],
              'pages' => $data['totalPages'],
              'from' => $data['from'] ?? 0,
              'to' => $data['to'] ?? 0,
              'page_param' => $pageParam,
          ], $anchor);
        ?>
      <?php endif; ?>
    </div>
    <?php
}

$action = get('action', 'list');
$id = (int) get('id', 0);
$q = get('q', '');
$filterCompany = (int) get('company_id', 0);
$filterProject = (int) get('project_id', 0);
$filterType = get('txn_type', '');
$filterFrom = get('from', '');
$filterTo = get('to', '');
$preCategory = (int) get('category_id', 0);
$preSlug = get('slug', '');
$preSection = get('section', '');

if ($preCategory <= 0 && $preSlug !== '') {
    $section = $preSection !== '' ? $preSection : 'credit';
    $preCategory = (int) (category_id_by_slug($pdo, $section, $preSlug) ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $postAction = post('action', '');

    if ($postAction === 'save') {
        $companyId = (int) post('company_id', 0);
        $projectId = post('project_id') !== '' ? (int) post('project_id') : null;
        $bankAccountId = post('bank_account_id') !== '' ? (int) post('bank_account_id') : null;
        $partnerId = post('partner_id') !== '' ? (int) post('partner_id') : null;
        $categoryId = (int) post('category_id', 0);
        $amount = (float) post('amount', 0);
        $txnDate = post('txn_date', date('Y-m-d'));
        $reference = post('reference_no', '');
        $payeeName = post('payee_name', '');
        $description = post('description', '');
        $editId = (int) post('id', 0);

        $cat = $pdo->prepare('SELECT section, slug FROM categories WHERE id = ?');
        $cat->execute([$categoryId]);
        $catRow = $cat->fetch();
        if (!$catRow) {
            flash('error', 'Invalid category.');
            redirect('pages/transactions.php?action=add');
        }
        $txnType = $catRow['section'] === 'credit' ? 'credit' : 'debit';

        if (!$companyId || !$categoryId || $amount <= 0) {
            flash('error', 'Company, category and a positive amount are required.');
            redirect('pages/transactions.php?action=add');
        }

        // Only attach partner on partner-related categories
        $partnerSlugs = ['partner', 'partner_capital', 'partner_advance', 'partner_capital_withdrawal', 'partner_advance_return'];
        if (!in_array($catRow['slug'], $partnerSlugs, true)) {
            $partnerId = null;
        }

        $userId = current_user()['id'] ?? null;
        $txnId = $editId;
        $before = null;

        if ($editId) {
            $beforeStmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ?');
            $beforeStmt->execute([$editId]);
            $before = $beforeStmt->fetch() ?: null;
            $stmt = $pdo->prepare('UPDATE transactions SET company_id=?, project_id=?, bank_account_id=?, category_id=?, partner_id=?, txn_type=?, amount=?, txn_date=?, reference_no=?, payee_name=?, description=? WHERE id=?');
            $stmt->execute([$companyId, $projectId, $bankAccountId, $categoryId, $partnerId, $txnType, $amount, $txnDate, $reference, $payeeName, $description, $editId]);
            audit_log($pdo, 'update', 'transaction', $editId, 'Updated txn #' . $editId . ' to ' . money($amount), $before, [
                'amount' => $amount, 'txn_date' => $txnDate, 'category_id' => $categoryId, 'description' => $description,
            ]);
            flash('success', 'Transaction updated.');
        } else {
            $txnId = create_transaction($pdo, $companyId, $categoryId, $txnType, $amount, $txnDate, $projectId, $bankAccountId, $partnerId, $reference, $description, $userId ? (int) $userId : null, null, null, $payeeName ?: null);
            audit_log($pdo, 'create', 'transaction', $txnId, 'Created ' . $txnType . ' ' . money($amount));
            flash('success', 'Transaction added.');
        }

        if (!empty($_FILES['attachments']) && $txnId) {
            $uploaded = save_transaction_uploads($pdo, (int) $txnId, $_FILES['attachments'], $userId ? (int) $userId : null);
            if ($uploaded > 0) {
                flash('success', ($editId ? 'Transaction updated' : 'Transaction added') . " with {$uploaded} attachment(s).");
            }
        }

        if ($partnerId) {
            sync_partner_invested($pdo, $partnerId);
            sync_partner_advance($pdo, $partnerId);
        }

        if ($projectId) {
            if (post('from', '') === 'entries' || get('from', '') === 'entries') {
                redirect('pages/project-entries.php?id=' . $projectId);
            }
            redirect('pages/project-view.php?id=' . $projectId);
        }
        redirect('pages/transactions.php');
    }

    if ($postAction === 'delete') {
        if (!can_delete()) {
            flash('error', 'Only admins can delete transactions.');
            redirect('pages/transactions.php');
        }
        $delId = (int) post('id', 0);
        $stmt = $pdo->prepare('SELECT partner_id FROM transactions WHERE id = ?');
        $stmt->execute([$delId]);
        $partnerId = $stmt->fetchColumn();
        // remove files
        $atts = $pdo->prepare('SELECT stored_name FROM attachments WHERE transaction_id = ?');
        $atts->execute([$delId]);
        foreach ($atts->fetchAll() as $att) {
            $path = uploads_dir() . '/' . $att['stored_name'];
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $pdo->prepare('DELETE FROM transactions WHERE id = ?')->execute([$delId]);
        audit_log($pdo, 'delete', 'transaction', $delId, 'Deleted transaction #' . $delId);
        if ($partnerId) {
            sync_partner_invested($pdo, (int) $partnerId);
            sync_partner_advance($pdo, (int) $partnerId);
        }
        flash('success', 'Transaction deleted.');
        redirect('pages/transactions.php');
    }
}

if ($action === 'add' || $action === 'edit') {
    $txn = null;
    if ($action === 'edit' && $id) {
        $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ?');
        $stmt->execute([$id]);
        $txn = $stmt->fetch();
        if (!$txn) {
            flash('error', 'Transaction not found.');
            redirect('pages/transactions.php');
        }
    }

    $preCompany = (int) ($txn['company_id'] ?? $filterCompany ?: 0);
    $preProject = (int) ($txn['project_id'] ?? $filterProject ?: 0);
    $selectedCategory = (int) ($txn['category_id'] ?? $preCategory ?: 0);

    // Categories for the Type (Credit/Debit) -> Category picker. Debit categories are grouped by
    // their original section (Land Purchase / Expense / General) via <optgroup> so nothing is hidden.
    // Booking/Booking Refund and Investment-related slugs are excluded — recorded exclusively via
    // their own dedicated pages (Bookings, Investments) so investor/customer info stays attached.
    $creditCats = [];
    $debitGroups = ['land_purchase' => [], 'expense' => [], 'general' => []];
    $catGroupStmt = $pdo->query(
        "SELECT id, name, section, slug FROM categories
         WHERE section IN ('credit','land_purchase','expense')
            OR (section = 'general' AND slug IN ('investment_withdrawal','daily_debit','monthly_debit','partner_capital_withdrawal','partner_advance_return'))
         ORDER BY FIELD(section,'credit','land_purchase','expense','general'), sort_order"
    );
    $excludedSlugs = ['booking', 'booking_refund', 'investment', 'daily_credit', 'monthly_credit', 'investment_withdrawal', 'daily_debit', 'monthly_debit'];
    foreach ($catGroupStmt->fetchAll() as $c) {
        if (in_array($c['slug'], $excludedSlugs, true)) {
            continue;
        }
        if ($c['section'] === 'credit') {
            $creditCats[] = ['id' => (int) $c['id'], 'name' => $c['name']];
        } else {
            $debitGroups[$c['section']][] = ['id' => (int) $c['id'], 'name' => $c['name']];
        }
    }
    $catData = ['credit' => $creditCats, 'debit' => $debitGroups];
    $debitGroupLabels = ['land_purchase' => 'Land Purchase', 'expense' => 'Expense', 'general' => 'General'];

    $selectedType = 'credit';
    if ($selectedCategory) {
        $secStmt = $pdo->prepare('SELECT section FROM categories WHERE id = ?');
        $secStmt->execute([$selectedCategory]);
        $selectedType = ($secStmt->fetchColumn() === 'credit') ? 'credit' : 'debit';
    }

    $pageTitle = $action === 'edit' ? 'Edit transaction' : 'Add transaction';
    $pageSub = 'Record credit (in) or debit (land / expense) against a company and project.';
    $fromEntries = get('from', '') === 'entries';
    $backHref = 'pages/transactions.php';
    $returnProjectId = (int) get('project_id', 0);
    if ($returnProjectId <= 0 && $fromEntries) {
        $returnProjectId = $preProject;
    }
    if ($returnProjectId > 0) {
        $backHref = $fromEntries
            ? 'pages/project-entries.php?id=' . $returnProjectId
            : 'pages/project-view.php?id=' . $returnProjectId;
    }
    $pageActions = '<a class="btn btn-outline" href="' . e(base_url($backHref)) . '">Back</a>';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="card">
      <form method="post" class="form-grid" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($txn['id'] ?? 0) ?>">
        <?php if ($fromEntries): ?>
        <input type="hidden" name="from" value="entries">
        <?php endif; ?>
        <div>
          <label>Company</label>
          <select name="company_id" id="company_id" required
            data-company-projects="project_id"
            data-company-accounts="bank_account_id"
            data-accounts-empty-label="Cash"
            data-company-partners="partner_id"
            data-projects-url="<?= e(base_url('api/projects.php')) ?>"
            data-accounts-url="<?= e(base_url('api/bank-accounts.php')) ?>"
            data-partners-url="<?= e(base_url('api/partners.php')) ?>">
            <?= company_options($pdo, $preCompany) ?>
          </select>
        </div>
        <div>
          <label>Project</label>
          <select name="project_id" id="project_id">
            <?= project_options($pdo, $preCompany ?: null, $preProject) ?>
          </select>
        </div>
        <div>
          <label>Type</label>
          <select id="txn_type_select">
            <option value="credit" <?= $selectedType === 'credit' ? 'selected' : '' ?>>Credit (money in)</option>
            <option value="debit" <?= $selectedType === 'debit' ? 'selected' : '' ?>>Debit (money out)</option>
          </select>
        </div>
        <div class="full">
          <label>Category</label>
          <select name="category_id" id="txn_category_id" required></select>
        </div>
        <div class="full">
          <label>Name (who is being paid / who this is from)</label>
          <input type="text" name="payee_name" value="<?= e($txn['payee_name'] ?? '') ?>">
        </div>
        <div>
          <label>Amount (₹)</label>
          <input type="number" step="0.01" min="0.01" name="amount" required value="<?= e((string) ($txn['amount'] ?? '')) ?>">
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
        <div>
          <label>Partner (for Partner credits)</label>
          <select name="partner_id" id="partner_id">
            <?= partner_options($pdo, $preCompany ?: null, (int) ($txn['partner_id'] ?? 0)) ?>
          </select>
        </div>
        <div class="full">
          <label>Description</label>
          <textarea name="description"><?= e($txn['description'] ?? '') ?></textarea>
        </div>
        <div class="full">
          <label>Attachments (PDF / images, max 5MB each)</label>
          <input type="file" name="attachments[]" accept=".pdf,image/*" multiple>
          <?php
          if (!empty($txn['id'])) {
              $attStmt = $pdo->prepare('SELECT * FROM attachments WHERE transaction_id = ? ORDER BY id');
              $attStmt->execute([(int)$txn['id']]);
              $existingAtts = $attStmt->fetchAll();
              if ($existingAtts) {
                  echo '<div style="margin-top:0.65rem;display:flex;flex-wrap:wrap;gap:0.45rem">';
                  foreach ($existingAtts as $att) {
                      echo '<a class="chip chip-primary" href="' . e(base_url('pages/attachment.php?id=' . $att['id'])) . '" target="_blank">' . e($att['original_name']) . '</a>';
                  }
                  echo '</div>';
              }
          }
          ?>
        </div>
        <div class="full highlight-box">
          Credit categories increase money in. Land purchase &amp; expense categories are debits.
          Linking a bank account updates its live balance. Partner field syncs partner invested totals.
        </div>
        <div class="full form-actions">
          <button class="btn btn-primary" type="submit">Save transaction</button>
        </div>
      </form>
    </div>
    <script>
      (function () {
        var CATEGORY_DATA = <?= json_encode($catData) ?>;
        var DEBIT_GROUP_LABELS = <?= json_encode($debitGroupLabels) ?>;
        var preselectId = <?= (int) $selectedCategory ?>;
        var typeEl = document.getElementById('txn_type_select');
        var categoryEl = document.getElementById('txn_category_id');

        function addOption(parent, opt, selectId, state) {
          var o = document.createElement('option');
          o.value = String(opt.id);
          o.textContent = opt.name;
          if (selectId && Number(selectId) === opt.id) {
            o.selected = true;
            state.matched = true;
          }
          parent.appendChild(o);
        }

        function populateCategories(selectId) {
          categoryEl.innerHTML = '';
          var placeholder = document.createElement('option');
          placeholder.value = '';
          placeholder.textContent = 'Select category';
          categoryEl.appendChild(placeholder);

          var state = { matched: false };
          if (typeEl.value === 'credit') {
            (CATEGORY_DATA.credit || []).forEach(function (opt) {
              addOption(categoryEl, opt, selectId, state);
            });
          } else {
            Object.keys(CATEGORY_DATA.debit || {}).forEach(function (key) {
              var items = CATEGORY_DATA.debit[key] || [];
              if (!items.length) return;
              var group = document.createElement('optgroup');
              group.label = DEBIT_GROUP_LABELS[key] || key;
              items.forEach(function (opt) {
                addOption(group, opt, selectId, state);
              });
              categoryEl.appendChild(group);
            });
          }
          if (!state.matched) placeholder.selected = true;
        }

        typeEl.addEventListener('change', function () { populateCategories(null); });
        populateCategories(preselectId);
      })();
    </script>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

[$fromMonth, $toMonth, $month, $year] = period_from_request();
if ($month && $filterFrom === '' && $filterTo === '') {
    $filterFrom = $fromMonth ?: '';
    $filterTo = $toMonth ?: '';
}

$pageTitle = 'Transactions';
$pageSub = 'Full ledger across companies and projects.';
$pageActions = report_export_buttons()
    . '<a class="btn btn-primary" href="' . e(base_url('pages/transactions.php?action=add')) . '">+ Add transaction</a>';

$emptyPage = ['rows' => [], 'total' => 0, 'sum' => 0.0, 'page' => 1, 'totalPages' => 1, 'from' => 0, 'to' => 0];
$creditPage = max(1, (int) get('credit_page', 1));
$debitPage = max(1, (int) get('debit_page', 1));
$perPage = list_page_limit();

$creditData = ($filterType === '' || $filterType === 'credit')
    ? fetch_txn_page($pdo, 'credit', $filterCompany, $filterProject, $filterFrom, $filterTo, $q, $creditPage, $perPage)
    : $emptyPage;
$debitData = ($filterType === '' || $filterType === 'debit')
    ? fetch_txn_page($pdo, 'debit', $filterCompany, $filterProject, $filterFrom, $filterTo, $q, $debitPage, $perPage)
    : $emptyPage;

$baseQueryParams = [
    'company_id' => $filterCompany ?: null,
    'project_id' => $filterProject ?: null,
    'txn_type' => $filterType ?: null,
    'from' => $filterFrom ?: null,
    'to' => $filterTo ?: null,
    'q' => $q !== '' ? $q : null,
    'month' => $month ?: null,
    'year' => $year ?: null,
    'credit_page' => $creditData['page'],
    'debit_page' => $debitData['page'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('export_action', ''), ['csv', 'excel', 'pdf'], true)) {
    $exportType = ($filterType === 'credit' || $filterType === 'debit') ? $filterType : '';
    $exportRows = fetch_txn_export($pdo, $exportType, $filterCompany, $filterProject, $filterFrom, $filterTo, $q);
    $creditTotal = 0.0;
    $debitTotal = 0.0;
    $ledgerRows = [];
    $byCategory = [];
    foreach ($exportRows as $i => $r) {
        $isCredit = ($r['txn_type'] ?? '') === 'credit';
        $amt = (float) $r['amount'];
        if ($isCredit) {
            $creditTotal += $amt;
        } else {
            $debitTotal += $amt;
        }
        $catKey = ($r['category_name'] ?? '—') . '|' . ($r['txn_type'] ?? '');
        if (!isset($byCategory[$catKey])) {
            $byCategory[$catKey] = [
                'category' => $r['category_name'] ?? '',
                'type' => $isCredit ? 'Credit' : 'Debit',
                'count' => 0,
                'amount' => 0.0,
            ];
        }
        $byCategory[$catKey]['count']++;
        $byCategory[$catKey]['amount'] += $amt;
        $bank = $r['account_name'] ? trim($r['account_name'] . ($r['bank_name'] ? ' - ' . $r['bank_name'] : '')) : '';
        $ledgerRows[] = [
            (string) ($i + 1),
            report_plain_date($r['txn_date'] ?? null),
            $isCredit ? 'Credit' : 'Debit',
            $r['company_name'] ?? '',
            $r['project_name'] ?? '',
            $r['category_name'] ?? '',
            $r['payee_name'] ?? '',
            $r['partner_name'] ?? '',
            $bank,
            $r['reference_no'] ?? '',
            $r['description'] ?? '',
            $isCredit ? $amt : null,
            $isCredit ? null : $amt,
        ];
    }
    $catRows = [];
    $n = 0;
    foreach ($byCategory as $info) {
        $n++;
        $catRows[] = [(string) $n, $info['category'], $info['type'], $info['count'], $info['amount']];
    }

    $companyName = 'All companies';
    if ($filterCompany) {
        $cn = $pdo->prepare('SELECT name FROM companies WHERE id = ?');
        $cn->execute([$filterCompany]);
        $companyName = (string) ($cn->fetchColumn() ?: 'Company #' . $filterCompany);
    }

    report_download(post('export_action'), [
        'filename' => 'transaction_ledger',
        'title' => 'Transaction Ledger',
        'orientation' => 'landscape',
        'meta' => [
            ['Period', report_display_period($filterFrom !== '' ? $filterFrom : $fromMonth, $filterTo !== '' ? $filterTo : $toMonth, $month, $year)],
            ['Company', $companyName],
            ['Type', $filterType === '' ? 'Credit and Debit' : ucfirst($filterType)],
            ['Search', $q !== '' ? $q : '—'],
        ],
        'summary' => [
            ['Total credit', $creditTotal, 'money'],
            ['Total debit', $debitTotal, 'money'],
            ['Net (credit − debit)', $creditTotal - $debitTotal, 'money'],
            ['Entries', count($exportRows), 'int'],
        ],
        'tables' => [
            [
                'title' => 'Ledger entries',
                'columns' => [
                    ['label' => 'Sr No', 'type' => 'text', 'width' => '4%', 'xls_width' => 35],
                    ['label' => 'Date', 'type' => 'text', 'width' => '8%', 'xls_width' => 80],
                    ['label' => 'Type', 'type' => 'text', 'width' => '7%', 'xls_width' => 60],
                    ['label' => 'Company', 'type' => 'text', 'width' => '11%', 'xls_width' => 120],
                    ['label' => 'Project', 'type' => 'text', 'width' => '10%', 'xls_width' => 110],
                    ['label' => 'Category', 'type' => 'text', 'width' => '10%', 'xls_width' => 110],
                    ['label' => 'Name / Payee', 'type' => 'text', 'width' => '10%', 'xls_width' => 110],
                    ['label' => 'Partner', 'type' => 'text', 'width' => '8%', 'xls_width' => 90],
                    ['label' => 'Bank account', 'type' => 'text', 'width' => '9%', 'xls_width' => 120],
                    ['label' => 'Ref', 'type' => 'text', 'width' => '6%', 'xls_width' => 70],
                    ['label' => 'Particulars', 'type' => 'text', 'width' => '9%', 'xls_width' => 140],
                    ['label' => 'Credit (INR)', 'type' => 'money', 'width' => '8%', 'xls_width' => 95],
                    ['label' => 'Debit (INR)', 'type' => 'money', 'width' => '8%', 'xls_width' => 95],
                ],
                'rows' => $ledgerRows,
                'totals' => ['', 'TOTAL', '', '', '', '', '', '', '', '', '', $creditTotal, $debitTotal],
            ],
            [
                'title' => 'Category totals',
                'columns' => [
                    ['label' => 'Sr No', 'type' => 'text', 'width' => '8%', 'xls_width' => 35],
                    ['label' => 'Category', 'type' => 'text', 'width' => '40%', 'xls_width' => 180],
                    ['label' => 'Type', 'type' => 'text', 'width' => '16%', 'xls_width' => 70],
                    ['label' => 'Entries', 'type' => 'int', 'width' => '14%', 'xls_width' => 70],
                    ['label' => 'Amount (INR)', 'type' => 'money', 'width' => '22%', 'xls_width' => 110],
                ],
                'rows' => $catRows,
                'totals' => ['', 'TOTAL', '', count($exportRows), $creditTotal + $debitTotal],
            ],
        ],
        'notes' => [
            'System-generated ledger. Credit and debit columns are exclusive per row.',
            'Net = total credit minus total debit for the selected filters.',
            'Confidential — internal use only.',
        ],
    ]);
    redirect('pages/transactions.php');
}

require __DIR__ . '/../includes/header.php';
?>

<form class="filters" method="get">
  <?= list_limit_hidden() ?>
  <?= period_filter_fields($month, $year) ?>
  <div class="field">
    <label>Search</label>
    <input type="search" name="q" value="<?= e($q) ?>">
  </div>
  <div class="field">
    <label>Company</label>
    <select name="company_id">
      <option value="">All</option>
      <?php foreach ($pdo->query('SELECT id, name FROM companies ORDER BY type, name') as $co): ?>
        <option value="<?= (int)$co['id'] ?>" <?= $filterCompany === (int)$co['id'] ? 'selected' : '' ?>><?= e($co['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($filterProject): ?>
    <input type="hidden" name="project_id" value="<?= $filterProject ?>">
  <?php endif; ?>
  <div class="field">
    <label>Type</label>
    <select name="txn_type">
      <option value="">All</option>
      <option value="credit" <?= $filterType === 'credit' ? 'selected' : '' ?>>Credit</option>
      <option value="debit" <?= $filterType === 'debit' ? 'selected' : '' ?>>Debit</option>
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

<div class="txn-split">
  <?php
  render_txn_column($creditData, 'credit', 'credit_page', $baseQueryParams);
  render_txn_column($debitData, 'debit', 'debit_page', $baseQueryParams);
  ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
