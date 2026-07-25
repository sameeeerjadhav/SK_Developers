# Sai Kuber Developers — Finance ERP

PHP + MySQL finance system for **Sai Kuber Developers**: main company + sub companies (Infra, Construction, Developers), projects, expenses, bank accounts, and total summary.

Designed for **Hostinger** shared hosting. UI follows `UI_UX_DESIGN_SYSTEM.md` (teal mint ERP).

## Features

- **Companies** — Main + Shri Sai Kuber Infra, Sai Kuber Construction, Shri Sai Kuber Developers
- **Projects** — Per company; Credit / Land Purchase / Expenses / Profit board
- **General topics** — Investment, Partner, Expense, Asset, Bank Loans, Bank Account, Deposit
- **Ledger** — Transactions with bank + partner linking
- **Bank statements** — Live balance from opening + credits − debits
- **Total Summary** — Group and per-company aggregates
- **Installer** — `install.php` writes DB config, imports schema, creates admin
- **Profile** — Change name / email / password
- Responsive glass sidebar (mobile off-canvas)

## Hostinger setup (recommended)

1. Create a MySQL database in hPanel.
2. Upload all project files to `public_html` (or a subfolder).
3. Open `https://your-domain.com/install.php`
4. Enter DB credentials + your admin email/password → Install.
5. Sign in and change nothing else unless needed.
6. (Optional) Import `sql/seed_demo.sql` in phpMyAdmin for sample data.
7. After install, you can delete `install.php` (lock file already blocks re-run).

### Manual setup (without installer)

1. Create MySQL DB.
2. Import `sql/schema.sql` (optional: then `sql/seed_demo.sql`).
3. Copy `config/database.example.php` → `config/database.php` and fill credentials.
4. Login with the admin you set in schema / installer.

### Default schema admin (only if you imported schema.sql without installer)

| Field | Value |
|--------|--------|
| Email | `admin@saikuber.com` |
| Password | `Admin@123` |

**Change this immediately** via Profile.

### PHP version

PHP **8.0+** recommended (8.1/8.2 ideal). PDO MySQL must be enabled.

## Typical workflow

1. Confirm companies under **Companies**.
2. Add **Bank Accounts** per company.
3. Create **Projects** under each company.
4. Record money via **Transactions** (or use Partner / Bank Loan forms with “post to ledger”).
5. Open a project to see Credit / Land / Expenses / Profit.
6. Check **Total Summary** for group totals.
7. Open a bank account → **Statement** for spent vs balance.

## Folder layout

```
/
├── install.php
├── index.php / login.php / logout.php
├── assets/
├── config/
├── includes/
├── pages/
├── api/
└── sql/schema.sql + seed_demo.sql
```
