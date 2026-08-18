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
        $plotNo = in_array($propertyType, ['plot', 'row_house'], true) ? trim(post('plot_no', '')) : '';
        $areaSqft = (float) post('area_sqft', 0);
        $ratePerSqft = (float) post('rate_per_sqft', 0);
        $calcTotal = round($areaSqft * $ratePerSqft, 2);
        $postedTotal = trim((string) post('total_amount', ''));
        $totalAmount = ($postedTotal !== '' && is_numeric($postedTotal) && (float) $postedTotal > 0)
            ? round((float) $postedTotal, 2)
            : $calcTotal;
        $status = post('status', 'active');
        if (!in_array($status, ['active', 'completed', 'cancelled'], true)) {
            $status = 'active';
        }
        $bankAccountId = post('bank_account_id') !== '' ? (int) post('bank_account_id') : null;
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

        if (!$companyId || !$customerId || $areaSqft <= 0 || $ratePerSqft <= 0 || $totalAmount <= 0) {
            flash('error', 'Company, customer, area, rate and total amount are required.');
            redirect('pages/bookings.php?action=' . ($editId ? 'edit&id=' . $editId : 'add'));
        }

        $userId = current_user()['id'] ?? null;
        if ($editId) {
            $stmt = $pdo->prepare('UPDATE bookings SET customer_id=?, company_id=?, project_id=?, bank_account_id=?, property_type=?, plot_no=?, area_sqft=?, rate_per_sqft=?, total_amount=?, status=?, notes=? WHERE id=?');
            $stmt->execute([$customerId, $companyId, $projectId, $bankAccountId, $propertyType, $plotNo, $areaSqft, $ratePerSqft, $totalAmount, $status, $notes, $editId]);
            audit_log($pdo, 'update', 'booking', $editId, 'Updated booking #' . $editId);
            sync_booking_ledger_project($pdo, $editId, $projectId);
            flash('success', 'Booking updated.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO bookings (customer_id, company_id, project_id, bank_account_id, property_type, plot_no, area_sqft, rate_per_sqft, total_amount, status, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$customerId, $companyId, $projectId, $bankAccountId, $propertyType, $plotNo, $areaSqft, $ratePerSqft, $totalAmount, $status, $notes, $userId]);
            $newId = (int) $pdo->lastInsertId();
            audit_log($pdo, 'create', 'booking', $newId, 'Created booking for customer #' . $customerId);

            $initialAmount = (float) post('initial_amount_received', 0);
            $initialReturned = (float) post('initial_amount_returned', 0);
            if ($initialAmount > 0 || $initialReturned > 0) {
                $initialDate = post('initial_payment_date') ?: date('Y-m-d');
                $initialBankAccountId = post('initial_bank_account_id') !== '' ? (int) post('initial_bank_account_id') : null;
                $propertyLabel = booking_property_label($propertyType, $plotNo);

                if ($initialAmount > 0) {
                    $initCatId = category_id_by_slug($pdo, 'credit', 'booking');
                    $initDescription = 'Booking payment — ' . $customerName . ' — ' . $propertyLabel;
                    $initTxnId = create_transaction(
                        $pdo, $companyId, (int) $initCatId, 'credit', $initialAmount, $initialDate,
                        $projectId, $initialBankAccountId, null, null, $initDescription, $userId ? (int) $userId : null
                    );
                    $pdo->prepare('INSERT INTO booking_payments (booking_id, transaction_id, payment_type, amount, payment_date, notes, created_by) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$newId, $initTxnId, 'received', $initialAmount, $initialDate, 'Initial payment at booking creation', $userId]);
                    audit_log($pdo, 'create', 'booking_payment', $newId, 'Recorded initial payment ' . money($initialAmount) . ' for booking #' . $newId);
                }

                if ($initialReturned > 0) {
                    $refundCatId = category_id_by_slug($pdo, 'general', 'booking_refund');
                    $refundDescription = 'Booking refund — ' . $customerName . ' — ' . $propertyLabel;
                    $refundTxnId = create_transaction(
                        $pdo, $companyId, (int) $refundCatId, 'debit', $initialReturned, $initialDate,
                        $projectId, $initialBankAccountId, null, null, $refundDescription, $userId ? (int) $userId : null
                    );
                    $pdo->prepare('INSERT INTO booking_payments (booking_id, transaction_id, payment_type, amount, payment_date, notes, created_by) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$newId, $refundTxnId, 'returned', $initialReturned, $initialDate, 'Initial return at booking creation', $userId]);
                    audit_log($pdo, 'create', 'booking_payment', $newId, 'Recorded initial return ' . money($initialReturned) . ' for booking #' . $newId);
                }
            }

            flash('success', 'Booking created.' . (($initialAmount > 0 || $initialReturned > 0) ? ' Initial payment recorded.' : ' Record payments from the list below.'));
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

        $propertyLabel = booking_property_label($booking['property_type'] ?? '', $booking['plot_no'] ?? '');
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
        redirect(list_posted_return_url('bookings.php', ['expand' => (string) $bookingId, 'extra' => '']));
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

        $propertyLabel = booking_property_label($payment['property_type'] ?? '', $payment['plot_no'] ?? '');
        $categorySlug = $paymentType === 'received' ? 'booking' : 'booking_refund';
        $categorySection = $paymentType === 'received' ? 'credit' : 'general';
        $categoryId = category_id_by_slug($pdo, $categorySection, $categorySlug);
        $txnType = $paymentType === 'received' ? 'credit' : 'debit';
        $description = ($paymentType === 'received' ? 'Booking payment' : 'Booking refund') . ' — ' . $payment['customer_name'] . ' — ' . $propertyLabel;

        if ($payment['transaction_id'] && $categoryId) {
            $projectId = $payment['project_id'] ? (int) $payment['project_id'] : null;
            $pdo->prepare('UPDATE transactions SET category_id=?, txn_type=?, amount=?, txn_date=?, description=?, project_id=? WHERE id=?')
                ->execute([$categoryId, $txnType, $amount, $paymentDate, $description, $projectId, $payment['transaction_id']]);
        }

        $pdo->prepare('UPDATE booking_payments SET payment_type=?, amount=?, payment_date=?, notes=? WHERE id=?')
            ->execute([$paymentType, $amount, $paymentDate, $notes, $paymentId]);

        audit_log($pdo, 'update', 'booking_payment', (int) $payment['booking_id'], 'Edited payment #' . $paymentId . ' to ' . money($amount) . ' (' . $paymentType . ')');
        flash('success', 'Payment updated.');
        redirect(list_posted_return_url('bookings.php', ['expand' => (string) (int) $payment['booking_id'], 'extra' => '']));
    }

    if ($postAction === 'delete_payment') {
        if (!can_delete()) {
            flash('error', 'Only admins can delete payment entries.');
            redirect('pages/bookings.php');
        }
        $paymentId = (int) post('payment_id', 0);
        $pStmt = $pdo->prepare(
            'SELECT id, booking_id, transaction_id, amount, payment_type
             FROM booking_payments WHERE id = ?'
        );
        $pStmt->execute([$paymentId]);
        $payment = $pStmt->fetch();
        if (!$payment) {
            flash('error', 'Payment entry not found.');
            redirect('pages/bookings.php');
        }
        $bookingId = (int) $payment['booking_id'];
        $txnId = $payment['transaction_id'] ? (int) $payment['transaction_id'] : 0;
        $pdo->prepare('DELETE FROM booking_payments WHERE id = ?')->execute([$paymentId]);
        if ($txnId) {
            delete_transactions_by_ids($pdo, [$txnId]);
        }
        audit_log(
            $pdo,
            'delete',
            'booking_payment',
            $bookingId,
            'Deleted payment #' . $paymentId . ' (' . ($payment['payment_type'] ?? '') . ' ' . money($payment['amount']) . ')'
        );
        flash('success', 'Payment entry deleted. Remaining amount has been updated.');
        redirect(list_posted_return_url('bookings.php', ['expand' => (string) $bookingId, 'extra' => '']));
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
        $bkLabel = booking_property_label($bk['property_type'] ?? '', $bk['plot_no'] ?? '');
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
    <div class="card">
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
            data-company-accounts="booking_bank_account_id,init_bank_account_id"
            data-projects-url="<?= e(base_url('api/projects.php')) ?>"
            data-accounts-url="<?= e(base_url('api/bank-accounts.php')) ?>">
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
          <label id="plot_no_label"><?= ($booking['property_type'] ?? '') === 'row_house' ? 'R-H number' : 'Plot no.' ?></label>
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
          <input type="number" step="0.01" min="0.01" name="total_amount" id="total_amount" required value="<?= e($booking ? (string) $booking['total_amount'] : '') ?>">
        </div>
        <div>
          <label>Status</label>
          <select name="status">
            <option value="active" <?= ($booking['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="completed" <?= ($booking['status'] ?? '') === 'completed' ? 'selected' : '' ?>>Completed</option>
            <option value="cancelled" <?= ($booking['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
          </select>
        </div>
        <div>
          <label style="display:flex;align-items:center;gap:0.5rem">Bank account (optional) <a href="#" class="open-add-bank-modal" style="font-size:0.75rem;font-weight:600;color:var(--primary)">+ Add</a></label>
          <select name="bank_account_id" id="booking_bank_account_id">
            <?= bank_account_options($pdo, $preCompany ?: null, ($booking['bank_account_id'] ?? null) !== null ? (int) $booking['bank_account_id'] : null, 'None') ?>
          </select>
        </div>
        <div class="full">
          <label>Notes</label>
          <textarea name="notes"><?= e($booking['notes'] ?? '') ?></textarea>
        </div>
        <?php if (!$booking): ?>
        <div class="full">
          <label>Initial payment (optional)</label>
        </div>
        <div>
          <label>Amount received (₹)</label>
          <input type="number" step="0.01" min="0" name="initial_amount_received" value="0">
        </div>
        <div>
          <label>Amount returned (₹)</label>
          <input type="number" step="0.01" min="0" name="initial_amount_returned" value="0">
        </div>
        <div>
          <label>Date</label>
          <input type="date" name="initial_payment_date" value="<?= e(date('Y-m-d')) ?>">
        </div>
        <div class="full">
          <label>Payment mode</label>
          <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-top:0.3rem">
            <label style="display:flex;align-items:center;gap:0.4rem;font-weight:600;color:var(--text);margin:0">
              <input type="radio" name="initial_payment_mode" value="cash" class="pay-mode-radio" checked style="width:auto">
              Cash
            </label>
            <label style="display:flex;align-items:center;gap:0.4rem;font-weight:600;color:var(--text);margin:0">
              <input type="radio" name="initial_payment_mode" value="bank" class="pay-mode-radio" style="width:auto">
              Bank transfer
            </label>
          </div>
        </div>
        <div class="pay-bank-account-group" style="display:none">
          <label style="display:flex;align-items:center;gap:0.5rem">Bank account <a href="#" class="open-add-bank-modal" style="font-size:0.75rem;font-weight:600;color:var(--primary)">+ Add</a></label>
          <select name="initial_bank_account_id" id="init_bank_account_id" class="pay-bank-account-select"><?= bank_account_options($pdo, $preCompany ?: null) ?></select>
        </div>
        <?php endif; ?>
        <div class="full highlight-box">
          Further payments (received / returned) are recorded from the bookings list after saving — no need to re-enter customer or property details next time.
        </div>
        <div class="full form-actions">
          <button class="btn btn-primary" type="submit">Save booking</button>
        </div>
      </form>
    </div>

    <div id="addBankModal" class="sk-modal-overlay" style="display:none">
      <div class="sk-modal">
        <button type="button" class="sk-modal-close" id="closeBankModal">&times;</button>
        <div class="sk-modal-title">Add bank account</div>
        <div class="form-grid" style="padding:0">
          <div>
            <label>Company</label>
            <select id="modal_bank_company" required><?= company_options($pdo) ?></select>
          </div>
          <div>
            <label>Account name</label>
            <input type="text" id="modal_bank_acname" required>
          </div>
          <div>
            <label>Bank name</label>
            <input type="text" id="modal_bank_bname" required>
          </div>
          <div>
            <label>Account number</label>
            <input type="text" id="modal_bank_acno">
          </div>
          <div>
            <label>IFSC</label>
            <input type="text" id="modal_bank_ifsc">
          </div>
          <div>
            <label>Opening balance (₹)</label>
            <input type="number" step="0.01" id="modal_bank_balance" value="0">
          </div>
          <div class="full form-actions">
            <button type="button" class="btn btn-primary" id="saveBankModal">Save account</button>
          </div>
          <div class="full" id="modal_bank_error" style="display:none;color:var(--danger,#dc2626);font-size:0.85rem"></div>
        </div>
      </div>
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
        var totalEl = document.getElementById('total_amount');

        function money(n) {
          return '₹' + n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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
          var type = propertyTypeEl.value;
          var show = type === 'plot' || type === 'row_house';
          plotNoField.style.display = show ? '' : 'none';
          var label = document.getElementById('plot_no_label');
          if (label) {
            label.textContent = type === 'row_house' ? 'R-H number' : 'Plot no.';
          }
        }
        function recalcTotal() {
          var area = parseFloat(areaEl.value) || 0;
          var rate = parseFloat(rateEl.value) || 0;
          var total = Math.round((area * rate) * 100) / 100;
          totalEl.value = total > 0 ? total.toFixed(2) : '';
        }

        customerSelect.addEventListener('change', onCustomerChange);
        propertyTypeEl.addEventListener('change', togglePlotNo);
        areaEl.addEventListener('input', recalcTotal);
        rateEl.addEventListener('input', recalcTotal);

        showCustomerBookings();
        togglePlotNo();

        var modal = document.getElementById('addBankModal');
        var modalCompany = document.getElementById('modal_bank_company');
        var modalError = document.getElementById('modal_bank_error');
        var apiUrl = <?= json_encode(base_url('api/bank-accounts.php')) ?>;

        document.querySelectorAll('.open-add-bank-modal').forEach(function (link) {
          link.addEventListener('click', function (e) {
            e.preventDefault();
            var companyId = document.getElementById('company_id').value;
            if (companyId) modalCompany.value = companyId;
            modalError.style.display = 'none';
            modal.style.display = '';
          });
        });
        document.getElementById('closeBankModal').addEventListener('click', function () {
          modal.style.display = 'none';
        });
        modal.addEventListener('click', function (e) {
          if (e.target === modal) modal.style.display = 'none';
        });

        document.getElementById('saveBankModal').addEventListener('click', function () {
          var btn = this;
          var acName = document.getElementById('modal_bank_acname').value.trim();
          var bName = document.getElementById('modal_bank_bname').value.trim();
          var cid = modalCompany.value;
          if (!acName || !bName || !cid) {
            modalError.textContent = 'Company, account name and bank name are required.';
            modalError.style.display = '';
            return;
          }
          btn.disabled = true;
          btn.textContent = 'Saving…';
          fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              company_id: cid,
              account_name: acName,
              bank_name: bName,
              account_number: document.getElementById('modal_bank_acno').value.trim(),
              ifsc: document.getElementById('modal_bank_ifsc').value.trim(),
              opening_balance: document.getElementById('modal_bank_balance').value || 0
            })
          })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            btn.disabled = false;
            btn.textContent = 'Save account';
            if (data.error) {
              modalError.textContent = data.error;
              modalError.style.display = '';
              return;
            }
            var label = (data.account_name || '') + ' — ' + (data.bank_name || '');
            ['booking_bank_account_id', 'init_bank_account_id'].forEach(function (selId) {
              var sel = document.getElementById(selId);
              if (!sel) return;
              var opt = document.createElement('option');
              opt.value = data.id;
              opt.textContent = label;
              sel.appendChild(opt);
              sel.value = data.id;
            });
            document.getElementById('modal_bank_acname').value = '';
            document.getElementById('modal_bank_bname').value = '';
            document.getElementById('modal_bank_acno').value = '';
            document.getElementById('modal_bank_ifsc').value = '';
            document.getElementById('modal_bank_balance').value = '0';
            modal.style.display = 'none';
          })
          .catch(function () {
            btn.disabled = false;
            btn.textContent = 'Save account';
            modalError.textContent = 'Something went wrong. Try again.';
            modalError.style.display = '';
          });
        });
      })();
    </script>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$filterCompany = (int) get('company_id', 0);
