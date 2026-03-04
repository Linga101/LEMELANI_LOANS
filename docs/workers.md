# Node Workers Runbook

Last updated: 2026-03-04

## Implemented worker

- `reminders/escalations`:
  - File: `workers/src/reminders_worker.js`
  - Purpose: sends repayment reminders and overdue escalations as in-app notifications.
  - Idempotency: enforced via `worker_job_runs` unique key on `(job_type, event_key, run_date)`.
- `scoring refresh`:
  - File: `workers/src/scoring_refresh_worker.js`
  - Purpose: recomputes customer credit scores in batches using parity rules from `CreditScoringEngine` (`MLW-v1.0`), updates `users.credit_score`, and logs `credit_history` (`score_adjusted`).
  - Idempotency: enforced via `worker_job_runs` unique key on `(job_type, event_key, run_date)`.
- `payments reconciliation/import`:
  - File: `workers/src/payments_reconcile_worker.js`
  - Purpose: imports external payment rows from CSV, validates loan/user mapping, and writes `repayments` records.
  - Idempotency: enforced via `worker_job_runs` event keys + duplicate check on `payment_reference`.
- `webhook processing`:
  - File: `workers/src/webhooks_process_worker.js`
  - Purpose: consumes `payment_webhook_events` in `received` status and converts valid events into `repayments`.
  - Idempotency: enforced via `worker_job_runs` per webhook event + duplicate check on `payment_reference`.

## Prerequisites

1. Apply migration:
   - `database/migrations/20260304_add_worker_job_runs.sql`
2. Install dependencies:
   - `cd workers`
   - `npm install`

## Environment variables

From project `.env`:

- `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`
- `WORKER_DB_POOL_SIZE` (default `5`)
- `WORKER_REMINDERS_DRY_RUN` (default `true`)
- `WORKER_REMINDERS_INTERVAL_SECONDS` (default `60`)
- `WORKER_REMINDER_WINDOW_DAYS` (default `2`)
- `WORKER_ESCALATION_GRACE_DAYS` (default `3`)
- `WORKER_SCORING_DRY_RUN` (default `true`)
- `WORKER_SCORING_INTERVAL_SECONDS` (default `600`)
- `WORKER_SCORING_STALE_DAYS` (default `30`)
- `WORKER_SCORING_BATCH_LIMIT` (default `200`)
- `WORKER_PAYMENTS_DRY_RUN` (default `true`)
- `WORKER_PAYMENTS_INTERVAL_SECONDS` (default `300`)
- `WORKER_PAYMENTS_IMPORT_DIR` (default `workers/imports/payments`)
- `WORKER_PAYMENTS_ARCHIVE_DIR` (default `workers/imports/archive`)
- `WORKER_WEBHOOKS_DRY_RUN` (default `true`)
- `WORKER_WEBHOOKS_INTERVAL_SECONDS` (default `120`)
- `WORKER_WEBHOOKS_BATCH_LIMIT` (default `100`)

## Commands

- Run once:
  - `npm run worker:reminders:once`
  - `npm run worker:scoring-refresh:once`
  - `npm run worker:payments-reconcile:once`
  - `npm run worker:webhooks-process:once`
- Run continuously:
  - `npm run worker:reminders`
  - `npm run worker:scoring-refresh`
  - `npm run worker:payments-reconcile`
  - `npm run worker:webhooks-process`

## Safety defaults

- Dry-run mode is enabled by default (`WORKER_REMINDERS_DRY_RUN=true`).
- In dry-run mode:
  - no notifications are inserted,
  - no credit score rows/updates are inserted for scoring refresh,
  - no imported repayments are inserted for payments reconciliation,
  - no webhook-driven repayments are inserted for webhook processing,
  - idempotency and audit records are still written for traceability.

## Recommended rollout

1. Run once in dry-run mode and review `worker_job_runs` + `audit_log`.
2. Switch to `WORKER_REMINDERS_DRY_RUN=false` in staging.
3. Schedule every 1-5 minutes in production process manager.

## Storage migration helper

To seed object storage mirror path from existing local files:

```powershell
# Dry-run
php scripts/migrate_storage_to_object.php

# Apply copy
php scripts/migrate_storage_to_object.php --apply
```

## Webhook ingestion scaffold

- Endpoint: `POST /api/v1/integrations/webhooks/{provider}`
- Providers: `airtel-money`, `tnm-mpamba`, `card`
- Header required: `X-Webhook-Secret`
- Secrets:
  - `WEBHOOK_SECRET_AIRTEL_MONEY`
  - `WEBHOOK_SECRET_TNM_MPAMBA`
  - `WEBHOOK_SECRET_CARD_GATEWAY`
- Storage table migration:
  - `database/migrations/20260304_add_payment_webhook_events.sql`
