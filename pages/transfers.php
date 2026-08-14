<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array(post('export_action', ''), ['csv', 'excel', 'pdf'], true)) {
    verify_csrf();
    $companyId = (int) post('company_id', 0);
    $amount = (float) post('amount', 0);
    $date = post('transfer_date', date('Y-m-d'));
    $ref = post('reference_no', '');
    $notes = post('notes', '');

    $fromSource = post('from_source', 'account'); // account | external
    $toDest = post('to_dest', 'account');         // account | external
    if (!in_array($fromSource, ['account', 'external'], true)) {
        $fromSource = 'account';
    }
    if (!in_array($toDest, ['account', 'external'], true)) {
        $toDest = 'account';
    }

    $fromId = post('from_account_id') !== '' ? (int) post('from_account_id') : null;
    $toId = post('to_account_id') !== '' ? (int) post('to_account_id') : null;

    $sourceName = trim(post('source_name', ''));
    $sourceAccountNumber = trim(post('source_account_number', ''));
    $sourceIfsc = trim(post('source_ifsc', ''));
    $sourceBankName = trim(post('source_bank_name', ''));

    $recipientName = trim(post('recipient_name', ''));
    $recipientAccountNumber = trim(post('recipient_account_number', ''));
    $recipientIfsc = trim(post('recipient_ifsc', ''));
    $recipientBankName = trim(post('recipient_bank_name', ''));

    if (!$companyId || $amount <= 0) {
        flash('error', 'Select company and enter a positive amount.');
        redirect('pages/transfers.php?action=add');
    }
    if ($fromSource === 'external' && $toDest === 'external') {
        flash('error', 'Choose at least one of our bank accounts (from or to).');
        redirect('pages/transfers.php?action=add');
    }

    // Derive transfer_type
    if ($fromSource === 'account' && $toDest === 'account') {
        $transferType = 'internal';
    } elseif ($fromSource === 'account' && $toDest === 'external') {
        $transferType = 'external'; // outbound
    } else {
        $transferType = 'inbound'; // money came from outside into our account
    }

    if ($fromSource === 'account') {
        if (!$fromId) {
            flash('error', 'Select the source bank account.');
            redirect('pages/transfers.php?action=add');
        }
    } else {
        if ($sourceName === '') {
            flash('error', 'Enter who / where the money came from.');
            redirect('pages/transfers.php?action=add');
        }
        $fromId = null;
    }

    if ($toDest === 'account') {
        if (!$toId) {
            flash('error', 'Select the destination bank account.');
            redirect('pages/transfers.php?action=add');
        }
    } else {
        if ($recipientName === '') {
            flash('error', "Enter the recipient's name.");
            redirect('pages/transfers.php?action=add');
        }
        $toId = null;
    }

    if ($transferType === 'internal' && $fromId === $toId) {
        flash('error', 'Select two different accounts for an internal transfer.');
        redirect('pages/transfers.php?action=add');
    }

    if ($fromId) {
        $bal = account_balance($pdo, $fromId);
        if ($bal < $amount) {
            flash('error', 'Insufficient balance in source account (' . money($bal) . ').');
            redirect('pages/transfers.php?action=add');
        }
    }

    $catId = category_id_by_slug($pdo, 'general', 'bank_transfer');
    if (!$catId) {
        flash('error', 'Transfer category missing. Refresh once to migrate schema.');
        redirect('pages/transfers.php');
    }

    $userId = current_user()['id'] ?? null;
    if ($notes !== '') {
        $desc = $notes;
    } elseif ($transferType === 'inbound') {
        $desc = 'Received from ' . $sourceName;
    } elseif ($transferType === 'external') {
        $desc = 'Transfer to ' . $recipientName;
    } else {
        $desc = 'Bank transfer';
    }

    $debitId = null;
    $creditId = null;
    if ($fromId) {
        $debitId = create_transaction(
            $pdo, $companyId, $catId, 'debit', $amount, $date, null, $fromId, null, $ref,
            $desc . ($transferType === 'internal' ? ' (out)' : ''),
            $userId ? (int) $userId : null
        );
    }
    if ($toId) {
        $creditId = create_transaction(
            $pdo, $companyId, $catId, 'credit', $amount, $date, null, $toId, null, $ref,
            $desc . ($transferType === 'internal' ? ' (in)' : ''),
            $userId ? (int) $userId : null
        );
    }

    $stmt = $pdo->prepare(
        'INSERT INTO bank_transfers
        (company_id, transfer_type, from_account_id, to_account_id,
         source_name, source_account_number, source_ifsc, source_bank_name,
         recipient_name, recipient_account_number, recipient_ifsc, recipient_bank_name,
         amount, transfer_date, reference_no, notes, debit_txn_id, credit_txn_id, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $companyId, $transferType, $fromId, $toId,
        $transferType === 'inbound' ? $sourceName : null,
        $transferType === 'inbound' && $sourceAccountNumber !== '' ? $sourceAccountNumber : null,
        $transferType === 'inbound' && $sourceIfsc !== '' ? $sourceIfsc : null,
        $transferType === 'inbound' && $sourceBankName !== '' ? $sourceBankName : null,
        $transferType === 'external' ? $recipientName : null,
        $transferType === 'external' && $recipientAccountNumber !== '' ? $recipientAccountNumber : null,
        $transferType === 'external' && $recipientIfsc !== '' ? $recipientIfsc : null,
        $transferType === 'external' && $recipientBankName !== '' ? $recipientBankName : null,
        $amount, $date, $ref, $notes, $debitId, $creditId, $userId,
    ]);
    $tid = (int) $pdo->lastInsertId();

    if ($transferType === 'inbound') {
        $summary = 'Received ' . money($amount) . ' from ' . $sourceName;
        $msg = 'Inbound transfer from ' . $sourceName . ' recorded.';
    } elseif ($transferType === 'external') {
        $summary = 'Transferred ' . money($amount) . ' to ' . $recipientName;
        $msg = 'Transfer to ' . $recipientName . ' recorded.';
    } else {
        $summary = 'Transferred ' . money($amount) . ' between accounts';
        $msg = 'Transfer completed. Both account balances updated.';
    }
    audit_log($pdo, 'create', 'bank_transfer', $tid, $summary);
    flash('success', $msg);
    redirect('pages/transfers.php');
}

