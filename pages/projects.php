<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$action = get('action', 'list');
$id = (int) get('id', 0);
$filterCompany = (int) get('company_id', 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $postAction = post('action', '');

    if ($postAction === 'save') {
        $companyId = (int) post('company_id', 0);
        $name = post('name', '');
        $location = post('location', '');
        $status = post('status', 'active');
        $start = post('start_date') ?: null;
        $end = post('end_date') ?: null;
        $notes = post('notes', '');
        $deedName = post('deed_name', '');
        $partyName = post('party_name', '');
        $surveyNo = post('survey_no', '');
        $areaSqft = post('area_sqft') !== '' ? (float) post('area_sqft') : null;
        $address = post('address', '');
        $editId = (int) post('id', 0);

        if (!$companyId || $name === '') {
            flash('error', 'Company and project name are required.');
            redirect('pages/projects.php?action=add');
        }

        if ($editId) {
            $stmt = $pdo->prepare('UPDATE projects SET company_id=?, name=?, location=?, status=?, start_date=?, end_date=?, notes=?, deed_name=?, party_name=?, survey_no=?, area_sqft=?, address=? WHERE id=?');
            $stmt->execute([$companyId, $name, $location, $status, $start, $end, $notes, $deedName, $partyName, $surveyNo, $areaSqft, $address, $editId]);
            flash('success', 'Project updated.');
            redirect('pages/project-view.php?id=' . $editId);
        }

        $stmt = $pdo->prepare('INSERT INTO projects (company_id, name, location, status, start_date, end_date, notes, deed_name, party_name, survey_no, area_sqft, address) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$companyId, $name, $location, $status, $start, $end, $notes, $deedName, $partyName, $surveyNo, $areaSqft, $address]);
        $newId = (int) $pdo->lastInsertId();
        flash('success', 'Project created.');
        redirect('pages/project-view.php?id=' . $newId);
    }

    if ($postAction === 'delete') {
        $delId = (int) post('id', 0);
        $stmt = $pdo->prepare('DELETE FROM projects WHERE id = ?');
        $stmt->execute([$delId]);
        flash('success', 'Project deleted.');
        redirect('pages/projects.php');
    }
}

if ($action === 'add' || $action === 'edit') {
    $project = null;
    if ($action === 'edit' && $id) {
        $stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
        $stmt->execute([$id]);
        $project = $stmt->fetch();
        if (!$project) {
            flash('error', 'Project not found.');
            redirect('pages/projects.php');
        }
    }
    $pageTitle = $action === 'edit' ? 'Edit project' : 'Add project';
    $pageSub = 'Each company has its own projects with credit, land purchase and expenses.';
    $pageActions = '<a class="btn btn-outline" href="' . e(base_url('pages/projects.php')) . '">Back</a>';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="card">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($project['id'] ?? 0) ?>">
        <div>
          <label>Company</label>
          <select name="company_id" required>
            <?= company_options($pdo, (int) ($project['company_id'] ?? $filterCompany ?: 0)) ?>
          </select>
        </div>
        <div>
          <label>Status</label>
          <select name="status">
            <?php foreach (['planning','active','completed','on_hold'] as $st): ?>
              <option value="<?= $st ?>" <?= (($project['status'] ?? 'active') === $st) ? 'selected' : '' ?>><?= e(ucwords(str_replace('_',' ',$st))) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="full">
          <label>Project name</label>
          <input type="text" name="name" required value="<?= e($project['name'] ?? '') ?>">
        </div>
        <div>
          <label>Location</label>
          <input type="text" name="location" value="<?= e($project['location'] ?? '') ?>">
        </div>
        <div>
          <label>Start date</label>
          <input type="date" name="start_date" value="<?= e($project['start_date'] ?? '') ?>">
        </div>
        <div>
          <label>End date</label>
          <input type="date" name="end_date" value="<?= e($project['end_date'] ?? '') ?>">
        </div>
        <div>
          <label>Deed name</label>
          <input type="text" name="deed_name" value="<?= e($project['deed_name'] ?? '') ?>">
        </div>
        <div>
          <label>Party name</label>
          <input type="text" name="party_name" value="<?= e($project['party_name'] ?? '') ?>">
        </div>
        <div>
          <label>Survey No. (S.No.)</label>
          <input type="text" name="survey_no" value="<?= e($project['survey_no'] ?? '') ?>">
        </div>
        <div>
          <label>Area (sqft)</label>
          <input type="number" step="0.01" name="area_sqft" value="<?= e((string) ($project['area_sqft'] ?? '')) ?>">
        </div>
        <div class="full">
          <label>Address</label>
          <textarea name="address"><?= e($project['address'] ?? '') ?></textarea>
        </div>
        <div class="full">
          <label>Notes</label>
          <textarea name="notes"><?= e($project['notes'] ?? '') ?></textarea>
        </div>
        <div class="full form-actions">
          <button class="btn btn-primary" type="submit">Save project</button>
        </div>
      </form>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$pageTitle = 'Projects';
$pageSub = 'Projects across main company and all sub companies.';
$pageActions = '<a class="btn btn-primary" href="' . e(base_url('pages/projects.php?action=add' . ($filterCompany ? '&company_id=' . $filterCompany : ''))) . '">+ Add project</a>';

$sql = 'SELECT p.*, c.name AS company_name FROM projects p JOIN companies c ON c.id = p.company_id';
$params = [];
if ($filterCompany) {
    $sql .= ' WHERE p.company_id = ?';
    $params[] = $filterCompany;
}
$sql .= ' ORDER BY p.created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$projects = $stmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<form class="filters" method="get">
  <div class="field">
    <label>Company</label>
    <select name="company_id" onchange="this.form.submit()">
      <option value="">All companies</option>
      <?php
      $cos = $pdo->query('SELECT id, name FROM companies ORDER BY type, name')->fetchAll();
      foreach ($cos as $co):
      ?>
        <option value="<?= (int) $co['id'] ?>" <?= $filterCompany === (int)$co['id'] ? 'selected' : '' ?>><?= e($co['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</form>

<div class="card">
  <?php if (!$projects): ?>
    <div class="empty"><strong>No projects found</strong><p>Create a project to start tracking credit and expenses.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>Project</th>
            <th>Company</th>
            <th>Status</th>
            <th class="num">Credits</th>
            <th class="num">Debits</th>
            <th class="num">Profit</th>
            <th class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($projects as $p):
            $credits = sum_transactions($pdo, 'credit', null, (int) $p['id']);
            $debits = sum_transactions($pdo, 'debit', null, (int) $p['id']);
            $profit = $credits - $debits;
          ?>
            <tr>
              <td>
                <strong><a href="<?= e(base_url('pages/project-view.php?id=' . $p['id'])) ?>"><?= e($p['name']) ?></a></strong>
                <div class="muted" style="font-size:0.75rem"><?= e($p['location'] ?? '') ?></div>
              </td>
              <td><?= e($p['company_name']) ?></td>
              <td><?= status_chip($p['status']) ?></td>
              <td class="num text-success"><?= money($credits) ?></td>
              <td class="num text-danger"><?= money($debits) ?></td>
              <td class="num <?= $profit >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($profit) ?></td>
              <td class="actions">
                <a class="btn btn-primary btn-sm" href="<?= e(base_url('pages/project-view.php?id=' . $p['id'])) ?>">Open</a>
                <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/projects.php?action=edit&id=' . $p['id'])) ?>">Edit</a>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete this project? Linked transactions stay but lose the project link.">Delete</button>
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
