<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

const INVESTMENT_CATEGORY_SQL = "((cat.section = 'credit' AND cat.slug IN ('investment','daily_credit','monthly_credit')) OR (cat.section = 'general' AND cat.slug IN ('investment_withdrawal','daily_debit','monthly_debit','investment_interest_paid')))";
const INVESTMENT_FIXED_SLUGS = ['investment', 'investment_withdrawal'];
const INVESTMENT_REGULAR_SLUGS = ['daily_credit', 'monthly_credit', 'daily_debit', 'monthly_debit'];
const INVESTMENT_INTEREST_SLUG = 'investment_interest_paid';

/** The three ways an entry is recorded: money in, principal paid back, interest paid out. */
const INVESTMENT_MODES = ['invest', 'principal', 'interest'];

function investment_segment_label(string $slug): string
{
    if ($slug === INVESTMENT_INTEREST_SLUG) {
        return 'Interest';
    }
    return in_array($slug, INVESTMENT_FIXED_SLUGS, true) ? 'Fixed' : 'Regular';
}

/** invest = credit categories; principal = debit principal categories; interest = the interest-paid category. */
function investment_mode_for_entry(string $slug, string $txnType): string
{
    if ($slug === INVESTMENT_INTEREST_SLUG) {
        return 'interest';
    }
    return $txnType === 'credit' ? 'invest' : 'principal';
}

function investment_mode_title(string $mode): string
{
    return ['invest' => 'Add investment', 'principal' => 'Paid investment', 'interest' => 'Paid interest'][$mode] ?? 'Add investment';
}

/**
 * Per-investor running position: principal in/out per segment plus interest paid.
 * Pass $excludeTxnId when editing an entry so its own amount is not counted against itself.
 *
 * @return array<int,array{fixed:array{in:float,out:float},regular:array{in:float,out:float},interest:float}>
 */
function investor_balances(PDO $pdo, int $excludeTxnId = 0): array
{
    $sql = 'SELECT t.investor_id, cat.slug, t.txn_type, COALESCE(SUM(t.amount), 0) AS total
            FROM transactions t
            JOIN categories cat ON cat.id = t.category_id
            WHERE t.investor_id IS NOT NULL AND ' . INVESTMENT_CATEGORY_SQL;
    $params = [];
    if ($excludeTxnId > 0) {
        $sql .= ' AND t.id <> ?';
        $params[] = $excludeTxnId;
    }
    $sql .= ' GROUP BY t.investor_id, cat.slug, t.txn_type';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $iid = (int) $r['investor_id'];
        if (!isset($out[$iid])) {
            $out[$iid] = ['fixed' => ['in' => 0.0, 'out' => 0.0], 'regular' => ['in' => 0.0, 'out' => 0.0], 'interest' => 0.0];
        }
        $total = (float) $r['total'];
        if ($r['slug'] === INVESTMENT_INTEREST_SLUG) {
            $out[$iid]['interest'] += $total;
            continue;
        }
        $segment = in_array($r['slug'], INVESTMENT_FIXED_SLUGS, true) ? 'fixed' : 'regular';
        $out[$iid][$segment][$r['txn_type'] === 'credit' ? 'in' : 'out'] += $total;
    }
    return $out;
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

