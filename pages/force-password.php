<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $pass = post('password', '');
    $confirm = post('confirm_password', '');
    if (strlen($pass) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($pass !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?')
            ->execute([$hash, current_user()['id']]);
        unset($_SESSION['must_change_password']);
        audit_log($pdo, 'update', 'user', (int) current_user()['id'], 'Forced password change completed');
        flash('success', 'Password updated.');
        redirect('index.php');
    }
}

$pageTitle = 'Change password';
$pageSub = 'You must set a new password before continuing.';
require __DIR__ . '/../includes/header.php';
?>
<div class="card" style="max-width:520px">
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <div class="full"><label>New password</label><input type="password" name="password" required minlength="6"></div>
    <div class="full"><label>Confirm password</label><input type="password" name="confirm_password" required minlength="6"></div>
    <div class="full form-actions"><button class="btn btn-primary" type="submit">Save & continue</button></div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
