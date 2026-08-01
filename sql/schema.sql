-- Sai Kuber Developers — Finance ERP
-- Import this in Hostinger phpMyAdmin (create DB first, then import)

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS deposits;
DROP TABLE IF EXISTS bank_loans;
DROP TABLE IF EXISTS assets;
DROP TABLE IF EXISTS partners;
DROP TABLE IF EXISTS bank_accounts;
DROP TABLE IF EXISTS projects;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS companies;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','staff') NOT NULL DEFAULT 'admin',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE companies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(80) NOT NULL UNIQUE,
  type ENUM('main','sub') NOT NULL DEFAULT 'sub',
  parent_id INT UNSIGNED NULL,
  description TEXT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_companies_parent FOREIGN KEY (parent_id) REFERENCES companies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE projects (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  name VARCHAR(180) NOT NULL,
  location VARCHAR(180) NULL,
  status ENUM('planning','active','completed','on_hold') NOT NULL DEFAULT 'active',
  start_date DATE NULL,
  end_date DATE NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_projects_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  section ENUM('credit','land_purchase','expense','general') NOT NULL,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_section_slug (section, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE bank_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  account_name VARCHAR(160) NOT NULL,
  bank_name VARCHAR(160) NOT NULL,
  account_number VARCHAR(64) NULL,
  ifsc VARCHAR(32) NULL,
  opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  status ENUM('active','closed') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_bank_accounts_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE partners (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NULL,
  name VARCHAR(160) NOT NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(160) NULL,
  share_percent DECIMAL(5,2) NULL,
  invested_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_partners_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE assets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  asset_type VARCHAR(80) NULL,
  purchase_date DATE NULL,
  purchase_value DECIMAL(14,2) NOT NULL DEFAULT 0,
  current_value DECIMAL(14,2) NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_assets_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE bank_loans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NULL,
  lender_name VARCHAR(160) NOT NULL,
  loan_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  outstanding_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  interest_rate DECIMAL(6,2) NULL,
  interest_charges DECIMAL(14,2) NULL,
  emi_amount DECIMAL(14,2) NULL,
  tenure_months INT UNSIGNED NULL,
  emi_start_date DATE NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  status ENUM('active','closed') NOT NULL DEFAULT 'active',
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_loans_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_loans_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE deposits (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  bank_account_id INT UNSIGNED NULL,
  title VARCHAR(160) NOT NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  deposit_date DATE NULL,
  maturity_date DATE NULL,
  interest_rate DECIMAL(6,2) NULL,
  status ENUM('active','matured','withdrawn') NOT NULL DEFAULT 'active',
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_deposits_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_deposits_account FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NULL,
  bank_account_id INT UNSIGNED NULL,
  category_id INT UNSIGNED NOT NULL,
  partner_id INT UNSIGNED NULL,
  txn_type ENUM('credit','debit') NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  txn_date DATE NOT NULL,
  reference_no VARCHAR(80) NULL,
  description TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_txn_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_txn_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
  CONSTRAINT fk_txn_account FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_txn_category FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT fk_txn_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE SET NULL,
  CONSTRAINT fk_txn_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_txn_date (txn_date),
  INDEX idx_txn_company_project (company_id, project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loan_emis (
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

CREATE TABLE loan_repayments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE attachments (
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

CREATE TABLE booking_details (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(160) NULL,
  address TEXT NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_customers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE bookings (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE booking_payments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default admin: admin@saikuber.com / Admin@123
INSERT INTO users (name, email, password, role) VALUES
('Admin', 'admin@saikuber.com', '$2y$12$v0ZJnO7EeTm7C9nrkIJYmuKoJL5KCd3s6sEDllOswDyrY2z2N5g22', 'admin');

-- Companies hierarchy
INSERT INTO companies (id, name, slug, type, parent_id, description) VALUES
(1, 'Sai Kuber Developers', 'main', 'main', NULL, 'Main company — Sai Kuber Developers'),
(2, 'Shri Sai Kuber Infra', 'infra', 'sub', 1, 'Sub company — Infrastructure'),
(3, 'Sai Kuber Construction', 'construction', 'sub', 1, 'Sub company — Construction'),
(4, 'Shri Sai Kuber Developers', 'developers', 'sub', 1, 'Sub company — Developers');

-- Ledger categories (from whiteboard)
INSERT INTO categories (section, name, slug, sort_order) VALUES
-- Credit
('credit', 'Investment', 'investment', 10),
('credit', 'Daily Credit', 'daily_credit', 11),
('credit', 'Monthly Credit', 'monthly_credit', 12),
('credit', 'Partner', 'partner', 20),
('credit', 'Booking', 'booking', 30),
('credit', 'Bank Loan', 'bank_loan', 40),
('credit', 'Bank Account', 'bank_account', 50),
-- Land purchase (debit)
('land_purchase', 'Land Purchase', 'land_purchase', 10),
('land_purchase', 'Stamp Duty', 'stamp_duty', 20),
('land_purchase', 'Documentation Charges', 'documentation_charges', 30),
('land_purchase', 'Commission', 'commission', 40),
-- Expenses (debit)
('expense', 'Office Expenses', 'office_expenses', 10),
('expense', 'Material Purchase', 'material_purchase', 20),
('expense', 'Electricity, Drainage, Water Line', 'utilities', 30),
('expense', 'Salary', 'salary', 40),
('expense', 'Commission', 'commission', 50),
('expense', 'Labour Charges', 'labour_charges', 60),
('expense', 'Interest Paid', 'interest_paid', 70),
('expense', 'Loan Repayment', 'loan_repayment', 75),
-- General topic helpers
('general', 'Investment', 'investment', 10),
('general', 'Deposit', 'deposit', 20),
('general', 'Asset Purchase', 'asset_purchase', 30),
('general', 'Bank Transfer', 'bank_transfer', 40),
('general', 'Investment Withdrawal', 'investment_withdrawal', 50),
('general', 'Daily Debit', 'daily_debit', 51),
('general', 'Monthly Debit', 'monthly_debit', 52),
('general', 'Booking Refund', 'booking_refund', 53);

-- Sample projects
INSERT INTO projects (company_id, name, location, status, start_date) VALUES
(1, 'Corporate HQ Ops', 'Pune', 'active', CURDATE()),
(2, 'Infra Phase 1', 'Pune', 'active', CURDATE()),
(3, 'Residential Block A', 'Pune', 'active', CURDATE()),
(4, 'Plot Development — West', 'Pune', 'planning', CURDATE());
