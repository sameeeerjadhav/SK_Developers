<?php
declare(strict_types=1);

/**
 * Auto-apply v2 schema pieces if missing (Hostinger-safe).
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
          paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
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

    // Columns on bank_loans
    $cols = $pdo->query("SHOW COLUMNS FROM bank_loans")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('emi_amount', $cols, true)) {
        $pdo->exec('ALTER TABLE bank_loans ADD COLUMN emi_amount DECIMAL(14,2) NULL AFTER interest_rate');
    }
    if (!in_array('tenure_months', $cols, true)) {
        $pdo->exec('ALTER TABLE bank_loans ADD COLUMN tenure_months INT UNSIGNED NULL AFTER emi_amount');
    }
    if (!in_array('emi_start_date', $cols, true)) {
        $pdo->exec('ALTER TABLE bank_loans ADD COLUMN emi_start_date DATE NULL AFTER tenure_months');
    }

    $userCols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('status', $userCols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN status ENUM('active','disabled') NOT NULL DEFAULT 'active' AFTER role");
    }
}