$filterProject = (int) get('project_id', 0);
$filterStatus = get('status', '');
$q = get('q', '');
$expandId = (int) get('expand', 0);
$extraTxnId = (int) get('extra', 0);
$extraTxn = null;
if ($extraTxnId > 0) {
    $extraStmt = $pdo->prepare('SELECT id, amount, txn_date, description FROM transactions WHERE id = ?');
    $extraStmt->execute([$extraTxnId]);
    $extraTxn = $extraStmt->fetch() ?: null;
    if ($extraTxn) {
        $linkedStmt = $pdo->prepare('SELECT id FROM booking_payments WHERE transaction_id = ? LIMIT 1');
        $linkedStmt->execute([$extraTxnId]);
        if ($linkedStmt->fetchColumn()) {
            $extraTxn = null;
        }
    }
}
$filterFrom = get('from', '');
$filterTo = get('to', '');
[$fromMonth, $toMonth, $month, $year] = period_from_request();
if ($month !== '' || $year !== '') {
    if ($filterFrom === '' && $filterTo === '') {
        $filterFrom = $fromMonth ?: '';
        $filterTo = $toMonth ?: '';
    }
}

if ($filterCompany && $filterProject) {
    $pjOk = $pdo->prepare('SELECT id FROM projects WHERE id = ? AND company_id = ?');
    $pjOk->execute([$filterProject, $filterCompany]);
    if (!$pjOk->fetchColumn()) {
        $filterProject = 0;
    }
}

