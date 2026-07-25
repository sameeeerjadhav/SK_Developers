# Sai Kuber Developers — Finance ERP

PHP + MySQL finance system for **Sai Kuber Developers**: main company + sub companies (Infra, Construction, Developers), projects, expenses, bank accounts, and total summary.

Designed for **Hostinger** shared hosting. UI follows `UI_UX_DESIGN_SYSTEM.md` (teal mint ERP).

## Features

- **Companies** — Main + Shri Sai Kuber Infra, Sai Kuber Construction, Shri Sai Kuber Developers
- **Projects** — Per company; Credit / Land Purchase / Expenses / Profit (matches whiteboard)
- **General topics** — Investment, Partner, Expense, Asset, Bank Loans, Bank Account, Deposit
- **Total Summary** — Group and per-company aggregates
- **Bank accounts** — Opening balance + live balance from linked transactions
- Responsive glass sidebar shell (mobile off-canvas)

## Hostinger setup

1. Create a MySQL database in hPanel.
2. Import `sql/schema.sql` via phpMyAdmin.
3. Copy `config/database.example.php` → `config/database.php` and fill credentials.
4. Upload all project files to `public_html` (or a subfolder).
5. Open the site and sign in.

### Default login

| Field | Value |
|--------|--------|
| Email | `admin@saikuber.com` |
| Password | `Admin@123` |

Change this password after first login (update via phpMyAdmin `users` table or a future profile screen).

### PHP version

PHP **8.0+** recommended (8.1/8.2 ideal). Enable PDO MySQL in Hostinger if not already on.

## Local folder layout

```
/
├── assets/css/app.css
├── assets/js/app.js
├── config/
├── includes/
├── pages/
├── api/
├── sql/schema.sql
├── index.php
├── login.php
└── logout.php
```

## Typical workflow

1. Confirm companies under **Companies**.
2. Create **Projects** under each company.
3. Add **Bank Accounts**.
4. Record money via **Transactions** (Investment, Partner, Booking, Land Purchase, Expenses…).
5. Open a project to see the Credit / Land / Expenses / Profit board.
6. Check **Total Summary** for group totals.

## Git

When your GitHub repo is ready, share the URL and we can push this codebase.
