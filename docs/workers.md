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

## Commands

- Run once:
  - `npm run worker:reminders:once`
  - `npm run worker:scoring-refresh:once`
- Run continuously:
  - `npm run worker:reminders`
  - `npm run worker:scoring-refresh`

## Safety defaults

- Dry-run mode is enabled by default (`WORKER_REMINDERS_DRY_RUN=true`).
- In dry-run mode:
  - no notifications are inserted,
  - no credit score rows/updates are inserted for scoring refresh,
  - idempotency and audit records are still written for traceability.

## Recommended rollout

1. Run once in dry-run mode and review `worker_job_runs` + `audit_log`.
2. Switch to `WORKER_REMINDERS_DRY_RUN=false` in staging.
3. Schedule every 1-5 minutes in production process manager.
