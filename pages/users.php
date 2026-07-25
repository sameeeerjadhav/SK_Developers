<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();
require_admin();

$action = get('action', 'list');
$id = (int) get('id', 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $postAction = post('action', '');

    if ($postAction === 'save') {
        $name = post('name', '');
        $email = post('email', '');
        $role = post('role', 'staff');
        $status = post('status', 'active');
        $password = post('password', '');
        $editId = (int) post('id', 0);

        if ($name === '' || $email === '') {
            flash('error', 'Name and email are required.');
            redirect('pages/users.php?action=' . ($editId ? 'edit&id=' . $editId : 'add'));
        }
        if (!in_array($role, ['admin', 'staff'], true)) {
            $role = 'staff';
        }

        if ($editId) {
            if ($password !== '') {
                if (strlen($password) < 6) {
                    flash('error', 'Password must be at least 6 characters.');
                    redirect('pages/users.php?action=edit&id=' . $editId);
                }
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('UPDATE users SET name=?, email=?, role=?, status=?, password=?, must_change_password=1 WHERE id=?');
                $stmt->execute([$name, $email, $role, $status, $hash, $editId]);
            } else {
                $stmt = $pdo->prepare('UPDATE users SET name=?, email=?, role=?, status=? WHERE id=?');
                $stmt->execute([$name, $email, $role, $status, $editId]);
            }
            audit_log($pdo, 'update', 'user', $editId, 'Updated user ' . $email);
            // Prevent disabling yourself
            if ($editId === (int) current_user()['id'] && $status === 'disabled') {
                $pdo->prepare('UPDATE users SET status="active" WHERE id=?')->execute([$editId]);
                flash('error', 'You cannot disable your own account.');
                redirect('pages/users.php');
            }
            flash('success', 'User updated.');
        } else {
            if (strlen($password) < 6) {
                flash('error', 'Password must be at least 6 characters.');
                redirect('pages/users.php?action=add');
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            try {
                $stmt = $pdo->prepare('INSERT INTO users (name, email, password, role, status, must_change_password) VALUES (?,?,?,?,?,1)');
                $stmt->execute([$name, $email, $hash, $role, $status]);
                audit_log($pdo, 'create', 'user', (int)$pdo->lastInsertId(), 'Created user ' . $email . ' (' . $role . ')');
                flash('success', 'User created. They must change password on first login.');
            } catch (Throwable $e) {
                flash('error', 'Could not create user (email may already exist).');
                redirect('pages/users.php?action=add');
            }
        }
        redirect('pages/users.php');
    }
}

if ($action === 'add' || $action === 'edit') {
    $row = null;
    if ($action === 'edit' && $id) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
    }
    $pageTitle = $action === 'edit' ? 'Edit user' : 'Add user';
    $pageSub = 'Admins have full access. Staff can add/edit records but cannot delete critical data or manage users.';
    $pageActions = '<a class="btn btn-outline" href="' . e(base_url('pages/users.php')) . '">Back</a>';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="card" style="max-width:640px">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>">
        <div>
          <label>Name</label>
          <input type="text" name="name" required value="<?= e($row['name'] ?? '') ?>">
        </div>
        <div>
          <label>Email</label>
          <input type="email" name="email" required value="<?= e($row['email'] ?? '') ?>">
        </div>
        <div>
          <label>Role</label>
          <select name="role">
            <option value="staff" <?= (($row['role'] ?? 'staff') === 'staff') ? 'selected' : '' ?>>Staff</option>
            <option value="admin" <?= (($row['role'] ?? '') === 'admin') ? 'selected' : '' ?>>Admin</option>
          </select>
        </div>
        <div>
          <label>Status</label>
          <select name="status">
            <option value="active" <?= (($row['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
            <option value="disabled" <?= (($row['status'] ?? '') === 'disabled') ? 'selected' : '' ?>>Disabled</option>
          </select>
        </div>
        <div class="full">
          <label><?= $row ? 'New password (leave blank to keep)' : 'Password' ?></label>
          <input type="password" name="password" <?= $row ? '' : 'required minlength="6"' ?> autocomplete="new-password">
          <?php if (!$row): ?><p class="muted" style="margin:0.35rem 0 0;font-size:0.8rem">New users must change this password on first login.</p><?php endif; ?>
        </div>
        <div class="full form-actions"><button class="btn btn-primary" type="submit">Save user</button></div>
      </form>
    </div>
    <?php require __DIR__ . '/../includes/footer.php'; exit;
}

$pageTitle = 'Users & roles';
$pageSub = 'Manage admin and staff access.';
$pageActions = '<a class="btn btn-primary" href="' . e(base_url('pages/users.php?action=add')) . '">+ Add user</a>';
$users = $pdo->query('SELECT id, name, email, role, status, created_at FROM users ORDER BY role, name')->fetchAll();
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th class="actions">Actions</th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><strong><?= e($u['name']) ?></strong></td>
            <td><?= e($u['email']) ?></td>
            <td><?= status_chip($u['role'] === 'admin' ? 'active' : 'planning') ?> <?= e(ucfirst($u['role'])) ?></td>
            <td><?= status_chip($u['status'] ?? 'active') ?></td>
            <td class="actions">
              <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/users.php?action=edit&id=' . $u['id'])) ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
