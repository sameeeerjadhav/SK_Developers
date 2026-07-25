-- Optional demo data (run AFTER schema.sql)
-- Gives sample bank accounts, partners, and transactions so the UI is not empty.

SET NAMES utf8mb4;

INSERT INTO bank_accounts (company_id, account_name, bank_name, account_number, ifsc, opening_balance, status) VALUES
(1, 'SKD Main Current', 'HDFC Bank', '50100012345678', 'HDFC0001234', 500000.00, 'active'),
(2, 'Infra Ops', 'ICICI Bank', '628001234567', 'ICIC0006280', 150000.00, 'active'),
(3, 'Construction Current', 'SBI', '30123456789', 'SBIN0001234', 200000.00, 'active'),
(4, 'Developers Current', 'Axis Bank', '912010123456789', 'UTIB0000123', 100000.00, 'active');

INSERT INTO partners (company_id, name, phone, share_percent, invested_amount, notes) VALUES
(1, 'Ramesh Patil', '9876543210', 25.00, 0, 'Main partner'),
(3, 'Suresh Deshmukh', '9123456780', 40.00, 0, 'Construction JV');

-- Sample credits / debits (adjust category IDs via slug joins)
INSERT INTO transactions (company_id, project_id, bank_account_id, category_id, partner_id, txn_type, amount, txn_date, description, created_by)
SELECT 1, 1, ba.id, c.id, NULL, 'credit', 250000.00, CURDATE(), 'Seed investment — main company', 1
FROM categories c
JOIN bank_accounts ba ON ba.company_id = 1
WHERE c.section = 'credit' AND c.slug = 'investment'
LIMIT 1;

INSERT INTO transactions (company_id, project_id, bank_account_id, category_id, partner_id, txn_type, amount, txn_date, description, created_by)
SELECT 3, 3, ba.id, c.id, p.id, 'credit', 100000.00, CURDATE(), 'Partner capital — construction', 1
FROM categories c
JOIN bank_accounts ba ON ba.company_id = 3
JOIN partners p ON p.company_id = 3
WHERE c.section = 'credit' AND c.slug = 'partner'
LIMIT 1;

INSERT INTO transactions (company_id, project_id, bank_account_id, category_id, txn_type, amount, txn_date, description, created_by)
SELECT 3, 3, ba.id, c.id, 'debit', 75000.00, CURDATE(), 'Land booking advance', 1
FROM categories c
JOIN bank_accounts ba ON ba.company_id = 3
WHERE c.section = 'land_purchase' AND c.slug = 'land_purchase'
LIMIT 1;

INSERT INTO transactions (company_id, project_id, bank_account_id, category_id, txn_type, amount, txn_date, description, created_by)
SELECT 2, 2, ba.id, c.id, 'debit', 18500.00, CURDATE(), 'Site office expenses', 1
FROM categories c
JOIN bank_accounts ba ON ba.company_id = 2
WHERE c.section = 'expense' AND c.slug = 'office_expenses'
LIMIT 1;

INSERT INTO transactions (company_id, project_id, bank_account_id, category_id, txn_type, amount, txn_date, description, created_by)
SELECT 4, 4, ba.id, c.id, 'credit', 50000.00, CURDATE(), 'Plot booking receipt', 1
FROM categories c
JOIN bank_accounts ba ON ba.company_id = 4
WHERE c.section = 'credit' AND c.slug = 'booking'
LIMIT 1;

UPDATE partners pr
JOIN (
  SELECT partner_id, SUM(amount) AS total
  FROM transactions t
  JOIN categories c ON c.id = t.category_id
  WHERE t.partner_id IS NOT NULL AND c.slug = 'partner'
  GROUP BY partner_id
) x ON x.partner_id = pr.id
SET pr.invested_amount = x.total;

INSERT INTO bank_loans (company_id, project_id, lender_name, loan_amount, outstanding_amount, interest_rate, start_date, status, notes)
VALUES (3, 3, 'HDFC Project Loan', 500000.00, 480000.00, 9.50, CURDATE(), 'active', 'Demo loan');

INSERT INTO assets (company_id, name, asset_type, purchase_date, purchase_value, current_value)
VALUES (1, 'Office Laptop', 'Electronics', CURDATE(), 65000.00, 55000.00);

INSERT INTO deposits (company_id, bank_account_id, title, amount, deposit_date, maturity_date, interest_rate, status)
SELECT 1, id, 'HDFC FD — 12 months', 100000.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 6.50, 'active'
FROM bank_accounts WHERE company_id = 1 LIMIT 1;
