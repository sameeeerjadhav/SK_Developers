<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$action = get('action', 'list');
$id = (int) get('id', 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $postAction = post('action', '');

    if (in_array(post('export_action', ''), ['csv', 'pdf'], true)) {
        $exportAction = post('export_action');
        $selectedIds = array_values(array_unique(array_filter(array_map('intval', $_POST['payment_ids'] ?? []), fn($id) => $id > 0)));

        if (!$selectedIds) {
            flash('error', 'Select at least one payment to export.');
            redirect('pages/bookings.php');
        }

        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $expStmt = $pdo->prepare(
            "SELECT bp.*, cu.name AS customer_name, c.name AS company_name, b.property_type, b.plot_no
             FROM booking_payments bp
             JOIN bookings b ON b.id = bp.booking_id
             JOIN customers cu ON cu.id = b.customer_id
             JOIN companies c ON c.id = b.company_id
             WHERE bp.id IN ($placeholders)
             ORDER BY bp.payment_date DESC, bp.id DESC"
        );
        $expStmt->execute($selectedIds);
        $exportRows = $expStmt->fetchAll();

        if (!$exportRows) {
            flash('error', 'Selected payments were not found.');
            redirect('pages/bookings.php');
        }

        $exportReceived = array_sum(array_map(fn($r) => $r['payment_type'] === 'received' ? (float) $r['amount'] : 0, $exportRows));
        $exportReturned = array_sum(array_map(fn($r) => $r['payment_type'] === 'returned' ? (float) $r['amount'] : 0, $exportRows));

        $propertyLabel = fn($r) => $r['property_type'] === 'plot'
            ? ('Plot ' . ($r['plot_no'] ?: '—'))
            : ucwords(str_replace('_', ' ', $r['property_type']));

        if ($exportAction === 'csv') {
            $filename = 'booking_payments_' . date('Ymd_His') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Customer', 'Company', 'Property', 'Type', 'Amount', 'Notes']);
            foreach ($exportRows as $r) {
                fputcsv($out, [
                    $r['payment_date'],
                    $r['customer_name'],
                    $r['company_name'],
                    $propertyLabel($r),
                    $r['payment_type'] === 'received' ? 'Received' : 'Returned',
                    number_format((float) $r['amount'], 2, '.', ''),
                    $r['notes'] ?? '',
                ]);
            }
            fputcsv($out, []);
            fputcsv($out, ['', '', '', '', 'Total received', number_format($exportReceived, 2, '.', ''), '']);
            fputcsv($out, ['', '', '', '', 'Total returned', number_format($exportReturned, 2, '.', ''), '']);
            fclose($out);
            exit;
        }

        // PDF: formal print-ready report sheet, same pattern as Investments/Loan Repayments.
        $entryWord = count($exportRows) === 1 ? 'entry' : 'entries';
        $datesCovered = array_unique(array_map(fn($r) => $r['payment_date'], $exportRows));
        sort($datesCovered);
        $rangeLabel = count($datesCovered) > 1
            ? format_date($datesCovered[0]) . ' – ' . format_date(end($datesCovered))
            : format_date($datesCovered[0] ?? null);

        $pageTitle = 'Booking payments export';
        $pageSub = count($exportRows) . ' selected ' . $entryWord . '.';
        $pageActions = '<button class="btn btn-primary no-print" type="button" onclick="window.print()">Print / Save PDF</button>';
        require __DIR__ . '/../includes/header.php';
        ?>
        <link rel="stylesheet" href="<?= e(base_url('assets/css/print.css')) ?>">
        <div class="print-sheet card">
          <div class="print-header report-header">
            <div>
              <div class="print-brand" style="font-family:Sora,sans-serif;font-weight:800;font-size:1.35rem;color:var(--teal-700,#0f766e)">Sai Kuber Developers</div>
              <div class="report-doc-title">Booking Payments Report</div>
              <div class="print-meta report-meta" style="text-align:left"><?= count($exportRows) ?> <?= e($entryWord) ?> · <?= e($rangeLabel) ?></div>
            </div>
            <div class="print-meta report-meta">Generated <?= e(date('d M Y, h:i A')) ?><br>By <?= e(current_user()['name'] ?? '') ?></div>
          </div>

          <div class="report-summary">
            <div>
              <div class="label">Total received</div>
              <div class="value text-success"><?= money($exportReceived) ?></div>
            </div>
            <div>
              <div class="label">Total returned</div>
              <div class="value text-danger"><?= money($exportReturned) ?></div>
            </div>
          </div>

          <div class="table-wrap">
            <table class="data">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Date</th>
                  <th>Customer</th>
                  <th>Company</th>
                  <th>Property</th>
                  <th>Type</th>
                  <th class="num">Amount (₹)</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($exportRows as $i => $r): ?>
                  <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= e(format_date($r['payment_date'])) ?></td>
                    <td><?= e($r['customer_name']) ?></td>
                    <td><?= e($r['company_name']) ?></td>
                    <td><?= e($propertyLabel($r)) ?></td>
                    <td><?= $r['payment_type'] === 'received' ? 'Received' : 'Returned' ?></td>
                    <td class="num"><?= number_format((float) $r['amount'], 2) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr>
                  <td colspan="5">TOTAL RECEIVED</td>
                  <td class="num" colspan="2"><?= number_format($exportReceived, 2) ?></td>
                </tr>
                <tr>
                  <td colspan="5">TOTAL RETURNED</td>
                  <td class="num" colspan="2"><?= number_format($exportReturned, 2) ?></td>
                </tr>
              </tfoot>
            </table>
          </div>

          <div class="report-footnote">
            <p>This is a system-generated report from the Sai Kuber Developers finance system. Figures reflect the payments selected at export time.</p>
            <p>Confidential — internal use only.</p>
          </div>
        </div>
        <?php
        require __DIR__ . '/../includes/footer.php';
        exit;
    }

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

        $customerName = trim(post('customer_name', ''));
        $customerPhone = post('customer_phone', '');
        $customerEmail = post('customer_email', '');
        $customerAddress = post('customer_address', '');
        if ($customerName === '') {
            flash('error', 'Enter the customer\'s name.');
            redirect('pages/bookings.php?action=' . ($editId ? 'edit&id=' . $editId : 'add'));
        }

        if ($customerId) {
            // Existing customer selected — keep their saved record in sync with any edits made here
            $pdo->prepare('UPDATE customers SET name=?, phone=?, email=?, address=? WHERE id=?')
                ->execute([$customerName, $customerPhone, $customerEmail, $customerAddress, $customerId]);
        } else {
            $cIns = $pdo->prepare('INSERT INTO customers (name, phone, email, address) VALUES (?,?,?,?)');
            $cIns->execute([$customerName, $customerPhone, $customerEmail, $customerAddress]);
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
        $amountReceived = (float) post('amount_received', 0);
        $amountReturned = (float) post('amount_returned', 0);
        $paymentDate = post('payment_date', date('Y-m-d'));
        $bankAccountId = post('bank_account_id') !== '' ? (int) post('bank_account_id') : null;
        $notes = post('notes', '');

        $bStmt = $pdo->prepare('SELECT b.*, cu.name AS customer_name FROM bookings b JOIN customers cu ON cu.id = b.customer_id WHERE b.id = ?');
        $bStmt->execute([$bookingId]);
        $booking = $bStmt->fetch();
        if (!$booking || ($amountReceived <= 0 && $amountReturned <= 0)) {
            flash('error', 'Select a valid booking and enter a received or returned amount.');
            redirect('pages/bookings.php');
        }

        $propertyLabel = $booking['property_type'] === 'plot'
            ? ('Plot ' . ($booking['plot_no'] ?: '—'))
            : ucwords(str_replace('_', ' ', $booking['property_type']));
        $userId = current_user()['id'] ?? null;
        $projectId = $booking['project_id'] ? (int) $booking['project_id'] : null;

        if ($amountReceived > 0) {
            $categoryId = category_id_by_slug($pdo, 'credit', 'booking');
            $description = 'Booking payment — ' . $booking['customer_name'] . ' — ' . $propertyLabel;
            $txnId = create_transaction(
                $pdo, (int) $booking['company_id'], (int) $categoryId, 'credit', $amountReceived, $paymentDate,
                $projectId, $bankAccountId, null, null, $description, $userId ? (int) $userId : null
            );
            $pdo->prepare('INSERT INTO booking_payments (booking_id, transaction_id, payment_type, amount, payment_date, notes, created_by) VALUES (?,?,?,?,?,?,?)')
                ->execute([$bookingId, $txnId, 'received', $amountReceived, $paymentDate, $notes, $userId]);
        }

        if ($amountReturned > 0) {
            $categoryId = category_id_by_slug($pdo, 'general', 'booking_refund');
            $description = 'Booking refund — ' . $booking['customer_name'] . ' — ' . $propertyLabel;
            $txnId = create_transaction(
                $pdo, (int) $booking['company_id'], (int) $categoryId, 'debit', $amountReturned, $paymentDate,
                $projectId, $bankAccountId, null, null, $description, $userId ? (int) $userId : null
            );
            $pdo->prepare('INSERT INTO booking_payments (booking_id, transaction_id, payment_type, amount, payment_date, notes, created_by) VALUES (?,?,?,?,?,?,?)')
                ->execute([$bookingId, $txnId, 'returned', $amountReturned, $paymentDate, $notes, $userId]);
        }

        audit_log($pdo, 'create', 'booking_payment', $bookingId, 'Recorded payment for booking #' . $bookingId . ' — received ' . money($amountReceived) . ', returned ' . money($amountReturned));
        flash('success', 'Payment recorded.');
        redirect('pages/bookings.php?expand=' . $bookingId);
    }

    if ($postAction === 'edit_payment') {
        $paymentId = (int) post('payment_id', 0);
        $paymentType = post('payment_type', 'received');
        if (!in_array($paymentType, ['received', 'returned'], true)) {
            $paymentType = 'received';
        }
        $amount = (float) post('amount', 0);
        $paymentDate = post('payment_date', date('Y-m-d'));
        $notes = post('notes', '');

        $pStmt = $pdo->prepare(
            'SELECT bp.*, b.company_id, b.project_id, b.property_type, b.plot_no, cu.name AS customer_name
             FROM booking_payments bp
             JOIN bookings b ON b.id = bp.booking_id
             JOIN customers cu ON cu.id = b.customer_id
             WHERE bp.id = ?'
        );
        $pStmt->execute([$paymentId]);
        $payment = $pStmt->fetch();
        if (!$payment || $amount <= 0) {
            flash('error', 'Invalid payment.');
            redirect('pages/bookings.php');
        }

        $propertyLabel = $payment['property_type'] === 'plot'
            ? ('Plot ' . ($payment['plot_no'] ?: '—'))
            : ucwords(str_replace('_', ' ', $payment['property_type']));
        $categorySlug = $paymentType === 'received' ? 'booking' : 'booking_refund';
        $categorySection = $paymentType === 'received' ? 'credit' : 'general';
        $categoryId = category_id_by_slug($pdo, $categorySection, $categorySlug);
        $txnType = $paymentType === 'received' ? 'credit' : 'debit';
        $description = ($paymentType === 'received' ? 'Booking payment' : 'Booking refund') . ' — ' . $payment['customer_name'] . ' — ' . $propertyLabel;

        if ($payment['transaction_id'] && $categoryId) {
            $pdo->prepare('UPDATE transactions SET category_id=?, txn_type=?, amount=?, txn_date=?, description=? WHERE id=?')
                ->execute([$categoryId, $txnType, $amount, $paymentDate, $description, $payment['transaction_id']]);
        }

        $pdo->prepare('UPDATE booking_payments SET payment_type=?, amount=?, payment_date=?, notes=? WHERE id=?')
            ->execute([$paymentType, $amount, $paymentDate, $notes, $paymentId]);

        audit_log($pdo, 'update', 'booking_payment', (int) $payment['booking_id'], 'Edited payment #' . $paymentId . ' to ' . money($amount) . ' (' . $paymentType . ')');
        flash('success', 'Payment updated.');
        redirect('pages/bookings.php?expand=' . (int) $payment['booking_id']);
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
    $customers = $pdo->query('SELECT id, name, phone, email, address FROM customers ORDER BY name')->fetchAll();
    $preCustomerId = (int) ($booking['customer_id'] ?? 0);
    $preCompany = (int) ($booking['company_id'] ?? 0);
    $preProject = (int) ($booking['project_id'] ?? 0);

    // Full customer details + their existing bookings, for the auto-fetch panel on selection
    $customerDetailsMap = [];
    foreach ($customers as $cust) {
        $customerDetailsMap[(int) $cust['id']] = [
            'name' => $cust['name'],
            'phone' => $cust['phone'] ?: '',
            'email' => $cust['email'] ?: '',
            'address' => $cust['address'] ?: '',
        ];
    }
    $preCustomer = $customerDetailsMap[$preCustomerId] ?? ['name' => '', 'phone' => '', 'email' => '', 'address' => ''];
    $customerBookingsMap = [];
    $custBookingsStmt = $pdo->query(
        "SELECT bk.id, bk.customer_id, bk.property_type, bk.plot_no, bk.total_amount,
                COALESCE(SUM(CASE WHEN bp.payment_type='received' THEN bp.amount ELSE 0 END),0) AS received,
                COALESCE(SUM(CASE WHEN bp.payment_type='returned' THEN bp.amount ELSE 0 END),0) AS returned
         FROM bookings bk
         LEFT JOIN booking_payments bp ON bp.booking_id = bk.id
         GROUP BY bk.id"
    );
    foreach ($custBookingsStmt->fetchAll() as $bk) {
        $bkLabel = $bk['property_type'] === 'plot'
            ? ('Plot ' . ($bk['plot_no'] ?: '—'))
            : ucwords(str_replace('_', ' ', $bk['property_type']));
        $customerBookingsMap[(int) $bk['customer_id']][] = [
            'id' => (int) $bk['id'],
            'label' => $bkLabel,
            'remaining' => round((float) $bk['total_amount'] - (float) $bk['received'] + (float) $bk['returned'], 2),
        ];
    }

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
        <div id="customer_fields" class="full">
          <div class="form-grid" style="padding:0">
            <div>
              <label>Customer name</label>
              <input type="text" name="customer_name" id="customer_name" required value="<?= e($preCustomer['name']) ?>">
            </div>
            <div>
              <label>Phone</label>
              <input type="text" name="customer_phone" id="customer_phone" value="<?= e($preCustomer['phone']) ?>">
            </div>
            <div>
              <label>Email (optional)</label>
              <input type="email" name="customer_email" id="customer_email" value="<?= e($preCustomer['email']) ?>">
            </div>
            <div class="full">
              <label>Address (optional)</label>
              <textarea name="customer_address" id="customer_address"><?= e($preCustomer['address']) ?></textarea>
            </div>
          </div>
          <p class="muted" id="customer_fields_hint" style="font-size:0.78rem;margin:0.5rem 0 0;display:<?= $preCustomerId ? '' : 'none' ?>">
            Editing these updates the selected customer's saved record.
          </p>
        </div>
        <div id="customer_bookings_panel" class="full" style="display:none">
          <div id="ci_bookings"></div>
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
        var CUSTOMER_DETAILS = <?= json_encode($customerDetailsMap) ?>;
        var CUSTOMER_BOOKINGS = <?= json_encode($customerBookingsMap) ?>;
        var bookingsUrl = <?= json_encode(base_url('pages/bookings.php')) ?>;

        var customerSelect = document.getElementById('customer_select');
        var nameEl = document.getElementById('customer_name');
        var phoneEl = document.getElementById('customer_phone');
        var emailEl = document.getElementById('customer_email');
        var addressEl = document.getElementById('customer_address');
        var hintEl = document.getElementById('customer_fields_hint');
        var bookingsPanel = document.getElementById('customer_bookings_panel');
        var ciBookings = document.getElementById('ci_bookings');
        var propertyTypeEl = document.getElementById('property_type');
        var plotNoField = document.getElementById('plot_no_field');
        var areaEl = document.getElementById('area_sqft');
        var rateEl = document.getElementById('rate_per_sqft');
        var totalPreview = document.getElementById('total_amount_preview');

        function money(n) {
          return '₹' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function fillCustomerFields() {
          var cid = customerSelect.value;
          var d = CUSTOMER_DETAILS[cid];
          if (cid && d) {
            nameEl.value = d.name || '';
            phoneEl.value = d.phone || '';
            emailEl.value = d.email || '';
            addressEl.value = d.address || '';
            hintEl.style.display = '';
          } else {
            nameEl.value = '';
            phoneEl.value = '';
            emailEl.value = '';
            addressEl.value = '';
            hintEl.style.display = 'none';
          }
        }

        function showCustomerBookings() {
          var cid = customerSelect.value;
          ciBookings.innerHTML = '';
          var bookings = (cid && CUSTOMER_BOOKINGS[cid]) || [];
          if (!cid) {
            bookingsPanel.style.display = 'none';
            return;
          }
          if (bookings.length) {
            var label = document.createElement('div');
            label.className = 'detail-label';
            label.style.marginBottom = '0.4rem';
            label.textContent = 'Existing bookings for this customer';
            ciBookings.appendChild(label);

            bookings.forEach(function (bk) {
              var row = document.createElement('div');
              row.style.cssText = 'display:flex;align-items:center;gap:0.6rem;margin-bottom:0.4rem;flex-wrap:wrap';

              var span = document.createElement('span');
              span.textContent = bk.label + ' — Remaining: ' + money(bk.remaining);
              row.appendChild(span);

              var link = document.createElement('a');
              link.className = 'btn btn-outline btn-sm';
              link.href = bookingsUrl + '?expand=' + bk.id;
              link.textContent = 'Record transaction';
              row.appendChild(link);

              ciBookings.appendChild(row);
            });
          } else {
            var empty = document.createElement('p');
            empty.className = 'muted';
            empty.style.margin = '0';
            empty.textContent = 'No existing bookings for this customer yet.';
            ciBookings.appendChild(empty);
          }
          bookingsPanel.style.display = '';
        }

        function onCustomerChange() {
          fillCustomerFields();
          showCustomerBookings();
        }
        function togglePlotNo() {
          plotNoField.style.display = propertyTypeEl.value === 'plot' ? '' : 'none';
        }
        function recalcTotal() {
          var area = parseFloat(areaEl.value) || 0;
          var rate = parseFloat(rateEl.value) || 0;
          totalPreview.value = money(area * rate);
        }

        customerSelect.addEventListener('change', onCustomerChange);
        propertyTypeEl.addEventListener('change', togglePlotNo);
        areaEl.addEventListener('input', recalcTotal);
        rateEl.addEventListener('input', recalcTotal);

        showCustomerBookings();
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
$expandId = (int) get('expand', 0);
$filterFrom = get('from', '');
$filterTo = get('to', '');
[$fromMonth, $toMonth, $month, $year] = period_from_request();
if ($month !== '' || $year !== '') {
    if ($filterFrom === '' && $filterTo === '') {
        $filterFrom = $fromMonth ?: '';
        $filterTo = $toMonth ?: '';
    }
}

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
    $paySql = "SELECT * FROM booking_payments WHERE booking_id IN ($ph)";
    $payParams = $bookingIds;
    apply_date_range($paySql, $payParams, $filterFrom !== '' ? $filterFrom : null, $filterTo !== '' ? $filterTo : null, 'payment_date');
    $paySql .= ' ORDER BY payment_date DESC, id DESC';
    $payStmt = $pdo->prepare($paySql);
    $payStmt->execute($payParams);
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
  <?= period_filter_fields($month, $year) ?>
  <div class="field">
    <label>From</label>
    <input type="date" name="from" value="<?= e($filterFrom) ?>">
  </div>
  <div class="field">
    <label>To</label>
    <input type="date" name="to" value="<?= e($filterTo) ?>">
  </div>
  <div class="field" style="flex:0">
    <label>&nbsp;</label>
    <button class="btn btn-outline" type="submit">Filter</button>
  </div>
</form>
<p class="muted" style="font-size:0.78rem;margin:-0.5rem 0 1rem">Date filters apply to the payment history shown per booking (used for export) — totals above always reflect the full history.</p>
<?php if ($bookings): ?>
<form id="bookingsExportForm" class="bulk-export-form" method="post">
  <?= csrf_field() ?>
  <div class="export-toolbar no-print">
    <label class="select-all-label">
      <input type="checkbox" class="select-all-toggle">
      Select all payments
    </label>
    <span class="selected-count muted">0 selected</span>
    <div class="export-actions">
      <button class="btn btn-outline btn-sm export-csv-btn" type="submit" name="export_action" value="csv" disabled>Export CSV</button>
      <button class="btn btn-outline btn-sm export-pdf-btn" type="submit" name="export_action" value="pdf" disabled>Export PDF</button>
    </div>
  </div>
</form>
<?php endif; ?>
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
            <tr class="row-clickable<?= $expandId === (int) $b['id'] ? ' row-expanded' : '' ?>" data-row-toggle="<?= e($detailId) ?>">
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
            <tr class="row-detail" id="<?= e($detailId) ?>" <?= $expandId === (int) $b['id'] ? '' : 'hidden' ?>>
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
                      <thead><tr><th class="select-col"></th><th>Date</th><th>Type</th><th class="num">Amount</th><th>Notes</th></tr></thead>
                      <tbody>
                        <?php foreach ($payments as $pay):
                          $payEditId = 'pay-edit-' . $pay['id'];
                        ?>
                          <tr class="row-clickable" data-row-toggle="<?= e($payEditId) ?>" title="Click to edit">
                            <td class="select-col"><input type="checkbox" class="bulk-checkbox" form="bookingsExportForm" name="payment_ids[]" value="<?= (int) $pay['id'] ?>"></td>
                            <td><span class="row-caret">▸</span><?= e(format_date($pay['payment_date'])) ?></td>
                            <td><?= $pay['payment_type'] === 'received' ? '<span class="chip chip-success">Received</span>' : '<span class="chip chip-danger">Returned</span>' ?></td>
                            <td class="num <?= $pay['payment_type'] === 'received' ? 'text-success' : 'text-danger' ?>">
                              <?= $pay['payment_type'] === 'received' ? '+' : '−' ?><?= money($pay['amount']) ?>
                            </td>
                            <td><?= e($pay['notes'] ?: '') ?></td>
                          </tr>
                          <tr class="row-detail" id="<?= e($payEditId) ?>" hidden>
                            <td colspan="5">
                              <form method="post" class="form-grid" style="padding:0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="edit_payment">
                                <input type="hidden" name="payment_id" value="<?= (int) $pay['id'] ?>">
                                <div>
                                  <label>Type</label>
                                  <select name="payment_type">
                                    <option value="received" <?= $pay['payment_type'] === 'received' ? 'selected' : '' ?>>Received</option>
                                    <option value="returned" <?= $pay['payment_type'] === 'returned' ? 'selected' : '' ?>>Returned</option>
                                  </select>
                                </div>
                                <div>
                                  <label>Amount (₹)</label>
                                  <input type="number" step="0.01" min="0.01" name="amount" required value="<?= e((string) $pay['amount']) ?>">
                                </div>
                                <div>
                                  <label>Date</label>
                                  <input type="date" name="payment_date" required value="<?= e($pay['payment_date']) ?>">
                                </div>
                                <div class="full">
                                  <label>Notes</label>
                                  <input type="text" name="notes" value="<?= e($pay['notes'] ?? '') ?>">
                                </div>
                                <div class="full form-actions" style="justify-content:flex-start">
                                  <button class="btn btn-primary btn-sm" type="submit">Save changes</button>
                                </div>
                              </form>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php else: ?>
                  <p class="muted" style="margin-bottom:1rem">No payments recorded yet.</p>
                <?php endif; ?>

                <form method="post" class="form-grid record-payment-form" style="padding:0" data-remaining="<?= $remaining ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="record_payment">
                  <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                  <div>
                    <label>Amount received (came from him) (₹)</label>
                    <input type="number" step="0.01" min="0" name="amount_received" class="pay-amount-field" value="0">
                  </div>
                  <div>
                    <label>Amount given back to him (₹)</label>
                    <input type="number" step="0.01" min="0" name="amount_returned" class="pay-amount-field" value="0">
                  </div>
                  <div>
                    <label>Date</label>
                    <input type="date" name="payment_date" required value="<?= e(date('Y-m-d')) ?>">
                  </div>
                  <div class="full">
                    <label>Payment mode</label>
                    <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-top:0.3rem">
                      <label style="display:flex;align-items:center;gap:0.4rem;font-weight:600;color:var(--text);margin:0">
                        <input type="radio" name="payment_mode" value="cash" class="pay-mode-radio" checked style="width:auto">
                        Cash
                      </label>
                      <label style="display:flex;align-items:center;gap:0.4rem;font-weight:600;color:var(--text);margin:0">
                        <input type="radio" name="payment_mode" value="bank" class="pay-mode-radio" style="width:auto">
                        Bank transfer
                      </label>
                    </div>
                  </div>
                  <div class="pay-bank-account-group" style="display:none">
                    <label>Bank account</label>
                    <select name="bank_account_id" class="pay-bank-account-select"><?= bank_account_options($pdo, (int) $b['company_id']) ?></select>
                  </div>
                  <div class="full">
                    <label>Notes</label>
                    <input type="text" name="notes">
                  </div>
                  <div class="full" style="font-size:0.85rem;font-weight:700">
                    Remaining after this entry:
                    <span class="remaining-preview <?= $remaining > 0 ? 'text-danger' : 'text-success' ?>"><?= money($remaining) ?></span>
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
<?php if ($expandId): ?>
<script>
  (function () {
    var el = document.getElementById('booking-detail-<?= (int) $expandId ?>');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  })();
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
