<?php
declare(strict_types=1);
/** @var PDO $pdo */
$user = current_user();
$current = basename($_SERVER['PHP_SELF'] ?? '');
$flash = get_flash();

$nav = [
    ['label' => 'Overview', 'items' => [
        ['href' => 'index.php', 'label' => 'Dashboard', 'icon' => 'dash', 'match' => ['index.php']],
        ['href' => 'pages/summary.php', 'label' => 'Total Summary', 'icon' => 'summary', 'match' => ['summary.php']],
        ['href' => 'pages/reports.php', 'label' => 'PDF Reports', 'icon' => 'summary', 'match' => ['reports.php']],
    ]],
    ['label' => 'Organisation', 'items' => [
        ['href' => 'pages/companies.php', 'label' => 'Companies', 'icon' => 'company', 'match' => ['companies.php']],
        ['href' => 'pages/projects.php', 'label' => 'Projects', 'icon' => 'project', 'match' => ['projects.php', 'project-view.php']],
    ]],
    ['label' => 'General Topics', 'items' => [
        ['href' => 'pages/investments.php', 'label' => 'Investment', 'icon' => 'invest', 'match' => ['investments.php']],
        ['href' => 'pages/partners.php', 'label' => 'Partner', 'icon' => 'partner', 'match' => ['partners.php']],
        ['href' => 'pages/expenses.php', 'label' => 'Expense', 'icon' => 'expense', 'match' => ['expenses.php']],
        ['href' => 'pages/assets.php', 'label' => 'Asset', 'icon' => 'asset', 'match' => ['assets.php']],
        ['href' => 'pages/bank-loans.php', 'label' => 'Bank Loans', 'icon' => 'loan', 'match' => ['bank-loans.php', 'loan-view.php']],
        ['href' => 'pages/bank-accounts.php', 'label' => 'Bank Account', 'icon' => 'bank', 'match' => ['bank-accounts.php', 'bank-account-view.php']],
        ['href' => 'pages/deposits.php', 'label' => 'Deposit', 'icon' => 'deposit', 'match' => ['deposits.php']],
    ]],
    ['label' => 'Ledger', 'items' => [
        ['href' => 'pages/transactions.php', 'label' => 'Transactions', 'icon' => 'txn', 'match' => ['transactions.php']],
    ]],
    ['label' => 'Account', 'items' => array_values(array_filter([
        ['href' => 'pages/profile.php', 'label' => 'Profile', 'icon' => 'partner', 'match' => ['profile.php']],
        is_admin() ? ['href' => 'pages/users.php', 'label' => 'Users & roles', 'icon' => 'company', 'match' => ['users.php']] : null,
    ]))],
];

function nav_icon(string $name): string
{
    $icons = [
        'dash' => '<path d="M4 4h7v7H4V4zm9 0h7v5h-7V4zM4 13h7v7H4v-7zm9 7v-9h7v9h-7z"/>',
        'summary' => '<path d="M4 19h16v2H4v-2zM6 10h2v7H6v-7zm5-4h2v11h-2V6zm5 2h2v9h-2V8z"/>',
        'company' => '<path d="M3 21h18v-2H3v2zm2-4h2V7H5v10zm4 0h2V3H9v14zm4 0h2V9h-2v8zm4 0h2V5h-2v12z"/>',
        'project' => '<path d="M3 7h6l2 2h10v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/>',
        'invest' => '<path d="M12 3v18M7 8l5-5 5 5M7 16l5 5 5-5"/>',
        'partner' => '<path d="M16 11a4 4 0 1 0-8 0 4 4 0 0 0 8 0zM4 20a8 8 0 0 1 16 0"/>',
        'expense' => '<path d="M12 2v20M17 7H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'asset' => '<path d="M4 20h16M6 20V10l6-6 6 6v10"/>',
        'loan' => '<path d="M3 10h18v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-8zm0 0l9-6 9 6"/>',
        'bank' => '<path d="M3 10l9-6 9 6M5 10v8h2v-8m4 0v8h2v-8m4 0v8h2v-8M3 20h18"/>',
        'deposit' => '<path d="M12 3v12m0 0l-4-4m4 4l4-4M5 19h14"/>',
        'txn' => '<path d="M7 7h13M7 7l3-3M7 7l3 3M17 17H4m13 0l-3-3m3 3l-3 3"/>',
    ];
    $path = $icons[$name] ?? $icons['dash'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle ?? 'Dashboard') ?> — <?= e(app_config('name')) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/app.css')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/print.css')) ?>">
</head>
<body>
<div class="app-shell">
  <div class="sidebar-overlay" id="sidebarOverlay"></div>
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <div class="brand-mark">SK</div>
      <div>
        <div class="brand-name">Sai Kuber</div>
        <div class="brand-sub">Developers Finance</div>
      </div>
    </div>
    <nav class="nav">
      <?php foreach ($nav as $section): ?>
        <div class="nav-section"><?= e($section['label']) ?></div>
        <?php foreach ($section['items'] as $item): ?>
          <?php $active = in_array($current, $item['match'], true); ?>
          <a class="nav-link<?= $active ? ' active' : '' ?>" href="<?= e(base_url($item['href'])) ?>">
            <?= nav_icon($item['icon']) ?>
            <span><?= e($item['label']) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <a class="nav-link logout" href="<?= e(base_url('logout.php')) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
      <span>Logout</span>
    </a>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="icon-btn" id="menuBtn" type="button" aria-label="Open menu">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
      </button>
      <div class="topbar-title" title="<?= e($pageTitle ?? 'Dashboard') ?>">
        <?= e($pageTitle ?? 'Dashboard') ?>
      </div>
      <form class="top-search" action="<?= e(base_url('pages/transactions.php')) ?>" method="get">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3-3"/></svg>
        <input type="search" name="q" placeholder="Search transactions…" value="<?= e(get('q', '')) ?>">
      </form>
      <div class="topbar-right">
        <div class="user-menu" id="userMenu">
          <button class="user-pill" type="button" id="userMenuBtn" aria-expanded="false" aria-haspopup="true">
            <div class="avatar"><?= e(strtoupper(substr($user['name'] ?? 'A', 0, 1))) ?></div>
            <div class="user-meta">
              <div class="user-name"><?= e($user['name'] ?? '') ?></div>
              <div class="user-role"><?= e(ucfirst($user['role'] ?? 'admin')) ?></div>
            </div>
            <svg class="user-caret" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
          </button>
          <div class="user-dropdown" id="userDropdown" role="menu" hidden>
            <a class="user-dropdown-item" role="menuitem" href="<?= e(base_url('pages/profile.php')) ?>">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 11a4 4 0 1 0-8 0 4 4 0 0 0 8 0zM4 20a8 8 0 0 1 16 0"/></svg>
              Profile
            </a>
            <a class="user-dropdown-item danger" role="menuitem" href="<?= e(base_url('logout.php')) ?>">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
              Logout
            </a>
          </div>
        </div>
      </div>
    </header>

    <main class="content">
      <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
      <?php endif; ?>
      <div class="page-head<?= empty($pageActions) && empty($pageSub) ? ' page-head-desktop-only' : '' ?>">
        <div class="page-head-text">
          <h1 class="page-title"><?= e($pageTitle ?? 'Dashboard') ?></h1>
          <?php if (!empty($pageSub)): ?>
            <p class="page-sub"><?= e($pageSub) ?></p>
          <?php endif; ?>
        </div>
        <?php if (!empty($pageActions)): ?>
          <div class="page-actions"><?= $pageActions ?></div>
        <?php endif; ?>
      </div>
