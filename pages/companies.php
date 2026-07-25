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
        $name = post('name', '');
        $type = post('type', 'sub');
        $parentId = post('parent_id') !== '' ? (int) post('parent_id') : null;
        $description = post('description', '');
        $status = post('status', 'active');
        $editId = (int) post('id', 0);

        if ($name === '') {
            flash('error', 'Company name is required.');
            redirect('pages/companies.php?action=' . ($editId ? 'edit&id=' . $editId : 'add'));
        }

        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $slug = trim($slug, '-');

        if ($editId) {
            $stmt = $pdo->prepare('UPDATE companies SET name=?, type=?, parent_id=?, description=?, status=? WHERE id=?');
            $stmt->execute([$name, $type, $parentId, $description, $status, $editId]);
            flash('success', 'Company updated.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO companies (name, slug, type, parent_id, description, status) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$name, $slug . '-' . time(), $type, $parentId, $description, $status]);
            flash('success', 'Company created.');
        }
        redirect('pages/companies.php');
    }

    if ($postAction === 'delete') {
        $delId = (int) post('id', 0);
        $stmt = $pdo->prepare('DELETE FROM companies WHERE id = ? AND type = "sub"');
        $stmt->execute([$delId]);
        flash('success', 'Company deleted (if it was a sub company).');
        redirect('pages/companies.php');
    }
}

if ($action === 'add' || $action === 'edit') {
    $company = null;
    if ($action === 'edit' && $id) {
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$id]);
        $company = $stmt->fetch();
        if (!$company) {
            flash('error', 'Company not found.');
            redirect('pages/companies.php');
        }
    }
    $pageTitle = $action === 'edit' ? 'Edit company' : 'Add company';
    $pageSub = 'Main company and sub companies (Infra, Construction, Developers).';
    $pageActions = '<a class="btn btn-outline" href="' . e(base_url('pages/companies.php')) . '">Back</a>';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="card" style="max-width:720px">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($company['id'] ?? 0) ?>">
        <div class="full">
          <label>Name</label>
          <input type="text" name="name" required value="<?= e($company['name'] ?? '') ?>">
        </div>
        <div>
          <label>Type</label>
          <select name="type">
            <option value="main" <?= (($company['type'] ?? '') === 'main') ? 'selected' : '' ?>>Main company</option>
            <option value="sub" <?= (($company['type'] ?? 'sub') === 'sub') ? 'selected' : '' ?>>Sub company</option>
          </select>
        </div>
        <div>
          <label>Parent company</label>
          <select name="parent_id">
            <option value="">None</option>
            <?php
            $parents = $pdo->query('SELECT id, name FROM companies WHERE type = "main" ORDER BY name')->fetchAll();
            foreach ($parents as $p):
            ?>
              <option value="<?= (int) $p['id'] ?>" <?= ((int)($company['parent_id'] ?? 0) === (int)$p['id']) ? 'selected' : '' ?>><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Status</label>
          <select name="status">
            <option value="active" <?= (($company['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= (($company['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
          </select>
        </div>
        <div class="full">
          <label>Description</label>
          <textarea name="description"><?= e($company['description'] ?? '') ?></textarea>
        </div>
        <div class="full form-actions">
          <button class="btn btn-primary" type="submit">Save company</button>
        </div>
      </form>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$pageTitle = 'Companies';
$pageSub = 'Main company wired to Infra, Construction and Developers.';
$pageActions = '<a class="btn btn-primary" href="' . e(base_url('pages/companies.php?action=add')) . '">+ Add company</a>';
$companies = $pdo->query('SELECT c.*, p.name AS parent_name FROM companies c LEFT JOIN companies p ON p.id = c.parent_id ORDER BY c.type ASC, c.id ASC')->fetchAll();
require __DIR__ . '/../includes/header.php';
?>

<div class="company-grid" style="margin-bottom:1.15rem">
  <?php foreach ($companies as $co):
    $s = summary_totals($pdo, (int) $co['id']);
  ?>
    <div class="company-card" style="cursor:default">
      <div class="kicker"><?= $co['type'] === 'main' ? 'Main company' : 'Sub company' ?></div>
      <h3><?= e($co['name']) ?></h3>
      <div class="meta"><?= e($co['parent_name'] ? 'Under ' . $co['parent_name'] : 'Root') ?> · <?= status_chip($co['status']) ?></div>
      <div class="ledger-total" style="margin-top:0.9rem">
        <span class="muted">Profit</span>
        <span class="<?= $s['profit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($s['profit']) ?></span>
      </div>
      <div class="form-actions" style="justify-content:flex-start;margin-top:0.85rem">
        <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/projects.php?company_id=' . $co['id'])) ?>">Projects</a>
        <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/companies.php?action=edit&id=' . $co['id'])) ?>">Edit</a>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <h2 class="card-title">All companies</h2>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Name</th>
          <th>Type</th>
          <th>Parent</th>
          <th>Status</th>
          <th class="actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($companies as $co): ?>
          <tr>
            <td><strong><?= e($co['name']) ?></strong></td>
            <td><?= e(ucfirst($co['type'])) ?></td>
            <td><?= e($co['parent_name'] ?? '—') ?></td>
            <td><?= status_chip($co['status']) ?></td>
            <td class="actions">
              <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/companies.php?action=edit&id=' . $co['id'])) ?>">Edit</a>
              <?php if ($co['type'] === 'sub'): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Delete this sub company?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $co['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