$pageTitle = 'Bookings';
$pageSub = 'Plot, flat and row house bookings — customer info stays saved for future payments.';
$pageActions = report_export_buttons()
    . '<a class="btn btn-primary" href="' . e(base_url('pages/bookings.php?action=add')) . '">+ New booking</a>';

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
if ($filterProject) { $sql .= ' AND b.project_id = ?'; $params[] = $filterProject; }
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
$totalSaleValue = array_sum(array_map(fn($b) => (float) $b['total_amount'], $bookings));
$totalReceived = array_sum(array_map(fn($b) => (float) $b['received'], $bookings));
$totalReturned = array_sum(array_map(fn($b) => (float) $b['returned'], $bookings));
$totalRemaining = $totalSaleValue - $totalReceived + $totalReturned;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('export_action', ''), ['csv', 'excel', 'pdf'], true)) {
    verify_csrf();
    $propertyLabel = static function (array $r): string {
        return booking_property_label($r['property_type'] ?? '', $r['plot_no'] ?? '');
    };
    $selectedIds = array_values(array_unique(array_filter(array_map('intval', $_POST['payment_ids'] ?? []), fn($id) => $id > 0)));
    $exportSelectedOnly = post('export_scope', '') !== 'all' && $selectedIds !== [];
    $companyName = 'All companies';
    if ($filterCompany) {
        $cn = $pdo->prepare('SELECT name FROM companies WHERE id = ?');
        $cn->execute([$filterCompany]);
        $companyName = (string) ($cn->fetchColumn() ?: 'Company #' . $filterCompany);
    }
    $projectName = 'All projects';
    if ($filterProject) {
        $pn = $pdo->prepare('SELECT name FROM projects WHERE id = ?');
        $pn->execute([$filterProject]);
        $projectName = (string) ($pn->fetchColumn() ?: 'Project #' . $filterProject);
    }

    if ($exportSelectedOnly) {
        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $expStmt = $pdo->prepare(
            "SELECT bp.*, cu.name AS customer_name, c.name AS company_name, p.name AS project_name,
                    b.property_type, b.plot_no
             FROM booking_payments bp
             JOIN bookings b ON b.id = bp.booking_id
             JOIN customers cu ON cu.id = b.customer_id
             JOIN companies c ON c.id = b.company_id
             LEFT JOIN projects p ON p.id = b.project_id
             WHERE bp.id IN ($placeholders)
             ORDER BY bp.payment_date ASC, bp.id ASC"
        );
        $expStmt->execute($selectedIds);
        $exportRows = $expStmt->fetchAll();
        if (!$exportRows) {
            flash('error', 'Selected payments were not found.');
            redirect('pages/bookings.php');
        }
        $exportReceived = array_sum(array_map(fn($r) => $r['payment_type'] === 'received' ? (float) $r['amount'] : 0, $exportRows));
        $exportReturned = array_sum(array_map(fn($r) => $r['payment_type'] === 'returned' ? (float) $r['amount'] : 0, $exportRows));
        $payRows = [];
        foreach ($exportRows as $i => $r) {
            $isRecv = ($r['payment_type'] ?? '') === 'received';
            $amt = (float) $r['amount'];
            $payRows[] = [
                (string) ($i + 1),
                report_plain_date($r['payment_date'] ?? null),
                $r['customer_name'] ?? '',
                $r['company_name'] ?? '',
                $r['project_name'] ?? '',
                $propertyLabel($r),
                $isRecv ? 'Received' : 'Returned',
                $isRecv ? $amt : null,
                $isRecv ? null : $amt,
                $r['notes'] ?? '',
            ];
        }
        report_download(post('export_action'), [
            'filename' => 'booking_payments',
            'title' => 'Booking Payments Register',
            'orientation' => 'landscape',
            'meta' => [
                ['Scope', 'Selected payments'],
                ['Entries', (string) count($exportRows)],
            ],
            'summary' => [
                ['Received', $exportReceived, 'money'],
                ['Returned', $exportReturned, 'money'],
                ['Net collected', $exportReceived - $exportReturned, 'money'],
                ['Entries', count($exportRows), 'int'],
            ],
            'tables' => [[
                'title' => 'Selected payments',
                'columns' => [
                    ['label' => 'Sr No', 'type' => 'text', 'width' => '5%', 'xls_width' => 35],
                    ['label' => 'Date', 'type' => 'text', 'width' => '9%', 'xls_width' => 80],
                    ['label' => 'Customer', 'type' => 'text', 'width' => '14%', 'xls_width' => 130],
                    ['label' => 'Company', 'type' => 'text', 'width' => '12%', 'xls_width' => 120],
                    ['label' => 'Project', 'type' => 'text', 'width' => '11%', 'xls_width' => 110],
                    ['label' => 'Property', 'type' => 'text', 'width' => '10%', 'xls_width' => 90],
                    ['label' => 'Type', 'type' => 'text', 'width' => '8%', 'xls_width' => 70],
                    ['label' => 'Received (INR)', 'type' => 'money', 'width' => '11%', 'xls_width' => 110],
                    ['label' => 'Returned (INR)', 'type' => 'money', 'width' => '11%', 'xls_width' => 110],
                    ['label' => 'Notes', 'type' => 'text', 'width' => '9%', 'xls_width' => 140],
                ],
                'rows' => $payRows,
                'totals' => ['', 'TOTAL', '', '', '', '', '', $exportReceived, $exportReturned, ''],
            ]],
            'notes' => [
                'System-generated booking payments register for the selected entries.',
                'Confidential — internal use only.',
            ],
        ]);
        redirect('pages/bookings.php');
    }

    $bookingRows = [];
    foreach ($bookings as $i => $b) {
        $received = (float) $b['received'];
        $returned = (float) $b['returned'];
        $sale = (float) $b['total_amount'];
        $bookingRows[] = [
            (string) ($i + 1),
            $b['customer_name'] ?? '',
            $b['customer_phone'] ?? '',
            $propertyLabel($b),
            $b['company_name'] ?? '',
            $b['project_name'] ?? '',
            $b['area_sqft'] !== null ? (string) $b['area_sqft'] : '',
            $b['rate_per_sqft'] !== null ? (float) $b['rate_per_sqft'] : null,
            $sale,
            $received,
            $returned,
            $sale - $received + $returned,
            ucfirst((string) ($b['status'] ?? '')),
        ];
    }
    $payRows = [];
    $payRecv = 0.0;
    $payRet = 0.0;
    $n = 0;
    $paymentsForExport = [];
    if ($bookings) {
        $exportBookingIds = array_map(fn($b) => (int) $b['id'], $bookings);
        $payPh = implode(',', array_fill(0, count($exportBookingIds), '?'));
        $allPayStmt = $pdo->prepare(
            "SELECT * FROM booking_payments WHERE booking_id IN ($payPh) ORDER BY payment_date ASC, id ASC"
        );
        $allPayStmt->execute($exportBookingIds);
        foreach ($allPayStmt->fetchAll() as $pay) {
            $paymentsForExport[(int) $pay['booking_id']][] = $pay;
        }
    }
    foreach ($bookings as $b) {
        foreach ($paymentsForExport[(int) $b['id']] ?? [] as $pay) {
            $n++;
            $isRecv = ($pay['payment_type'] ?? '') === 'received';
            $amt = (float) $pay['amount'];
            if ($isRecv) {
                $payRecv += $amt;
            } else {
                $payRet += $amt;
            }
            $payRows[] = [
                (string) $n,
                report_plain_date($pay['payment_date'] ?? null),
                $b['customer_name'] ?? '',
                $propertyLabel($b),
                $isRecv ? 'Received' : 'Returned',
                $isRecv ? $amt : null,
                $isRecv ? null : $amt,
                $pay['notes'] ?? '',
            ];
        }
    }
    $rateColType = 'money';
    report_download(post('export_action'), [
        'filename' => 'bookings_register',
        'title' => 'Booking Register',
        'orientation' => 'landscape',
        'meta' => [
            ['Company', $companyName],
            ['Project', $projectName],
            ['Status', $filterStatus !== '' ? ucfirst($filterStatus) : 'All'],
            ['Search', $q !== '' ? $q : '—'],
            ['Payment period', report_display_period($filterFrom !== '' ? $filterFrom : null, $filterTo !== '' ? $filterTo : null, $month, $year)],
        ],
        'summary' => [
            ['Sale value', $totalSaleValue, 'money'],
            ['Received', $totalReceived, 'money'],
            ['Remaining', $totalRemaining, 'money'],
            ['Bookings', count($bookings), 'int'],
            ['Payment entries', $n, 'int'],
        ],
        'tables' => [
            [
                'title' => 'Bookings',
                'columns' => [
                    ['label' => 'Sr No', 'type' => 'text', 'width' => '4%', 'xls_width' => 35],
                    ['label' => 'Customer', 'type' => 'text', 'width' => '12%', 'xls_width' => 130],
                    ['label' => 'Phone', 'type' => 'text', 'width' => '9%', 'xls_width' => 90],
                    ['label' => 'Property', 'type' => 'text', 'width' => '8%', 'xls_width' => 80],
                    ['label' => 'Company', 'type' => 'text', 'width' => '10%', 'xls_width' => 120],
                    ['label' => 'Project', 'type' => 'text', 'width' => '9%', 'xls_width' => 110],
                    ['label' => 'Area sqft', 'type' => 'text', 'width' => '7%', 'xls_width' => 70],
                    ['label' => 'Rate (INR)', 'type' => $rateColType, 'width' => '8%', 'xls_width' => 90],
                    ['label' => 'Sale value (INR)', 'type' => 'money', 'width' => '9%', 'xls_width' => 110],
                    ['label' => 'Received (INR)', 'type' => 'money', 'width' => '8%', 'xls_width' => 100],
                    ['label' => 'Returned (INR)', 'type' => 'money', 'width' => '8%', 'xls_width' => 100],
                    ['label' => 'Remaining (INR)', 'type' => 'money', 'width' => '8%', 'xls_width' => 100],
                    ['label' => 'Status', 'type' => 'text', 'width' => '7%', 'xls_width' => 70],
                ],
                'rows' => $bookingRows,
                'totals' => ['', 'TOTAL', '', '', '', '', '', '', $totalSaleValue, $totalReceived, $totalReturned, $totalRemaining, ''],
            ],
            [
                'title' => 'Payment history',
                'columns' => [
                    ['label' => 'Sr No', 'type' => 'text', 'width' => '6%', 'xls_width' => 35],
                    ['label' => 'Date', 'type' => 'text', 'width' => '10%', 'xls_width' => 80],
                    ['label' => 'Customer', 'type' => 'text', 'width' => '16%', 'xls_width' => 140],
                    ['label' => 'Property', 'type' => 'text', 'width' => '12%', 'xls_width' => 100],
                    ['label' => 'Type', 'type' => 'text', 'width' => '10%', 'xls_width' => 80],
                    ['label' => 'Received (INR)', 'type' => 'money', 'width' => '14%', 'xls_width' => 110],
                    ['label' => 'Returned (INR)', 'type' => 'money', 'width' => '14%', 'xls_width' => 110],
                    ['label' => 'Notes', 'type' => 'text', 'width' => '18%', 'xls_width' => 160],
                ],
                'rows' => $payRows,
                'totals' => ['', 'TOTAL', '', '', '', $payRecv, $payRet, ''],
            ],
        ],
        'notes' => [
            'System-generated booking register. Sale/received/remaining totals are the full booking history.',
            'Payment history includes every payment for the listed bookings.',
            'Confidential — internal use only.',
        ],
    ]);
    redirect('pages/bookings.php');
}

