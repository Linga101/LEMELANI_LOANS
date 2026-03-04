# Cutover Runbook

Last updated: 2026-03-04

## Goal

Route customer/admin traffic to Next.js safely while keeping PHP rollback available.

## Preconditions

1. API parity complete for required customer/admin surfaces.
2. Smoke tests green:
   - `scripts/api_smoke_tests.ps1`
3. Workers validated and no critical failures:
   - reminders
   - scoring refresh
   - payments reconcile
   - webhook processor

## Flag strategy

- Per-surface flags:
  - `FF_NEXTJS_*`
- Global cutover flags:
  - `FF_NEXTJS_ALL`
  - `FF_NEXTJS_CUSTOMER_ALL`
  - `FF_NEXTJS_ADMIN_ALL`

Specific page flags take precedence if explicitly set.  
If not explicitly set, global flags can enable rollout by surface.

## Readiness check

Run:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/cutover_readiness.ps1
```

Target for full cutover:
- Customer cutover ready: `YES`
- Admin cutover ready: `YES`
- Workers live-ready: `YES`

## Rollout sequence

1. Enable `FF_NEXTJS_CUSTOMER_ALL=true` in staging.
2. Validate end-to-end customer flows.
3. Enable `FF_NEXTJS_ADMIN_ALL=true` in staging.
4. Validate admin operations and reports.
5. Promote same flags to production.
6. Keep legacy PHP pages available for rollback for at least 2 release cycles.

## Rollback

Set these to `false` and reload:
- `FF_NEXTJS_ALL`
- `FF_NEXTJS_CUSTOMER_ALL`
- `FF_NEXTJS_ADMIN_ALL`

Legacy PHP controllers remain active and will serve traffic again.

## Decommission gate

Only remove legacy PHP views when all are true:
1. 2 stable release cycles after full cutover.
2. No P0/P1 parity issues open.
3. No worker ingestion/reconciliation incidents unresolved.
4. Audit/report outputs validated against historical baseline.

