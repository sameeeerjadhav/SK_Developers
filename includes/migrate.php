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
          from_account_id INT UNSIGNED NOT NULL,
          to_account_id INT UNSIGNED NOT NULL,
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
}