/** Formal investment register payload — not a copy of the on-screen drill-down. */
function investment_register_report(array $exportRows, array $meta, string $scopeNote): array
{
    $exportIn = 0.0;
    $exportOut = 0.0;
    $exportInterest = 0.0;
    $ledgerRows = [];
    $segments = [
        'Fixed' => ['count' => 0, 'in' => 0.0, 'out' => 0.0, 'interest' => 0.0],
        'Regular' => ['count' => 0, 'in' => 0.0, 'out' => 0.0, 'interest' => 0.0],
        'Interest' => ['count' => 0, 'in' => 0.0, 'out' => 0.0, 'interest' => 0.0],
    ];
    foreach ($exportRows as $i => $r) {
        $isCredit = ($r['txn_type'] ?? '') === 'credit';
        $isInterestPaid = ($r['category_slug'] ?? '') === INVESTMENT_INTEREST_SLUG;
        $amt = (float) $r['amount'];
        // Interest-paid entries carry their value in `amount`; other entries use the optional interest field.
        if ($isInterestPaid) {
            $interest = $amt;
        } else {
            $interest = $r['interest_amount'] !== null && $r['interest_amount'] !== '' ? (float) $r['interest_amount'] : null;
        }
        $principalIn = (!$isInterestPaid && $isCredit) ? $amt : 0.0;
        $principalOut = (!$isInterestPaid && !$isCredit) ? $amt : 0.0;
        $exportIn += $principalIn;
        $exportOut += $principalOut;
        if ($interest !== null) {
            $exportInterest += $interest;
        }
        $segment = investment_segment_label((string) ($r['category_slug'] ?? ''));
        if (!isset($segments[$segment])) {
            $segments[$segment] = ['count' => 0, 'in' => 0.0, 'out' => 0.0, 'interest' => 0.0];
        }
        $segments[$segment]['count']++;
        $segments[$segment]['in'] += $principalIn;
        $segments[$segment]['out'] += $principalOut;
        $segments[$segment]['interest'] += $interest ?? 0;
        $bank = $r['account_name'] ? trim($r['account_name'] . ($r['bank_name'] ? ' - ' . $r['bank_name'] : '')) : 'Cash';
        $ledgerRows[] = [
            (string) ($i + 1),
            report_plain_date($r['txn_date'] ?? null),
            $r['investor_name'] ?? '',
            $segment,
            $r['company_name'] ?? '',
            $r['project_name'] ?? '',
            $r['category_name'] ?? '',
            $isInterestPaid ? 'Interest paid' : ($isCredit ? 'Invested' : 'Withdrawn'),
            $bank,
            $r['reference_no'] ?? '',
            $r['description'] ?? '',
            $principalIn > 0 ? $principalIn : null,
            $principalOut > 0 ? $principalOut : null,
            $interest,
        ];
    }
    $segRows = [];
    $n = 0;
    foreach ($segments as $name => $info) {
        if ($info['count'] === 0) {
            continue;
        }
        $n++;
        $segRows[] = [
            (string) $n,
            $name,
            $info['count'],
            $info['in'],
            $info['out'],
            $info['interest'],
            $info['in'] - $info['out'],
        ];
    }
    return [
        'filename' => 'investment_register',
        'title' => 'Investment Register',
        'orientation' => 'landscape',
        'meta' => $meta,
        'summary' => [
            ['Total invested', $exportIn, 'money'],
            ['Total withdrawn', $exportOut, 'money'],
            ['Interest', $exportInterest, 'money'],
            ['Net investment', $exportIn - $exportOut, 'money'],
        ],
        'tables' => [
            [
                'title' => 'Investment ledger',
                'columns' => [
                    ['label' => 'Sr No', 'type' => 'text', 'width' => '4%', 'xls_width' => 35],
                    ['label' => 'Date', 'type' => 'text', 'width' => '8%', 'xls_width' => 80],
                    ['label' => 'Investor', 'type' => 'text', 'width' => '11%', 'xls_width' => 120],
                    ['label' => 'Segment', 'type' => 'text', 'width' => '7%', 'xls_width' => 70],
                    ['label' => 'Company', 'type' => 'text', 'width' => '10%', 'xls_width' => 120],
                    ['label' => 'Project', 'type' => 'text', 'width' => '9%', 'xls_width' => 110],
                    ['label' => 'Category', 'type' => 'text', 'width' => '9%', 'xls_width' => 110],
                    ['label' => 'Type', 'type' => 'text', 'width' => '8%', 'xls_width' => 70],
                    ['label' => 'Account', 'type' => 'text', 'width' => '9%', 'xls_width' => 120],
                    ['label' => 'Ref', 'type' => 'text', 'width' => '6%', 'xls_width' => 70],
                    ['label' => 'Particulars', 'type' => 'text', 'width' => '9%', 'xls_width' => 140],
                    ['label' => 'Invested (INR)', 'type' => 'money', 'width' => '8%', 'xls_width' => 100],
                    ['label' => 'Withdrawn (INR)', 'type' => 'money', 'width' => '8%', 'xls_width' => 100],
                    ['label' => 'Interest (INR)', 'type' => 'money', 'width' => '7%', 'xls_width' => 90],
                ],
                'rows' => $ledgerRows,
                'totals' => ['', 'TOTAL', '', '', '', '', '', '', '', '', '', $exportIn, $exportOut, $exportInterest],
            ],
            [
                'title' => 'Segment totals',
                'columns' => [
                    ['label' => 'Sr No', 'type' => 'text', 'width' => '8%', 'xls_width' => 40],
                    ['label' => 'Segment', 'type' => 'text', 'width' => '16%', 'xls_width' => 90],
                    ['label' => 'Entries', 'type' => 'int', 'width' => '12%', 'xls_width' => 70],
                    ['label' => 'Invested (INR)', 'type' => 'money', 'width' => '16%', 'xls_width' => 110],
                    ['label' => 'Withdrawn (INR)', 'type' => 'money', 'width' => '16%', 'xls_width' => 110],
                    ['label' => 'Interest (INR)', 'type' => 'money', 'width' => '16%', 'xls_width' => 110],
                    ['label' => 'Net (INR)', 'type' => 'money', 'width' => '16%', 'xls_width' => 110],
                ],
                'rows' => $segRows,
                'totals' => ['', 'TOTAL', count($exportRows), $exportIn, $exportOut, $exportInterest, $exportIn - $exportOut],
            ],
        ],
        'notes' => [
            $scopeNote,
            'System-generated investment register. Fixed = long-term capital; Regular = daily/monthly movement.',
            'Confidential — internal use only.',
        ],
    ];
}

