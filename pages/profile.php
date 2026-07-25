<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$user = current_user();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = post('name', '');
    $email = post('email', '');
    $current = post('current_password', '');
    $newPass = post('new_password', '');
    $confirm = post('confirm_password', '');

    if ($name === '' || $email === '') {
        $error = 'Name and email are required.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([(int) $user['id']]);
        $row = $stmt->fetch();

        if (!$row) {
            $error = 'User not found.';
        } else {
            // Email unique check
            $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
            $chk->execute([$email, (int) $user['id']]);
            if ($chk->fetch()) {
                $error = 'That email is already in use.';
            } elseif ($newPass !== '') {
                if (!password_verify($current, $row['password'])) {
                    $error = 'Current password is incorrect.';
                } elseif (strlen($newPass) < 6) {
                    $error = 'New password must be at least 6 characters.';
                } elseif ($newPass !== $confirm) {
                    $error = 'New password confirmation does not match.';
                } else {
                    $hash = password_hash($newPass, PASSWORD_DEFAULT);
                    $upd = $pdo->prepare('UPDATE users SET name = ?, email = ?, password = ? WHERE id = ?');
                    $upd->execute([$name, $email, $hash, (int) $user['id']]);
                    $_SESSION['user']['name'] = $name;
                    $_SESSION['user']['email'] = $email;
                    flash('success', 'Profile and password updated.');
                    redirect('pages/profile.php');
                }
            } else {
                $upd = $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?');
                $upd->execute([$name, $email, (int) $user['id']]);
                $_SESSION['user']['name'] = $name;
                $_SESSION['user']['email'] = $email;
                flash('success', 'Profile updated.');
                redirect('pages/profile.php');
            }
        }
    }
}

$pageTitle = 'Profile';
$pageSub = 'Update your name, email, or password.';
require __DIR__ . '/../includes/header.php';
$u = current_user();
?>
<div class="card" style="max-width:640px">
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <div>
      <label>Name</label>
      <input type="text" name="name" required value="<?= e(post('name', $u['name'] ?? '')) ?>">
    </div>
    <div>
      <label>Email</label>
      <input type="email" name="email" required value="<?= e(post('email', $u['email'] ?? '')) ?>">
    </div>
    <div class="full"><div class="card-title" style="margin:0.5rem 0 0">Change password</div>
      <p class="muted" style="margin:0.35rem 0 0;font-size:0.85rem">Leave blank to keep your current password.</p>
    </div>
    <div class="full">
      <label>Current password</label>
      <input type="password" name="current_password" autocomplete="current-password">
    </div>
    <div>
      <label>New password</label>
      <input type="password" name="new_password" autocomplete="new-password" minlength="6">
    </div>
    <div>
      <label>Confirm new password</label>
      <input type="password" name="confirm_password" autocomplete="new-password" minlength="6">
    </div>
    <div class="full form-actions">
      <button class="btn btn-primary" type="submit">Save profile</button>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
