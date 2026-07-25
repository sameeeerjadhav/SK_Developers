<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$result = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $companyId = (int) post('company_id', 0);
    $projectId = post('project_id') !== '' ? (int) post('project_id') : null;
    $type = post('import_type', 'expense'); // expense | booking
    if (!$companyId || empty($_FILES['csv']['tmp_name'])) {
        $error = 'Company and CSV file are required.';
    } else {
        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$fh) {
            $error = 'Could not read CSV.';
        } else {
            $header = fgetcsv($fh);
            $ok = 0; $fail = 0; $errors = [];
            $slug = $type === 'booking' ? 'booking' : 'office_expenses';
            $section = $type === 'booking' ? 'credit' : 'expense';
            $catId = category_id_by_slug($pdo, $section, $slug);
            if (!$catId) {
                $error = 'Category missing for import type.';
            } else {
                $line = 1;
                while (($row = fgetcsv($fh)) !== false) {
                    $line++;
                    if (count($row) < 2) { $fail++; continue; }
                    // date, amount, description[, reference]
                    $date = trim($row[0] ?? '');
                    $amount = (float) str_replace([',',' '], '', (string)($row[1] ?? '0'));
                    $desc = trim($row[2] ?? '');
                    $ref = trim($row[3] ?? '');
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                        // try d/m/Y
                        $ts = strtotime(str_replace('/', '-', $date));
                        $date = $ts ? date('Y-m-d', $ts) : '';
                    }
                    if ($date === '' || $amount <= 0) {
                        $fail++;
                        $errors[] = "Line {$line}: invalid date/amount";
                        continue;
                    }
                    $txnType = $section === 'credit' ? 'credit' : 'debit';
                    create_transaction($pdo, $companyId, $catId, $txnType, $amount, $date, $projectId, null, null, $ref !== '' ? $ref : null, $desc !== '' ? $desc : 'CSV import', current_user()['id'] ?? null);
                    $ok++;
                }
                audit_log($pdo, 'import', 'csv', null, "Imported {$ok} {$type} rows" . ($fail ? ", {$fail} failed" : ''));
                $result = compact('ok', 'fail', 'errors');
            }
            fclose($fh);
        }
    }
}

$pageTitle = 'CSV import';
$pageSub = 'Bulk import expenses or booking credits. Format: date, amount, description, reference';
$pageActions = '<a class="btn btn-outline" href="' . e(base_url('assets/csv-template.csv')) . '" download>Download template</a>';
require __DIR__ . '/../includes/header.php';
?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if ($result): ?>
  <div class="alert alert-success">Imported <?= (int)$result['ok'] ?> row(s). Failed: <?= (int)$result['fail'] ?>.</div>
  <?php if ($result['errors']): ?>
    <div class="card"><ul><?php foreach (array_slice($result['errors'], 0, 20) as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
<?php endif; ?>
<div class="card" style="max-width:720px">
  <form method="post" enctype="multipart/form-data" class="form-grid">
    <?= csrf_field() ?>
    <div>
      <label>Company</label>
      <select name="company_id" required data-company-projects="project_id" data-projects-url="<?= e(base_url('api/projects.php')) ?>">
        <?= company_options($pdo) ?>
      </select>
    </div>
    <div>
      <label>Project (optional)</label>
      <select name="project_id" id="project_id"><option value="">None</option></select>
    </div>
    <div>
      <label>Import as</label>
      <select name="import_type">
        <option value="expense">Expenses (debit)</option>
        <option value="booking">Bookings (credit)</option>
      </select>
    </div>
    <div>
      <label>CSV file</label>
      <input type="file" name="csv" accept=".csv,text/csv" required>
    </div>
    <div class="full highlight-box">
      Columns: <code>date,amount,description,reference</code><br>
      Date as <code>YYYY-MM-DD</code> preferred. Max practical size ~2MB.
    </div>
    <div class="full form-actions"><button class="btn btn-primary" type="submit">Import CSV</button></div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
