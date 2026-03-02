# Lemelani Loans — Database (Malawi schema + FIFO)

This folder contains the integrated **Malawi loan lending** schema used by Lemelani Loans. The database is **MySQL 8.0+**, currency **MWK** (Malawian Kwacha), and loan processing follows **FIFO** (First In, First Out).

## Setup

1. Create the database and tables by running the schema file in MySQL:

   ```bash
   mysql -u root -p < "Database Schema.sql"
   ```

   Or from MySQL client:

   ```sql
   source c:/xampp/htdocs/LEMELANI_LOANS/database/Database Schema.sql
   ```

2. Ensure `config/database.php` uses the same database name: `lemelani_loans`.

3. If upgrading an existing installation, run:

   ```sql
   source c:/xampp/htdocs/LEMELANI_LOANS/database/migrations/20260302_add_disbursement_accounts.sql
   ```

## Main tables

| Table | Purpose |
|-------|--------|
| `users` | Borrowers and staff (Malawi + Lemelani: role, verification_status) |
| `user_profiles` | Employment, income, district, next of kin |
| `credit_scores` | 300–850 score and tier (Malawi model) |
| `loan_products` | Product types (Salary Advance, SME, Agri, Emergency, etc.) |
| `loan_applications` | **FIFO queue** — applications in `applied_at` order |
| `loans` | Disbursed loans only (created when an application is approved) |
| `repayments` | Payment events (amount_paid_mwk, payment_reference, status on_time/late/missed) |
| `repayment_schedule` | Installments per loan |
| `credit_history` | Events (loan_applied, loan_approved, payment_made, etc.) |
| `alternative_credit_data` | Mobile money / utility data |
| `user_documents` | KYC documents |
| `notifications` | In-app messages |
| `audit_log` | System audit trail |
| `system_settings` | Config (min/max loan, rates, FIFO on/off) |

## FIFO (First In, First Out)

- New applications are inserted into `loan_applications` with `status = 'pending'`.
- Admin (or automated job) should process applications in **oldest-first** order:  
  `ORDER BY applied_at ASC`.
- The application code uses `getPendingApplicationsFifo()` and processes by `application_id`; on approve, a row is created in `loans` and the application is marked `disbursed`.

## Views

- **v_borrower_profile** — Borrower summary with latest credit score and loan counts.
- **v_repayment_performance** — Per-loan repayment stats (on_time / late / missed).
- **v_loans_legacy** — Loans with legacy column names (loan_amount, remaining_balance, total_amount) for compatibility.

## Notes

- Primary keys: `user_id` (users), `loan_id` (loans), `repayment_id` (repayments), `id` (loan_applications).
- All monetary amounts are in **MWK**.
- Credit score range: **300–850** (synced to `users.credit_score` for quick checks).
