<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$action = get('action', 'list');
$id = (int) get('id', 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $postAction = post('action', '');

    if ($postAction === 'save_booking') {
        $editId = (int) post('id', 0);
        $customerId = (int) post('customer_id', 0);
        $companyId = (int) post('company_id', 0);
        $projectId = post('project_id') !== '' ? (int) post('project_id') : null;
        $propertyType = post('property_type', '');
        if (!in_array($propertyType, ['row_house', 'flat', 'plot'], true)) {
            flash('error', 'Select a property type.');
            redirect('pages/bookings.php?action=' . ($editId ? 'edit&id=' . $editId : 'add'));
        }
        $plotNo = $propertyType === 'plot' ? post('plot_no', '') : '';
        $areaSqft = (float) post('area_sqft', 0);
        $ratePerSqft = (float) post('rate_per_sqft', 0);
        $totalAmount = round($areaSqft * $ratePerSqft, 2);
        $status = post('status', 'active');
        if (!in_array($status, ['active', 'completed', 'cancelled'], true)) {
            $status = 'active';
        }
        $notes = post('notes', '');

        if (!$customerId) {
            $newName = trim(post('customer_name', ''));
            if ($newName === '') {
                flash('error', 'Select an existing customer or enter a name for a new one.');
                redirect('pages/bookings.php?action=' . ($editId ? 'edit&id=' . $editId : 'add'));
            }
            $cIns = $pdo->prepare('INSERT INTO customers (name, phone, email, address) VALUES (?,?,?,?)');
            $cIns->execute([$newName, post('customer_phone', ''), post('customer_email', ''), post('customer_address', '')]);
            $customerId = (int) $pdo->lastInsertId();
        }

        if (!$companyId || !$customerId || $areaSqft <= 0 || $ratePerSqft <= 0) {
            flash('error', 'Company, customer, area and rate are required.');
            redirect('pages/bookings.php?action=' . ($editId ? 'edit&id=' . $editId : 'add'));
        }

        $userId = current_user()['id'] ?? null;
        if ($editId) {
            $stmt = $pdo->prepare('UPDATE bookings SET customer_id=?, company_id=?, project_id=?, property_type=?, plot_no=?, area_sqft=?, rate_per_sqft=?, total_amount=?, status=?, notes=? WHERE id=?');
            $stmt->execute([$customerId, $companyId, $projectId, $propertyType, $plotNo, $areaSqft, $ratePerSqft, $totalAmount, $status, $notes, $editId]);
            audit_log($pdo, 'update', 'booking', $editId, 'Updated booking #' . $editId);
            flash('success', 'Booking updated.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO bookings (customer_id, company_id, project_id, property_type, plot_no, area_sqft, rate_per_sqft, total_amount, status, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$customerId, $companyId, $projectId, $propertyType, $plotNo, $areaSqft, $ratePerSqft, $totalAmount, $status, $notes, $userId]);
            $newId = (int) $pdo->lastInsertId();
            audit_log($pdo, 'create', 'booking', $newId, 'Created booking for customer #' . $customerId);
            flash('success', 'Booking created. Record payments from the list below.');
        }
        redirect('pages/bookings.php');
    }

    if ($postAction === 'record_payment') {
        $bookingId = (int) post('booking_id', 0);
        $paymentType = post('payment_type', 'received');
        if (!in_array($paymentType, ['received', 'returned'], true)) {
            $paymentType = 'received';
        }
        $amount = (float) post('amount', 0);
        $paymentDate = post('payment_date', date('Y-m-d'));
        $bankAccountId = post('bank_account_id') !== '' ? (int) post('bank_account_id') : null;
        $notes = post('notes', '');

        $bStmt = $pdo->prepare('SELECT b.*, cu.name AS customer_name FROM bookings b JOIN customers cu ON cu.id = b.customer_id WHERE b.id = ?');
        $bStmt->execute([$bookingId]);
        $booking = $bStmt->fetch();
        if (!$booking || $amount <= 0) {
            flash('error', 'Select a valid booking and enter a positive amount.');
            redirect('pages/bookings.php');
        }

        $categorySlug = $paymentType === 'received' ? 'booking' : 'booking_refund';
        $categorySection = $paymentType === 'received' ? 'credit' : 'general';
        $categoryId = category_id_by_slug($pdo, $categorySection, $categorySlug);
        if (!$categoryId) {
            flash('error', 'Booking category missing. Refresh once to migrate schema.');
            redirect('pages/bookings.php');
        }
        $txnType = $paymentType === 'received' ? 'credit' : 'debit';
        $propertyLabel = $booking['property_type'] === 'plot'
            ? ('Plot ' . ($booking['plot_no'] ?: '—'))
            : ucwords(str_replace('_', ' ', $booking['property_type']));
        $description = ($paymentType === 'received' ? 'Booking payment' : 'Booking refund') . ' — ' . $booking['customer_name'] . ' — ' . $propertyLabel;
        $userId = current_user()['id'] ?? null;

        $txnId = create_transaction(
            $pdo,
            (int) $booking['company_id'],
            (int) $categoryId,
            $txnType,
            $amount,
            $paymentDate,
            $booking['project_id'] ? (int) $booking['project_id'] : null,
            $bankAccountId,
            null,
            null,
            $description,
            $userId ? (int) $userId : null
        );

        $pStmt = $pdo->prepare('INSERT INTO booking_payments (booking_id, transaction_id, payment_type, amount, payment_date, notes, created_by) VALUES (?,?,?,?,?,?,?)');
        $pStmt->execute([$bookingId, $txnId, $paymentType, $amount, $paymentDate, $notes, $userId]);

        audit_log($pdo, 'create', 'booking_payment', $bookingId, ucfirst($paymentType) . ' ' . money($amount) . ' for booking #' . $bookingId);
        flash('success', 'Payment recorded.');
        redirect('pages/bookings.php');
    }

    if ($postAction === 'delete') {
        if (!can_delete()) {
            flash('error', 'Only admins can delete bookings.');
            redirect('pages/bookings.php');
        }
        $delId = (int) post('id', 0);
        $txnIds = $pdo->prepare('SELECT transaction_id FROM booking_payments WHERE booking_id = ? AND transaction_id IS NOT NULL');
        $txnIds->execute([$delId]);
        foreach ($txnIds->fetchAll(PDO::FETCH_COLUMN) as $tid) {
            $pdo->prepare('DELETE FROM transactions WHERE id = ?')->execute([(int) $tid]);
        }
        $pdo->prepare('DELETE FROM bookings WHERE id = ?')->execute([$delId]);
        audit_log($pdo, 'delete', 'booking', $delId, 'Deleted booking #' . $delId . ' and its linked transactions');
        flash('success', 'Booking and its payment history deleted.');
        redirect('pages/bookings.php');
    }
}

if ($action === 'add' || $action === 'edit') {
    $booking = null;
    if ($action === 'edit' && $id) {
        $stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
        $stmt->execute([$id]);
        $booking = $stmt->fetch();
        if (!$booking) {
            flash('error', 'Booking not found.');
            redirect('pages/bookings.php');
        }
    }
    $customers = $pdo->query('SELECT id, name, phone FROM customers ORDER BY name')->fetchAll();
    $preCustomerId = (int) ($booking['customer_id'] ?? 0);
    $preCompany = (int) ($booking['company_id'] ?? 0);
    $preProject = (int) ($booking['project_id'] ?? 0);

    $pageTitle = $action === 'edit' ? 'Edit booking' : 'New booking';
    $pageSub = 'Customer and property details — payments are recorded separately from the bookings list.';
    $pageActions = '<a class="btn btn-outline" href="' . e(base_url('pages/bookings.php')) . '">Back</a>';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="card" style="max-width:820px">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_booking">
        <input type="hidden" name="id" value="<?= (int) ($booking['id'] ?? 0) ?>">

        <div>
          <label>Customer</label>
          <select name="customer_id" id="customer_select">
            <option value="">+ New customer</option>
            <?php foreach ($customers as $cust): ?>
              <option value="<?= (int) $cust['id'] ?>" <?= $preCustomerId === (int) $cust['id'] ? 'selected' : '' ?>>
                <?= e($cust['name']) ?><?= $cust['phone'] ? ' — ' . e($cust['phone']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Company</label>
          <select name="company_id" id="company_id" required
            data-company-projects="project_id"
            data-projects-url="<?= e(base_url('api/projects.php')) ?>">
            <?= company_options($pdo, $preCompany) ?>
          </select>
        </div>
        <div id="new_customer_fields" class="full" style="display:none">
          <div class="form-grid" style="padding:0">
            <div>
              <label>Customer name</label>
              <input type="text" name="customer_name" id="new_customer_name">
            </div>
            <div>
              <label>Phone</label>
              <input type="text" name="customer_phone">
            </div>
            <div>
              <label>Email (optional)</label>
              <input type="email" name="customer_email">
            </div>
            <div class="full">
              <label>Address (optional)</label>
              <textarea name="customer_address"></textarea>
            </div>
          </div>
        </div>
        <div>
          <label>Project (optional)</label>
          <select name="project_id" id="project_id">
            <?= project_options($pdo, $preCompany ?: null, $preProject) ?>
          </select>
        </div>
        <div>
          <label>Property type</label>
          <select name="property_type" id="property_type" required>
            <option value="">Select type</option>
            <option value="row_house" <?= ($booking['property_type'] ?? '') === 'row_house' ? 'selected' : '' ?>>Row House</option>
            <option value="flat" <?= ($booking['property_type'] ?? '') === 'flat' ? 'selected' : '' ?>>Flat</option>
            <option value="plot" <?= ($booking['property_type'] ?? '') === 'plot' ? 'selected' : '' ?>>Plot</option>
          </select>
        </div>
        <div id="plot_no_field" style="display:none">
          <label>Plot no.</label>
          <input type="text" name="plot_no" id="plot_no" value="<?= e($booking['plot_no'] ?? '') ?>">
        </div>
        <div>
          <label>Area (sq ft)</label>
          <input type="number" step="0.01" min="0.01" name="area_sqft" id="area_sqft" required value="<?= e((string) ($booking['area_sqft'] ?? '')) ?>">
        </div>
        <div>
          <label>Rate per sq ft (₹)</label>
          <input type="number" step="0.01" min="0.01" name="rate_per_sqft" id="rate_per_sqft" required value="<?= e((string) ($booking['rate_per_sqft'] ?? '')) ?>">
        </div>
        <div>
          <label>Total amount (₹)</label>
          <input type="text" id="total_amount_preview" readonly value="<?= e(money($booking['total_amount'] ?? 0)) ?>">
        </div>
        <div>
          <label>Status</label>
          <select name="status">
            <option value="active" <?= ($booking['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="completed" <?= ($booking['status'] ?? '') === 'completed' ? 'selected' : '' ?>>Completed</option>
            <option value="cancelled" <?= ($booking['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
          </select>
        </div>
        <div class="full">
          <label>Notes</label>
          <textarea name="notes"><?= e($booking['notes'] ?? '') ?></textarea>
        </div>
        <div class="full highlight-box">
          Payments (received / returned) are recorded from the bookings list after saving — no need to re-enter customer or property details next time.
        </div>
        <div class="full form-actions">
          <button class="btn btn-primary" type="submit">Save booking</button>
        </div>
      </form>
    </div>
    <script>
      (function () {
        var customerSelect = document.getElementById('customer_select');
        var newCustomerFields = document.getElementById('new_customer_fields');
        var newCustomerName = document.getElementById('new_customer_name');
        var propertyTypeEl = document.getElementById('property_type');
        var plotNoField = document.getElementById('plot_no_field');
        var areaEl = document.getElementById('area_sqft');
        var rateEl = document.getElementById('rate_per_sqft');
        var totalPreview = document.getElementById('total_amount_preview');

        function toggleCustomer() {
          var isNew = customerSelect.value === '';
          newCustomerFields.style.display = isNew ? '' : 'none';
          newCustomerName.required = isNew;
        }
        function togglePlotNo() {
          plotNoField.style.display = propertyTypeEl.value === 'plot' ? '' : 'none';
        }
        function recalcTotal() {
          var area = parseFloat(areaEl.value) || 0;
          var rate = parseFloat(rateEl.value) || 0;
          totalPreview.value = '₹' + (area * rate).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        customerSelect.addEventListener('change', toggleCustomer);
        propertyTypeEl.addEventListener('change', togglePlotNo);
        areaEl.addEventListener('input', recalcTotal);
        rateEl.addEventListener('input', recalcTotal);

        toggleCustomer();
        togglePlotNo();
      })();
    </script>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$filterCompany = (int) get('company_id', 0);
$filterStatus = get('status', '');
$q = get('q', '');

$pageTitle = 'Bookings';
$pageSub = 'Plot, flat and row house bookings — customer info stays saved for future payments.';
$pageActions = '<a class="btn btn-primary" href="' . e(base_url('pages/bookings.php?action=add')) . '">+ New booking</a>';

$sql = "SELECT b.*, cu.name AS customer_name, cu.phone AS customer_phone, cu.email AS customer_email, cu.address AS customer_address,
               comp.name AS company_name, p.name AS project_name,
               COALESCE(SUM(CASE WHEN bp.payment_type='received' THEN bp.amount ELSE 0 END),0) AS received,
               COALESCE(SUM(CASE WHEN bp.payment_type='returned' THEN bp.amount ELSE 0 END),0) AS returned
        FROM bookings b
        JOIN customers cu ON cu.id = b.customer_id
        JOIN companies comp ON comp.id = b.company_id
        LEFT JOIN projects p ON p.id = b.project_id
        LEFT JOIN booking_payments bp ON bp.booking_id = b.id
        WHERE 1=1";
$params = [];
if ($filterCompany) { $sql .= ' AND b.company_id = ?'; $params[] = $filterCompany; }
if ($filterStatus !== '') { $sql .= ' AND b.status = ?'; $params[] = $filterStatus; }
if ($q !== '') {
    $sql .= ' AND (cu.name LIKE ? OR cu.phone LIKE ? OR b.plot_no LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
$sql .= ' GROUP BY b.id ORDER BY b.created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

$paymentsByBooking = [];
if ($bookings) {
    $bookingIds = array_map(fn($b) => (int) $b['id'], $bookings);
    $ph = implode(',', array_fill(0, count($bookingIds), '?'));
    $payStmt = $pdo->prepare("SELECT * FROM booking_payments WHERE booking_id IN ($ph) ORDER BY payment_date DESC, id DESC");
    $payStmt->execute($bookingIds);
    foreach ($payStmt->fetchAll() as $pay) {
        $paymentsByBooking[(int) $pay['booking_id']][] = $pay;
    }
}

$totalSaleValue = array_sum(array_map(fn($b) => (float) $b['total_amount'], $bookings));
$totalReceived = array_sum(array_map(fn($b) => (float) $b['received'], $bookings));
$totalReturned = array_sum(array_map(fn($b) => (float) $b['returned'], $bookings));
$totalRemaining = $totalSaleValue - $totalReceived + $totalReturned;

require __DIR__ . '/../includes/header.php';
?>
<div class="stat-grid" style="grid-template-columns:repeat(4,1fr)">
  <div class="stat-card">
    <div class="stat-label">Bookings</div>
    <div class="stat-value"><?= count($bookings) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total sale value</div>
    <div class="stat-value"><?= money($totalSaleValue) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total received</div>
    <div class="stat-value text-success"><?= money($totalReceived) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total remaining</div>
    <div class="stat-value <?= $totalRemaining > 0 ? 'text-danger' : 'text-success' ?>"><?= money($totalRemaining) ?></div>
  </div>
</div>
<form class="filters" method="get">
  <div class="field">
    <label>Search</label>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Customer, phone, plot no…">
  </div>
  <div class="field">
    <label>Company</label>
    <select name="company_id">
      <option value="">All</option>
      <?php foreach ($pdo->query('SELECT id, name FROM companies ORDER BY type, name') as $co): ?>
        <option value="<?= (int) $co['id'] ?>" <?= $filterCompany === (int) $co['id'] ? 'selected' : '' ?>><?= e($co['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label>Status</label>
    <select name="status">
      <option value="">All</option>
      <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
      <option value="completed" <?= $filterStatus === 'completed' ? 'selected' : '' ?>>Completed</option>
      <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
    </select>
  </div>
  <div class="field" style="flex:0">
    <label>&nbsp;</label>
    <button class="btn btn-outline" type="submit">Filter</button>
  </div>
</form>
<div class="card">
  <?php if (!$bookings): ?>
    <div class="empty"><strong>No bookings yet</strong><p>Create a booking to start tracking a customer's plot, flat or row house sale.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>Customer</th>
            <th>Property</th>
            <th>Company / Project</th>
            <th class="num">Total</th>
            <th class="num">Received</th>
            <th class="num">Remaining</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($bookings as $b):
            $received = (float) $b['received'];
            $returned = (float) $b['returned'];
            $total = (float) $b['total_amount'];
            $remaining = $total - $received + $returned;
            $detailId = 'booking-detail-' . $b['id'];
            $propertyLabel = $b['property_type'] === 'plot'
                ? ('Plot ' . ($b['plot_no'] ?: '—'))
                : ucwords(str_replace('_', ' ', $b['property_type']));
            $payments = $paymentsByBooking[(int) $b['id']] ?? [];
          ?>
            <tr class="row-clickable" data-row-toggle="<?= e($detailId) ?>">
              <td>
                <span class="row-caret">▸</span><strong><?= e($b['customer_name']) ?></strong>
                <div class="muted" style="font-size:0.75rem"><?= e($b['customer_phone'] ?: '') ?></div>
              </td>
              <td><?= e($propertyLabel) ?></td>
              <td>
                <strong><?= e($b['company_name']) ?></strong>
                <div class="muted" style="font-size:0.75rem"><?= e($b['project_name'] ?? '—') ?></div>
              </td>
              <td class="num"><?= money($total) ?></td>
              <td class="num text-success"><?= money($received) ?></td>
              <td class="num <?= $remaining > 0 ? 'text-danger' : 'text-success' ?>"><?= money($remaining) ?></td>
              <td><?= status_chip($b['status']) ?></td>
            </tr>
            <tr class="row-detail" id="<?= e($detailId) ?>" hidden>
              <td colspan="7">
                <table class="detail-table" style="margin-bottom:1rem">
                  <tbody>
                    <tr><td>Phone</td><td><?= e($b['customer_phone'] ?: '—') ?></td></tr>
                    <tr><td>Email</td><td><?= e($b['customer_email'] ?: '—') ?></td></tr>
                    <tr><td>Address</td><td><?= nl2br(e($b['customer_address'] ?: '—')) ?></td></tr>
                    <tr><td>Area × Rate</td><td><?= e(number_format((float) $b['area_sqft'], 2)) ?> sq ft × <?= money($b['rate_per_sqft']) ?></td></tr>
                    <tr><td>Notes</td><td><?= nl2br(e($b['notes'] ?: '—')) ?></td></tr>
                  </tbody>
                </table>

                <?php if ($payments): ?>
                  <div class="table-wrap" style="margin-bottom:1rem">
                    <table class="data">
                      <thead><tr><th>Date</th><th>Type</th><th class="num">Amount</th><th>Notes</th></tr></thead>
                      <tbody>
                        <?php foreach ($payments as $pay): ?>
                          <tr>
                            <td><?= e(format_date($pay['payment_date'])) ?></td>
                            <td><?= $pay['payment_type'] === 'received' ? '<span class="chip chip-success">Received</span>' : '<span class="chip chip-danger">Returned</span>' ?></td>
                            <td class="num <?= $pay['payment_type'] === 'received' ? 'text-success' : 'text-danger' ?>">
                              <?= $pay['payment_type'] === 'received' ? '+' : '−' ?><?= money($pay['amount']) ?>
                            </td>
                            <td><?= e($pay['notes'] ?: '') ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php else: ?>
                  <p class="muted" style="margin-bottom:1rem">No payments recorded yet.</p>
                <?php endif; ?>

                <form method="post" class="form-grid" style="padding:0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="record_payment">
                  <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                  <div>
                    <label>Payment type</label>
                    <select name="payment_type">
                      <option value="received">Received</option>
                      <option value="returned">Returned</option>
                    </select>
                  </div>
                  <div>
                    <label>Amount (₹)</label>
                    <input type="number" step="0.01" min="0.01" name="amount" required>
                  </div>
                  <div>
                    <label>Date</label>
                    <input type="date" name="payment_date" required value="<?= e(date('Y-m-d')) ?>">
                  </div>
                  <div>
                    <label>Bank account (optional)</label>
                    <select name="bank_account_id"><?= bank_account_options($pdo, (int) $b['company_id']) ?></select>
                  </div>
                  <div class="full">
                    <label>Notes</label>
                    <input type="text" name="notes">
                  </div>
                  <div class="full form-actions" style="justify-content:flex-start">
                    <button class="btn btn-primary btn-sm" type="submit">Record payment</button>
                  </div>
                </form>
                <div class="form-actions" style="justify-content:flex-start;margin-top:0.5rem">
                  <a class="btn btn-outline btn-sm" href="<?= e(base_url('pages/bookings.php?action=edit&id=' . $b['id'])) ?>">Edit booking</a>
                  <?php if (can_delete()): ?>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                      <button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete this booking and all its payment history? This also removes the linked ledger transactions.">Delete booking</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
