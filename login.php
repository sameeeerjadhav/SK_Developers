<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (!$pdo) {
    redirect('install.php');
}

if (current_user()) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = post('email', '');
    $password = post('password', '');
    if ($email === '' || $password === '') {
        $error = 'Email and password are required.';
    } elseif (attempt_login($pdo, $email, $password)) {
        redirect('index.php');
    } else {
        $error = 'Invalid email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in — Sai Kuber Developers</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/app.css')) ?>">
</head>
<body>
<div class="login-page">
  <div class="orb orb-1"></div>
  <div class="orb orb-2"></div>
  <section class="login-hero">
    <div class="login-kicker">Finance ERP</div>
    <h1 class="login-brand">Sai Kuber<br>Developers</h1>
    <p class="login-tagline">Track investments, projects, bank accounts, and expenses across main and sub companies — in one calm workspace.</p>
    <ul class="login-features">
      <li>Main + Infra, Construction &amp; Developers</li>
      <li>Project credits, land purchase &amp; expenses</li>
      <li>Live bank balances and total summary</li>
    </ul>
  </section>
  <section class="login-panel-wrap">
    <div class="login-panel">
      <h2>Welcome back</h2>
      <p>Sign in to manage company finances.</p>
      <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
      <?php endif; ?>
      <form method="post" autocomplete="on">
        <?= csrf_field() ?>
        <div class="field">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" required value="<?= e(post('email', '')) ?>" autofocus>
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required>
        </div>
        <button class="btn btn-primary" type="submit">Sign in</button>
      </form>
    </div>
  </section>
</div>
</body>
</html>
