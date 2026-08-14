<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$action = get('action', 'list');
$id = (int) get('id', 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $postAction = post('action', '');
    if ($postAction === 'save') {
        $companyId = (int) post('company_id', 0);
        $name = post('name', '');
        $type = post('asset_type', '');
        $purchaseDate = post('purchase_date') ?: null;
        $purchaseValue = (float) post('purchase_value', 0);
        $currentValue = post('current_value') !== '' ? (float) post('current_value') : null;
        $notes = post('notes', '');
        $editId = (int) post('id', 0);
        $bankAccountId = post('bank_account_id') !== '' ? (int) post('bank_account_id') : null;
        if (!$companyId || $name === '') {
            flash('error', 'Company and asset name are required.');
            redirect('pages/assets.php?action=add');
        }
        if ($editId) {
            $stmt = $pdo->prepare('UPDATE assets SET company_id=?, name=?, asset_type=?, purchase_date=?, purchase_value=?, current_value=?, notes=? WHERE id=?');
            $stmt->execute([$companyId, $name, $type, $purchaseDate, $purchaseValue, $currentValue, $notes, $editId]);
            flash('success', 'Asset updated.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO assets (company_id, name, asset_type, purchase_date, purchase_value, current_value, notes) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$companyId, $name, $type, $purchaseDate, $purchaseValue, $currentValue, $notes]);
            $newId = (int) $pdo->lastInsertId();

            if ($purchaseValue > 0) {
                $catId = category_id_by_slug($pdo, 'general', 'asset_purchase');
                if ($catId) {
                    create_transaction(
                        $pdo, $companyId, $catId, 'debit', $purchaseValue, $purchaseDate ?: date('Y-m-d'),
                        null, $bankAccountId, null, null, 'Asset purchase — ' . $name,
                        current_user()['id'] ?? null
                    );
                }
            }
            flash('success', 'Asset added.');
        }
        redirect('pages/assets.php');
    }
    if ($postAction === 'delete') {
        if (!can_delete()) {
            flash('error', 'Only admins can delete assets.');
            redirect('pages/assets.php');
        }
        $delId = (int) post('id', 0);
        $rowStmt = $pdo->prepare('SELECT * FROM assets WHERE id = ?');
        $rowStmt->execute([$delId]);
        $asset = $rowStmt->fetch();
        if ($asset) {
            delete_ledger_for_record(
                $pdo,
                (int) $asset['company_id'],
                'general',
                'asset_purchase',
                (float) $asset['purchase_value'],
                'Asset purchase — ' . $asset['name']
            );
            $pdo->prepare('DELETE FROM assets WHERE id = ?')->execute([$delId]);
            audit_log($pdo, 'delete', 'asset', $delId, 'Deleted asset ' . $asset['name']);
        }
        flash('success', 'Asset deleted.');
        redirect('pages/assets.php');
    }
}

if ($action === 'add' || $action === 'edit') {
    $row = null;
    if ($action === 'edit' && $id) {
        $stmt = $pdo->prepare('SELECT * FROM assets WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
    }
    $pageTitle = $action === 'edit' ? 'Edit asset' : 'Add asset';
    $pageActions = '<a class="btn btn-outline" href="' . e(base_url('pages/assets.php')) . '">Back</a>';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="card">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>">
        <div>
          <label>Company</label>
          <select name="company_id" required
            <?php if (!$row): ?>data-company-accounts="asset_bank_account_id" data-accounts-url="<?= e(base_url('api/bank-accounts.php')) ?>"<?php endif; ?>>
            <?= company_options($pdo, (int)($row['company_id'] ?? 0)) ?>
          </select>
        </div>
        <div>
          <label>Asset type</label>
          <input type="text" name="asset_type" placeholder="Vehicle, Equipment…" value="<?= e($row['asset_type'] ?? '') ?>">
        </div>
        <div class="full">
          <label>Name</label>
          <input type="text" name="name" required value="<?= e($row['name'] ?? '') ?>">
        </div>
        <div>
          <label>Purchase date</label>
          <input type="date" name="purchase_date" value="<?= e($row['purchase_date'] ?? '') ?>">
        </div>
        <div>
          <label>Purchase value (₹)</label>
          <input type="number" step="0.01" name="purchase_value" value="<?= e((string)($row['purchase_value'] ?? '0')) ?>">
        </div>
        <div>
          <label>Current value (₹)</label>
          <input type="number" step="0.01" name="current_value" value="<?= e((string)($row['current_value'] ?? '')) ?>">
        </div>
        <?php if (!$row): ?>
        <div class="full">
          <label>Payment mode</label>
          <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-top:0.3rem">
            <label style="display:flex;align-items:center;gap:0.4rem;font-weight:600;color:var(--text);margin:0">
              <input type="radio" name="payment_mode" value="cash" class="pay-mode-radio" checked style="width:auto">
              Cash
            </label>
            <label style="display:flex;align-items:center;gap:0.4rem;font-weight:600;color:var(--text);margin:0">
              <input type="radio" name="payment_mode" value="bank" class="pay-mode-radio" style="width:auto">
              Bank transfer
            </label>
          </div>
        </div>
        <div class="pay-bank-account-group" style="display:none">
          <label>Bank account</label>
          <select name="bank_account_id" id="asset_bank_account_id" class="pay-bank-account-select"><?= bank_account_options($pdo, (int)($row['company_id'] ?? 0) ?: null) ?></select>
        </div>
        <?php endif; ?>
        <div class="full">
          <label>Notes</label>
          <textarea name="notes"><?= e($row['notes'] ?? '') ?></textarea>
        </div>
        <div class="full form-actions"><button class="btn btn-primary" type="submit">Save asset</button></div>
      </form>
    </div>
    <?php require __DIR__ . '/../includes/footer.php'; exit;
}

$pageTitle = 'Assets';
$pageSub = 'Track company assets and purchase values.';
$pageActions = report_export_buttons()
    . '<a class="btn btn-primary" href="' . e(base_url('pages/assets.php?action=add')) . '">+ Add asset</a>';
$assets = $pdo->query('SELECT a.*, c.name AS company_name FROM assets a JOIN companies c ON c.id = a.company_id ORDER BY a.created_at DESC')->fetchAll();
$totalValue = array_sum(array_map(fn($a) => (float)($a['current_value'] ?? $a['purchase_value']), $assets));
$purchaseTotal = array_sum(array_map(fn($a) => (float) $a['purchase_value'], $assets));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('export_action', ''), ['csv', 'excel', 'pdf'], true)) {
    verify_csrf();
    $assetRows = [];
    foreach ($assets as $i => $a) {
        $current = (float) ($a['current_value'] ?? $a['purchase_value']);
        $assetRows[] = [
            (string) ($i + 1),
            $a['name'] ?? '',
            $a['company_name'] ?? '',
            $a['asset_type'] ?? '',
            report_plain_date($a['purchase_date'] ?? null),
            (float) $a['purchase_value'],
            $current,
            $a['notes'] ?? '',
        ];
    }
    report_download(post('export_action'), [
        'filename' => 'assets_register',
        'title' => 'Asset Register',
        'orientation' => 'landscape',
        'meta' => [
            ['Assets', (string) count($assets)],
        ],
        'summary' => [
            ['Purchase value', $purchaseTotal, 'money'],
            ['Current value', $totalValue, 'money'],
            ['Assets', count($assets), 'int'],
        ],
        'tables' => [[
            'title' => 'Assets',
            'columns' => [
                ['label' => 'Sr No', 'type' => 'text', 'width' => '5%', 'xls_width' => 35],
                ['label' => 'Asset', 'type' => 'text', 'width' => '18%', 'xls_width' => 160],
                ['label' => 'Company', 'type' => 'text', 'width' => '14%', 'xls_width' => 130],
                ['label' => 'Type', 'type' => 'text', 'width' => '12%', 'xls_width' => 100],
                ['label' => 'Purchase date', 'type' => 'text', 'width' => '10%', 'xls_width' => 90],
                ['label' => 'Purchase (INR)', 'type' => 'money', 'width' => '12%', 'xls_width' => 110],
                ['label' => 'Current (INR)', 'type' => 'money', 'width' => '12%', 'xls_width' => 110],
                ['label' => 'Notes', 'type' => 'text', 'width' => '17%', 'xls_width' => 160],
            ],
            'rows' => $assetRows,
            'totals' => ['', 'TOTAL', '', '', '', $purchaseTotal, $totalValue, ''],
        ]],
        'notes' => [
            'System-generated asset register. Current value uses purchase value when current is not set.',
            'Confidential — internal use only.',
        ],
    ]);
    redirect('pages/assets.php');
}