if ($action === 'add') {
    $allBankAccounts = $pdo->query(
        'SELECT ba.id, ba.account_name, ba.bank_name, c.name AS company_name
         FROM bank_accounts ba JOIN companies c ON c.id = ba.company_id
         WHERE ba.status = "active" ORDER BY c.name, ba.account_name'
    )->fetchAll();
    $pageTitle = 'Bank transfer';
    $pageSub = 'Move money between accounts, or record money coming from / going to an external party.';
    $pageActions = '<a class="btn btn-outline" href="' . e(base_url('pages/transfers.php')) . '">Back</a>';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="card">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>

        <div class="full">
          <label>Transfer from (where money came from)</label>
          <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-top:0.3rem">
            <label style="display:flex;align-items:center;gap:0.4rem;font-weight:600;color:var(--text);margin:0">
              <input type="radio" name="from_source" value="account" id="from_account" checked style="width:auto">
              Our bank account
            </label>
            <label style="display:flex;align-items:center;gap:0.4rem;font-weight:600;color:var(--text);margin:0">
              <input type="radio" name="from_source" value="external" id="from_external" style="width:auto">
              Another person / external account
            </label>
          </div>
        </div>

        <div class="full">
          <label>Transfer to</label>
          <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-top:0.3rem">
            <label style="display:flex;align-items:center;gap:0.4rem;font-weight:600;color:var(--text);margin:0">
              <input type="radio" name="to_dest" value="account" id="to_account" checked style="width:auto">
              Our bank account
            </label>
            <label style="display:flex;align-items:center;gap:0.4rem;font-weight:600;color:var(--text);margin:0">
              <input type="radio" name="to_dest" value="external" id="to_external" style="width:auto">
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

        <div id="fromAccountGroup">
          <label>From account</label>
          <select name="from_account_id" id="from_account_id"><?= bank_account_options($pdo) ?></select>
        </div>
        <div id="sourceExternalGroup" class="full" style="display:none">
          <div class="form-grid" style="padding:0">
            <div>
              <label>Source name (who paid / where it came from)</label>
              <input type="text" name="source_name" id="source_name" placeholder="Person or organisation name">
            </div>
            <div>
              <label>Their account number</label>
              <input type="text" name="source_account_number">
            </div>
            <div>
              <label>Their IFSC code</label>
              <input type="text" name="source_ifsc">
            </div>
            <div>
              <label>Their bank name</label>
              <input type="text" name="source_bank_name">
            </div>
          </div>
        </div>

        <div id="toAccountGroup">
          <label>To account</label>
          <select name="to_account_id" id="to_account_id">
            <option value="">None</option>
            <?php foreach ($allBankAccounts as $acc): ?>
              <option value="<?= (int) $acc['id'] ?>"><?= e($acc['account_name'] . ' — ' . $acc['bank_name'] . ' (' . $acc['company_name'] . ')') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="recipientExternalGroup" class="full" style="display:none">
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
          <label>Reference no. (optional)</label>
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
        var fromAccountGroup = document.getElementById('fromAccountGroup');
        var toAccountGroup = document.getElementById('toAccountGroup');
        var sourceExternalGroup = document.getElementById('sourceExternalGroup');
        var recipientExternalGroup = document.getElementById('recipientExternalGroup');
        var fromAccountSelect = document.getElementById('from_account_id');
        var toAccountSelect = document.getElementById('to_account_id');
        var sourceNameInput = document.getElementById('source_name');
        var recipientNameInput = document.getElementById('recipient_name');
        var transferHint = document.getElementById('transferHint');

        var hints = {
          internal: 'Creates a linked debit on the source account and credit on the destination — not counted as business expense/profit noise when filtered by transfer category.',
          outbound: 'Creates a debit on the source account only, since the funds leave the business — this is recorded as a real outflow in reports.',
          inbound: 'Creates a credit on the destination account only — records money that came in from an external person/account.'
        };

        function syncMode() {
          var fromExt = document.getElementById('from_external').checked;
          var toExt = document.getElementById('to_external').checked;

          fromAccountGroup.style.display = fromExt ? 'none' : '';
          sourceExternalGroup.style.display = fromExt ? '' : 'none';
          fromAccountSelect.required = !fromExt;
          sourceNameInput.required = fromExt;

          toAccountGroup.style.display = toExt ? 'none' : '';
          recipientExternalGroup.style.display = toExt ? '' : 'none';
          toAccountSelect.required = !toExt;
          recipientNameInput.required = toExt;

          if (fromExt && toExt) {
            transferHint.textContent = 'Select at least one of our bank accounts (from or to). External → external is not allowed.';
          } else if (fromExt) {
            transferHint.textContent = hints.inbound;
          } else if (toExt) {
            transferHint.textContent = hints.outbound;
          } else {
            transferHint.textContent = hints.internal;
          }
          syncToAccountOptions();
        }

        function syncToAccountOptions() {
          var fromVal = fromAccountSelect.value;
          Array.prototype.forEach.call(toAccountSelect.options, function (opt) {
            opt.hidden = fromVal !== '' && opt.value === fromVal;
          });
          if (fromVal !== '' && toAccountSelect.value === fromVal) {
            toAccountSelect.value = '';
          }
        }

        ['from_account', 'from_external', 'to_account', 'to_external'].forEach(function (id) {
          document.getElementById(id).addEventListener('change', syncMode);
        });
        fromAccountSelect.addEventListener('change', syncToAccountOptions);
        syncMode();

        document.getElementById('transfer_company').addEventListener('change', function () {
          var companyId = this.value;
          var url = <?= json_encode(base_url('api/bank-accounts.php')) ?> + '?company_id=' + encodeURIComponent(companyId);
          fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (rows) {
            var html = '<option value="">None</option>';
            rows.forEach(function (row) {
              html += '<option value="' + row.id + '">' + (row.account_name || '') + ' — ' + (row.bank_name || '') + '</option>';
            });
            fromAccountSelect.innerHTML = html;
            syncToAccountOptions();
          });
        });
      })();
    </script>
    <?php require __DIR__ . '/../includes/footer.php'; exit;
}

