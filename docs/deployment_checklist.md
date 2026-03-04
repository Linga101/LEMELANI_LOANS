# Deployment Checklist

Last updated: 2026-03-04

## 1) Environment validation

Run:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/validate_production_env.ps1
```

Optional strict mode (warnings fail build):

```powershell
powershell -ExecutionPolicy Bypass -File scripts/validate_production_env.ps1 -Strict
```

## 2) Readiness summary

Run:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/cutover_readiness.ps1
```

Required before production cutover:
- Customer cutover ready: `YES`
- Admin cutover ready: `YES`
- Workers live-ready: `YES`

## 3) Database migrations

Apply:
- `database/migrations/20260304_add_worker_job_runs.sql`
- `database/migrations/20260304_add_payment_webhook_events.sql`

## 4) Worker health checks

Run once each:

```powershell
cd workers
npm run worker:reminders:once
npm run worker:scoring-refresh:once
npm run worker:payments-reconcile:once
npm run worker:webhooks-process:once
```

## 5) API smoke

Run:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/api_smoke_tests.ps1 -UserEmail "<user>" -UserPassword "<pass>" -AdminEmail "<admin>" -AdminPassword "<pass>"
```

## 6) Rollback posture

Confirm rollback path remains available:
- `scripts/set_cutover_profile.ps1 -Profile rollback -Apply`
- legacy PHP pages still load after rollback.

## 7) Decommission gate

Do not remove legacy PHP views until:
1. Two stable release cycles after full cutover.
2. No unresolved P0/P1 incidents on API/workers.
3. Reporting and audit parity validated.

Automated gate check:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/decommission_gate.ps1
```

Gate configuration:
- `docs/release_markers.json`
