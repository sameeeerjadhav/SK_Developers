<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$q = get('q', '');
$pageTitle = 'Audit log';
$pageSub = 'Who changed what, and when.';
$sql = 'SELECT * FROM audit_logs WHERE 1=1';
$params = [];
if ($q !== '') {
    $sql .= ' AND (summary LIKE ? OR user_name LIKE ? OR entity_type LIKE ? OR action LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
$sql .= ' ORDER BY id DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<form class="filters" method="get">
  <div class="field">
    <label>Search</label>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="User, action, entity…">
  </div>
  <div class="field" style="flex:0"><label>&nbsp;</label><button class="btn btn-outline" type="submit">Filter</button></div>
</form>
<div class="card">
  <?php if (!$rows): ?>
    <div class="empty"><strong>No audit entries yet</strong><p>Creates, updates, transfers and EMI payments will appear here.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>When</th><th>User</th><th>Action</th><th>Entity</th><th>Summary</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= e($r['created_at']) ?></td>
              <td><?= e($r['user_name'] ?? 'System') ?></td>
              <td><?= e($r['action']) ?></td>
              <td><?= e($r['entity_type']) ?> #<?= (int)($r['entity_id'] ?? 0) ?></td>
              <td><?= e($r['summary']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
