<?php
declare(strict_types=1);

/**
 * Auto-apply schema upgrades (Hostinger-safe).
 */
function ensure_v2_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $version = (string) (app_config('schema_version') ?? 1);
    $marker = __DIR__ . '/../config/.schema_version';
    if (is_file($marker) && trim((string) @file_get_contents($marker)) === $version) {
        return;
    }

    try {
        $pdo->query('SELECT 1 FROM loan_emis LIMIT 1');
    } catch (Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS loan_emis (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          loan_id INT UNSIGNED NOT NULL,
          installment_no INT UNSIGNED NOT NULL,
          due_date DATE NOT NULL,
          amount DECIMAL(14,2) NOT NULL DEFAULT 0,
          principal_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
          interest_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
          paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
          principal_paid DECIMAL(14,2) NOT NULL DEFAULT 0,
          interest_paid DECIMAL(14,2) NOT NULL DEFAULT 0,
          paid_date DATE NULL,
          status ENUM('pending','paid','partial','skipped') NOT NULL DEFAULT 'pending',
          notes TEXT NULL,
          transaction_id INT UNSIGNED NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uq_loan_installment (loan_id, installment_no),
          INDEX idx_emi_due (due_date, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    try {
        $pdo->query('SELECT 1 FROM attachments LIMIT 1');
    } catch (Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS attachments (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          transaction_id INT UNSIGNED NOT NULL,
          original_name VARCHAR(255) NOT NULL,
          stored_name VARCHAR(255) NOT NULL,
          mime_type VARCHAR(120) NULL,
          size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
          uploaded_by INT UNSIGNED NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_att_txn (transaction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    try {
        $pdo->query('SELECT 1 FROM audit_logs LIMIT 1');
    } catch (Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          user_id INT UNSIGNED NULL,
          user_name VARCHAR(120) NULL,
          action VARCHAR(40) NOT NULL,
          entity_type VARCHAR(60) NOT NULL,
          entity_id INT UNSIGNED NULL,
          summary VARCHAR(255) NOT NULL,
          before_json TEXT NULL,
          after_json TEXT NULL,
          ip_address VARCHAR(64) NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_audit_created (created_at),
          INDEX idx_audit_entity (entity_type, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    try {
        $pdo->query('SELECT 1 FROM bank_transfers LIMIT 1');
    } catch (Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS bank_transfers (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          company_id INT UNSIGNED NOT NULL,
          transfer_type ENUM('internal','external','inbound') NOT NULL DEFAULT 'internal',
          from_account_id INT UNSIGNED NULL,
          to_account_id INT UNSIGNED NULL,
          source_name VARCHAR(160) NULL,
          source_account_number VARCHAR(60) NULL,
          source_ifsc VARCHAR(20) NULL,
          source_bank_name VARCHAR(160) NULL,
          recipient_name VARCHAR(160) NULL,
          recipient_account_number VARCHAR(60) NULL,
          recipient_ifsc VARCHAR(20) NULL,
          recipient_bank_name VARCHAR(160) NULL,
          amount DECIMAL(14,2) NOT NULL,
          transfer_date DATE NOT NULL,
          reference_no VARCHAR(80) NULL,
          notes TEXT NULL,
          debit_txn_id INT UNSIGNED NULL,
          credit_txn_id INT UNSIGNED NULL,
          created_by INT UNSIGNED NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_transfer_date (transfer_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    $transferCols = $pdo->query("SHOW COLUMNS FROM bank_transfers")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('transfer_type', $transferCols, true)) {
        $pdo->exec("ALTER TABLE bank_transfers ADD COLUMN transfer_type ENUM('internal','external','inbound') NOT NULL DEFAULT 'internal' AFTER company_id");
        $pdo->exec('ALTER TABLE bank_transfers MODIFY to_account_id INT UNSIGNED NULL');
        $pdo->exec('ALTER TABLE bank_transfers ADD COLUMN recipient_name VARCHAR(160) NULL AFTER to_account_id');
        $pdo->exec('ALTER TABLE bank_transfers ADD COLUMN recipient_account_number VARCHAR(60) NULL AFTER recipient_name');
        $pdo->exec('ALTER TABLE bank_transfers ADD COLUMN recipient_ifsc VARCHAR(20) NULL AFTER recipient_account_number');
        $pdo->exec('ALTER TABLE bank_transfers ADD COLUMN recipient_bank_name VARCHAR(160) NULL AFTER recipient_ifsc');
    }
    // Expand transfer types + allow null from_account for inbound; capture external source details
    try {
        $pdo->exec("ALTER TABLE bank_transfers MODIFY transfer_type ENUM('internal','external','inbound') NOT NULL DEFAULT 'internal'");
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE bank_transfers MODIFY from_account_id INT UNSIGNED NULL');
    } catch (Throwable $e) {
    }
    $transferCols = $pdo->query("SHOW COLUMNS FROM bank_transfers")->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'source_name' => 'ALTER TABLE bank_transfers ADD COLUMN source_name VARCHAR(160) NULL AFTER from_account_id',
        'source_account_number' => 'ALTER TABLE bank_transfers ADD COLUMN source_account_number VARCHAR(60) NULL AFTER source_name',
        'source_ifsc' => 'ALTER TABLE bank_transfers ADD COLUMN source_ifsc VARCHAR(20) NULL AFTER source_account_number',
        'source_bank_name' => 'ALTER TABLE bank_transfers ADD COLUMN source_bank_name VARCHAR(160) NULL AFTER source_ifsc',
    ] as $col => $ddl) {
        if (!in_array($col, $transferCols, true)) {
            $pdo->exec($ddl);
        }
    }

    try {
        $pdo->query('SELECT 1 FROM login_attempts LIMIT 1');
    } catch (Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          email VARCHAR(160) NOT NULL,
          ip_address VARCHAR(64) NOT NULL,
          attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_login_email_time (email, attempted_at),
          INDEX idx_login_ip_time (ip_address, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    try {
        $pdo->query('SELECT 1 FROM password_resets LIMIT 1');
    } catch (Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          user_id INT UNSIGNED NOT NULL,
          token_hash VARCHAR(64) NOT NULL,
          expires_at DATETIME NOT NULL,
          used_at DATETIME NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uq_reset_token (token_hash),
          INDEX idx_reset_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    $cols = $pdo->query("SHOW COLUMNS FROM bank_loans")->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'emi_amount' => 'ALTER TABLE bank_loans ADD COLUMN emi_amount DECIMAL(14,2) NULL AFTER interest_rate',
        'tenure_months' => 'ALTER TABLE bank_loans ADD COLUMN tenure_months INT UNSIGNED NULL AFTER emi_amount',
        'emi_start_date' => 'ALTER TABLE bank_loans ADD COLUMN emi_start_date DATE NULL AFTER tenure_months',
        'interest_charges' => 'ALTER TABLE bank_loans ADD COLUMN interest_charges DECIMAL(14,2) NULL AFTER interest_rate',
    ] as $col => $sql) {
        if (!in_array($col, $cols, true)) {
            $pdo->exec($sql);
        }
    }

    $emiCols = [];
    try {
        $emiCols = $pdo->query("SHOW COLUMNS FROM loan_emis")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
    }
    if ($emiCols && !in_array('principal_amount', $emiCols, true)) {
        $pdo->exec('ALTER TABLE loan_emis ADD COLUMN principal_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER amount');
    }
    if ($emiCols && !in_array('interest_amount', $emiCols, true)) {
        $pdo->exec('ALTER TABLE loan_emis ADD COLUMN interest_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER principal_amount');
    }
    if ($emiCols && !in_array('principal_paid', $emiCols, true)) {
        $pdo->exec('ALTER TABLE loan_emis ADD COLUMN principal_paid DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER paid_amount');
    }
    if ($emiCols && !in_array('interest_paid', $emiCols, true)) {
        $pdo->exec('ALTER TABLE loan_emis ADD COLUMN interest_paid DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER principal_paid');
    }

    $userCols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('status', $userCols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN status ENUM('active','disabled') NOT NULL DEFAULT 'active' AFTER role");
    }
    if (!in_array('must_change_password', $userCols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    }

    // Ensure transfer category exists
    $chk = $pdo->prepare("SELECT id FROM categories WHERE section='general' AND slug='bank_transfer' LIMIT 1");
    $chk->execute();
    if (!$chk->fetchColumn()) {
        $pdo->exec("INSERT INTO categories (section, name, slug, sort_order) VALUES ('general', 'Bank Transfer', 'bank_transfer', 40)");
    }

    // Ensure investment withdrawal category exists (debit side of investments)
    $chkInvW = $pdo->prepare("SELECT id FROM categories WHERE section='general' AND slug='investment_withdrawal' LIMIT 1");
    $chkInvW->execute();
    if (!$chkInvW->fetchColumn()) {
        $pdo->exec("INSERT INTO categories (section, name, slug, sort_order) VALUES ('general', 'Investment Withdrawal', 'investment_withdrawal', 50)");
    }

    // Ensure Daily Credit / Monthly Credit investment categories exist
    foreach ([
        ['Daily Credit', 'daily_credit', 11],
        ['Monthly Credit', 'monthly_credit', 12],
    ] as [$catName, $catSlug, $catSort]) {
        $chkCat = $pdo->prepare("SELECT id FROM categories WHERE section='credit' AND slug=? LIMIT 1");
        $chkCat->execute([$catSlug]);
        if (!$chkCat->fetchColumn()) {
            $ins = $pdo->prepare("INSERT INTO categories (section, name, slug, sort_order) VALUES ('credit', ?, ?, ?)");
            $ins->execute([$catName, $catSlug, $catSort]);
        }
    }

    // Ensure Daily Debit / Monthly Debit exist — debit-side mirror of Daily/Monthly Credit
    foreach ([
        ['Daily Debit', 'daily_debit', 51],
        ['Monthly Debit', 'monthly_debit', 52],
    ] as [$catName, $catSlug, $catSort]) {
        $chkCat = $pdo->prepare("SELECT id FROM categories WHERE section='general' AND slug=? LIMIT 1");
        $chkCat->execute([$catSlug]);
        if (!$chkCat->fetchColumn()) {
            $ins = $pdo->prepare("INSERT INTO categories (section, name, slug, sort_order) VALUES ('general', ?, ?, ?)");
            $ins->execute([$catName, $catSlug, $catSort]);
        }
    }

    try {
        $pdo->query('SELECT 1 FROM booking_details LIMIT 1');
    } catch (Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS booking_details (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          transaction_id INT UNSIGNED NOT NULL,
          customer_name VARCHAR(160) NULL,
          property_type ENUM('row_house','flat','plot') NULL,
          plot_no VARCHAR(60) NULL,
          area_sqft DECIMAL(12,2) NULL,
          rate_per_sqft DECIMAL(12,2) NULL,
          total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
          amount_received DECIMAL(14,2) NOT NULL DEFAULT 0,
          amount_returned DECIMAL(14,2) NOT NULL DEFAULT 0,
          remaining_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          CONSTRAINT fk_booking_txn FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
          UNIQUE KEY uq_booking_txn (transaction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    $bookingCols = [];
    try {
        $bookingCols = $pdo->query('SHOW COLUMNS FROM booking_details')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
    }
    if ($bookingCols && !in_array('property_type', $bookingCols, true)) {
        $pdo->exec("ALTER TABLE booking_details ADD COLUMN property_type ENUM('row_house','flat','plot') NULL AFTER customer_name");
    }

    // Ensure Booking Refund category exists (debit-side mirror of Booking)
    $chkRefund = $pdo->prepare("SELECT id FROM categories WHERE section='general' AND slug='booking_refund' LIMIT 1");
    $chkRefund->execute();
    if (!$chkRefund->fetchColumn()) {
        $pdo->exec("INSERT INTO categories (section, name, slug, sort_order) VALUES ('general', 'Booking Refund', 'booking_refund', 53)");
    }

    try {
        $pdo->query('SELECT 1 FROM customers LIMIT 1');
    } catch (Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(160) NOT NULL,
          phone VARCHAR(40) NULL,
          email VARCHAR(160) NULL,
          address TEXT NULL,
          notes TEXT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_customers_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    try {
        $pdo->query('SELECT 1 FROM bookings LIMIT 1');
    } catch (Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS bookings (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          customer_id INT UNSIGNED NOT NULL,
          company_id INT UNSIGNED NOT NULL,
          project_id INT UNSIGNED NULL,
          property_type ENUM('row_house','flat','plot') NOT NULL,
          plot_no VARCHAR(60) NULL,
          area_sqft DECIMAL(12,2) NOT NULL DEFAULT 0,
          rate_per_sqft DECIMAL(12,2) NOT NULL DEFAULT 0,
          total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
          status ENUM('active','cancelled','completed') NOT NULL DEFAULT 'active',
          notes TEXT NULL,
          created_by INT UNSIGNED NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          CONSTRAINT fk_booking_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
          CONSTRAINT fk_booking_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
          CONSTRAINT fk_booking_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
          CONSTRAINT fk_booking_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
          INDEX idx_bookings_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    try {
        $pdo->query('SELECT 1 FROM booking_payments LIMIT 1');
    } catch (Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS booking_payments (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          booking_id INT UNSIGNED NOT NULL,
          transaction_id INT UNSIGNED NULL,
          payment_type ENUM('received','returned') NOT NULL DEFAULT 'received',
          amount DECIMAL(14,2) NOT NULL DEFAULT 0,
          payment_date DATE NOT NULL,
          notes TEXT NULL,
          created_by INT UNSIGNED NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          CONSTRAINT fk_bp_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
          CONSTRAINT fk_bp_txn FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
          CONSTRAINT fk_bp_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
          INDEX idx_bp_booking (booking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // Ensure Loan Repayment category exists
    $chkLoanRepay = $pdo->prepare("SELECT id FROM categories WHERE section='expense' AND slug='loan_repayment' LIMIT 1");
    $chkLoanRepay->execute();
    if (!$chkLoanRepay->fetchColumn()) {
        $pdo->exec("INSERT INTO categories (section, name, slug, sort_order) VALUES ('expense', 'Loan Repayment', 'loan_repayment', 75)");
    }

    try {
        $pdo->query('SELECT 1 FROM loan_repayments LIMIT 1');
    } catch (Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS loan_repayments (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          loan_id INT UNSIGNED NOT NULL,
          amount DECIMAL(14,2) NOT NULL DEFAULT 0,
          principal_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
          interest_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
          payment_date DATE NOT NULL,
          bank_account_id INT UNSIGNED NULL,
          transaction_id INT UNSIGNED NULL,
          notes TEXT NULL,
          created_by INT UNSIGNED NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          CONSTRAINT fk_lr_loan FOREIGN KEY (loan_id) REFERENCES bank_loans(id) ON DELETE CASCADE,
          CONSTRAINT fk_lr_account FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL,
          CONSTRAINT fk_lr_txn FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
          CONSTRAINT fk_lr_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
          INDEX idx_lr_loan (loan_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // ---- Investors (identity behind Investment transactions) ----
    try {
        $pdo->query('SELECT 1 FROM investors LIMIT 1');
    } catch (Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS investors (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(160) NOT NULL,
          phone VARCHAR(40) NULL,
          email VARCHAR(160) NULL,
          address TEXT NULL,
          notes TEXT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_investors_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    $txnCols = $pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('investor_id', $txnCols, true)) {
        $pdo->exec('ALTER TABLE transactions ADD COLUMN investor_id INT UNSIGNED NULL AFTER partner_id');
        try {
            $pdo->exec('ALTER TABLE transactions ADD CONSTRAINT fk_txn_investor FOREIGN KEY (investor_id) REFERENCES investors(id) ON DELETE SET NULL');
        } catch (Throwable $e) {
        }
    }
    if (!in_array('interest_amount', $txnCols, true)) {
        $pdo->exec('ALTER TABLE transactions ADD COLUMN interest_amount DECIMAL(14,2) NULL AFTER investor_id');
    }
    if (!in_array('payee_name', $txnCols, true)) {
        $pdo->exec('ALTER TABLE transactions ADD COLUMN payee_name VARCHAR(160) NULL AFTER reference_no');
    }

    // ---- Partner Capital / Advance categories (credit + debit mirror) ----
    // Partner Advance is money in (credit). Returning it is money out (debit/general).
    migrate_partner_advance_categories($pdo);
    $pdo->exec("UPDATE transactions t JOIN categories c ON c.id = t.category_id SET t.txn_type = 'credit' WHERE c.slug = 'partner_advance' AND t.txn_type <> 'credit'");
    $pdo->exec("UPDATE transactions t JOIN categories c ON c.id = t.category_id SET t.txn_type = 'debit' WHERE c.slug = 'partner_advance_return' AND t.txn_type <> 'debit'");

    $partnerCols = $pdo->query("SHOW COLUMNS FROM partners")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('advance_amount', $partnerCols, true)) {
        $pdo->exec('ALTER TABLE partners ADD COLUMN advance_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER invested_amount');
    }
    try {
        foreach ($pdo->query('SELECT id FROM partners')->fetchAll(PDO::FETCH_COLUMN) ?: [] as $pid) {
            sync_partner_invested($pdo, (int) $pid);
            sync_partner_advance($pdo, (int) $pid);
        }
    } catch (Throwable $e) {
    }

    // ---- Bank loan mortgage document tracking ----
    $loanCols = $pdo->query("SHOW COLUMNS FROM bank_loans")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('mortgage_noc_date', $loanCols, true)) {
        $pdo->exec('ALTER TABLE bank_loans ADD COLUMN mortgage_noc_date DATE NULL AFTER end_date');
    }
    if (!in_array('reconveyance_date', $loanCols, true)) {
        $pdo->exec('ALTER TABLE bank_loans ADD COLUMN reconveyance_date DATE NULL AFTER mortgage_noc_date');
    }

    try {
        $pdo->query('SELECT 1 FROM loan_borrowers LIMIT 1');
    } catch (Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS loan_borrowers (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          loan_id INT UNSIGNED NOT NULL,
          name VARCHAR(160) NOT NULL,
          loan_amount DECIMAL(14,2) NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          CONSTRAINT fk_borrower_loan FOREIGN KEY (loan_id) REFERENCES bank_loans(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    $borrowerCols = $pdo->query("SHOW COLUMNS FROM loan_borrowers")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('loan_amount', $borrowerCols, true)) {
        $pdo->exec('ALTER TABLE loan_borrowers ADD COLUMN loan_amount DECIMAL(14,2) NULL AFTER name');
    }
    if (in_array('phone', $borrowerCols, true)) {
        $pdo->exec('ALTER TABLE loan_borrowers DROP COLUMN phone');
    }
    foreach ([
        'account_number' => 'ALTER TABLE loan_borrowers ADD COLUMN account_number VARCHAR(60) NULL AFTER name',
        'outstanding_amount' => 'ALTER TABLE loan_borrowers ADD COLUMN outstanding_amount DECIMAL(14,2) NULL AFTER loan_amount',
        'interest_charges' => 'ALTER TABLE loan_borrowers ADD COLUMN interest_charges DECIMAL(14,2) NULL AFTER outstanding_amount',
        'start_date' => 'ALTER TABLE loan_borrowers ADD COLUMN start_date DATE NULL AFTER interest_charges',
        'end_date' => 'ALTER TABLE loan_borrowers ADD COLUMN end_date DATE NULL AFTER start_date',
        'mortgage_noc_date' => 'ALTER TABLE loan_borrowers ADD COLUMN mortgage_noc_date DATE NULL AFTER end_date',
        'reconveyance_date' => 'ALTER TABLE loan_borrowers ADD COLUMN reconveyance_date DATE NULL AFTER mortgage_noc_date',
    ] as $col => $ddl) {
        if (!in_array($col, $borrowerCols, true)) {
            $pdo->exec($ddl);
        }
    }

    $repayCols = $pdo->query("SHOW COLUMNS FROM loan_repayments")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('borrower_id', $repayCols, true)) {
        $pdo->exec('ALTER TABLE loan_repayments ADD COLUMN borrower_id INT UNSIGNED NULL AFTER transaction_id');
        try {
            $pdo->exec('ALTER TABLE loan_repayments ADD CONSTRAINT fk_lr_borrower FOREIGN KEY (borrower_id) REFERENCES loan_borrowers(id) ON DELETE SET NULL');
        } catch (Throwable $e) {
        }
    }

    // ---- Project land-record fields ----
    $projectCols = $pdo->query("SHOW COLUMNS FROM projects")->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'deed_name' => 'ALTER TABLE projects ADD COLUMN deed_name VARCHAR(180) NULL AFTER end_date',
        'party_name' => 'ALTER TABLE projects ADD COLUMN party_name VARCHAR(180) NULL AFTER deed_name',
        'survey_no' => 'ALTER TABLE projects ADD COLUMN survey_no VARCHAR(80) NULL AFTER party_name',
        'area_sqft' => 'ALTER TABLE projects ADD COLUMN area_sqft DECIMAL(12,2) NULL AFTER survey_no',
        'address' => 'ALTER TABLE projects ADD COLUMN address TEXT NULL AFTER area_sqft',
    ] as $col => $ddl) {
        if (!in_array($col, $projectCols, true)) {
            $pdo->exec($ddl);
        }
    }

  // bookings.bank_account_id (v12)
    $bkCols = array_column($pdo->query('SHOW COLUMNS FROM bookings')->fetchAll(), 'Field');
    if (!in_array('bank_account_id', $bkCols, true)) {
        $pdo->exec('ALTER TABLE bookings ADD COLUMN bank_account_id INT UNSIGNED NULL AFTER project_id');
        try { $pdo->exec('ALTER TABLE bookings ADD CONSTRAINT fk_booking_bank FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL'); } catch (Throwable $e) {}
    }

  // Query performance indexes (safe to re-check)
    ensure_db_index($pdo, 'transactions', 'idx_txn_bank_account', 'bank_account_id');
    ensure_db_index($pdo, 'transactions', 'idx_txn_company_date', 'company_id, txn_date');
    ensure_db_index($pdo, 'transactions', 'idx_txn_type_date', 'txn_type, txn_date');
    ensure_db_index($pdo, 'transactions', 'idx_txn_category', 'category_id');
    ensure_db_index($pdo, 'transactions', 'idx_txn_project', 'project_id');
    ensure_db_index($pdo, 'transactions', 'idx_txn_project_date', 'project_id, txn_date');
    ensure_db_index($pdo, 'booking_payments', 'idx_bp_transaction', 'transaction_id');
    ensure_db_index($pdo, 'bookings', 'idx_bookings_project', 'project_id');
    ensure_db_index($pdo, 'loan_emis', 'idx_emi_status_due', 'status, due_date');

    @file_put_contents($marker, $version);
}

function migrate_partner_advance_categories(PDO $pdo): void
{
    $ensure = static function (PDO $pdo, string $section, string $name, string $slug, int $sortOrder): int {
        $stmt = $pdo->prepare('SELECT id FROM categories WHERE section = ? AND slug = ? LIMIT 1');
        $stmt->execute([$section, $slug]);
        $id = $stmt->fetchColumn();
        if ($id) {
            $pdo->prepare('UPDATE categories SET name = ?, sort_order = ? WHERE id = ?')->execute([$name, $sortOrder, (int) $id]);
            return (int) $id;
        }
        $ins = $pdo->prepare('INSERT INTO categories (section, name, slug, sort_order) VALUES (?,?,?,?)');
        $ins->execute([$section, $name, $slug, $sortOrder]);
        return (int) $pdo->lastInsertId();
    };

    $moveIfWrongSection = static function (PDO $pdo, string $slug, string $fromSection, int $toId): void {
        $fromStmt = $pdo->prepare('SELECT id FROM categories WHERE section = ? AND slug = ? LIMIT 1');
        $fromStmt->execute([$fromSection, $slug]);
        $fromId = $fromStmt->fetchColumn();
        if (!$fromId || (int) $fromId === $toId) {
            return;
        }
        $pdo->prepare('UPDATE transactions SET category_id = ? WHERE category_id = ?')->execute([$toId, (int) $fromId]);
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([(int) $fromId]);
    };

    $advanceId = $ensure($pdo, 'credit', 'Partner Advance', 'partner_advance', 22);
    $returnId = $ensure($pdo, 'general', 'Partner Advance Return', 'partner_advance_return', 55);
    $moveIfWrongSection($pdo, 'partner_advance', 'general', $advanceId);
    $moveIfWrongSection($pdo, 'partner_advance_return', 'credit', $returnId);

    foreach ([
        ['credit', 'Partner Capital', 'partner_capital', 21],
        ['general', 'Partner Capital Withdrawal', 'partner_capital_withdrawal', 54],
    ] as [$catSection, $catName, $catSlug, $catSort]) {
        $chkCat = $pdo->prepare('SELECT id FROM categories WHERE section=? AND slug=? LIMIT 1');
        $chkCat->execute([$catSection, $catSlug]);
        if (!$chkCat->fetchColumn()) {
            $ins = $pdo->prepare('INSERT INTO categories (section, name, slug, sort_order) VALUES (?,?,?,?)');
            $ins->execute([$catSection, $catName, $catSlug, $catSort]);
        }
    }

    try {
        $pdo->exec(
            'UPDATE transactions t
             INNER JOIN booking_payments bp ON bp.transaction_id = t.id
             INNER JOIN bookings b ON b.id = bp.booking_id
             SET t.project_id = b.project_id
             WHERE b.project_id IS NOT NULL
               AND (t.project_id IS NULL OR t.project_id <> b.project_id)'
        );
    } catch (Throwable $e) {
    }
}

function migrate_category_section(PDO $pdo, string $slug, string $fromSection, string $toSection, int $sortOrder): void
{
    $fromStmt = $pdo->prepare('SELECT id FROM categories WHERE section = ? AND slug = ? LIMIT 1');
    $fromStmt->execute([$fromSection, $slug]);
    $fromId = $fromStmt->fetchColumn();
    $toStmt = $pdo->prepare('SELECT id FROM categories WHERE section = ? AND slug = ? LIMIT 1');
    $toStmt->execute([$toSection, $slug]);
    $toId = $toStmt->fetchColumn();

    if ($fromId && !$toId) {
        $upd = $pdo->prepare('UPDATE categories SET section = ?, sort_order = ? WHERE id = ?');
        $upd->execute([$toSection, $sortOrder, (int) $fromId]);
        return;
    }
    if ($fromId && $toId && (int) $fromId !== (int) $toId) {
        $pdo->prepare('UPDATE transactions SET category_id = ? WHERE category_id = ?')->execute([(int) $toId, (int) $fromId]);
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([(int) $fromId]);
    }
}

function ensure_db_index(PDO $pdo, string $table, string $indexName, string $columns): void
{
    try {
        $stmt = $pdo->query('SHOW INDEX FROM `' . str_replace('`', '', $table) . '`');
        foreach ($stmt->fetchAll() as $row) {
            if (($row['Key_name'] ?? '') === $indexName) {
                return;
            }
        }
        $pdo->exec("ALTER TABLE `$table` ADD INDEX `$indexName` ($columns)");
    } catch (Throwable $e) {
    }
}
