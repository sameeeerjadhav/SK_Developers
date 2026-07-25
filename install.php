<?php
declare(strict_types=1);

$lockFile = __DIR__ . '/config/installed.lock';
$dbFile = __DIR__ . '/config/database.php';

// If already installed and DB works, bounce to login
if (is_file($lockFile) && is_file($dbFile)) {
    require __DIR__ . '/includes/bootstrap.php';
    if ($pdo) {
        redirect('login.php');
    }
}

require_once __DIR__ . '/includes/functions.php';

$error = '';
$success = '';
$step = 'form';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION)) {
        session_start();
    }
    $host = trim($_POST['host'] ?? 'localhost');
    $dbname = trim($_POST['dbname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $adminName = trim($_POST['admin_name'] ?? 'Admin');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPass = (string) ($_POST['admin_password'] ?? '');
    $importSchema = !empty($_POST['import_schema']);

    if ($dbname === '' || $username === '' || $adminEmail === '' || strlen($adminPass) < 6) {
        $error = 'Database name, username, admin email and password (min 6 chars) are required.';
    } else {
        try {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $dbname);
            $test = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            $php = "<?php\nreturn [\n"
                . "    'host'     => " . var_export($host, true) . ",\n"
                . "    'dbname'   => " . var_export($dbname, true) . ",\n"
                . "    'username' => " . var_export($username, true) . ",\n"
                . "    'password' => " . var_export($password, true) . ",\n"
                . "    'charset'  => 'utf8mb4',\n"
                . "];\n";

            if (!is_dir(__DIR__ . '/config')) {
                mkdir(__DIR__ . '/config', 0755, true);
            }
            if (file_put_contents($dbFile, $php) === false) {
                throw new RuntimeException('Could not write config/database.php. Check folder permissions.');
            }

            if ($importSchema) {
                $sql = file_get_contents(__DIR__ . '/sql/schema.sql');
                if ($sql === false) {
                    throw new RuntimeException('Could not read sql/schema.sql');
                }
                // Split on semicolons carefully enough for our schema file
                $test->exec('SET FOREIGN_KEY_CHECKS=0');
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                    // Strip SQL line comments then run
                    $clean = preg_replace('/^--.*$/m', '', $statement);
                    $clean = trim((string) $clean);
                    if ($clean !== '') {
                        $test->exec($clean);
                    }
                }
                $test->exec('SET FOREIGN_KEY_CHECKS=1');

                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                $stmt = $test->prepare('UPDATE users SET name = ?, email = ?, password = ? WHERE id = 1');
                $stmt->execute([$adminName, $adminEmail, $hash]);
                if ($stmt->rowCount() === 0) {
                    $ins = $test->prepare('INSERT INTO users (name, email, password, role) VALUES (?,?,?,"admin")');
                    $ins->execute([$adminName, $adminEmail, $hash]);
                }
            }

            file_put_contents($lockFile, date('c') . "\ninstalled_by=web\n");
            $success = 'Installation complete. You can sign in now.';
            $step = 'done';
        } catch (Throwable $ex) {
            $error = $ex->getMessage();
        }
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Install — Sai Kuber Developers</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="login-page" style="grid-template-columns:1fr">
  <div class="orb orb-1"></div>
  <div class="orb orb-2"></div>
  <section class="login-panel-wrap">
    <div class="login-panel" style="max-width:560px">
      <div class="login-kicker" style="margin-bottom:0.5rem">Hostinger setup</div>
      <h2>Install Finance ERP</h2>
      <p>Connect MySQL, import schema, and create your admin login.</p>

      <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
      <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

      <?php if ($step === 'done'): ?>
        <a class="btn btn-primary" href="login.php" style="width:100%;display:inline-flex;justify-content:center">Go to login</a>
        <p class="login-hint">For security, delete or lock <code>install.php</code> after setup (a lock file already prevents re-run).</p>
      <?php else: ?>
      <form method="post" class="form-grid">
        <div>
          <label>DB host</label>
          <input type="text" name="host" value="<?= e($_POST['host'] ?? 'localhost') ?>" required>
        </div>
        <div>
          <label>DB name</label>
          <input type="text" name="dbname" value="<?= e($_POST['dbname'] ?? '') ?>" required placeholder="uXXXX_saikuber">
        </div>
        <div>
          <label>DB username</label>
          <input type="text" name="username" value="<?= e($_POST['username'] ?? '') ?>" required>
        </div>
        <div>
          <label>DB password</label>
          <input type="password" name="password" value="">
        </div>
        <div class="full"><hr style="border:0;border-top:1px solid var(--border)"></div>
        <div>
          <label>Admin name</label>
          <input type="text" name="admin_name" value="<?= e($_POST['admin_name'] ?? 'Admin') ?>" required>
        </div>
        <div>
          <label>Admin email</label>
          <input type="email" name="admin_email" value="<?= e($_POST['admin_email'] ?? '') ?>" required>
        </div>
        <div class="full">
          <label>Admin password</label>
          <input type="password" name="admin_password" required minlength="6" placeholder="Min 6 characters">
        </div>
        <div class="full highlight-box">
          <label style="display:flex;gap:0.55rem;align-items:flex-start;margin:0;font-weight:600;color:var(--text)">
            <input type="checkbox" name="import_schema" value="1" checked style="width:auto;margin-top:0.2rem">
            <span>Import full database schema (companies, categories, sample projects). <strong>This drops existing SKD tables if they exist.</strong></span>
          </label>
        </div>
        <div class="full form-actions">
          <button class="btn btn-primary" type="submit" style="width:100%">Install now</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </section>
</div>
</body>
</html>