$pageTitle = 'Bank transfers';
$pageSub = 'Internal moves, outbound payments, and money received from outside.';
$pageActions = report_export_buttons()
    . '<a class="btn btn-primary" href="' . e(base_url('pages/transfers.php?action=add')) . '">+ New transfer</a>';
$transferSql = 'SELECT tr.*, c.name AS company_name, fa.account_name AS from_name, fa.bank_name AS from_bank,
        ta.account_name AS to_name, ta.bank_name AS to_bank
     FROM bank_transfers tr
     JOIN companies c ON c.id = tr.company_id
     LEFT JOIN bank_accounts fa ON fa.id = tr.from_account_id
     LEFT JOIN bank_accounts ta ON ta.id = tr.to_account_id
     ORDER BY tr.transfer_date DESC, tr.id DESC';
$rows = $pdo->query($transferSql)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('export_action', ''), ['csv', 'excel', 'pdf'], true)) {
    verify_csrf();
    $exportRows = $pdo->query($transferSql)->fetchAll();
    $typeLabel = static function (string $type): string {
        if ($type === 'inbound') {
            return 'Incoming';
        }
        if ($type === 'external') {
            return 'Outgoing / external';
        }
        return 'Internal';
    };
    $total = 0.0;
    $byType = ['internal' => [0, 0.0], 'external' => [0, 0.0], 'inbound' => [0, 0.0]];
    $entryRows = [];
    foreach ($exportRows as $i => $r) {
        $type = $r['transfer_type'] ?? 'internal';
        $amt = (float) $r['amount'];
        $total += $amt;
        if (!isset($byType[$type])) {
            $byType[$type] = [0, 0.0];
        }
        $byType[$type][0]++;
        $byType[$type][1] += $amt;
        $fromLabel = $type === 'inbound'
            ? (string) ($r['source_name'] ?? '')
            : trim(($r['from_name'] ?? '') . (($r['from_bank'] ?? '') ? ' - ' . $r['from_bank'] : ''));
        $toLabel = $type === 'external'
            ? (string) ($r['recipient_name'] ?? '')
            : trim(($r['to_name'] ?? '') . (($r['to_bank'] ?? '') ? ' - ' . $r['to_bank'] : ''));
        $partyDetail = '';
        if ($type === 'inbound') {
            $partyDetail = trim(implode(' / ', array_filter([
                $r['source_account_number'] ?? '',
                $r['source_ifsc'] ?? '',
                $r['source_bank_name'] ?? '',
            ])));
        } elseif ($type === 'external') {
            $partyDetail = trim(implode(' / ', array_filter([
                $r['recipient_account_number'] ?? '',
                $r['recipient_ifsc'] ?? '',
                $r['recipient_bank_name'] ?? '',
            ])));
        }
        $entryRows[] = [
            (string) ($i + 1),
            report_plain_date($r['transfer_date'] ?? null),
            $typeLabel((string) $type),
            $r['company_name'] ?? '',
            $fromLabel,
            $toLabel,
            $partyDetail,
            $r['reference_no'] ?? '',
            $r['notes'] ?? '',
            $amt,
        ];
    }
    $typeRows = [];
    $n = 0;
    foreach (['internal' => 'Internal', 'inbound' => 'Incoming', 'external' => 'Outgoing / external'] as $key => $label) {
        if (($byType[$key][0] ?? 0) <= 0) {
            continue;
        }
        $n++;
        $typeRows[] = [(string) $n, $label, $byType[$key][0], $byType[$key][1]];
    }

    report_download(post('export_action'), [
        'filename' => 'bank_transfers',
        'title' => 'Bank Transfer Register',
        'orientation' => 'landscape',
        'meta' => [
            ['Entries included', (string) count($exportRows)],
        ],
        'summary' => [
            ['Total transferred', $total, 'money'],
            ['Internal', $byType['internal'][1], 'money'],
            ['Incoming', $byType['inbound'][1], 'money'],
            ['Outgoing', $byType['external'][1], 'money'],
        ],
        'tables' => [
            [
                'title' => 'Transfer entries',
                'columns' => [
                    ['label' => 'Sr No', 'type' => 'text', 'width' => '5%', 'xls_width' => 35],
                    ['label' => 'Date', 'type' => 'text', 'width' => '9%', 'xls_width' => 80],
                    ['label' => 'Type', 'type' => 'text', 'width' => '12%', 'xls_width' => 100],
                    ['label' => 'Company', 'type' => 'text', 'width' => '12%', 'xls_width' => 130],
                    ['label' => 'From', 'type' => 'text', 'width' => '14%', 'xls_width' => 140],
                    ['label' => 'To', 'type' => 'text', 'width' => '14%', 'xls_width' => 140],
                    ['label' => 'Party bank details', 'type' => 'text', 'width' => '14%', 'xls_width' => 160],
                    ['label' => 'Ref', 'type' => 'text', 'width' => '8%', 'xls_width' => 80],
                    ['label' => 'Notes', 'type' => 'text', 'width' => '12%', 'xls_width' => 140],
                    ['label' => 'Amount (INR)', 'type' => 'money', 'width' => '10%', 'xls_width' => 100],
                ],
                'rows' => $entryRows,
                'totals' => ['', 'TOTAL', '', '', '', '', '', '', '', $total],
            ],
            [
                'title' => 'Totals by type',
                'columns' => [
                    ['label' => 'Sr No', 'type' => 'text', 'width' => '10%', 'xls_width' => 35],
                    ['label' => 'Type', 'type' => 'text', 'width' => '40%', 'xls_width' => 160],
                    ['label' => 'Entries', 'type' => 'int', 'width' => '20%', 'xls_width' => 70],
                    ['label' => 'Amount (INR)', 'type' => 'money', 'width' => '30%', 'xls_width' => 110],
                ],
                'rows' => $typeRows,
                'totals' => ['', 'TOTAL', count($exportRows), $total],
            ],
        ],
        'notes' => [
            'System-generated transfer register. Includes internal, incoming, and outgoing transfers.',
            'Party bank details apply to incoming sources and external recipients.',
            'Confidential — internal use only.',
        ],
    ]);
    redirect('pages/transfers.php');
}

