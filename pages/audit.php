<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$q = get('q', '');
$pageTitle = 'Audit log';
$pageSub = 'Who changed what, and when.';
$where = 'WHERE 1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (summary LIKE ? OR user_name LIKE ? OR entity_type LIKE ? OR action LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs $where");
$countStmt->execute($params);
$list = paginate_meta((int) $countStmt->fetchColumn());
$sql = "SELECT * FROM audit_logs $where ORDER BY id DESC LIMIT " . (int) $list['limit'] . ' OFFSET ' . (int) $list['offset'];
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<form class="filters" method="get">
  <?= list_limit_hidden() ?>
  <div class="field">
    <label>Search</label>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="User, action, entity…">
  </div>
  <div class="field" style="flex:0"><label>&nbsp;</label><button class="btn btn-outline" type="submit">Filter</button></div>
</form>
<div class="card" id="list">
  <div class="card-head">
    <h2 class="card-title">Audit entries</h2>
    <?php render_limit_control('audit.php'); ?>
  </div>
  <?php if (!$list['total']): ?>
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
    <?php render_pager('audit.php', $list); ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
