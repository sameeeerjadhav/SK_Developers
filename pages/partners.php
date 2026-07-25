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
        $companyId = post('company_id') !== '' ? (int) post('company_id') : null;
        $name = post('name', '');
        $phone = post('phone', '');
        $email = post('email', '');
        $share = post('share_percent') !== '' ? (float) post('share_percent') : null;
        $invested = (float) post('invested_amount', 0);
        $notes = post('notes', '');
        $editId = (int) post('id', 0);
        if ($name === '') {
            flash('error', 'Partner name is required.');
            redirect('pages/partners.php?action=add');
        }
        if ($editId) {
            $stmt = $pdo->prepare('UPDATE partners SET company_id=?, name=?, phone=?, email=?, share_percent=?, invested_amount=?, notes=? WHERE id=?');
            $stmt->execute([$companyId, $name, $phone, $email, $share, $invested, $notes, $editId]);
            flash('success', 'Partner updated.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO partners (company_id, name, phone, email, share_percent, invested_amount, notes) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$companyId, $name, $phone, $email, $share, $invested, $notes]);
            flash('success', 'Partner added.');
        }
        redirect('pages/partners.php');
    }
    if ($postAction === 'delete') {
        $stmt = $pdo->prepare('DELETE FROM partners WHERE id = ?');
        $stmt->execute([(int) post('id', 0)]);
        flash('success', 'Partner deleted.');
        redirect('pages/partners.php');
    }
}

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
        <input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>">
        <div>
          <label>Company</label>
          <select name="company_id"><?= company_options($pdo, (int)($row['company_id'] ?? 0)) ?></select>
        </div>
        <div>
          <label>Share %</label>
          <input type="number" step="0.01" name="share_percent" value="<?= e((string)($row['share_percent'] ?? '')) ?>">
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
        <div>
          <label>Invested amount (₹)</label>
          <input type="number" step="0.01" name="invested_amount" value="<?= e((string)($row['invested_amount'] ?? '0')) ?>">
        </div>
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
$pageSub = 'Partner directory and capital contributions.';
$pageActions = '<a class="btn btn-primary" href="' . e(base_url('pages/partners.php?action=add')) . '">+ Add partner</a>';
$partners = $pdo->query('SELECT pr.*, c.name AS company_name FROM partners pr LEFT JOIN companies c ON c.id = pr.company_id ORDER BY pr.name')->fetchAll();
$partnerCredits = sum_by_category_slug($pdo, 'credit', 'partner');
require __DIR__ . '/../includes/header.php';
?>
<div class="stat-grid" style="grid-template-columns:repeat(2,1fr)">
  <div class="stat-card"><div class="stat-label">Partner credits (ledger)</div><div class="stat-value"><?= money($partnerCredits) ?></div></div>
  <div class="stat-card"><div class="stat-label">Registered partners</div><div class="stat-value"><?= count($partners) ?></div></div>
</div>
<div class="card">
  <?php if (!$partners): ?>
    <div class="empty"><strong>No partners</strong><p>Add partners linked to main or sub companies.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Name</th><th>Company</th><th>Contact</th><th class="num">Share %</th><th class="num">Invested</th><th class="actions">Actions</th></tr></thead>
        <tbody>
          <?php foreach ($partners as $p): ?>
            <tr>
              <td><strong><?= e($p['name']) ?></strong></td>
              <td><?= e($p['company_name'] ?? '—') ?></td>
              <td><?= e($p['phone'] ?: $p['email'] ?: '—') ?></td>
              <td class="num"><?= $p['share_percent'] !== null ? e((string)$p['share_percent']) . '%' : '—' ?></td>
              <td class="num"><?= money($p['invested_amount']) ?></td>
              <td class="actions">
                <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/partners.php?action=edit&id=' . $p['id'])) ?>">Edit</a>
                <form method="post" style="display:inline" onsubmit="return confirm('Delete partner?')">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