/** Renders the company -> transaction -> detail drill-down table for one section. */
function render_investment_companies(array $companies, array $attachmentsByTxn, string $idPrefix, string $kind = 'principal'): void
{
    if (!$companies) {
        $emptyNote = $kind === 'interest'
            ? 'No interest payments recorded for the current filters.'
            : 'No entries in this section for the current filters.';
        echo '<div class="empty"><strong>Nothing here yet</strong><p>' . e($emptyNote) . '</p></div>';
        return;
    }
    $isInterest = $kind === 'interest';
    $colspan = $isInterest ? 3 : 5;
    ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>Company</th>
            <th>Entries</th>
            <?php if ($isInterest): ?>
              <th class="num">Interest paid</th>
            <?php else: ?>
              <th class="num">Invested</th>
              <th class="num">Withdrawn</th>
              <th class="num">Net</th>
            <?php endif; ?>
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
              <?php if ($isInterest): ?>
                <td class="num text-danger"><?= money($co['out']) ?></td>
              <?php else: ?>
                <td class="num text-success"><?= money($co['in']) ?></td>
                <td class="num text-danger"><?= money($co['out']) ?></td>
                <td class="num <?= $net >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($net) ?></td>
              <?php endif; ?>
            </tr>
            <tr class="row-detail" id="<?= e($coDetailId) ?>" hidden>
              <td colspan="<?= $colspan ?>">
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
                          <td><span class="row-caret">▸</span><?= e(format_date($row['txn_date'])) ?></td>
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


$action = get('action', 'list');

/** Categories each mode may use. Always matched on section + slug: the 'investment' slug exists in two sections. */
const INVESTMENT_MODE_CATEGORIES = [
    'invest'    => ['section' => 'credit',  'fixed' => ['investment'],            'regular' => ['daily_credit', 'monthly_credit'], 'interest' => []],
    'principal' => ['section' => 'general', 'fixed' => ['investment_withdrawal'], 'regular' => ['daily_debit', 'monthly_debit'],   'interest' => []],
    'interest'  => ['section' => 'general', 'fixed' => [],                        'regular' => [],                                 'interest' => [INVESTMENT_INTEREST_SLUG]],
];

/** @return array{fixed:list<array>,regular:list<array>,interest:list<array>} */
function investment_mode_categories(PDO $pdo, string $mode): array
{
    $spec = INVESTMENT_MODE_CATEGORIES[$mode];
    $slugs = array_merge($spec['fixed'], $spec['regular'], $spec['interest']);
    $ph = implode(',', array_fill(0, count($slugs), '?'));
    $stmt = $pdo->prepare("SELECT id, name, slug FROM categories WHERE section = ? AND slug IN ($ph) ORDER BY sort_order, id");
    $stmt->execute(array_merge([$spec['section']], $slugs));
    $out = ['fixed' => [], 'regular' => [], 'interest' => []];
    foreach ($stmt->fetchAll() as $c) {
        if (in_array($c['slug'], $spec['fixed'], true)) {
            $segment = 'fixed';
        } elseif (in_array($c['slug'], $spec['regular'], true)) {
            $segment = 'regular';
        } else {
            $segment = 'interest';
        }
        $out[$segment][] = ['id' => (int) $c['id'], 'name' => $c['name'], 'slug' => $c['slug']];
    }
    return $out;
}

/** Loads a ledger entry only if it really is an investment entry (so this page cannot edit other transactions). */
function investment_entry_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT t.*, cat.slug AS category_slug
         FROM transactions t
         JOIN categories cat ON cat.id = t.category_id
         WHERE t.id = ? AND ' . INVESTMENT_CATEGORY_SQL
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action', '') === 'save_investment') {
    verify_csrf();
    $editId = (int) post('id', 0);
    $before = null;
    if ($editId) {
        $before = investment_entry_by_id($pdo, $editId);
        if (!$before) {
            flash('error', 'Entry not found.');
            redirect('pages/investments.php');
        }
        $mode = investment_mode_for_entry((string) $before['category_slug'], (string) $before['txn_type']);
    } else {
        $mode = (string) post('mode', 'invest');
        if (!in_array($mode, INVESTMENT_MODES, true)) {
            $mode = 'invest';
        }
    }
    $backUrl = 'pages/investments.php?action=' . ($editId ? 'edit&id=' . $editId : 'add&mode=' . $mode);

    $investorId = (int) post('investor_id', 0);
    $companyId = (int) post('company_id', 0);
    $projectId = post('project_id', '') !== '' ? (int) post('project_id', '') : null;
    $categoryId = (int) post('category_id', 0);
    $amount = (float) post('amount', 0);
    $txnDate = (string) post('txn_date', date('Y-m-d'));
    $bankAccountId = post('bank_account_id', '') !== '' ? (int) post('bank_account_id', '') : null;
    // The form has no reference field; keep whatever is already stored when editing.
    $reference = $before ? (string) ($before['reference_no'] ?? '') : '';
    $description = (string) post('description', '');
    $investorName = (string) post('investor_name', '');
    $investorPhone = (string) post('investor_phone', '');
    $investorEmail = (string) post('investor_email', '');
    $investorAddress = (string) post('investor_address', '');

    // The optional interest field only exists on "Add investment"; other modes keep what is stored.
    $interestAmount = null;
    if ($mode === 'invest') {
        $interestAmount = post('interest_amount', '') !== '' ? (float) post('interest_amount', '') : null;
    } elseif ($mode === 'principal' && $before && $before['interest_amount'] !== null) {
        $interestAmount = (float) $before['interest_amount'];
    }

    $modeCats = investment_mode_categories($pdo, $mode);
    $categoryOk = false;
    if ($mode === 'interest') {
        // One fixed category: the interest-paid category is resolved here, never taken from the form.
        $categoryId = (int) ($modeCats['interest'][0]['id'] ?? 0);
        $categoryOk = $categoryId > 0;
    } else {
        foreach (['fixed', 'regular'] as $seg) {
            foreach ($modeCats[$seg] as $c) {
                if ($c['id'] === $categoryId) {
                    $categoryOk = true;
                }
            }
        }
    }

    if (!$categoryOk || !$companyId || $amount <= 0) {
        flash('error', 'Category, company and a positive amount are required.');
        redirect($backUrl);
    }

    if ($mode === 'invest') {
        if ($investorName === '') {
            flash('error', 'Investor name is required.');
            redirect($backUrl);
        }
    } else {
        // Paying money out only makes sense to someone who already invested.
        $invStmt = $pdo->prepare('SELECT id, name FROM investors WHERE id = ?');
        $invStmt->execute([$investorId]);
        $investorRow = $invStmt->fetch();
        if (!$investorRow) {
            flash('error', 'Select the investor this payment is for.');
            redirect($backUrl);
        }
    }

    if ($mode === 'principal') {
        // Block paying back more principal than the investor still has outstanding.
        // Skipped when an existing entry is re-saved with the same investor and amount (keeps old data editable).
        $unchanged = $before
            && (int) $before['investor_id'] === $investorId
            && abs((float) $before['amount'] - $amount) < 0.005;
        if (!$unchanged) {
            $bal = investor_balances($pdo, $editId)[$investorId] ?? null;
            $invested = $bal ? $bal['fixed']['in'] + $bal['regular']['in'] : 0.0;
            $paidBack = $bal ? $bal['fixed']['out'] + $bal['regular']['out'] : 0.0;
            $outstanding = round($invested - $paidBack, 2);
            if ($amount - $outstanding > 0.005) {
                flash('error', 'Paid investment ' . money($amount) . ' is more than the ' . money(max(0, $outstanding)) . ' principal still outstanding for ' . $investorRow['name'] . '.');
                redirect($backUrl);
            }
        }
    }

    if ($mode === 'invest') {
        if ($investorId) {
            $pdo->prepare('UPDATE investors SET name=?, phone=?, email=?, address=? WHERE id=?')
                ->execute([$investorName, $investorPhone, $investorEmail, $investorAddress, $investorId]);
        } else {
            $iIns = $pdo->prepare('INSERT INTO investors (name, phone, email, address) VALUES (?,?,?,?)');
            $iIns->execute([$investorName, $investorPhone, $investorEmail, $investorAddress]);
            $investorId = (int) $pdo->lastInsertId();
        }
    }

    $txnType = $mode === 'invest' ? 'credit' : 'debit';
    $userId = current_user()['id'] ?? null;
    $modeLabel = ['invest' => 'investment', 'principal' => 'paid investment', 'interest' => 'paid interest'][$mode];

    if ($editId) {
        $stmt = $pdo->prepare('UPDATE transactions SET company_id=?, project_id=?, bank_account_id=?, category_id=?, investor_id=?, interest_amount=?, txn_type=?, amount=?, txn_date=?, reference_no=?, description=? WHERE id=?');
        $stmt->execute([$companyId, $projectId, $bankAccountId, $categoryId, $investorId, $interestAmount, $txnType, $amount, $txnDate, $reference, $description, $editId]);
        audit_log($pdo, 'update', 'transaction', $editId, 'Updated ' . $modeLabel . ' #' . $editId . ' to ' . money($amount), $before, [
            'amount' => $amount, 'txn_date' => $txnDate, 'category_id' => $categoryId, 'investor_id' => $investorId,
        ]);
        flash('success', ucfirst($modeLabel) . ' entry updated.');
    } else {
        $newId = create_transaction(
            $pdo, $companyId, $categoryId, $txnType, $amount, $txnDate,
            $projectId, $bankAccountId, null, $reference, $description,
            $userId ? (int) $userId : null, $investorId, $interestAmount
        );
        audit_log($pdo, 'create', 'transaction', $newId, 'Recorded ' . $modeLabel . ' ' . money($amount) . ' for investor #' . $investorId);
        flash('success', ['invest' => 'Investment entry added.', 'principal' => 'Paid investment recorded.', 'interest' => 'Paid interest recorded.'][$mode]);
    }
    redirect('pages/investments.php');
}

