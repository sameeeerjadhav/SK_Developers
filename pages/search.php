<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$q = trim(get('q', ''));
$pageTitle = 'Search';
$pageSub = $q !== '' ? 'Results for “' . $q . '”' : 'Find projects, partners, transactions, loans, accounts.';
$results = ['projects' => [], 'partners' => [], 'transactions' => [], 'loans' => [], 'accounts' => []];

if ($q !== '') {
    $like = '%' . $q . '%';
    $p = $pdo->prepare('SELECT p.id, p.name, c.name AS company_name FROM projects p JOIN companies c ON c.id=p.company_id WHERE p.name LIKE ? OR p.location LIKE ? LIMIT 20');
    $p->execute([$like, $like]);
    $results['projects'] = $p->fetchAll();

    $p = $pdo->prepare('SELECT pr.id, pr.name, c.name AS company_name FROM partners pr LEFT JOIN companies c ON c.id=pr.company_id WHERE pr.name LIKE ? OR pr.phone LIKE ? OR pr.email LIKE ? LIMIT 20');
    $p->execute([$like, $like, $like]);
    $results['partners'] = $p->fetchAll();

    $p = $pdo->prepare('SELECT t.id, t.amount, t.txn_date, t.description, cat.name AS category_name, c.name AS company_name FROM transactions t JOIN categories cat ON cat.id=t.category_id JOIN companies c ON c.id=t.company_id WHERE t.description LIKE ? OR t.reference_no LIKE ? OR cat.name LIKE ? ORDER BY t.txn_date DESC LIMIT 30');
    $p->execute([$like, $like, $like]);
    $results['transactions'] = $p->fetchAll();

    $p = $pdo->prepare('SELECT l.id, l.lender_name, l.loan_amount, c.name AS company_name FROM bank_loans l JOIN companies c ON c.id=l.company_id WHERE l.lender_name LIKE ? OR l.notes LIKE ? LIMIT 20');
    $p->execute([$like, $like]);
    $results['loans'] = $p->fetchAll();

    $p = $pdo->prepare('SELECT ba.id, ba.account_name, ba.bank_name, c.name AS company_name FROM bank_accounts ba JOIN companies c ON c.id=ba.company_id WHERE ba.account_name LIKE ? OR ba.bank_name LIKE ? OR ba.account_number LIKE ? LIMIT 20');
    $p->execute([$like, $like, $like]);
    $results['accounts'] = $p->fetchAll();
}

require __DIR__ . '/../includes/header.php';
?>
<form class="filters" method="get" action="<?= e(base_url('pages/search.php')) ?>">
  <div class="field" style="flex:2">
    <label>Search everything</label>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Project, partner, txn, loan, account…" autofocus>
  </div>
  <div class="field" style="flex:0"><label>&nbsp;</label><button class="btn btn-primary" type="submit">Search</button></div>
</form>

<?php if ($q === ''): ?>
  <div class="card"><div class="empty"><strong>Type to search</strong><p>Projects, partners, transactions, loans and bank accounts.</p></div></div>
<?php else: ?>
  <?php
  $sections = [
    'projects' => ['label' => 'Projects', 'link' => fn($r) => base_url('pages/project-view.php?id=' . $r['id']), 'text' => fn($r) => $r['name'] . ' · ' . ($r['company_name'] ?? '')],
    'partners' => ['label' => 'Partners', 'link' => fn($r) => base_url('pages/partners.php?action=edit&id=' . $r['id']), 'text' => fn($r) => $r['name'] . ' · ' . ($r['company_name'] ?? '—')],
    'transactions' => ['label' => 'Transactions', 'link' => fn($r) => base_url('pages/transactions.php?action=edit&id=' . $r['id']), 'text' => fn($r) => $r['txn_date'] . ' · ' . $r['category_name'] . ' · ' . money($r['amount'])],
    'loans' => ['label' => 'Loans', 'link' => fn($r) => base_url('pages/loan-view.php?id=' . $r['id']), 'text' => fn($r) => $r['lender_name'] . ' · ' . money($r['loan_amount'])],
    'accounts' => ['label' => 'Bank accounts', 'link' => fn($r) => base_url('pages/bank-account-view.php?id=' . $r['id']), 'text' => fn($r) => $r['account_name'] . ' — ' . $r['bank_name']],
  ];
  $any = false;
  foreach ($sections as $key => $meta):
    if (!$results[$key]) continue;
    $any = true;
  ?>
    <div class="card">
      <h2 class="card-title"><?= e($meta['label']) ?></h2>
      <div style="display:grid;gap:0.45rem">
        <?php foreach ($results[$key] as $r): ?>
          <a href="<?= e($meta['link']($r)) ?>" style="padding:0.65rem 0.75rem;border-radius:12px;background:#f7fcfb;border:1px solid var(--border);color:inherit;font-weight:600"><?= e($meta['text']($r)) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$any): ?>
    <div class="card"><div class="empty"><strong>No matches</strong><p>Try another keyword.</p></div></div>
  <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
