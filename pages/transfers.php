<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $companyId = (int) post('company_id', 0);
    $fromId = (int) post('from_account_id', 0);
    $amount = (float) post('amount', 0);
    $date = post('transfer_date', date('Y-m-d'));
    $ref = post('reference_no', '');
    $notes = post('notes', '');
    $transferType = post('transfer_type', 'internal');
    if (!in_array($transferType, ['internal', 'external'], true)) {
        $transferType = 'internal';
    }
    $toId = post('to_account_id') !== '' ? (int) post('to_account_id') : null;
    $recipientName = trim(post('recipient_name', ''));
    $recipientAccountNumber = trim(post('recipient_account_number', ''));
    $recipientIfsc = trim(post('recipient_ifsc', ''));
    $recipientBankName = trim(post('recipient_bank_name', ''));

    if (!$companyId || !$fromId || $amount <= 0) {
        flash('error', 'Select company, source account, and a positive amount.');
        redirect('pages/transfers.php?action=add');
    }
    if ($transferType === 'internal') {
        if (!$toId || $fromId === $toId) {
            flash('error', 'Select two different accounts for an internal transfer.');
            redirect('pages/transfers.php?action=add');
        }
    } else {
        if ($recipientName === '') {
            flash('error', "Enter the recipient's name for an external transfer.");
            redirect('pages/transfers.php?action=add');
        }
        $toId = null;
    }

    $bal = account_balance($pdo, $fromId);
    if ($bal < $amount) {
        flash('error', 'Insufficient balance in source account (' . money($bal) . ').');
        redirect('pages/transfers.php?action=add');
    }

    $catId = category_id_by_slug($pdo, 'general', 'bank_transfer');
    if (!$catId) {
        flash('error', 'Transfer category missing. Refresh once to migrate schema.');
        redirect('pages/transfers.php');
    }

    $userId = current_user()['id'] ?? null;
    $desc = $notes !== '' ? $notes : ($transferType === 'external' ? ('Transfer to ' . $recipientName) : 'Bank transfer');

    $debitId = create_transaction($pdo, $companyId, $catId, 'debit', $amount, $date, null, $fromId, null, $ref, $desc . ($transferType === 'internal' ? ' (out)' : ''), $userId ? (int) $userId : null);
    $creditId = null;
    if ($transferType === 'internal') {
        $creditId = create_transaction($pdo, $companyId, $catId, 'credit', $amount, $date, null, $toId, null, $ref, $desc . ' (in)', $userId ? (int) $userId : null);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO bank_transfers
        (company_id, transfer_type, from_account_id, to_account_id, recipient_name, recipient_account_number, recipient_ifsc, recipient_bank_name, amount, transfer_date, reference_no, notes, debit_txn_id, credit_txn_id, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $companyId, $transferType, $fromId, $toId,
        $transferType === 'external' ? $recipientName : null,
        $transferType === 'external' && $recipientAccountNumber !== '' ? $recipientAccountNumber : null,
        $transferType === 'external' && $recipientIfsc !== '' ? $recipientIfsc : null,
        $transferType === 'external' && $recipientBankName !== '' ? $recipientBankName : null,
        $amount, $date, $ref, $notes, $debitId, $creditId, $userId,
    ]);
    $tid = (int) $pdo->lastInsertId();
    audit_log($pdo, 'create', 'bank_transfer', $tid, $transferType === 'external'
        ? 'Transferred ' . money($amount) . ' to ' . $recipientName
        : 'Transferred ' . money($amount) . ' between accounts');
    flash('success', $transferType === 'external' ? 'Transfer to ' . $recipientName . ' recorded.' : 'Transfer completed. Both account balances updated.');
    redirect('pages/transfers.php');
}