if ($action === 'add' || $action === 'edit') {
    $txn = null;
    $mode = (string) get('mode', 'invest');
    if ($action === 'edit' && $id = (int) get('id', 0)) {
        $txn = investment_entry_by_id($pdo, $id);
        if (!$txn) {
            flash('error', 'Entry not found.');
            redirect('pages/investments.php');
        }
        $mode = investment_mode_for_entry((string) $txn['category_slug'], (string) $txn['txn_type']);
    }
    if (!in_array($mode, INVESTMENT_MODES, true)) {
        $mode = 'invest';
    }
    $isEdit = $txn !== null;

    $investors = $pdo->query('SELECT id, name, phone, email, address FROM investors ORDER BY name')->fetchAll();
    $preInvestorId = (int) ($txn['investor_id'] ?? get('investor_id', 0));
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

    // Live balances for the "Paid …" forms; an entry being edited is left out so it is not counted against itself.
    $balances = $mode === 'invest' ? [] : investor_balances($pdo, $txn ? (int) $txn['id'] : 0);

    $modeCats = investment_mode_categories($pdo, $mode);
    $categoryMap = ['fixed' => $modeCats['fixed'], 'regular' => $modeCats['regular']];
    $preSegment = 'fixed';
    $preCategoryId = 0;
    if ($txn) {
        $preSegment = in_array($txn['category_slug'], INVESTMENT_FIXED_SLUGS, true) ? 'fixed' : 'regular';
        $preCategoryId = (int) $txn['category_id'];
    } else {
        $preCategoryId = (int) ($categoryMap[$preSegment][0]['id'] ?? 0);
    }

    $preCompany = (int) ($txn['company_id'] ?? 0);
    $preProject = (int) ($txn['project_id'] ?? 0);

    $titles = [
        'invest' => ['Add investment entry', 'Edit investment entry', 'Investor, type and category, then company, amount and date.'],
        'principal' => ['Paid investment', 'Edit paid investment', 'Principal paid back to an investor. Their current balance is shown once you pick them.'],
        'interest' => ['Paid interest', 'Edit paid interest', 'Interest paid to an investor. Tracked separately, so it never reduces the invested principal.'],
    ];
    $pageTitle = $titles[$mode][$isEdit ? 1 : 0];
    $pageSub = $titles[$mode][2];
    $pageActions = '<a class="btn btn-outline" href="' . e(base_url('pages/investments.php')) . '">Back</a>';
    require __DIR__ . '/../includes/header.php';
    ?>
    <?php if ($mode !== 'invest'): ?>
    <?php /* Full page width, outside the narrow form card; filled in by the script below once an investor is picked. */ ?>
    <div id="investor_balance" style="margin-bottom:1rem">
      <p class="muted" id="balance_empty" style="margin:0 0 0.35rem;font-size:0.85rem">Select an investor below to see their balance.</p>
      <div id="balance_grid" class="stat-grid" style="margin:0;display:none">
        <div class="stat-card"><div class="stat-label">Invested</div><div class="stat-value text-success" id="bal_invested">—</div></div>
        <div class="stat-card"><div class="stat-label">Paid back</div><div class="stat-value text-danger" id="bal_paid">—</div></div>
        <div class="stat-card"><div class="stat-label">Outstanding principal</div><div class="stat-value" id="bal_outstanding">—</div></div>
        <div class="stat-card"><div class="stat-label">Interest paid</div><div class="stat-value" id="bal_interest">—</div></div>
      </div>
      <p class="muted" id="bal_split" style="font-size:0.78rem;margin:0.5rem 0 0;display:none"></p>
    </div>
    <?php endif; ?>
    <div class="card" style="max-width:820px">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_investment">
        <input type="hidden" name="mode" value="<?= e($mode) ?>">
        <input type="hidden" name="id" value="<?= (int) ($txn['id'] ?? 0) ?>">

        <div>
          <label>Investor</label>
          <?php if ($mode === 'invest'): ?>
            <select name="investor_id" id="investor_select">
              <option value="">+ New investor</option>
              <?php foreach ($investors as $inv): ?>
                <option value="<?= (int) $inv['id'] ?>" <?= $preInvestorId === (int) $inv['id'] ? 'selected' : '' ?>>
                  <?= e($inv['name']) ?><?= $inv['phone'] ? ' — ' . e($inv['phone']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <select name="investor_id" id="investor_select" required>
              <option value="">Select investor…</option>
              <?php foreach ($investors as $inv): ?>
                <option value="<?= (int) $inv['id'] ?>" <?= $preInvestorId === (int) $inv['id'] ? 'selected' : '' ?>>
                  <?= e($inv['name']) ?><?= $inv['phone'] ? ' — ' . e($inv['phone']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
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

        <?php if ($mode === 'invest'): ?>
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
        <?php endif; ?>

        <div>
          <label>Project (optional)</label>
          <select name="project_id" id="project_id">
            <?= project_options($pdo, $preCompany ?: null, $preProject) ?>
          </select>
        </div>
        <?php if ($mode === 'interest'): ?>
          <div>
            <label>Category</label>
            <input type="text" value="Investment Interest Paid" disabled>
          </div>
        <?php else: ?>
          <div>
            <label>Investment type</label>
            <select name="segment" id="inv_segment">
              <option value="fixed" <?= $preSegment === 'fixed' ? 'selected' : '' ?>>Fixed Investment</option>
              <option value="regular" <?= $preSegment === 'regular' ? 'selected' : '' ?>>Regular Investment</option>
            </select>
          </div>
          <div class="full">
            <label>Category</label>
            <select name="category_id" id="inv_category" required></select>
          </div>
        <?php endif; ?>
        <div>
          <label><?= $mode === 'invest' ? 'Amount (₹)' : ($mode === 'principal' ? 'Principal paid (₹)' : 'Interest paid (₹)') ?></label>
          <input type="number" step="0.01" min="0.01" name="amount" id="inv_amount" required value="<?= e((string) ($txn['amount'] ?? '')) ?>">
          <p class="text-danger" id="amount_warning" style="display:none;font-size:0.8rem;font-weight:600;margin:0.4rem 0 0"></p>
        </div>
        <?php if ($mode === 'invest'): ?>
          <div>
            <label>Interest (₹, optional)</label>
            <input type="number" step="0.01" min="0" name="interest_amount" value="<?= e((string) ($txn['interest_amount'] ?? '')) ?>">
          </div>
        <?php endif; ?>
        <div>
          <label>Date</label>
          <input type="date" name="txn_date" required value="<?= e($txn['txn_date'] ?? date('Y-m-d')) ?>">
        </div>
        <div>
          <label><?= $mode === 'invest' ? 'Bank account (optional)' : 'Paid from bank account (optional)' ?></label>
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
        var MODE = <?= json_encode($mode) ?>;
        var CATEGORY_MAP = <?= json_encode($categoryMap) ?>;
        var INVESTOR_DETAILS = <?= json_encode((object) $investorDetailsMap) ?>;
        var BALANCES = <?= json_encode((object) $balances) ?>;
        var preselectId = <?= (int) $preCategoryId ?>;

        function $(id) { return document.getElementById(id); }
        function fmt(n) {
          return '₹' + Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        var investorSelect = $('investor_select');
        var segmentEl = $('inv_segment');
        var categoryEl = $('inv_category');
        var amountEl = $('inv_amount');

        function populateCategories(selectId) {
          if (!segmentEl || !categoryEl) return;
          categoryEl.innerHTML = '';
          (CATEGORY_MAP[segmentEl.value] || []).forEach(function (opt) {
            var o = document.createElement('option');
            o.value = String(opt.id);
            o.textContent = opt.name;
            if (selectId && Number(selectId) === opt.id) o.selected = true;
            categoryEl.appendChild(o);
          });
        }

        // "Add investment": picking an investor loads their saved details for editing.
        function fillInvestorFields() {
          var d = INVESTOR_DETAILS[investorSelect.value];
          var has = !!(investorSelect.value && d);
          $('investor_name').value = has ? (d.name || '') : '';
          $('investor_phone').value = has ? (d.phone || '') : '';
          $('investor_email').value = has ? (d.email || '') : '';
          $('investor_address').value = has ? (d.address || '') : '';
          $('investor_fields_hint').style.display = has ? '' : 'none';
        }

        // "Paid investment" / "Paid interest": show where the selected investor stands.
        function balanceFor(iid) {
          return BALANCES[iid] || { fixed: { 'in': 0, out: 0 }, regular: { 'in': 0, out: 0 }, interest: 0 };
        }
        function outstandingFor(iid) {
          var b = balanceFor(iid);
          return (b.fixed['in'] + b.regular['in']) - (b.fixed.out + b.regular.out);
        }
        function checkAmount() {
          var warn = $('amount_warning');
          if (!warn || MODE !== 'principal') return;
          var iid = investorSelect.value;
          var amt = parseFloat(amountEl.value) || 0;
          var outstanding = outstandingFor(iid);
          if (iid && amt - outstanding > 0.005) {
            warn.textContent = 'More than the ' + fmt(Math.max(0, outstanding)) + ' principal outstanding for this investor — it will be rejected.';
            warn.style.display = '';
          } else {
            warn.style.display = 'none';
          }
        }
        function renderBalance() {
          var grid = $('balance_grid');
          if (!grid) return;
          var iid = investorSelect.value;
          var empty = $('balance_empty');
          var split = $('bal_split');
          if (!iid) {
            empty.style.display = '';
            grid.style.display = 'none';
            split.style.display = 'none';
            checkAmount();
            return;
          }
          var b = balanceFor(iid);
          var invested = b.fixed['in'] + b.regular['in'];
          var paid = b.fixed.out + b.regular.out;
          $('bal_invested').textContent = fmt(invested);
          $('bal_paid').textContent = fmt(paid);
          $('bal_outstanding').textContent = fmt(invested - paid);
          $('bal_interest').textContent = fmt(b.interest);
          split.textContent = 'Outstanding — Fixed ' + fmt(b.fixed['in'] - b.fixed.out) + ' · Regular ' + fmt(b.regular['in'] - b.regular.out);
          empty.style.display = 'none';
          grid.style.display = '';
          split.style.display = '';
          checkAmount();
        }

        if (segmentEl) {
          segmentEl.addEventListener('change', function () { populateCategories(null); });
          populateCategories(preselectId);
        }
        if (MODE === 'invest') {
          investorSelect.addEventListener('change', fillInvestorFields);
        } else {
          investorSelect.addEventListener('change', renderBalance);
          if (amountEl) amountEl.addEventListener('input', checkAmount);
          renderBalance();
        }
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
$q = trim(get('q', ''));

$pageTitle = 'Investment';
$pageSub = 'Fixed, long-term capital and regular (daily/monthly) investment movement — by investor and company.';
$pageActions = report_export_buttons()
    . '<a class="btn btn-primary" href="' . e(base_url('pages/investments.php?action=add&mode=invest')) . '">+ Add investment</a>'
    . '<a class="btn btn-outline" href="' . e(base_url('pages/investments.php?action=add&mode=principal')) . '">Paid investment</a>'
    . '<a class="btn btn-outline" href="' . e(base_url('pages/investments.php?action=add&mode=interest')) . '">Paid interest</a>';

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
if ($q !== '') {
    $sql .= ' AND (inv.name LIKE ? OR inv.phone LIKE ? OR c.name LIKE ? OR p.name LIKE ? OR cat.name LIKE ?
                OR ba.account_name LIKE ? OR ba.bank_name LIKE ? OR t.reference_no LIKE ? OR t.description LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
}
apply_date_range($sql, $params, $filterFrom !== '' ? $filterFrom : null, $filterTo !== '' ? $filterTo : null);
$sql .= ' ORDER BY t.txn_date DESC, t.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
// Interest paid is its own bucket: it never counts as "withdrawn" or reduces the net invested principal.
$isInterestRow = fn($r) => $r['category_slug'] === INVESTMENT_INTEREST_SLUG;
$interestRows = array_values(array_filter($rows, $isInterestRow));
$principalRows = array_values(array_filter($rows, fn($r) => !$isInterestRow($r)));
$totalIn = array_sum(array_map(fn($r) => $r['txn_type'] === 'credit' ? (float)$r['amount'] : 0, $principalRows));
$totalOut = array_sum(array_map(fn($r) => $r['txn_type'] === 'debit' ? (float)$r['amount'] : 0, $principalRows));
$interestPaid = array_sum(array_map(fn($r) => (float) $r['amount'], $interestRows));
// Plus the optional interest figure that older entries may carry on the investment itself.
$totalInterest = $interestPaid + array_sum(array_map(fn($r) => (float) ($r['interest_amount'] ?? 0), $principalRows));

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
$interestCompanies = group_investment_rows_by_company($interestRows);
$interestInvestorCount = count(array_unique(array_filter(array_map(fn($r) => (int) ($r['investor_id'] ?? 0), $interestRows))));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('export_action', ''), ['csv', 'excel', 'pdf'], true)) {
    verify_csrf();
    $selectedIds = array_values(array_unique(array_filter(array_map('intval', $_POST['txn_ids'] ?? []), fn($id) => $id > 0)));
    $companyName = 'All companies';
    if ($filterCompany) {
        $cn = $pdo->prepare('SELECT name FROM companies WHERE id = ?');
        $cn->execute([$filterCompany]);
        $companyName = (string) ($cn->fetchColumn() ?: 'Company #' . $filterCompany);
    }
    $investorName = 'All investors';
    if ($filterInvestor) {
        $in = $pdo->prepare('SELECT name FROM investors WHERE id = ?');
        $in->execute([$filterInvestor]);
        $investorName = (string) ($in->fetchColumn() ?: 'Investor #' . $filterInvestor);
    }
    $period = report_display_period($filterFrom !== '' ? $filterFrom : null, $filterTo !== '' ? $filterTo : null, $month, $year);
    if ($selectedIds) {
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
             ORDER BY t.txn_date ASC, t.id ASC"
        );
        $expStmt->execute($selectedIds);
        $exportRows = $expStmt->fetchAll();
        if (!$exportRows) {
            flash('error', 'Selected transactions were not found.');
            redirect('pages/investments.php');
        }
        $scopeNote = 'Figures reflect the ' . count($exportRows) . ' selected entries at export time.';
        $meta = [
            ['Scope', 'Selected entries'],
            ['Entries', (string) count($exportRows)],
        ];
    } else {
        $exportRows = $rows;
        $scopeNote = 'Figures match the current filters at export time.';
        $meta = [
            ['Period', $period],
            ['Company', $companyName],
            ['Investor', $investorName],
            ['Entries', (string) count($exportRows)],
        ];
    }
    report_download(post('export_action'), investment_register_report($exportRows, $meta, $scopeNote));
    redirect('pages/investments.php');
}

$fixedList = paginate_list(array_values($fixedCompanies), null, 'fixed_page');
$regularList = paginate_list(array_values($regularCompanies), null, 'regular_page');
$interestList = paginate_list(array_values($interestCompanies), null, 'interest_page');

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
    <div class="stat-label">Interest paid</div>
    <div class="stat-value"><?= money($totalInterest) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Net investment</div>
    <div class="stat-value <?= ($totalIn - $totalOut) >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($totalIn - $totalOut) ?></div>
  </div>
</div>
<form class="filters" method="get">
  <?= list_limit_hidden() ?>
  <div class="field list-search">
    <label for="investments-q">Search investments</label>
    <div class="list-search-box">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
      <input id="investments-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Search by investor, phone, company, project, bank, reference or description…" autocomplete="off">
    </div>
  </div>
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

<?php if ($rows): ?>
  <div class="card-head" id="list" style="justify-content:flex-end;margin-bottom:0.35rem">
    <?php render_limit_control('investments.php'); ?>
  </div>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="card">
    <div class="empty"><strong><?= $q !== '' ? 'No matches' : 'No investments yet' ?></strong><p><?= $q !== '' ? 'No entries match “' . e($q) . '” with the current filters.' : 'Add an investment, then record any paid investment or paid interest against it.' ?></p></div>
  </div>
<?php else: ?>
  <form id="investmentsExportForm" class="bulk-export-form" method="post" action="<?= e(base_url('pages/investments.php' . ($filterCompany || $filterFrom || $filterTo || $q !== '' ? '?' . http_build_query(['company_id' => $filterCompany ?: null, 'from' => $filterFrom ?: null, 'to' => $filterTo ?: null, 'q' => $q ?: null]) : ''))) ?>">
    <?= csrf_field() ?>
    <div class="export-toolbar no-print">
      <label class="select-all-label">
        <input type="checkbox" class="select-all-toggle">
        Select all
      </label>
      <span class="selected-count muted">0 selected</span>
      <div class="export-actions">
        <button class="btn btn-outline btn-sm export-csv-btn" type="submit" name="export_action" value="csv" disabled>Export CSV</button>
        <button class="btn btn-outline btn-sm export-excel-btn" type="submit" name="export_action" value="excel" disabled>Export Excel</button>
        <button class="btn btn-outline btn-sm export-pdf-btn" type="submit" name="export_action" value="pdf" disabled>Export PDF</button>
      </div>
    </div>

    <div class="card investment-section investment-section-fixed" id="fixed-list">
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
      <?php render_investment_companies($fixedList['rows'], $attachmentsByTxn, 'fixed'); ?>
      <?php render_pager('investments.php', $fixedList, 'fixed-list'); ?>
    </div>

    <div class="card investment-section investment-section-regular" id="regular-list">
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
      <?php render_investment_companies($regularList['rows'], $attachmentsByTxn, 'regular'); ?>
      <?php render_pager('investments.php', $regularList, 'regular-list'); ?>
    </div>

    <div class="card investment-section investment-section-interest" id="interest-list">
      <div class="investment-section-head">
        <div class="investment-section-icon">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg>
        </div>
        <div>
          <div class="investment-section-title">Paid Interest</div>
          <div class="investment-section-sub">Interest paid out to investors — tracked apart from principal, so it never reduces the amount invested.</div>
        </div>
        <span class="investment-section-badge">Payouts</span>
      </div>
      <div class="stat-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1rem">
        <div class="stat-card">
          <div class="stat-label">Interest paid</div>
          <div class="stat-value text-danger"><?= money($interestPaid) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Payments</div>
          <div class="stat-value"><?= count($interestRows) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Investors paid</div>
          <div class="stat-value"><?= (int) $interestInvestorCount ?></div>
        </div>
      </div>
      <?php render_investment_companies($interestList['rows'], $attachmentsByTxn, 'interest', 'interest'); ?>
      <?php render_pager('investments.php', $interestList, 'interest-list'); ?>
    </div>
  </form>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
