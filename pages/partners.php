<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

const PARTNER_ENTRY_MAP = [
    'partner_capital' => ['credit', 'partner_capital', 'Capital added'],
    'partner_capital_withdrawal' => ['general', 'partner_capital_withdrawal', 'Capital withdrawn'],
    'partner_advance' => ['general', 'partner_advance', 'Advance paid to partner'],
    'partner_advance_return' => ['credit', 'partner_advance_return', 'Advance returned by partner'],
];

/** Renders the partner directory table with an expandable per-partner ledger + quick entry form. */
function render_partner_rows(PDO $pdo, array $partners, array $partnerTxns): void
{
    if (!$partners) {
        echo '<div class="empty"><strong>No partners</strong><p>Add partners linked to main or sub companies.</p></div>';
        return;
    }
    ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>Name</th><th>Company</th><th>Contact</th><th class="num">Share %</th>
            <th class="num">Capital</th><th class="num">Advance</th><th class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($partners as $p):
            $detailId = 'partner-detail-' . $p['id'];
            $rows = $partnerTxns[(int) $p['id']] ?? [];
          ?>
            <tr class="row-clickable" data-row-toggle="<?= e($detailId) ?>">
              <td><span class="row-caret">▸</span><strong><?= e($p['name']) ?></strong></td>
              <td><?= e($p['company_name'] ?? '—') ?></td>
              <td><?= e($p['phone'] ?: $p['email'] ?: '—') ?></td>
              <td class="num"><?= $p['share_percent'] !== null ? e((string) $p['share_percent']) . '%' : '—' ?></td>
              <td class="num"><?= money($p['invested_amount']) ?></td>
              <td class="num"><?= money($p['advance_amount']) ?></td>
              <td class="actions">
                <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/partners.php?action=edit&id=' . $p['id'])) ?>">Edit</a>
                <form method="post" style="display:inline" onsubmit="return confirm('Delete partner?')">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                </form>
              </td>
            </tr>
            <tr class="row-detail" id="<?= e($detailId) ?>" hidden>
              <td colspan="7">
                <div class="grid-2" style="align-items:flex-start;gap:1rem">
                  <div class="table-wrap">
                    <table class="data">
                      <thead>
                        <tr><th>Date</th><th>Entry</th><th>Bank account</th><th class="num">Amount</th></tr>
                      </thead>
                      <tbody>
                        <?php if (!$rows): ?>
                          <tr><td colspan="4" class="muted">No capital/advance entries yet.</td></tr>
                        <?php else: foreach ($rows as $r): ?>
                          <tr>
                            <td><?= e(format_date($r['txn_date'])) ?></td>
                            <td><?= e($r['category_name']) ?></td>
                            <td><?= $r['account_name'] ? e($r['account_name'] . ' — ' . $r['bank_name']) : '—' ?></td>
                            <td class="num <?= $r['txn_type'] === 'credit' ? 'text-success' : 'text-danger' ?>"><?= money($r['amount']) ?></td>
                          </tr>
                        <?php endforeach; endif; ?>
                      </tbody>
                    </table>
                  </div>
                  <form method="post" class="form-grid" style="padding:0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="record">
                    <input type="hidden" name="partner_id" value="<?= (int) $p['id'] ?>">
                    <div class="full">
                      <label>Entry type</label>
                      <select name="entry_type" required>
                        <?php foreach (PARTNER_ENTRY_MAP as $slug => $meta): ?>
                          <option value="<?= e($slug) ?>"><?= e($meta[2]) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div>
                      <label>Amount (₹)</label>
                      <input type="number" step="0.01" min="0.01" name="amount" required>
                    </div>
                    <div>
                      <label>Date</label>
                      <input type="date" name="txn_date" required value="<?= e(date('Y-m-d')) ?>">
                    </div>
                    <div class="full">
                      <label>Bank account (optional)</label>
                      <select name="bank_account_id"><?= bank_account_options($pdo, (int) ($p['company_id'] ?? 0) ?: null, null, 'Cash') ?></select>
                    </div>
                    <div class="full">
                      <label>Notes</label>
                      <input type="text" name="description">
                    </div>
                    <div class="full form-actions" style="justify-content:flex-start">
                      <button class="btn btn-primary btn-sm" type="submit" <?= $p['company_id'] ? '' : 'disabled title="Assign a company to this partner first"' ?>>Record entry</button>
                    </div>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $postAction = post('action', '');
    if ($postAction === 'save') {
        $companyId = post('company_id') !== '' ? (int) post('company_id') : null;
        $name = post('name', '');
        $phone = post('phone', '');
        $email = post('email', '');
        $share = post('share_percent') !== '' ? (float) post('share_percent') : null;
        $notes = post('notes', '');
        $editId = (int) post('id', 0);
        if ($name === '') {
            flash('error', 'Partner name is required.');
            redirect('pages/partners.php?action=add');
        }
        if ($editId) {
            $stmt = $pdo->prepare('UPDATE partners SET company_id=?, name=?, phone=?, email=?, share_percent=?, notes=? WHERE id=?');
            $stmt->execute([$companyId, $name, $phone, $email, $share, $notes, $editId]);
            flash('success', 'Partner updated.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO partners (company_id, name, phone, email, share_percent, invested_amount, advance_amount, notes) VALUES (?,?,?,?,?,0,0,?)');
            $stmt->execute([$companyId, $name, $phone, $email, $share, $notes]);
            flash('success', 'Partner added.');
        }
        redirect('pages/partners.php');
    }
    if ($postAction === 'record') {
        $partnerId = (int) post('partner_id', 0);
        $entryType = post('entry_type', '');
        $amount = (float) post('amount', 0);
        $txnDate = post('txn_date', date('Y-m-d'));
        $bankAccountId = post('bank_account_id') !== '' ? (int) post('bank_account_id') : null;
        $description = post('description', '');

        if (!$partnerId || !isset(PARTNER_ENTRY_MAP[$entryType]) || $amount <= 0) {
            flash('error', 'Partner, entry type and a positive amount are required.');
            redirect('pages/partners.php');
        }
        $pStmt = $pdo->prepare('SELECT company_id, name FROM partners WHERE id = ?');
        $pStmt->execute([$partnerId]);
        $partnerRow = $pStmt->fetch();
        if (!$partnerRow || !$partnerRow['company_id']) {
            flash('error', 'Partner must have a company assigned before recording entries.');
            redirect('pages/partners.php');
        }
        [$section, $slug, $label] = PARTNER_ENTRY_MAP[$entryType];
        $catId = category_id_by_slug($pdo, $section, $slug);
        $txnType = $section === 'credit' ? 'credit' : 'debit';
        $txnId = create_transaction(
            $pdo, (int) $partnerRow['company_id'], (int) $catId, $txnType, $amount, $txnDate,
            null, $bankAccountId, $partnerId, null, $description ?: ($label . ' — ' . $partnerRow['name']),
            current_user()['id'] ?? null
        );
        audit_log($pdo, 'create', 'transaction', $txnId, $label . ': ' . $partnerRow['name'] . ' — ' . money($amount));
        sync_partner_invested($pdo, $partnerId);
        sync_partner_advance($pdo, $partnerId);
        flash('success', 'Entry recorded.');
        redirect('pages/partners.php');
    }
    if ($postAction === 'delete') {
        $stmt = $pdo->prepare('DELETE FROM partners WHERE id = ?');
        $stmt->execute([(int) post('id', 0)]);
        flash('success', 'Partner deleted.');
        redirect('pages/partners.php');
    }
}