$list = paginate_list($rows);

require __DIR__ . '/../includes/header.php';
?>
<div class="card" id="list">
  <div class="card-head">
    <h2 class="card-title">Transfers</h2>
    <?php render_limit_control('transfers.php'); ?>
  </div>
  <?php if (!$list['total']): ?>
    <div class="empty"><strong>No transfers yet</strong><p>Move funds between accounts, or record money from / to an external party.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Date</th><th>Company</th><th>From</th><th>To</th><th class="num">Amount</th><th>Ref</th></tr></thead>
        <tbody>
          <?php foreach ($list['rows'] as $r):
            $type = $r['transfer_type'] ?? 'internal';
            $isExternal = $type === 'external';
            $isInbound = $type === 'inbound';
            $hasDetail = $isExternal || $isInbound;
            $detailId = 'transfer-detail-' . $r['id'];
            $fromLabel = $isInbound
                ? (($r['source_name'] ?? '—') . '')
                : ($r['from_name'] ?? '—');
            $toLabel = $isExternal
                ? ($r['recipient_name'] ?? '—')
                : ($r['to_name'] ?? '—');
          ?>
            <tr<?= $hasDetail ? ' class="row-clickable" data-row-toggle="' . e($detailId) . '"' : '' ?>>
              <td><?= e(format_date($r['transfer_date'])) ?></td>
              <td><?= e($r['company_name']) ?></td>
              <td>
                <?php if ($isInbound): ?>
                  <?php if ($hasDetail): ?><span class="row-caret">▸</span><?php endif; ?>
                  <?= e($fromLabel) ?> <span class="chip chip-success">Incoming</span>
                <?php else: ?>
                  <?= e($fromLabel) ?>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($isExternal): ?>
                  <?php if ($hasDetail): ?><span class="row-caret">▸</span><?php endif; ?>
                  <?= e($toLabel) ?> <span class="chip chip-info">External</span>
                <?php else: ?>
                  <?= e($toLabel) ?>
                <?php endif; ?>
              </td>
              <td class="num"><?= money($r['amount']) ?></td>
              <td><?= e($r['reference_no'] ?? '') ?></td>
            </tr>
            <?php if ($hasDetail): ?>
            <tr class="row-detail" id="<?= e($detailId) ?>" hidden>
              <td colspan="6">
                <table class="detail-table">
                  <tbody>
                    <?php if ($isInbound): ?>
                      <tr><td>Source (from)</td><td><?= e($r['source_name'] ?? '—') ?></td></tr>
                      <tr><td>Their account number</td><td><?= e($r['source_account_number'] ?: '—') ?></td></tr>
                      <tr><td>Their IFSC</td><td><?= e($r['source_ifsc'] ?: '—') ?></td></tr>
                      <tr><td>Their bank</td><td><?= e($r['source_bank_name'] ?: '—') ?></td></tr>
                      <tr><td>Received into</td><td><?= e($r['to_name'] ?? '—') ?></td></tr>
                    <?php else: ?>
                      <tr><td>Recipient</td><td><?= e($r['recipient_name'] ?? '—') ?></td></tr>
                      <tr><td>Account number</td><td><?= e($r['recipient_account_number'] ?: '—') ?></td></tr>
                      <tr><td>IFSC code</td><td><?= e($r['recipient_ifsc'] ?: '—') ?></td></tr>
                      <tr><td>Bank name</td><td><?= e($r['recipient_bank_name'] ?: '—') ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($r['notes'])): ?>
                      <tr><td>Notes</td><td><?= e($r['notes']) ?></td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </td>
            </tr>
            <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php render_pager('transfers.php', $list); ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
