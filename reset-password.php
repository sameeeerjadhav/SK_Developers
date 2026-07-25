<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

$token = get('token', '');
$error = '';
$ok = false;

$row = null;
if ($token !== '') {
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare('SELECT pr.*, u.email FROM password_resets pr JOIN users u ON u.id = pr.user_id WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at >= NOW() LIMIT 1');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
}

if (!$row) {
    $error = 'Reset link is invalid or expired.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $row) {
    verify_csrf();
    $pass = post('password', '');
    $confirm = post('confirm_password', '');
    if (strlen($pass) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($pass !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hashPass = password_hash($pass, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?')->execute([$hashPass, (int)$row['user_id']]);
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);
        $ok = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reset password — Sai Kuber</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=Sora:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/app.css')) ?>">
</head>
<body>
<div class="login-page" style="grid-template-columns:1fr">
  <section class="login-panel-wrap">
    <div class="login-panel">
      <h2>Reset password</h2>
      <?php if ($ok): ?>
        <div class="alert alert-success">Password updated. You can sign in now.</div>
        <a class="btn btn-primary" href="<?= e(base_url('login.php')) ?>" style="width:100%;display:inline-flex;justify-content:center">Sign in</a>
      <?php elseif ($error && !$row): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
        <a href="<?= e(base_url('forgot-password.php')) ?>">Request a new link</a>
      <?php else: ?>
        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
        <p>Resetting password for <strong><?= e($row['email']) ?></strong></p>
        <form method="post">
          <?= csrf_field() ?>
          <div class="field"><label>New password</label><input type="password" name="password" required minlength="6"></div>
          <div class="field"><label>Confirm</label><input type="password" name="confirm_password" required minlength="6"></div>
          <button class="btn btn-primary" type="submit" style="width:100%">Update password</button>
        </form>
      <?php endif; ?>
    </div>
  </section>
</div>
</body>
</html>