if ($action === 'add') {
    $pageTitle = 'Bank transfer';
    $pageSub = 'Move money between accounts without booking a fake expense.';
    $pageActions = '<a class="btn btn-outline" href="' . e(base_url('pages/transfers.php')) . '">Back</a>';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="card">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <div class="full">
          <label>Transfer to</label>
          <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-top:0.3rem">
            <label style="display:flex;align-items:center;gap:0.4rem;font-weight:600;color:var(--text);margin:0">
              <input type="radio" name="transfer_type" value="internal" id="type_internal" checked style="width:auto">
              Existing bank account
            </label>
            <label style="display:flex;align-items:center;gap:0.4rem;font-weight:600;color:var(--text);margin:0">
              <input type="radio" name="transfer_type" value="external" id="type_external" style="width:auto">
              Another person / external account
            </label>
          </div>
        </div>
        <div>
          <label>Company</label>
          <select name="company_id" required id="transfer_company">
            <?= company_options($pdo) ?>
          </select>
        </div>
        <div>
          <label>Date</label>
          <input type="date" name="transfer_date" required value="<?= e(date('Y-m-d')) ?>">
        </div>
        <div>
          <label>From account</label>
          <select name="from_account_id" id="from_account_id" required><?= bank_account_options($pdo) ?></select>
        </div>
        <div id="toAccountGroup">
          <label>To account</label>
          <select name="to_account_id" id="to_account_id" required><?= bank_account_options($pdo) ?></select>
        </div>
        <div id="externalGroup" class="full" style="display:none">
          <div class="form-grid" style="padding:0">
            <div>
              <label>Recipient name</label>
              <input type="text" name="recipient_name" id="recipient_name">
            </div>
            <div>
              <label>Account number</label>
              <input type="text" name="recipient_account_number">
            </div>
            <div>
              <label>IFSC code</label>
              <input type="text" name="recipient_ifsc">
            </div>
            <div>
              <label>Bank name</label>
              <input type="text" name="recipient_bank_name">
            </div>
          </div>
        </div>
        <div>
          <label>Amount (₹)</label>
          <input type="number" step="0.01" min="0.01" name="amount" required>
        </div>
        <div>
          <label>Reference</label>
          <input type="text" name="reference_no">
        </div>
        <div class="full">
          <label>Notes</label>
          <textarea name="notes"></textarea>
        </div>
        <div class="full highlight-box" id="transferHint">Creates a linked debit on the source account and credit on the destination — not counted as business expense/profit noise when filtered by transfer category.</div>
        <div class="full form-actions"><button class="btn btn-primary" type="submit">Transfer</button></div>
      </form>
    </div>
    <script>
      (function () {
        var toAccountGroup = document.getElementById('toAccountGroup');
        var fromAccountSelect = document.getElementById('from_account_id');
        var toAccountSelect = document.getElementById('to_account_id');
        var externalGroup = document.getElementById('externalGroup');
        var recipientNameInput = document.getElementById('recipient_name');
        var transferHint = document.getElementById('transferHint');
        var internalHint = 'Creates a linked debit on the source account and credit on the destination — not counted as business expense/profit noise when filtered by transfer category.';
        var externalHint = 'Creates a debit on the source account only, since the funds leave the business — this is recorded as a real outflow in reports.';

        function updateTransferType() {
          var external = document.getElementById('type_external').checked;
          toAccountGroup.style.display = external ? 'none' : '';
          toAccountSelect.required = !external;
          externalGroup.style.display = external ? '' : 'none';
          recipientNameInput.required = external;
          transferHint.textContent = external ? externalHint : internalHint;
        }
        document.getElementById('type_internal').addEventListener('change', updateTransferType);
        document.getElementById('type_external').addEventListener('change', updateTransferType);
        updateTransferType();

        // The "To account" list must never offer whichever account is currently selected as "From".
        function syncToAccountOptions() {
          var fromVal = fromAccountSelect.value;
          Array.prototype.forEach.call(toAccountSelect.options, function (opt) {
            opt.hidden = fromVal !== '' && opt.value === fromVal;
          });
          if (fromVal !== '' && toAccountSelect.value === fromVal) {
            toAccountSelect.value = '';
          }
        }
        fromAccountSelect.addEventListener('change', syncToAccountOptions);
        syncToAccountOptions();

        document.getElementById('transfer_company').addEventListener('change', function () {
          var companyId = this.value;
          var url = <?= json_encode(base_url('api/bank-accounts.php')) ?> + '?company_id=' + encodeURIComponent(companyId);
          fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (rows) {
            var html = '<option value="">None</option>';
            rows.forEach(function (row) { html += '<option value="' + row.id + '">' + (row.account_name || '') + ' — ' + (row.bank_name || '') + '</option>'; });
            fromAccountSelect.innerHTML = html;
            toAccountSelect.innerHTML = html;
            syncToAccountOptions();
          });
        });
      })();
    </script>
    <?php require __DIR__ . '/../includes/footer.php'; exit;
}

$pageTitle = 'Bank transfers';
$pageSub = 'Internal account-to-account movements.';
$pageActions = '<a class="btn btn-primary" href="' . e(base_url('pages/transfers.php?action=add')) . '">+ New transfer</a>';
$rows = $pdo->query(
    'SELECT tr.*, c.name AS company_name, fa.account_name AS from_name, ta.account_name AS to_name
     FROM bank_transfers tr
     JOIN companies c ON c.id = tr.company_id
     JOIN bank_accounts fa ON fa.id = tr.from_account_id
     LEFT JOIN bank_accounts ta ON ta.id = tr.to_account_id
     ORDER BY tr.transfer_date DESC, tr.id DESC LIMIT 100'
)->fetchAll();
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
  <?php if (!$rows): ?>
    <div class="empty"><strong>No transfers yet</strong><p>Move funds between company bank accounts cleanly.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Date</th><th>Company</th><th>From</th><th>To</th><th class="num">Amount</th><th>Ref</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r):
            $isExternal = $r['transfer_type'] === 'external';
            $detailId = 'transfer-detail-' . $r['id'];
          ?>
            <tr<?= $isExternal ? ' class="row-clickable" data-row-toggle="' . e($detailId) . '"' : '' ?>>
              <td><?= e(format_date($r['transfer_date'])) ?></td>
              <td><?= e($r['company_name']) ?></td>
              <td><?= e($r['from_name']) ?></td>
              <td>
                <?php if ($isExternal): ?>
                  <span class="row-caret">▸</span><?= e($r['recipient_name'] ?? '—') ?> <span class="chip chip-info">External</span>
                <?php else: ?>
                  <?= e($r['to_name'] ?? '—') ?>
                <?php endif; ?>
              </td>
              <td class="num"><?= money($r['amount']) ?></td>
              <td><?= e($r['reference_no'] ?? '') ?></td>
            </tr>
            <?php if ($isExternal): ?>
            <tr class="row-detail" id="<?= e($detailId) ?>" hidden>
              <td colspan="6">
                <table class="detail-table">
                  <tbody>
                    <tr><td>Recipient</td><td><?= e($r['recipient_name'] ?? '—') ?></td></tr>
                    <tr><td>Account number</td><td><?= e($r['recipient_account_number'] ?: '—') ?></td></tr>
                    <tr><td>IFSC code</td><td><?= e($r['recipient_ifsc'] ?: '—') ?></td></tr>
                    <tr><td>Bank name</td><td><?= e($r['recipient_bank_name'] ?: '—') ?></td></tr>
                  </tbody>
                </table>
              </td>
            </tr>
            <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