require __DIR__ . '/../includes/header.php';
?>
<div class="stat-grid" style="grid-template-columns:repeat(2,1fr)">
  <div class="stat-card"><div class="stat-label">Total asset value</div><div class="stat-value"><?= money($totalValue) ?></div></div>
  <div class="stat-card"><div class="stat-label">Assets</div><div class="stat-value"><?= count($assets) ?></div></div>
</div>
<div class="card">
  <?php if (!$assets): ?>
    <div class="empty"><strong>No assets</strong><p>Register vehicles, equipment and other company assets.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Name</th><th>Company</th><th>Type</th><th>Purchase date</th><th class="num">Purchase</th><th class="num">Current</th><th class="actions">Actions</th></tr></thead>
        <tbody>
          <?php foreach ($assets as $a): ?>
            <tr>
              <td><strong><?= e($a['name']) ?></strong></td>
              <td><?= e($a['company_name']) ?></td>
              <td><?= e($a['asset_type'] ?? '—') ?></td>
              <td><?= e($a['purchase_date'] ?? '—') ?></td>
              <td class="num"><?= money($a['purchase_value']) ?></td>
              <td class="num"><?= money($a['current_value'] ?? $a['purchase_value']) ?></td>
              <td class="actions">
                <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/assets.php?action=edit&id=' . $a['id'])) ?>">Edit</a>
                <?php if (can_delete()): ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete this asset and its purchase ledger entry?">Delete</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