if ($expandId && (!isset($_GET['page']) || $_GET['page'] === '')) {
    foreach ($bookings as $i => $b) {
        if ((int) $b['id'] === $expandId) {
            $_GET['page'] = (string) (intdiv((int) $i, list_page_limit()) + 1);
            break;
        }
    }
}
$list = paginate_list($bookings);

// Load payment lines only for the current page (export uses its own queries above).
$pageBookingIds = array_map(static fn($b) => (int) $b['id'], $list['rows']);
if ($pageBookingIds) {
    $ph = implode(',', array_fill(0, count($pageBookingIds), '?'));
    $paySql = "SELECT * FROM booking_payments WHERE booking_id IN ($ph)";
    $payParams = $pageBookingIds;
    apply_date_range($paySql, $payParams, $filterFrom !== '' ? $filterFrom : null, $filterTo !== '' ? $filterTo : null, 'payment_date');
    $paySql .= ' ORDER BY payment_date DESC, id DESC';
    $payStmt = $pdo->prepare($paySql);
    $payStmt->execute($payParams);
    foreach ($payStmt->fetchAll() as $pay) {
        $paymentsByBooking[(int) $pay['booking_id']][] = $pay;
    }
}

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
  <?= list_limit_hidden() ?>
  <div class="field">
    <label>Search</label>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Customer, phone, plot no…">
  </div>
  <div class="field">
    <label>Company</label>
    <select name="company_id" onchange="this.form.project_id.value=''; this.form.submit()">
      <option value="">All</option>
      <?php foreach ($pdo->query('SELECT id, name FROM companies ORDER BY type, name') as $co): ?>
        <option value="<?= (int) $co['id'] ?>" <?= $filterCompany === (int) $co['id'] ? 'selected' : '' ?>><?= e($co['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label>Project</label>
    <select name="project_id" onchange="this.form.submit()">
      <?= project_options($pdo, $filterCompany ?: null, $filterProject ?: null) ?>
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
<p class="muted" style="font-size:0.78rem;margin:-0.5rem 0 1rem">Date filters apply to the payment history shown per booking. Excel / CSV / PDF always include every payment for the bookings in this list unless you tick only some rows.</p>
<?php if ($bookings): ?>
<form id="bookingsExportForm" class="bulk-export-form" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="export_scope" value="all">
  <div class="export-toolbar no-print">
    <label class="select-all-label">
      <input type="checkbox" class="select-all-toggle">
      Select all payments
    </label>
    <span class="selected-count muted">0 selected</span>
    <div class="export-actions">
      <button class="btn btn-outline btn-sm export-csv-btn" type="submit" name="export_action" value="csv">Export CSV</button>
      <button class="btn btn-outline btn-sm" type="submit" name="export_action" value="excel">Export Excel</button>
      <button class="btn btn-outline btn-sm export-pdf-btn" type="submit" name="export_action" value="pdf">Export PDF</button>
    </div>
  </div>
</form>
<?php endif; ?>
<div class="card" id="list">
  <div class="card-head">
    <h2 class="card-title">Bookings</h2>
    <?php render_limit_control('bookings.php'); ?>
  </div>
  <?php if (!$list['total']): ?>
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
          <?php foreach ($list['rows'] as $b):
            $received = (float) $b['received'];
            $returned = (float) $b['returned'];
            $total = (float) $b['total_amount'];
            $remaining = $total - $received + $returned;
            $detailId = 'booking-detail-' . $b['id'];
            $propertyLabel = booking_property_label($b['property_type'] ?? '', $b['plot_no'] ?? '');
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
                <?php if ($extraTxn && $expandId === (int) $b['id']): ?>
                  <div class="highlight-box" style="border-color:#fde68a;background:#fffbeb;margin-bottom:1rem">
                    <strong>You opened this from an extra Transactions row, not from a payment listed below.</strong>
                    <p style="margin:0.4rem 0 0">This booking has only one <?= money($extraTxn['amount']) ?> payment<?= !empty($extraTxn['txn_date']) ? ' on ' . e(format_date($extraTxn['txn_date'])) : '' ?>. The other Transactions credit is a duplicate on the ledger. Go back to Transactions and delete the row labelled <strong>Extra ledger row</strong>. Do not delete the <?= money($extraTxn['amount']) ?> line in this list unless the customer did not actually pay it.</p>
                  </div>
                <?php endif; ?>
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
                      <thead><tr><th class="select-col"></th><th>Date</th><th>Type</th><th class="num">Amount</th><th>Notes</th><th class="actions">Actions</th></tr></thead>
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
                            <td class="actions">
                              <?php if (can_delete()): ?>
                              <form method="post" action="<?= e(base_url(list_return_url('bookings.php', [], ''))) ?>" style="display:inline">
                                <?= csrf_field() ?>
                                <?= list_return_hidden('bookings.php') ?>
                                <input type="hidden" name="action" value="delete_payment">
                                <input type="hidden" name="payment_id" value="<?= (int) $pay['id'] ?>">
                                <button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete this payment entry? It will be removed from the booking and the ledger.">Delete</button>
                              </form>
                              <?php endif; ?>
                            </td>
                          </tr>
                          <tr class="row-detail" id="<?= e($payEditId) ?>" hidden>
                            <td colspan="6">
                              <form method="post" class="form-grid" style="padding:0" action="<?= e(base_url(list_return_url('bookings.php', [], ''))) ?>">
                                <?= csrf_field() ?>
                                <?= list_return_hidden('bookings.php') ?>
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
                                  <?php if (can_delete()): ?>
                                  <button class="btn btn-danger btn-sm" type="submit" name="action" value="delete_payment" formnovalidate data-confirm="Delete this payment entry? It will be removed from the booking and the ledger.">Delete entry</button>
                                  <?php endif; ?>
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
                      <button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete this entire booking and all of its payment history? Linked ledger transactions will also be removed. This cannot be undone.">Delete entire booking</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php render_pager('bookings.php', $list); ?>
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