$action = get('action', 'list');
$id = (int) get('id', 0);

if ($action === 'add' || $action === 'edit') {
    $row = null;
    if ($action === 'edit' && $id) {
        $stmt = $pdo->prepare('SELECT * FROM partners WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
    }
    $pageTitle = $action === 'edit' ? 'Edit partner' : 'Add partner';
    $pageActions = '<a class="btn btn-outline" href="' . e(base_url('pages/partners.php')) . '">Back</a>';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="card" style="max-width:720px">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>">
        <div>
          <label>Company</label>
          <select name="company_id">
            <?= company_options($pdo, (int) ($row['company_id'] ?? 0)) ?>
          </select>
        </div>
        <div>
          <label>Share %</label>
          <input type="number" step="0.01" name="share_percent" value="<?= e((string) ($row['share_percent'] ?? '')) ?>">
        </div>
        <div class="full">
          <label>Name</label>
          <input type="text" name="name" required value="<?= e($row['name'] ?? '') ?>">
        </div>
        <div>
          <label>Phone</label>
          <input type="text" name="phone" value="<?= e($row['phone'] ?? '') ?>">
        </div>
        <div>
          <label>Email</label>
          <input type="email" name="email" value="<?= e($row['email'] ?? '') ?>">
        </div>
        <?php if ($row): ?>
        <div>
          <label>Capital (₹)</label>
          <input type="text" value="<?= e(money($row['invested_amount'])) ?>" readonly>
          <p class="muted" style="font-size:0.75rem;margin:0.3rem 0 0">Synced from Capital entries. Record entries from the partner list.</p>
        </div>
        <div>
          <label>Advance (₹)</label>
          <input type="text" value="<?= e(money($row['advance_amount'])) ?>" readonly>
          <p class="muted" style="font-size:0.75rem;margin:0.3rem 0 0">Synced from Advance entries. Record entries from the partner list.</p>
        </div>
        <?php endif; ?>
        <div class="full">
          <label>Notes</label>
          <textarea name="notes"><?= e($row['notes'] ?? '') ?></textarea>
        </div>
        <div class="full form-actions"><button class="btn btn-primary" type="submit">Save partner</button></div>
      </form>
    </div>
    <?php require __DIR__ . '/../includes/footer.php'; exit;
}

$pageTitle = 'Partners';
$pageSub = 'Partner directory, capital and advances.';
$pageActions = report_export_buttons()
    . '<a class="btn btn-primary" href="' . e(base_url('pages/partners.php?action=add')) . '">+ Add partner</a>';
$partners = $pdo->query('SELECT pr.*, c.name AS company_name FROM partners pr LEFT JOIN companies c ON c.id = pr.company_id ORDER BY pr.name')->fetchAll();

$partnerTxns = [];
$partnerIds = array_column($partners, 'id');
if ($partnerIds) {
    $in = implode(',', array_fill(0, count($partnerIds), '?'));
    $txnStmt = $pdo->prepare(
        "SELECT t.*, c.name AS category_name, c.slug AS category_slug, ba.account_name, ba.bank_name
         FROM transactions t
         JOIN categories c ON c.id = t.category_id
         LEFT JOIN bank_accounts ba ON ba.id = t.bank_account_id
         WHERE t.partner_id IN ($in) AND c.slug IN ('partner','partner_capital','partner_advance','partner_capital_withdrawal','partner_advance_return')
         ORDER BY t.txn_date DESC, t.id DESC"
    );
    $txnStmt->execute($partnerIds);
    foreach ($txnStmt->fetchAll() as $t) {
        $partnerTxns[(int) $t['partner_id']][] = $t;
    }
}

$totalCapital = (float) $pdo->query('SELECT COALESCE(SUM(invested_amount),0) FROM partners')->fetchColumn();
$totalAdvance = (float) $pdo->query('SELECT COALESCE(SUM(advance_amount),0) FROM partners')->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('export_action', ''), ['csv', 'excel', 'pdf'], true)) {
    verify_csrf();
    $dirRows = [];
    foreach ($partners as $i => $p) {
        $dirRows[] = [
            (string) ($i + 1),
            $p['name'] ?? '',
            $p['company_name'] ?? '',
            $p['phone'] ?? '',
            $p['email'] ?? '',
            $p['share_percent'] !== null ? (string) $p['share_percent'] : '',
            (float) $p['invested_amount'],
            (float) $p['advance_amount'],
        ];
    }
    $ledgerRows = [];
    $n = 0;
    foreach ($partners as $p) {
        foreach ($partnerTxns[(int) $p['id']] ?? [] as $t) {
            $n++;
            $isCredit = ($t['txn_type'] ?? '') === 'credit';
            $amt = (float) $t['amount'];
            $bank = $t['account_name'] ? trim($t['account_name'] . ($t['bank_name'] ? ' - ' . $t['bank_name'] : '')) : 'Cash';
            $ledgerRows[] = [
                (string) $n,
                report_plain_date($t['txn_date'] ?? null),
                $p['name'] ?? '',
                $t['category_name'] ?? '',
                $isCredit ? 'Credit' : 'Debit',
                $bank,
                $t['description'] ?? '',
                $isCredit ? $amt : null,
                $isCredit ? null : $amt,
            ];
        }
    }
    report_download(post('export_action'), [
        'filename' => 'partners_register',
        'title' => 'Partner Register',
        'orientation' => 'landscape',
        'meta' => [
            ['Partners', (string) count($partners)],
        ],
        'summary' => [
            ['Total capital', $totalCapital, 'money'],
            ['Total advances', $totalAdvance, 'money'],
            ['Partners', count($partners), 'int'],
        ],
        'tables' => [
            [
                'title' => 'Partner directory',
                'columns' => [
                    ['label' => 'Sr No', 'type' => 'text', 'width' => '6%', 'xls_width' => 35],
                    ['label' => 'Name', 'type' => 'text', 'width' => '16%', 'xls_width' => 140],
                    ['label' => 'Company', 'type' => 'text', 'width' => '16%', 'xls_width' => 140],
                    ['label' => 'Phone', 'type' => 'text', 'width' => '12%', 'xls_width' => 100],
                    ['label' => 'Email', 'type' => 'text', 'width' => '16%', 'xls_width' => 140],
                    ['label' => 'Share %', 'type' => 'text', 'width' => '8%', 'xls_width' => 60],
                    ['label' => 'Capital (INR)', 'type' => 'money', 'width' => '13%', 'xls_width' => 110],
                    ['label' => 'Advance (INR)', 'type' => 'money', 'width' => '13%', 'xls_width' => 110],
                ],
                'rows' => $dirRows,
                'totals' => ['', 'TOTAL', '', '', '', '', $totalCapital, $totalAdvance],
            ],
            [
                'title' => 'Partner ledger',
                'columns' => [
                    ['label' => 'Sr No', 'type' => 'text', 'width' => '5%', 'xls_width' => 35],
                    ['label' => 'Date', 'type' => 'text', 'width' => '10%', 'xls_width' => 80],
                    ['label' => 'Partner', 'type' => 'text', 'width' => '14%', 'xls_width' => 130],
                    ['label' => 'Category', 'type' => 'text', 'width' => '16%', 'xls_width' => 130],
                    ['label' => 'Type', 'type' => 'text', 'width' => '8%', 'xls_width' => 60],
                    ['label' => 'Account', 'type' => 'text', 'width' => '14%', 'xls_width' => 130],
                    ['label' => 'Particulars', 'type' => 'text', 'width' => '13%', 'xls_width' => 140],
                    ['label' => 'Credit (INR)', 'type' => 'money', 'width' => '10%', 'xls_width' => 100],
                    ['label' => 'Debit (INR)', 'type' => 'money', 'width' => '10%', 'xls_width' => 100],
                ],
                'rows' => $ledgerRows,
            ],
        ],
        'notes' => [
            'System-generated partner register. Capital and advance are the current balances on each partner.',
            'Confidential — internal use only.',
        ],
    ]);
    redirect('pages/partners.php');
}

require __DIR__ . '/../includes/header.php';
?>
<div class="stat-grid" style="grid-template-columns:repeat(3,1fr)">
  <div class="stat-card"><div class="stat-label">Total capital</div><div class="stat-value"><?= money($totalCapital) ?></div></div>
  <div class="stat-card"><div class="stat-label">Total advances</div><div class="stat-value"><?= money($totalAdvance) ?></div></div>
  <div class="stat-card"><div class="stat-label">Registered partners</div><div class="stat-value"><?= count($partners) ?></div></div>
</div>
<div class="card">
  <?php render_partner_rows($pdo, $partners, $partnerTxns); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
