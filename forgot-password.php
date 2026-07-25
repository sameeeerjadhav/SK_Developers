<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(post('email', ''));
    if ($email === '') {
        $error = 'Email is required.';
    } else {
        $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE email = ? AND status = "active" LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        // Always show success message to avoid email enumeration
        $message = 'If that email exists, a reset link is ready below (Hostinger shared hosting — link shown on-screen).';
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([(int)$user['id']]);
            $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 1 HOUR))')
                ->execute([(int)$user['id'], $hash]);
            $resetUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\')
                . '/reset-password.php?token=' . urlencode($token);
            $message .= ' Reset link: ' . $resetUrl;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Forgot password — Sai Kuber</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=Sora:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/app.css')) ?>">
</head>
<body>
<div class="login-page" style="grid-template-columns:1fr">
  <section class="login-panel-wrap">
    <div class="login-panel">
      <h2>Forgot password</h2>
      <p>Enter your account email to get a reset link.</p>
      <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
      <?php if ($message): ?><div class="alert alert-success" style="word-break:break-all"><?= e($message) ?></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="field"><label>Email</label><input type="email" name="email" required></div>
        <button class="btn btn-primary" type="submit" style="width:100%">Continue</button>
      </form>
      <div class="login-hint"><a href="<?= e(base_url('login.php')) ?>">Back to sign in</a></div>
    </div>
  </section>
</div>
</body>
</html>
