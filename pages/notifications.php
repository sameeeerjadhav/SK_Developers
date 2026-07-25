<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$pageTitle = 'Notifications';
$pageSub = 'EMI due, deposit maturity, and low balances.';
$notes = system_notifications($pdo);
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
  <?php if (!$notes): ?>
    <div class="empty"><strong>All clear</strong><p>No upcoming EMI, maturities, or low balances right now.</p></div>
  <?php else: ?>
    <div style="display:grid;gap:0.75rem">
      <?php foreach ($notes as $n): ?>
        <a class="highlight-box" href="<?= e(base_url($n['href'])) ?>" style="display:block;text-decoration:none;color:inherit;border-color:<?= $n['type']==='danger'?'#fecaca':($n['type']==='warning'?'#fde68a':'#bae6fd') ?>;background:<?= $n['type']==='danger'?'#fef2f2':($n['type']==='warning'?'#fffbeb':'#f0f9ff') ?>">
          <strong><?= e($n['title']) ?></strong>
          <div class="muted" style="margin-top:0.25rem"><?= e($n['body']) ?></div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
