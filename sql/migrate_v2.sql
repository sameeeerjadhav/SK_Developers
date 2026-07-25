-- Run once on existing Hostinger DB (phpMyAdmin) if app was already installed.
-- Safe to skip columns that already exist (will error — ignore those lines).

ALTER TABLE bank_loans
  ADD COLUMN emi_amount DECIMAL(14,2) NULL AFTER interest_rate,
  ADD COLUMN tenure_months INT UNSIGNED NULL AFTER emi_amount,
  ADD COLUMN emi_start_date DATE NULL AFTER tenure_months;

ALTER TABLE users
  ADD COLUMN status ENUM('active','disabled') NOT NULL DEFAULT 'active' AFTER role;

CREATE TABLE IF NOT EXISTS loan_emis (
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
  CONSTRAINT fk_emis_loan FOREIGN KEY (loan_id) REFERENCES bank_loans(id) ON DELETE CASCADE,
  CONSTRAINT fk_emis_txn FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
  UNIQUE KEY uq_loan_installment (loan_id, installment_no),
  INDEX idx_emi_due (due_date, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attachments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id INT UNSIGNED NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NULL,
  size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_att_txn FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_att_txn (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
